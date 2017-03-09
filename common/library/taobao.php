<?php

class Taobao
{
    private $service = NULL;
    private $appkey = NULL;
    private $secretKey = NULL;

    function __construct()
    {
        $top_config = Yaconf::get('common')['topclient'];
        $this->appkey = $top_config['appkey'];
        $this->appkey = $top_config['secretKey'];
        $this->service = new TopClient;
        $this->service->appkey = $this->appkey;
        $this->service->secretKey = $this->secretKey;
        $this->service->format = 'json';
    }

    /**
     * 请求top
     *
     * $req = new TbkItemInfoGetRequest;
     * $req->setFields(implode(',', $fields));
     * $req->setNumIids(implode(',', $numIids));
     * @param $req
     * @return mixed|ResultSet|SimpleXMLElement
     */
    function request($req)
    {
        $resp = $this->service->execute($req);
        return $resp;
    }
}
