<?php

abstract class AbstractAMQPConnector
{
    /**
     * Return a concrete AMQP abstraction object. Factory method.
     * @param string $name Name of desired concrete object: 'pecl', 'php-amqplib' or false: autodetect
     * @return AbstractAMQPConnector concrete object implementing AbstractAMQPConnector interface
     */
    static function GetConcrete($name = false)
    {
        if ($name === false) {
            $name = self::GetBestInstalledExtensionName();
        }

        return self::GetConcreteByName($name);
    }

    /**
     * Return a concrete AMQP abstraction object given by the name
     * @param string $name Name of desired concrete object: 'pecl', 'php-amqplib'
     * @return AbstractAMQPConnector concrete object implementing AbstractAMQPConnector interface
     */
    static function GetConcreteByName($name)
    {
        if ($name == 'pecl') {
            return new PECLAMQPConnector();
        } elseif ($name == 'redis') {
            return new RedisConnector();
        } else {
            throw new Exception('Unknown extension name ' . $name);
        }
    }

    /**
     * Return name of best available AMQP connector library
     * @return string Name of available library or 'unknown'
     */
    static function GetBestInstalledExtensionName()
    {
        return 'pecl';
    }

    /**
     * Return backend-specific connection object passed to all other calls
     * @param array $details Array of connection details
     * @return object
     */
    abstract function GetConnectionObject($details); // details = array

    /**
     * Initialize connection on a given connection object
     * @return NULL
     */
    abstract function Connect($connection);

    /**
     * Post a task to exchange specified in $details
     * @param AMQPConnection $connection Connection object
     * @param array $details Array of connection details
     * @param string $task JSON-encoded task
     * @param array $params AMQP message parameters
     * @return bool true if posted successfuly
     */
    abstract function PostToExchange($connection, $details, $task, $params);

    /**
     * Return result of task execution for $task_id
     * @param object $connection Backend-specific connection object returned by GetConnectionObject()
     * @param string $task_id Celery task identifier
     * @param boolean $removeMessageFromQueue whether to remove message from queue
     * @return array array('body' => JSON-encoded message body, 'complete_result' => library-specific message object)
     *            or false if result not ready yet
     */
    abstract function GetMessageBody($connection, $task_id, $removeMessageFromQueue);
}


class RedisConnector extends AbstractAMQPConnector
{
    public $content_type = 'application/json';
    public $celery_result_prefix = 'celery-task-meta-';

    /**
     * Return headers used sent to Celery
     * Override this function to set custom headers
     */
    protected function GetHeaders()
    {
        return new stdClass;
    }

    /**
     * Prepare the message sent to Celery
     */
    protected function GetMessage($task)
    {
        $result = Array();
        $result['body'] = base64_encode($task);
        $result['headers'] = $this->GetHeaders();
        $result['content-type'] = $this->content_type;
        $result['content-encoding'] = 'binary';
        return $result;
    }

    /**
     * Return preferred delivery mode
     */
    protected function GetDeliveryMode($params = array())
    {
        /*
        * http://celery.readthedocs.org/en/latest/userguide/optimizing.html#using-transient-queues
        * 1 - will not be written to disk
        * 2 - can be written to disk
        */
        if (isset($params['delivery_mode'])) {
            return $params['delivery_mode'];
        }
        return 2;
    }

    /**
     * Convert the message dictionary to string
     * Override this function to use non-JSON serialization
     */
    protected function ToStr($var)
    {
        return json_encode($var);
    }

    /**
     * Convert the message string to dictionary
     * Override this function to use non-JSON serialization
     */
    protected function ToDict($raw_json)
    {
        return json_decode($raw_json, TRUE);
    }

    /**
     * Post the message to Redis
     * This function implements the AbstractAMQPConnector interface
     */
    public function PostToExchange($connection, $details, $task, $params)
    {
        $body = json_decode($task, true);
        $message = $this->GetMessage($task);
        $message['properties'] = Array(
            'body_encoding' => 'base64',
            'reply_to' => $body['id'],
            'delivery_info' => Array(
                'priority' => 0,
                'routing_key' => $details['binding'],
                'exchange' => $details['exchange'],
            ),
            'delivery_mode' => $this->GetDeliveryMode($params),
            'delivery_tag' => $body['id']
        );
        $connection->lPush($details['exchange'], $this->ToStr($message));
        return TRUE;
    }

    /**
     * Initialize connection on a given connection object
     * This function implements the AbstractAMQPConnector interface
     * @return NULL
     */
    public function Connect($connection)
    {
        $connection->connect();
        return $connection;
    }

