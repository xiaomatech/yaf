<?php

/**
 * Class MetaQ
 *
 *  $metaq = new MetaQ();
 *  consumer
 *  $topic = 't1';
 * $group = 0;
 * $metaq->subscribe($topic, $group);
 *
 * while (1) {
 * $msgs = $metaq->getNext();
 * foreach ($msgs as $msg) {
 * print_r($msg);
 * }
 * }
 *  producer
 *
 *  $i = 0;
 * while (++$i < 100000) {
 * $result = $metaq->put('t1', 'hello' . $i);
 * print_r($result);
 * sleep(1);
 * }
 */
class MetaQ
{

    static $log;
    static $config;
    private $producer;
    private $consumer;
    private $topics;
    private $writeList;
    private $readList;


    public function __construct()
    {
        $this->config = Yaconf::get("common")['metaq'];
        self::$log = KLogger::instance($this->config['log_file'], KLogger::DEBUG);
        $this->initPartitionList();
    }

    private function initPartitionList()
    {
        $readList = $writeList = array();
        foreach ($this->config['brokers'] as $id => $broker) {
            if ($broker['role'] === 'master') {
                foreach ($broker['topics'] as $topic => $parts) {
                    if (!isset($writeList[$topic])) {
                        $writeList[$topic] = array();
                    }
                    foreach ($parts['partitions'] as $part) {
                        $writeList[$topic][] = $broker['host'] . '-' . $broker['port'] . '-' . $part;
                    }
                }
                foreach ($broker['topics'] as $topic => $parts) {
                    if (!isset($readList[$topic])) {
                        $readList[$topic] = array();
                    }
                    foreach ($parts['partitions'] as $part) {
                        $readList[$topic][] = $broker['host'] . '-' . $broker['port'] .
                            '-' . $part . '-' . $broker['host'] . '-' . $broker['port'] . '-' . $part;
                    }
                }
            } else {
                foreach ($broker['topics'] as $topic => $parts) {
                    if (!isset($readList[$topic])) {
                        $readList[$topic] = array();
                    }
                    foreach ($parts['partitions'] as $part) {
                        $master = $this->config['brokers'][(int)$part['master']];
                        $readList[$topic][] = $broker['host'] . '-' . $broker['port'] .
                            '-' . $part . '-' . $master['host'] . '-' . $master['port'] . '-' . $part;
                    }
                }
            }

        }

        $this->writeList = $writeList;
        $this->readList = $readList;
    }

    public function put($topic, $msg, $async = false)
    {
        if (!$this->producer) {
            $this->producer = new Producer($this->writeList);
        }
        $result = -1;
        try {
            $result = $this->producer->put($topic, $msg, $async);
        } catch (Exception $e) {
            echo $e;
        }
        return $result;
    }

    public function subscribe($topic, $group)
    {
        if (!$this->consumer) {
            $this->consumer = new Consumer($this->config['zkHosts']);
        }
        try {
            $this->consumer->subscribe($topic, $group);
        } catch (Exception $e) {
            echo $e;
        }
    }

    public function subscribePartition($topic, $group, $host, $port, $partition)
    {
        if (!$this->consumer) {
            $this->consumer = new Consumer($this->config['zkHosts']);
        }
        try {
            $this->consumer->subscribePartition($topic, $group, $host, $port, $partition);
        } catch (Exception $e) {
            echo $e;
        }
    }

    public function getNext()
    {
        return $this->consumer->getNext();
    }
}

class Producer extends MetaQ
{

    const RETRY = 3;
    private $sockets;
    private $writeList;
    private $inactive;

    public function __construct($writeList)
    {
        $this->writeList = $writeList;
    }

    public function put($topic, $msg, $async)
    {
        if (strlen($msg) > Codec::MAX_MSG_LENGTH) {
            throw new Exception('Can not put message which length > ' . Codec::MAX_MSG_LENGTH);
        }
        list($host, $port, $part) = $this->selectPart($topic);
        if (!isset($this->sockets[$host . ':' . $port])) {
            $this->sockets[$host . ':' . $port] = new Socket($host, $port);
            try {
                $this->sockets[$host . ':' . $port]->connect();
            } catch (Exception $e) {
                echo $e->getMessage();
            }

            if (!$this->sockets[$host . ':' . $port]->active) {
                $this->inactive = $host . '-' . $port;
                $this->removeInactive($topic);
                if (sizeof($this->writeList[$topic]) > 0) {
                    $this->put($topic, $msg);
                } else {
                    throw new Exception('all brokers seems down');
                }
            }
        }
        $socket = $this->sockets[$host . ':' . $port];

        $data = Codec::putEncode($topic, $part, $msg);
        $reTry = 0;
        $success = false;
        while (1) {
            $writeSuccess = $socket->write($data);
            if ($writeSuccess && $async) {
                $success = true;
                $result = array(
                    'id' => -1,
                    'code' => 0,
                    'offset' => -1
                );
                break;
            }
            $buf = $socket->read0();
            list($success, $result, $errorMsg) = Codec::putResultDecode($buf);
            if ($errorMsg) {
                throw new MetaQ_Exception($errorMsg);
            }
            if ($success || $reTry >= self::RETRY) {
                break;
            }
            usleep(500);
            $reTry++;
        }

        if (!$success) {
            throw new MetaQ_Exception('put command not succeed to ' . $host . ':' . $port);
            $this->inactive = $host . '-' . $port;
            $this->removeInactive($topic);
            if (sizeof($this->writeList) > 0) {
                $this->put($topic, $msg);
            } else {
                throw new Exception('all brokers seems down');
            }
        }
        return $result;
    }

