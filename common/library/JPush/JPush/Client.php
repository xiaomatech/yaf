<?php
namespace JPush;

use InvalidArgumentException;

class Client
{

    private $appKey;
    private $masterSecret;
    private $retryTimes;
    private $logFile;

    public function __construct($logFile = Config::DEFAULT_LOG_FILE, $retryTimes = Config::DEFAULT_MAX_RETRY_TIMES)
    {
        $jpush_config = Yaconf::get('common')['jpush'];

        $appKey = $jpush_config['appKey'];
        $masterSecret = $jpush_config['masterSecret'];

        if (!is_string($appKey) || !is_string($masterSecret)) {
            throw new InvalidArgumentException("Invalid appKey or masterSecret");
        }
        $this->appKey = $appKey;
        $this->masterSecret = $masterSecret;
        if (!is_null($retryTimes)) {
            $this->retryTimes = $retryTimes;
        } else {
            $this->retryTimes = 1;
        }
        $this->logFile = $logFile;
    }

    public function push()
    {
        return new PushPayload($this);
    }

    public function report()
    {
        return new ReportPayload($this);
    }

    public function device()
    {
        return new DevicePayload($this);
    }

    public function schedule()
    {
        return new SchedulePayload($this);
    }

    public function getAuthStr()
    {
        return $this->appKey . ":" . $this->masterSecret;
    }

    public function getRetryTimes()
    {
        return $this->retryTimes;
    }

    public function getLogFile()
    {
        return $this->logFile;
    }
}