    /**
     * Return the result queue name for a given task ID
     * @param string $task_id
     * @return string
     */
    protected function GetResultKey($task_id)
    {
        return sprintf("%s%s", $this->celery_result_prefix, $task_id);
    }

    /**
     * Clean up after reading the message body
     * @param object $connection Predis\Client connection object returned by GetConnectionObject()
     * @param string $task_id
     * @return bool
     */
    protected function FinalizeResult($connection, $task_id)
    {
        if ($connection->exists($this->GetResultKey($task_id))) {
            $connection->del($this->GetResultKey($task_id));
            return true;
        }
        return false;
    }

    /**
     * Return result of task execution for $task_id
     * @param object $connection Predis\Client connection object returned by GetConnectionObject()
     * @param string $task_id Celery task identifier
     * @param int $expire Unused in Redis
     * @param boolean $removeMessageFromQueue whether to remove message from queue
     * @return array|bool array('body' => JSON-encoded message body, 'complete_result' => library-specific message object)
     *            or false if result not ready yet
     */
    public function GetMessageBody($connection, $task_id, $expire = 0, $removeMessageFromQueue = true)
    {
        $result = $connection->get($this->GetResultKey($task_id));
        if ($result) {
            $redis_result = $this->ToDict($result, true);
            $result = Array(
                'complete_result' => $redis_result,
                'body' => json_encode($redis_result)
            );
            if ($removeMessageFromQueue) {
                $this->FinalizeResult($connection, $task_id);
            }
            return $result;
        } else {
            return false;
        }
    }

    /**
     * Return Predis\Client connection object passed to all other calls
     * @param array $details Array of connection details
     * @return object
     */
    function GetConnectionObject($details)
    {
        $redis = new Redis();
        $connect = $redis->connect($details['host'], $details['port']);
        return $connect;
    }
}

class PECLAMQPConnector extends AbstractAMQPConnector
{
    /**
     * Return AMQPConnection object passed to all other calls
     * @param array $details Array of connection details
     * @return AMQPConnection
     */
    function GetConnectionObject($details)
    {
        $connection = new AMQPConnection();
        $connection->setHost($details['host']);
        $connection->setLogin($details['login']);
        $connection->setPassword($details['password']);
        $connection->setVhost($details['vhost']);
        $connection->setPort($details['port']);

        return $connection;
    }

    /**
     * Initialize connection on a given connection object
     * @return NULL
     */
    function Connect($connection)
    {
        $connection->connect();
        $connection->channel = new AMQPChannel($connection);
    }

    /**
     * Post a task to exchange specified in $details
     * @param AMQPConnection $connection Connection object
     * @param array $details Array of connection details
     * @param string $task JSON-encoded task
     * @param array $params AMQP message parameters
     */
    function PostToExchange($connection, $details, $task, $params)
    {
        $ch = $connection->channel;
        $xchg = new AMQPExchange($ch);
        $xchg->setName($details['exchange']);

        $success = $xchg->publish($task, $details['binding'], 0, $params);

        return $success;
    }

    /**
     * Return result of task execution for $task_id
     * @param AMQPConnection $connection Connection object
     * @param string $task_id Celery task identifier
     * @param boolean $removeMessageFromQueue whether to remove message from queue
     * @return array array('body' => JSON-encoded message body, 'complete_result' => AMQPEnvelope object)
     *            or false if result not ready yet
     */
    function GetMessageBody($connection, $task_id, $removeMessageFromQueue = true)
    {
        $this->Connect($connection);
        $ch = $connection->channel;
        $q = new AMQPQueue($ch);
        $q->setName($task_id);
        $q->setFlags(AMQP_AUTODELETE | AMQP_DURABLE);
        $q->declareQueue();
        try {
            $q->bind('celeryresults', $task_id);
        } catch (AMQPQueueException $e) {
            if ($removeMessageFromQueue) {
                $q->delete();
            }
            $connection->disconnect();
            return false;
        }

        $message = $q->get(AMQP_AUTOACK);

        if (!$message) {
            if ($removeMessageFromQueue) {
                $q->delete();
            }
            $connection->disconnect();
            return false;
        }

        if ($message->getContentType() != 'application/json') {
            if ($removeMessageFromQueue) {
                $q->delete();
            }
            $connection->disconnect();

            throw new CeleryException('Response was not encoded using JSON - found ' .
                $message->getContentType() .
                ' - check your CELERY_RESULT_SERIALIZER setting!');
        }

        if ($removeMessageFromQueue) {
            $q->delete();
        }
        $connection->disconnect();

        return array(
            'complete_result' => $message,
            'body' => $message->getBody(),
        );
    }
}