    private function selectPart($topic)
    {
        if (!isset($this->writeList[$topic])) {
            throw new Exception('no broker accept topic ' . $topic);
        }
        $partId = rand(0, sizeof($this->writeList[$topic]) - 1);
        return explode('-', $this->writeList[$topic][$partId]);
    }

    private function removeInactive($topic)
    {
        $writeList = $this->writeList[$topic];
        $newWriteList = array();
        foreach ($writeList as $part) {
            if (strpos($part, $this->inactive) === false) {
                $newWriteList[] = $part;
            }
        }
        $this->writeList[$topic] = $newWriteList;
    }

}

class Socket
{

    public $active = false;
    private $host;
    private $port;
    private $timeout;
    private $stream;

    public function __construct($host = 'localhost', $port = 8023, $timeout = 3)
    {
        $this->host = $host;
        $this->port = $port;
        $this->timeout = $timeout;
    }

    public function connect()
    {
        $this->stream = @fsockopen(
            $this->host,
            $this->port,
            $errNo,
            $errStr,
            $this->timeout
        );
        @stream_set_blocking($this->stream, 0);
        if (!is_resource($this->stream)) {
            throw new MetaQ_Exception('Can not connect to remote port ' . $this->host . ':' . $this->port);
            return;
        }
        register_shutdown_function(array($this, 'close'));
        $this->active = true;
    }

    public function close()
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    public function read0()
    {
        $null = null;
        $selective = array($this->stream);
        $readable = @stream_select($selective, $null, $null, $this->timeout);
        if ($readable) {
            $data = $chunk = '';
            $data .= fgets($this->stream);
            $data .= fgets($this->stream);
            return $data;
        }
        if ($readable !== false && $this->stream) {
            $res = stream_get_meta_data($this->stream);
            if (!empty($res['timed_out'])) {
                throw new MetaQ_Exception('Can not read from remote server, timeout');
            }
        }
        return false;
    }

    public function read($maxLen)
    {
        $null = null;
        $selective = array($this->stream);
        $readable = @stream_select($selective, $null, $null, $this->timeout);
        if ($readable) {
            $data = $chunk = '';

            $head = fgets($this->stream);
            $data .= $head;
            list($first, $second, $third) = explode(" ", $head);
            if ($first == 'value') {
                while (1) {
                    $chunk = fread($this->stream, 8192);
                    $data .= $chunk;
                    if (strlen($data) >= $second + strlen($head)) {
                        break;
                    }
                }
            } else if ($first == 'result') {
                $data .= fgets($this->stream);
            }
            return $data;
        }
        if ($readable !== false && $this->stream) {
            $res = stream_get_meta_data($this->stream);
            if (!empty($res['timed_out'])) {
                throw new MetaQ_Exception('Can not read from remote server, timeout');
            }
        }
        return false;
    }

    public function write($buf)
    {
        $null = null;
        $selective = array($this->stream);
        $writable = @stream_select($null, $selective, $null, $this->timeout);
        if ($writable) {
            $written = fwrite($this->stream, $buf);
            if ($written === -1 || $written === false) {
                throw new MetaQ_Exception('Can not write to remote server');
            }
            if ($written === strlen($buf)) {
                return true;
            }
        }
        if ($writable !== false && $this->stream) {
            $res = stream_get_meta_data($this->stream);
            if (!empty($res['timed_out'])) {
                throw new MetaQ_Exception('Can not write to remote server, timeout');
            }
        }
        return false;
    }
}

class MetaZookeeper
{

    private $conn;
    private $zk;
    private $brokerMetaData = null;

    public function __construct($conn = '127.0.0.1:2181')
    {
        $this->conn = $conn;
        $this->connect();
    }

    private function connect()
    {
        if (!$this->zk) {
            $this->zk = new Zookeeper($this->conn);
            $this->brokerMetaData = null;
        }
    }

