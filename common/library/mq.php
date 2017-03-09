<?php

class MQ
{
    private $mq_config;
    private $stomp;

    function __construct()
    {
        $this->mq_config = Yaconf::get('common')['mq'];
        $this->stomp = new Stomp($this->mq_config['url']);
        $this->stomp->connect($this->mq_config['user'], $this->mq_config['password']);
        $this->stomp->setReadTimeout($this->mq_config['timeout']);
    }

    function __destruct()
    {
        $this->stomp->disconnect();
    }
}