/**
 * General exception class
 * @package celery-php
 */
class CeleryException extends Exception
{
}

;

/**
 * Emited by AsyncResult::get() on timeout
 * @package celery-php
 */
class CeleryTimeoutException extends CeleryException
{
}

;

/**
 * Emited by CeleryAbstract::PostTask() connection failures etc
 * @package celery-php
 */
class CeleryPublishException extends CeleryException
{
}

;

/**
 * Simple client for a Celery server
 *
 * for when queue and results are in the same broker
 * Use this class if you don't know what the above means
 * @package celery-php
 */
class Celery extends CeleryAbstract
{
    function __construct()
    {
        $celery_config = Yaconf::get('common')['celery'];

        $broker_connection = array(
            'host' => $celery_config['host'],
            'login' => $celery_config['login'],
            'password' => $celery_config['password'],
            'vhost' => $celery_config['vhost'],
            'exchange' => $celery_config['exchange'],
            'binding' => $celery_config['binding'],
            'port' => $celery_config['port'],
            'connector' => $celery_config['connector'],
            'result_expire' => $celery_config['result_expire']
        );
        $backend_connection = $broker_connection;

        $items = $this->BuildConnection($broker_connection);
        $items = $this->BuildConnection($backend_connection, true);
    }
}

/**
 * Client for a Celery server - with a constructor supporting separate backend queue
 * @package celery-php
 */
class CeleryAdvanced extends CeleryAbstract
{
    /**
     * @param array broker_connection - array for connecting to task queue, see Celery class above for supported keys
     * @param array backend_connection - array for connecting to result backend, see Celery class above for supported keys
     */
    function __construct($broker_connection, $backend_connection = false)
    {
        if ($backend_connection == false) {
            $backend_connection = $broker_connection;
        }

        $items = $this->BuildConnection($broker_connection);
        $items = $this->BuildConnection($backend_connection, true);
    }
}


/**
 * Client for a Celery server - abstract base class implementing actual logic
 * @package celery-php
 */
abstract class CeleryAbstract
{

    private $broker_connection = null;
    private $broker_connection_details = array();
    private $broker_amqp = null;

    private $backend_connection = null;
    private $backend_connection_details = array();
    private $backend_amqp = null;

    private $isConnected = false;

    private function SetDefaultValues($details)
    {
        $defaultValues = array("host" => "", "login" => "", "password" => "", "vhost" => "", "exchange" => "celery", "binding" => "celery", "port" => 5672, "connector" => false, "persistent_messages" => false, "result_expire" => 0);

        $returnValue = array();

        foreach (array('host', 'login', 'password', 'vhost', 'exchange', 'binding', 'port', 'connector', 'persistent_messages', 'result_expire') as $detail) {
            if (!array_key_exists($detail, $details)) {
                $returnValue[$detail] = $defaultValues[$detail];
            } else $returnValue[$detail] = $details[$detail];
        }
        return $returnValue;
    }

    public function BuildConnection($connection_details, $is_backend = false)
    {
        $connection_details = $this->SetDefaultValues($connection_details);

        if ($connection_details['connector'] === false) {
            $connection_details['connector'] = 'pecl';
        }
        $amqp = AbstractAMQPConnector::GetConcrete($connection_details['connector']);
        $connection = self::InitializeAMQPConnection($connection_details);
        $amqp->Connect($connection);

        if ($is_backend) {
            $this->backend_connection_details = $connection_details;
            $this->backend_connection = $connection;
            $this->backend_amqp = $amqp;
        } else {
            $this->broker_connection_details = $connection_details;
            $this->broker_connection = $connection;
            $this->broker_amqp = $amqp;
        }
    }

    static function InitializeAMQPConnection($details)
    {
        $amqp = AbstractAMQPConnector::GetConcrete($details['connector']);
        return $amqp->GetConnectionObject($details);
    }