    public function getTopicMetadata()
    {
        $this->connect();
        $topicMetadata = array();
        foreach ($this->zk->getChildren("/meta/brokers/topics") as $topic) {
            foreach ($this->zk->getChildren("/meta/brokers/topics/$topic") as $brokerId) {
                $brokerInfo = $this->getBrokerInfo($brokerId);
                if (!$brokerInfo) continue;
                $arr = explode("-", $brokerId);
                $masterInfo = $this->getBrokerInfo($arr[0] . '-' . 'm');
                $partitionCount = (int)$this->zk->get(
                    "/meta/brokers/topics/$topic/$brokerId"
                );
                for ($p = 0; $p < $partitionCount; $p++) {
                    $topicMetadata[$topic][] = array(
                        "id" => "{$brokerId}-{$p}",
                        "broker" => $brokerId,
                        "host" => $brokerInfo['host'],
                        "port" => $brokerInfo['port'],
                        "part" => $p,
                        "master" => $masterInfo['host'] . '-' . $masterInfo['port'] . '-' . $p,
                        "gid" => $arr[0],
                        "type" => (strpos($brokerId, 'm') > -1) ? 'master' : 'slave'
                        // "brokerInfo" => $brokerInfo,
                        // "masterInfo" => $masterInfo
                    );
                }
            }
        }

        return $topicMetadata;
    }

    public function getCversion($path)
    {
        $a = array();
        $this->zk->get($path, null, $a);
        return $a['cversion'];
    }

    public function getBrokerInfo($brokerId)
    {
        $this->getBrokerMetadata();
        if (!isset($this->brokerMetadata[$brokerId])) {
            //throw new \MetaQ_Exception("Unknown brokerId `$brokerId`");
            return false;
        }
        return $this->brokerMetadata[$brokerId];
    }

    public function getBrokerMetadata()
    {
        if ($this->brokerMetaData === null) {
            $this->connect();
            $this->brokerMetadata = array();
            $brokers = $this->zk->getChildren("/meta/brokers/ids", array($this, 'brokerWatcher'));
            foreach ($brokers as $brokerId) {
                $group = $this->zk->getChildren("/meta/brokers/ids/$brokerId");
                foreach ($group as $server) {
                    $brokerIdentifier = $this->zk->get("/meta/brokers/ids/$brokerId/$server");
                    if ($server === "master_config_checksum") continue;
                    $server = str_replace('master', 'm', $server);
                    $server = str_replace('slave', 's', $server);

                    $brokerIdentifier = str_replace('meta://', '', $brokerIdentifier);
                    $parts = explode(":", $brokerIdentifier);
                    $this->brokerMetadata[$brokerId . '-' . $server] = array(
                        'host' => $parts[0],
                        'port' => $parts[1],
                    );
                }
            }
        }
        return $this->brokerMetadata;
    }

    public function brokerWatcher($type, $state, $path)
    {
        if ($path == "/meta/brokers/ids") {
            $this->brokerMetadata = null;
        }
    }

    public function needsRefereshing()
    {
        return $this->brokerMetadata === null;
    }

    public function registerConsumerProcess($groupId, $processId)
    {
        $this->connect();
        if (!$this->zk->exists("/meta/consumers")) $this->createPermaNode("/meta/consumers");
        if (!$this->zk->exists("/meta/consumers/{$groupId}")) $this->createPermaNode("/meta/consumers/{$groupId}");
        if (!$this->zk->exists("/meta/consumers/{$groupId}/owners")) $this->createPermaNode("/meta/consumers/{$groupId}/owners");
        if (!$this->zk->exists("/meta/consumers/{$groupId}/offsets")) $this->createPermaNode("/meta/consumers/{$groupId}/offsets");
        if (!$this->zk->exists("/meta/consumers/{$groupId}/ids")) $this->createPermaNode("/meta/consumers/{$groupId}/ids");

        if (!$this->zk->exists("/meta/consumers/{$groupId}/ids/$processId")) {
            $this->createEphemeralNode("/meta/consumers/{$groupId}/ids/$processId", "");
        }
    }

    private function createPermaNode($path, $value = null)
    {
        $this->zk->create(
            $path,
            $value,
            $params = array(array(
                'perms' => Zookeeper::PERM_ALL,
                'scheme' => 'world',
                'id' => 'anyone',
            ))
        );
    }

    private function createEphemeralNode($path, $value)
    {
        $result = $this->zk->create(
            $path,
            $value,
            $params = array(array(
                'perms' => Zookeeper::PERM_ALL,
                'scheme' => 'world',
                'id' => 'anyone',
            )),
            Zookeeper::EPHEMERAL
        );
        return $result;
    }

    public function removeOldOwnPartitions($groupId, $topic, $partition)
    {
        $pid = $partition[3];
        $path = "/meta/consumers/{$groupId}/owners/{$topic}/{$pid}";
        if ($this->zk->exists($path)) {
            $this->zk->delete($path);
        }
    }

    public function setPartitionOwner($groupId, $topic, $partition, $consumerId)
    {
        $pid = $partition['gid'] . '-' . $partition['part'];
        $path = "/meta/consumers/{$groupId}/owners/{$topic}";
        if (!$this->zk->exists($path)) {
            $this->createPermaNode("{$path}");
        }
        if (!$this->zk->exists("{$path}/{$pid}")) {
            $this->createEphemeralNode("{$path}/{$pid}", $consumerId);
            $result = true;
        } else {
            $result = false;
        }
        return $result;
    }

    public function getTopicConsumers($groupId, $topic)
    {
        $consumers = array();
        $this->connect();
        if (!$this->zk->exists("/meta/consumers/{$groupId}/ids")) {
            $this->createPermaNode("/meta/consumers/{$groupId}/ids");
        }
        $path = "/meta/consumers/{$groupId}/ids";
        if ($this->zk->exists($path)) {
            foreach ($this->zk->getChildren($path) as $id) {
                $consumers[] = array($id, $this->zk->get("{$path}/{$id}"));
            }
        }
        return $consumers;
    }

    public function getTopicOffset($groupId, $topic, $brokerId, $partition)
    {
        $offset = 0;
        $this->connect();
        if (!$this->zk->exists("/meta/consumers/{$groupId}/offsets/$topic/{$brokerId}-{$partition}")) {
            $this->createPermaNode("/meta/consumers/{$groupId}/offsets/$topic/{$brokerId}-{$partition}");
        }
        $path = "/meta/consumers/{$groupId}/offsets/$topic/{$brokerId}-{$partition}";
        $offset = $this->zk->get("{$path}");
        return $offset;
    }

    public function commitOffset($groupId, $topic, $brokerId, $partition, $offset)
    {
        $this->connect();
        $path = "/meta/consumers/{$groupId}/offsets/{$topic}";
        if (!$this->zk->exists($path)) {
            $this->createPermaNode($path);
        }
        if (!$this->zk->exists("{$path}/{$brokerId}-{$partition}")) {
            $this->createPermaNode("{$path}/{$brokerId}-{$partition}", $offset);
        } else {
            $this->zk->set("{$path}/{$brokerId}-{$partition}", $offset);
        }
    }
}


class MetaQ_Exception extends Exception
{
    public function __construct($msg)
    {
        echo 'MetaQ exception: ' . $msg . "\n";
        return $msg;
    }
}

class KLogger
{
    /**
     * Error severity, from low to high. From BSD syslog RFC, secion 4.1.1
     * @link http://www.faqs.org/rfcs/rfc3164.html
     */
    const EMERG = 0;  // Emergency: system is unusable
    const ALERT = 1;  // Alert: action must be taken immediately
    const CRIT = 2;  // Critical: critical conditions
    const ERR = 3;  // Error: error conditions
    const WARN = 4;  // Warning: warning conditions
    const NOTICE = 5;  // Notice: normal but significant condition
    const INFO = 6;  // Informational: informational messages
    const DEBUG = 7;  // Debug: debug messages

    //custom logging level
    /**
     * Log nothing at all
     */
    const OFF = 8;
    /**
     * Alias for CRIT
     * @deprecated
     */
    const FATAL = 2;

    /**
     * Internal status codes
     */
    const STATUS_LOG_OPEN = 1;
    const STATUS_OPEN_FAILED = 2;
    const STATUS_LOG_CLOSED = 3;

    /**
     * We need a default argument value in order to add the ability to easily
     * print out objects etc. But we can't use NULL, 0, FALSE, etc, because those
     * are often the values the developers will test for. So we'll make one up.
     */
    const NO_ARGUMENTS = 'KLogger::NO_ARGUMENTS';

    /**
     * Current status of the log file
     * @var integer
     */
    private $_logStatus = self::STATUS_LOG_CLOSED;
    /**
     * Holds messages generated by the class
     * @var array
     */
    private $_messageQueue = array();
    /**
     * Path to the log file
     * @var string
     */
    private $_logFilePath = null;
    /**
     * Current minimum logging threshold
     * @var integer
     */
    private $_severityThreshold = self::INFO;
    /**
     * This holds the file handle for this instance's log file
     * @var resource
     */
    private $_fileHandle = null;

    /**
     * Standard messages produced by the class. Can be modified for il8n
     * @var array
     */
    private $_messages = array(
        'writefail' => 'The file could not be written to. Check that appropriate permissions have been set.',
        'opensuccess' => 'The log file was opened successfully.',
        'openfail' => 'The file could not be opened. Check permissions.',
    );

    /**
     * Default severity of log messages, if not specified
     * @var integer
     */
    private static $_defaultSeverity = self::DEBUG;
    /**
     * Valid PHP date() format string for log timestamps
     * @var string
     */
    private static $_dateFormat = 'Y-m-d G:i:s';
    /**
     * Octal notation for default permissions of the log file
     * @var integer
     */
    private static $_defaultPermissions = 0777;
    /**
     * Array of KLogger instances, part of Singleton pattern
     * @var array
     */
    private static $instances = array();

    /**
     * Partially implements the Singleton pattern. Each $logDirectory gets one
     * instance.
     *
     * @param string $logDirectory File path to the logging directory
     * @param integer $severity One of the pre-defined severity constants
     * @return KLogger
     */
    public static function instance($logDirectory = false, $severity = false)
    {
        if ($severity === false) {
            $severity = self::$_defaultSeverity;
        }

        if ($logDirectory === false) {
            if (count(self::$instances) > 0) {
                return current(self::$instances);
            } else {
                $logDirectory = dirname(__FILE__);
            }
        }

        if (in_array($logDirectory, self::$instances)) {
            return self::$instances[$logDirectory];
        }

        self::$instances[$logDirectory] = new self($logDirectory, $severity);

        return self::$instances[$logDirectory];
    }