    /**
     * Post a task to Celery
     * @param string $task Name of the task, prefixed with module name (like tasks.add for function add() in task.py)
     * @param array $args Array of arguments (kwargs call when $args is associative)
     * @param bool $async_result Set to false if you don't need the AsyncResult object returned
     * @param string $routing_key Set to routing key name if you're using something other than "celery"
     * @param array $task_args Additional settings for Celery - normally not needed
     * @return AsyncResult
     */
    function PostTask($task, $args, $async_result = true, $routing_key = "celery", $task_args = array())
    {
        if (!is_array($args)) {
            throw new CeleryException("Args should be an array");
        }

        if (!$this->isConnected) {
            $this->broker_amqp->Connect($this->broker_connection);
            $this->isConnected = true;
        }

        $id = uniqid('php_', TRUE);

        /* $args is numeric -> positional args */
        if (array_keys($args) === range(0, count($args) - 1)) {
            $kwargs = array();
        } /* $args is associative -> contains kwargs */
        else {
            $kwargs = $args;
            $args = array();
        }

        /*
        *	$task_args may contain additional arguments such as eta which are useful in task execution
        *	The usecase of this field is as follows:
        *	$task_args = array( 'eta' => "2014-12-02T16:00:00" );
        */
        $task_array = array_merge(
            array(
                'id' => $id,
                'task' => $task,
                'args' => $args,
                'kwargs' => (object)$kwargs,
            ),
            $task_args
        );

        $task = json_encode($task_array);
        $params = array('content_type' => 'application/json',
            'content_encoding' => 'UTF-8',
            'immediate' => false,
        );

        if ($this->broker_connection_details['persistent_messages']) {
            $params['delivery_mode'] = 2;
        }

        $this->broker_connection_details['routing_key'] = $routing_key;

        $success = $this->broker_amqp->PostToExchange(
            $this->broker_connection,
            $this->broker_connection_details,
            $task,
            $params
        );

        if (!$success) {
            throw new CeleryPublishException();
        }

        if ($async_result) {
            return new AsyncResult($id, $this->backend_connection_details, $task_array['task'], $args);
        } else {
            return true;
        }
    }

    /**
     * Get the current message of the async result. If there is no async result for a task in the queue false will be returned.
     * Can be used to pass custom states to the client as mentioned in http://celery.readthedocs.org/en/latest/userguide/tasks.html#custom-states
     *
     * @param string $taskName Name of the called task, like 'tasks.add'
     * @param string $taskId The Task ID - from AsyncResult::getId()
     * @param null|array $args Task arguments
     * @param boolean $removeMessageFromQueue whether to remove the message from queue. If not celery will remove the message
     * due to its expire parameter
     * @return array|boolean array('body' => JSON-encoded message body, 'complete_result' => library-specific message object)
     *            or false if result not ready yet
     *
     */
    public function getAsyncResultMessage($taskName, $taskId, $args = null, $removeMessageFromQueue = true)
    {
        $result = new AsyncResult($taskId, $this->backend_connection_details, $taskName, $args);

        $messageBody = $result->amqp->GetMessageBody(
            $result->connection,
            $taskId,
            $this->backend_connection_details['result_expire'],
            $removeMessageFromQueue
        );

        return $messageBody;
    }

}

/*
 * Asynchronous result of Celery task
 * @package celery-php
 */

class AsyncResult
{
    private $task_id; // string, queue name
    private $connection; // AMQPConnection instance
    private $connection_details; // array of strings required to connect
    private $complete_result; // Backend-dependent message instance (AMQPEnvelope or PhpAmqpLib\Message\AMQPMessage)
    private $body; // decoded array with message body (whatever Celery task returned)
    private $amqp = null; // AbstractAMQPConnector implementation

    /**
     * Don't instantiate AsyncResult yourself, used internally only
     * @param string $id Task ID in Celery
     * @param array $connection_details used to initialize AMQPConnection, keys are the same as args to Celery::__construct
     * @param string task_name
     * @param array task_args
     */
    function __construct($id, $connection_details, $task_name = NULL, $task_args = NULL)
    {
        $this->task_id = $id;
        $this->connection = Celery::InitializeAMQPConnection($connection_details);
        $this->connection_details = $connection_details;
        $this->task_name = $task_name;
        $this->task_args = $task_args;
        $this->amqp = AbstractAMQPConnector::GetConcrete($connection_details['connector']);
    }

    function __wakeup()
    {
        if ($this->connection_details) {
            $this->connection = Celery::InitializeAMQPConnection($this->connection_details);
        }
    }

    /**
     * Connect to queue, see if there's a result waiting for us
     * Private - to be used internally
     */
    private function getCompleteResult()
    {
        if ($this->complete_result) {
            return $this->complete_result;
        }

        $message = $this->amqp->GetMessageBody($this->connection, $this->task_id, $this->connection_details['result_expire'], true);

        if ($message !== false) {
            $this->complete_result = $message['complete_result'];
            $this->body = json_decode(
                $message['body']
            );
        }

        return false;
    }