    /**
     * Class constructor
     *
     * @param string $logDirectory File path to the logging directory
     * @param integer $severity One of the pre-defined severity constants
     * @return void
     */
    public function __construct($logDirectory, $severity)
    {
        $logDirectory = rtrim($logDirectory, '\\/');

        if ($severity === self::OFF) {
            return;
        }

        $this->_logFilePath = $logDirectory
            . DIRECTORY_SEPARATOR
            . 'log_'
            . date('Y-m-d')
            . '.txt';

        $this->_severityThreshold = $severity;
        if (!file_exists($logDirectory)) {
            mkdir($logDirectory, self::$_defaultPermissions, true);
        }

        if (file_exists($this->_logFilePath) && !is_writable($this->_logFilePath)) {
            $this->_logStatus = self::STATUS_OPEN_FAILED;
            $this->_messageQueue[] = $this->_messages['writefail'];
            return;
        }

        if (($this->_fileHandle = fopen($this->_logFilePath, 'a'))) {
            $this->_logStatus = self::STATUS_LOG_OPEN;
            $this->_messageQueue[] = $this->_messages['opensuccess'];
        } else {
            $this->_logStatus = self::STATUS_OPEN_FAILED;
            $this->_messageQueue[] = $this->_messages['openfail'];
        }
    }

    /**
     * Class destructor
     */
    public function __destruct()
    {
        if ($this->_fileHandle) {
            fclose($this->_fileHandle);
        }
    }

    /**
     * Writes a $line to the log with a severity level of DEBUG
     *
     * @param string $line Information to log
     * @return void
     */
    public function logDebug($line, $args = self::NO_ARGUMENTS)
    {
        $this->log($line, self::DEBUG, $args);
    }

    /**
     * Returns (and removes) the last message from the queue.
     * @return string
     */
    public function getMessage()
    {
        return array_pop($this->_messageQueue);
    }

    /**
     * Returns the entire message queue (leaving it intact)
     * @return array
     */
    public function getMessages()
    {
        return $this->_messageQueue;
    }

    /**
     * Empties the message queue
     * @return void
     */
    public function clearMessages()
    {
        $this->_messageQueue = array();
    }

    /**
     * Sets the date format used by all instances of KLogger
     *
     * @param string $dateFormat Valid format string for date()
     */
    public static function setDateFormat($dateFormat)
    {
        self::$_dateFormat = $dateFormat;
    }

    /**
     * Writes a $line to the log with a severity level of INFO. Any information
     * can be used here, or it could be used with E_STRICT errors
     *
     * @param string $line Information to log
     * @return void
     */
    public function logInfo($line, $args = self::NO_ARGUMENTS)
    {
        $this->log($line, self::INFO, $args);
    }

    /**
     * Writes a $line to the log with a severity level of NOTICE. Generally
     * corresponds to E_STRICT, E_NOTICE, or E_USER_NOTICE errors
     *
     * @param string $line Information to log
     * @return void
     */
    public function logNotice($line, $args = self::NO_ARGUMENTS)
    {
        $this->log($line, self::NOTICE, $args);
    }

    /**
     * Writes a $line to the log with a severity level of WARN. Generally
     * corresponds to E_WARNING, E_USER_WARNING, E_CORE_WARNING, or
     * E_COMPILE_WARNING
     *
     * @param string $line Information to log
     * @return void
     */
    public function logWarn($line, $args = self::NO_ARGUMENTS)
    {
        $this->log($line, self::WARN, $args);
    }

    /**
     * Writes a $line to the log with a severity level of ERR. Most likely used
     * with E_RECOVERABLE_ERROR
     *
     * @param string $line Information to log
     * @return void
     */
    public function logError($line, $args = self::NO_ARGUMENTS)
    {
        $this->log($line, self::ERR, $args);
    }

    /**
     * Writes a $line to the log with a severity level of FATAL. Generally
     * corresponds to E_ERROR, E_USER_ERROR, E_CORE_ERROR, or E_COMPILE_ERROR
     *
     * @param string $line Information to log
     * @return void
     * @deprecated Use logCrit
     */
    public function logFatal($line, $args = self::NO_ARGUMENTS)
    {
        $this->log($line, self::FATAL, $args);
    }

    /**
     * Writes a $line to the log with a severity level of ALERT.
     *
     * @param string $line Information to log
     * @return void
     */
    public function logAlert($line, $args = self::NO_ARGUMENTS)
    {
        $this->log($line, self::ALERT, $args);
    }

    /**
     * Writes a $line to the log with a severity level of CRIT.
     *
     * @param string $line Information to log
     * @return void
     */
    public function logCrit($line, $args = self::NO_ARGUMENTS)
    {
        $this->log($line, self::CRIT, $args);
    }

    /**
     * Writes a $line to the log with a severity level of EMERG.
     *
     * @param string $line Information to log
     * @return void
     */
    public function logEmerg($line, $args = self::NO_ARGUMENTS)
    {
        $this->log($line, self::EMERG, $args);
    }

    /**
     * Writes a $line to the log with the given severity
     *
     * @param string $line Text to add to the log
     * @param integer $severity Severity level of log message (use constants)
     */
    public function log($line, $severity, $args = self::NO_ARGUMENTS)
    {
        if ($this->_severityThreshold >= $severity) {
            $status = $this->_getTimeLine($severity);

            $line = "$status $line";

            if ($args !== self::NO_ARGUMENTS) {
                /* Print the passed object value */
                $line = $line . '; ' . var_export($args, true);
            }

            $this->writeFreeFormLine($line . PHP_EOL);
        }
    }

    /**
     * Writes a line to the log without prepending a status or timestamp
     *
     * @param string $line Line to write to the log
     * @return void
     */
    public function writeFreeFormLine($line)
    {
        if ($this->_logStatus == self::STATUS_LOG_OPEN
            && $this->_severityThreshold != self::OFF
        ) {
            if (fwrite($this->_fileHandle, $line) === false) {
                $this->_messageQueue[] = $this->_messages['writefail'];
            }
        }
    }

    private function _getTimeLine($level)
    {
        $time = date(self::$_dateFormat);

        switch ($level) {
            case self::EMERG:
                return "$time - EMERG -->";
            case self::ALERT:
                return "$time - ALERT -->";
            case self::CRIT:
                return "$time - CRIT -->";
            case self::FATAL: # FATAL is an alias of CRIT
                return "$time - FATAL -->";
            case self::NOTICE:
                return "$time - NOTICE -->";
            case self::INFO:
                return "$time - INFO -->";
            case self::WARN:
                return "$time - WARN -->";
            case self::DEBUG:
                return "$time - DEBUG -->";
            case self::ERR:
                return "$time - ERROR -->";
            default:
                return "$time - LOG -->";
        }
    }
}


class Codec
{
    const PUT_COMMAND = 'put';
    const GET_COMMAND = 'get';
    const RESULT_COMMAND = 'result';
    const VALUE_COMMAND = 'value';
    const OFFSET_COMMAND = 'offset';
    const HTTP_BadRequest = '400';
    const HTTP_NotFound = '404';
    const HTTP_Forbidden = '403';
    const HTTP_Unauthorized = '401';
    const HTTP_InternalServerError = '500';
    const HTTP_ServiceUnavailable = '503';
    const HTTP_GatewayTimeout = '504';
    const HTTP_Success = '200';
    const HTTP_Moved = '301';
    const MAX_MSG_LENGTH = 102400;

    public static function putEncode($topic, $partition, $msg)
    {
        $data = sprintf(
            "put %s %d %d %d %d\r\n%s",
            $topic,
            $partition,
            strlen($msg),
            0,
            1,
            $msg
        );
        return $data;
    }

    public static function putResultDecode($data)
    {
        list($head, $payload) = explode("\r\n", $data);
        if (!$head || !$payload) {
            throw new MetaQ_Exception('can not receive complete response: ' . $data);
        }
        list($type, $status, $len, $code) = explode(" ", $head);

        $success = false;
        $result = array();
        $errorMsg = '';
        if ($type == self::RESULT_COMMAND) {
            switch ($status) {
                case self::HTTP_Success:
                    $success = true;
                    list($id, $code, $offset) = explode(" ", $payload);
                    $result = array(
                        'id' => $id,
                        'code' => $code,
                        'offset' => $offset
                    );

                    break;
                case self::HTTP_NotFound:
                case self::HTTP_InternalServerError:
                    $success = true;
                    $errorMsg = $payload;
                    break;
            }

        }
        return array($success, $result, $errorMsg);
    }

    public static function getEncode($topic, $group, $partition, $offset)
    {
        $data = sprintf("get %s %s %d %d %d 0\r\n",
            $topic,
            $group,
            $partition,
            $offset,
            self::MAX_MSG_LENGTH
        );
        return $data;
    }

    public static function getResultDecode($data)
    {
        $msgs = array();
        $offset = $_offset = 0;

        $arr = explode("\r\n", $data, 2);

        if (sizeof($arr) !== 2) {
            echo "can not parse data " . sizeof($arr) . "\n";
            print_r($data);
            return array(array(), 0, 0);
        }
        $head = $arr[0];
        $body = $arr[1];

        list($first, $second, $third) = self::_decode_head($head);

        if ($first == 'result') {
            $status = $second;
            if ($second == self::HTTP_Moved) {
                $_offset = $body;
            }
        } else if ($first == 'value') {
            list($msgs, $offset) = self::_decode_body($body);
        }
        return array($msgs, $offset, $_offset);
    }

    private static function _decode_head($head)
    {
        list($first, $second, $third) = explode(" ", $head);
        return array($first, $second, $third);
    }