    /**
     * Helper function to return current microseconds time as float
     */
    static private function getmicrotime()
    {
        list($usec, $sec) = explode(" ", microtime());
        return ((float)$usec + (float)$sec);
    }

    /**
     * Get the Task Id
     * @return string
     */
    function getId()
    {
        return $this->task_id;
    }

    /**
     * Check if a task result is ready
     * @return bool
     */
    function isReady()
    {
        return ($this->getCompleteResult() !== false);
    }

    /**
     * Return task status (needs to be called after isReady() returned true)
     * @return string 'SUCCESS', 'FAILURE' etc - see Celery source
     */
    function getStatus()
    {
        if (!$this->body) {
            throw new CeleryException('Called getStatus before task was ready');
        }
        return $this->body->status;
    }

    /**
     * Check if task execution has been successful or resulted in an error
     * @return bool
     */
    function isSuccess()
    {
        return ($this->getStatus() == 'SUCCESS');
    }

    /**
     * If task execution wasn't successful, return a Python traceback
     * @return string
     */
    function getTraceback()
    {
        if (!$this->body) {
            throw new CeleryException('Called getTraceback before task was ready');
        }
        return $this->body->traceback;
    }

    /**
     * Return a result of successful execution.
     * In case of failure, this returns an exception object
     * @return mixed Whatever the task returned
     */
    function getResult()
    {
        if (!$this->body) {
            throw new CeleryException('Called getResult before task was ready');
        }

        return $this->body->result;
    }

    /****************************************************************************
     * Python API emulation                                                     *
     * http://ask.github.com/celery/reference/celery.result.html                *
     ****************************************************************************/

    /**
     * Returns TRUE if the task failed
     */
    function failed()
    {
        return $this->isReady() && !$this->isSuccess();
    }

    /**
     * Forget about (and possibly remove the result of) this task
     * Currently does nothing in PHP client
     */
    function forget()
    {
    }

    /**
     * Wait until task is ready, and return its result.
     * @param float $timeout How long to wait, in seconds, before the operation times out
     * @param bool $propagate (TODO - not working) Re-raise exception if the task failed.
     * @param float $interval Time to wait (in seconds) before retrying to retrieve the result
     * @throws CeleryTimeoutException on timeout
     * @return mixed result on both success and failure
     */
    function get($timeout = 10, $propagate = TRUE, $interval = 0.5)
    {
        $interval_us = (int)($interval * 1000000);

        $start_time = self::getmicrotime();
        while (self::getmicrotime() - $start_time < $timeout) {
            if ($this->isReady()) {
                break;
            }

            usleep($interval_us);
        }

        if (!$this->isReady()) {
            throw new CeleryTimeoutException(sprintf('AMQP task %s(%s) did not return after %d seconds', $this->task_name, json_encode($this->task_args), $timeout), 4);
        }

        return $this->getResult();
    }

    /**
     * Implementation of Python's properties: result, state/status
     */
    public function __get($property)
    {
        /**
         * When the task has been executed, this contains the return value.
         * If the task raised an exception, this will be the exception instance.
         */
        if ($property == 'result') {
            if ($this->isReady()) {
                return $this->getResult();
            } else {
                return NULL;
            }
        } /**
         * state: The tasks current state.
         *
         * Possible values includes:
         *
         * PENDING
         * The task is waiting for execution.
         *
         * STARTED
         * The task has been started.
         *
         * RETRY
         * The task is to be retried, possibly because of failure.
         *
         * FAILURE
         * The task raised an exception, or has exceeded the retry limit. The result attribute then contains the exception raised by the task.
         *
         * SUCCESS
         * The task executed successfully. The result attribute then contains the tasks return value.
         *
         * status: Deprecated alias of state.
         */
        elseif ($property == 'state' || $property == 'status') {
            if ($this->isReady()) {
                return $this->getStatus();
            } else {
                return 'PENDING';
            }
        }

        return $this->$property;
    }

    /**
     * Returns True if the task has been executed.
     * If the task is still running, pending, or is waiting for retry then False is returned.
     */
    function ready()
    {
        return $this->isReady();
    }

    /**
     * Send revoke signal to all workers
     * Does nothing in PHP client
     */
    function revoke()
    {
    }

    /**
     * Returns True if the task executed successfully.
     */
    function successful()
    {
        return $this->isSuccess();
    }

    /**
     * Deprecated alias to get()
     */
    function wait($timeout = 10, $propagate = TRUE, $interval = 0.5)
    {
        return $this->get($timeout, $propagate, $interval);
    }
}