    private static function _decode_body($body)
    {
        $offset = 0;
        $msgs = array();
        while (1) {
            if (strlen($body) < 20) {
                break;
            }

            $len = array_shift(unpack('N', mb_substr($body, 0, 4)));
            $crc = array_shift(unpack('N', mb_substr($body, 4, 4)));
            $id = self::_64id(mb_substr($body, 8, 8));
            $flag = array_shift(unpack('N', mb_substr($body, 16, 4)));
            $msg = mb_substr($body, 20, $len);

            if (strlen($msg) < $len) {
                break;
            }

            if ((int)(crc32($msg) & 0x7FFFFFFF) !== $crc) {
                echo "crc error.\n";
                break;
            }

            $body = mb_substr($body, 20 + $len);
            $offset += 20 + $len;
            $msgs[] = array('id' => $id, 'msg' => $msg);
        }
        return array($msgs, $offset);
    }

    private static function _64id($id)
    {
        $return = unpack('Na/Nb', $id);
        return ($return['a'] << 32) + ($return['b']);
    }

    public static function offsetEncode($topic, $group, $partition, $offset)
    {
        $data = sprintf("offset %s %s %d %d\r\n",
            $topic,
            $group,
            $partition,
            $offset
        );
        return $data;
    }
}

class Consumer extends MetaQ
{

    public $processId;
    public $id;
    public $topic;
    public $group;
    private $offset = array();
    private $sockets;
    private $partitionList = array();
    private $zkHosts;
    private $zk;
    private $topicMetas;
    private $pTime;
    private $cversion;

    public function __construct($zkHosts = '127.0.0.1:2181')
    {
        $this->zkHosts = $zkHosts;
        $this->processId = $this->group . gethostname() . "-" . uniqid();
    }

    public function subscribe($topic, $group)
    {
        $this->topic = $topic;
        $this->group = $group;

        if (!$this->zk) {
            $this->zk = new MetaZookeeper($this->zkHosts);
        }
        $this->zk->registerConsumerProcess($this->group, $this->getSelfId());
        $this->balance();

    }

    private function getSelfId()
    {
        $host = str_replace("\n", "",
            shell_exec("ifconfig eth0 | grep 'inet addr' | awk -F':' {'print $2'} | awk -F' ' {'print $1'}"));
        if (!$host) {
            $host = gethostname();
        }
        $this->id = $this->group . '_' . $host . "-" . getmypid();
        return $this->id;
    }

    private function checkBalance()
    {
        $newCversion = $this->zk->getCversion("/meta/consumers/{$this->group}/ids");
        if ($newCversion !== $this->cversion) {
            $retry = 0;
            while ($retry++ < 30) {
                if ($this->balance()) break;
            }

            if ($retry > 10) {
                throw new Exception('balanced too many times');
            }

            $newCversion = $this->zk->getCversion("/meta/consumers/{$this->group}/ids");
            $this->cversion = $newCversion;
        }
    }

    public function balance()
    {
        MetaQ::$log->logDebug("begin balancing.");
        // begin balance
        // unsubscribe
        foreach ($this->partitionList as $partition) {
            $this->zk->removeOldOwnPartitions($this->group, $this->topic, $partition);
        }
        $this->partitionList = array();

        // wait other consumers
        usleep(2000000);
        $this->topicMetas = $this->zk->getTopicMetadata();
        $partitions = $this->topicMetas[$this->topic];

        $ids = $this->zk->getTopicConsumers($this->group, $this->topic);
        // Only master partitions
        $newPartitions = $this->getNewPartitions($partitions, $ids, $this->id);

        foreach ($newPartitions as $partition) {
            $own = $this->zk->setPartitionOwner($this->group, $this->topic, $partition, $this->id);
            if (!$own) {
                //echo "can not own ". $partition['id']. ", try later\n";
                // Needs further balance.
                usleep(1000000);
                return false;
            }
            $this->subscribePartition($this->topic, $this->group, $partition);
            $slaves = $this->getSlavePartitions($partition, $partitions);
            foreach ($slaves as $slave) {
                $this->subscribePartition($this->topic, $this->group, $slave);
            }
        }
        // echo "finished balance.\n";
        MetaQ::$log->logDebug("end balancing.");
        // end balance
        return true;
    }

    public function getNewPartitions($partitions, $ids, $id)
    {
        $masterPartitions = array();
        foreach ($partitions as $partition) {
            if ($partition['type'] === 'slave') continue;
            $masterPartitions[] = $partition;
        }
        $newPartitions = array();
        $consumers = array();
        foreach ($ids as $value) {
            $consumers[] = $value[0];
        }
        $nPartsPerConsumer = (int)(sizeof($masterPartitions) / sizeof($consumers));
        $nConsumerswithExtPart = sizeof($masterPartitions) % sizeof($consumers);

        $myConsumerPosition = array_search($id, $consumers);

        if ($myConsumerPosition < 0) {
            return array();
        }

        $startPart = $nPartsPerConsumer * $myConsumerPosition + min($myConsumerPosition, $nConsumerswithExtPart);
        $nParts = $nPartsPerConsumer + ($myConsumerPosition + 1 > $nConsumerswithExtPart ? 0 : 1);

        if ($nParts <= 0) {
            return array();
        }

        for ($i = $startPart; $i < $startPart + $nParts; $i++) {
            $newPartitions[] = $masterPartitions[$i];
        }

        return $newPartitions;
    }

    public function subscribePartition($topic, $group, $partition)
    {
        $this->topic = $topic;
        $this->group = $group;
        $host = $partition['host'];
        $port = $partition['port'];
        $part = $partition['part'];
        $master = $partition['master'];
        $this->partitionList[] = array(
            //name
            $host . '-' . $port . '-' . $part,
            //sleep
            1,
            //master
            $master,
            //partId
            $partition['gid'] . '-' . $partition['part']
        );
    }

    private function getSlavePartitions($partition, $partitions)
    {
        $slaves = array();
        $master = $partition['master'];
        foreach ($partitions as $value) {
            if ($value['master'] == $master && $value['type'] != 'master') {
                $slaves[] = $value;
            }
        }
        return $slaves;
    }

    public function getNext()
    {
        if ((time() - $this->pTime) > 1) {
            $this->pTime = time();
//            MetaQ::$log->logDebug("current status:", array(
//                'id' => $this->id,
//                'group' => $this->group,
//                'topic' => $this->topic,
//                'offset' => $this->offset,
//                'sockets' => $this->sockets,
//                'partitionList' => $this->partitionList
//            ));
            $this->checkBalance();
        }

        if (sizeof($this->partitionList) == 0) {
            return array();
        }

        $arr = $this->getCurrentPartition();
        list($partition, $sleep, $master, $partId) = $arr;
        list($host, $port, $pid) = explode('-', $partition);

        $offset = $this->getOffset($master);
        if (!isset($this->sockets[$host . ':' . $port])) {
            $this->sockets[$host . ':' . $port] = new Socket($host, $port);
            try {
                $this->sockets[$host . ':' . $port]->connect();
            } catch (Exception $e) {
                echo $e->getMessage();
            }
        }
        $socket = $this->sockets[$host . ':' . $port];
        $data = Codec::getEncode($this->topic, $this->group, $pid, $offset);
        $socket->write($data);
        $data = $socket->read(Codec::MAX_MSG_LENGTH + 14);
        list($msgs, $offset, $_offset) = Codec::getResultDecode($data);
        // 301, We can turn off this feature at server side
        if ($_offset) {
            $sleep += $sleep * 20;
            $this->partitionList[] = array(
                $partition,
                $sleep,
                $master,
                $partId);
            $this->offset[$master] = $_offset;
            $this->commitOffset($this->group, $master, $_offset, 0);
            return array();
        } else if ($offset) {
            $this->partitionList[] = array(
                $partition,
                $sleep,
                $master,
                $partId);
            $this->offset[$master] += $offset;
            $lastMsg = array_pop(array_values($msgs));
            $lastId = $lastMsg['id'];
            $this->commitOffset($this->group, $master, $this->offset[$master], $lastId);
            if ($msgs) {
                return $msgs;
            }
        } else {
            $sleep += $sleep * 2;
            $this->partitionList[] = array(
                $partition,
                $sleep,
                $master,
                $partId);
            // Reorder the partition list, sort and get the smallest sleep value
            $parts = array_values($this->partitionList);
            uasort($parts, array($this, 'cmp'));
            $smallest = array_shift($parts);
            if ($smallest[1] >= 2000000) {
                //echo "sleep 2s\n";
                //echo "offset ". $this->offset[$master]. "\n";
                usleep(2000000);
                foreach ($this->partitionList as $key => $value) {
                    $this->partitionList[$key][1] -= 2000000;
                }
            }
            return array();
        }
    }

    public function getCurrentPartition()
    {

        uasort($this->partitionList, array($this, 'cmp'));
        return array_shift($this->partitionList);
    }

    private function getOffset($partition)
    {
        if (!isset($this->offset[$partition])) {
            $offset = $this->fetchOffset($this->group, $partition);
            $this->offset[$partition] = $offset;
        }
        return $this->offset[$partition];
    }

    private function fetchOffset($group, $partition)
    {

        // master broker id
        $gid = $this->getGid($partition);
        $pid = array_pop(explode('-', $partition));
        $id_offset = $this->zk->getTopicOffset($this->group, $this->topic, $gid, $pid);
        $result = explode('-', $id_offset);
        $offset = $result[sizeof($result) - 1];
        $id = str_replace('-' . $offset, '', $id_offset);
        if (!$offset) {
            $offset = 0;
        }
        return $offset;
    }

    private function getGid($master)
    {
        foreach ($this->topicMetas[$this->topic] as $meta) {
            if ($meta['master'] == $master) {
                return $meta['gid'];
            }
        }
    }

    private function commitOffset($group, $partition, $_offset, $id)
    {

        $pid = array_pop(explode('-', $partition));
        // master broker id
        $gid = $this->getGid($partition);
        $this->zk->commitOffset($this->group, $this->topic, $gid, $pid, $id . '-' . $_offset);
    }

    private function cmp($a, $b)
    {
        if ($a[1] == $b[1]) {
            return 0;
        }
        return ($a[1] < $b[1]) ? -1 : 1;
    }
}
