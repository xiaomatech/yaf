<?php

class KdtApiProtocol
{
    const APP_ID_KEY = 'app_id';
    const METHOD_KEY = 'method';
    const TIMESTAMP_KEY = 'timestamp';
    const FORMAT_KEY = 'format';
    const VERSION_KEY = 'v';
    const SIGN_KEY = 'sign';
    const SIGN_METHOD_KEY = 'sign_method';

    const ALLOWED_DEVIATE_SECONDS = 600;

    const ERR_SYSTEM = -1;
    const ERR_INVALID_APP_ID = 40001;
    const ERR_INVALID_APP = 40002;
    const ERR_INVALID_TIMESTAMP = 40003;
    const ERR_EMPTY_SIGNATURE = 40004;
    const ERR_INVALID_SIGNATURE = 40005;
    const ERR_INVALID_METHOD_NAME = 40006;
    const ERR_INVALID_METHOD = 40007;
    const ERR_INVALID_TEAM = 40008;
    const ERR_PARAMETER = 41000;
    const ERR_LOGIC = 50000;


    public static function sign($appSecret, $params, $method = 'md5')
    {
        if (!is_array($params)) $params = array();

        ksort($params);
        $text = '';
        foreach ($params as $k => $v) {
            $text .= $k . $v;
        }

        return self::hash($method, $appSecret . $text . $appSecret);
    }

    private static function hash($method, $text)
    {
        switch ($method) {
            case 'md5':
            default:
                $signature = md5($text);
                break;
        }
        return $signature;
    }

    public static function allowedSignMethods()
    {
        return array('md5');
    }

    public static function allowedFormat()
    {
        return array('json');
    }


    public static function doc()
    {
        return array(
            'params' => array(
                self::APP_ID_KEY => array(
                    'type' => 'String',
                    'required' => true,
                    'desc' => 'App ID',
                ),
                self::METHOD_KEY => array(
                    'type' => 'String',
                    'required' => true,
                    'desc' => 'API接口名称',
                ),
                self::TIMESTAMP_KEY => array(
                    'type' => 'String',
                    'required' => true,
                    'desc' => '时间戳，格式为yyyy-mm-dd HH:mm:ss，例如：2013-05-06 13:52:03。服务端允许客户端请求时间误差为' . intval(self::ALLOWED_DEVIATE_SECONDS / 60) . '分钟。',
                ),
                self::FORMAT_KEY => array(
                    'type' => 'String',
                    'required' => false,
                    'desc' => '可选，指定响应格式。默认json,目前支持格式为json',
                ),
                self::VERSION_KEY => array(
                    'type' => 'String',
                    'required' => true,
                    'desc' => 'API协议版本，可选值:1.0',
                ),
                self::SIGN_KEY => array(
                    'type' => 'String',
                    'required' => true,
                    'desc' => '对 API 输入参数进行 md5 加密获得，详细参考签名章节',
                ),
                self::SIGN_METHOD_KEY => array(
                    'type' => 'String',
                    'required' => false,
                    'desc' => '可选，参数的加密方法选择。默认为md5，可选值是：md5',
                ),
            ),

        );
    }

    public static function errors()
    {
        return array(
            'response' => array(
                'code' => array(
                    'type' => 'Number',
                    'desc' => '错误编号',
                    'example' => 40002,
                    'required' => true,
                ),
                'msg' => array(
                    'type' => 'String',
                    'desc' => '错误信息',
                    'example' => 'invalid app',
                    'required' => true,
                ),
                'params' => array(
                    'type' => 'List',
                    'desc' => '请求参数列表',
                    'example' => array(
                        'app_id' => 'ac9aaepv37d2a5guc',
                        'method' => 'kdt.trades.sold.get',
                        'timestamp' => '2014-01-20 20:38:42',
                        'format' => 'json',
                        'sign_method' => 'md5',
                        'v' => '1.0',
                        'sign' => 'wi93n31d034a9207ert7d3971e3vno10',
                    ),
                    'required' => true,
                ),
            ),
            'errors' => array(
                self::ERR_SYSTEM => array(
                    'desc' => '系统错误',
                    'suggest' => '',
                ),
                self::ERR_INVALID_APP_ID => array(
                    'desc' => '未指定 AppId',
                    'suggest' => '请求时传入 AppId',
                ),
                self::ERR_INVALID_APP => array(
                    'desc' => '无效的App',
                    'suggest' => '申请有效的 AppId',
                ),
                self::ERR_INVALID_TIMESTAMP => array(
                    'desc' => '无效的时间参数',
                    'suggest' => '以当前时间重新发起请求；如果系统时间和服务器时间误差超过10分钟，请调整系统时间',
                ),
                self::ERR_EMPTY_SIGNATURE => array(
                    'desc' => '请求没有签名',
                    'suggest' => '请使用协议规范对请求中的参数进行签名',
                ),
                self::ERR_INVALID_SIGNATURE => array(
                    'desc' => '签名校验失败',
                    'suggest' => '检查 AppId 和 AppSecret 是否正确；如果是自行开发的协议分装，请检查代码',
                ),
                self::ERR_INVALID_METHOD_NAME => array(
                    'desc' => '未指定请求的 Api 方法',
                    'suggest' => '指定 Api 方法',
                ),
                self::ERR_INVALID_METHOD => array(
                    'desc' => '请求非法的方法',
                    'suggest' => '检查请求的方法的值',
                ),
                self::ERR_INVALID_TEAM => array(
                    'desc' => '校验团队信息失败',
                    'suggest' => '检查团队是否有效、是否绑定微信',
                ),
                self::ERR_PARAMETER => array(
                    'desc' => '请求方法的参数错误',
                    'suggest' => '',
                ),
                self::ERR_LOGIC => array(
                    'desc' => '请求方法时业务逻辑发生错误',
                    'suggest' => '',
                ),
            ),
        );
    }
}

class SimpleHttpClient
{
    private static $boundary = '';

    public static function get($url, $params)
    {
        $url = $url . '?' . http_build_query($params);
        return self::http($url, 'GET');
    }

    public static function post($url, $params, $files = array())
    {
        $headers = array();
        if (!$files) {
            $body = http_build_query($params);
        } else {
            $body = self::build_http_query_multi($params, $files);
            $headers[] = "Content-Type: multipart/form-data; boundary=" . self::$boundary;
        }
        return self::http($url, 'POST', $body, $headers);
    }

    /**
     * Make an HTTP request
     *
     * @return string API results
     * @ignore
     */
    private static function http($url, $method, $postfields = NULL, $headers = array())
    {
        $ci = curl_init();
        /* Curl settings */
        curl_setopt($ci, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_0);
        curl_setopt($ci, CURLOPT_USERAGENT, 'KdtApiSdk Client v0.1');
        curl_setopt($ci, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ci, CURLOPT_TIMEOUT, 30);
        curl_setopt($ci, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ci, CURLOPT_ENCODING, "");
        curl_setopt($ci, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ci, CURLOPT_SSL_VERIFYHOST, 1);
        curl_setopt($ci, CURLOPT_HEADER, FALSE);

        switch ($method) {
            case 'POST':
                curl_setopt($ci, CURLOPT_POST, TRUE);
                if (!empty($postfields)) {
                    curl_setopt($ci, CURLOPT_POSTFIELDS, $postfields);
                }
                break;
        }

        curl_setopt($ci, CURLOPT_URL, $url);
        curl_setopt($ci, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ci, CURLINFO_HEADER_OUT, TRUE);

        $response = curl_exec($ci);
        $httpCode = curl_getinfo($ci, CURLINFO_HTTP_CODE);
        $httpInfo = curl_getinfo($ci);

        curl_close($ci);
        return $response;
    }

    private static function build_http_query_multi($params, $files)
    {
        if (!$params) return '';

        $pairs = array();

        self::$boundary = $boundary = uniqid('------------------');
        $MPboundary = '--' . $boundary;
        $endMPboundary = $MPboundary . '--';
        $multipartbody = '';

        foreach ($params as $key => $value) {
            $multipartbody .= $MPboundary . "\r\n";
            $multipartbody .= 'content-disposition: form-data; name="' . $key . "\"\r\n\r\n";
            $multipartbody .= $value . "\r\n";
        }
        foreach ($files as $key => $value) {
            if (!$value) {
                continue;
            }

            if (is_array($value)) {
                $url = $value['url'];
                if (isset($value['name'])) {
                    $filename = $value['name'];
                } else {
                    $parts = explode('?', basename($value['url']));
                    $filename = $parts[0];
                }
                $field = isset($value['field']) ? $value['field'] : $key;
            } else {
                $url = $value;
                $parts = explode('?', basename($url));
                $filename = $parts[0];
                $field = $key;
            }
            $content = file_get_contents($url);

            $multipartbody .= $MPboundary . "\r\n";
            $multipartbody .= 'Content-Disposition: form-data; name="' . $field . '"; filename="' . $filename . '"' . "\r\n";
            $multipartbody .= "Content-Type: image/unknown\r\n\r\n";
            $multipartbody .= $content . "\r\n";
        }

        $multipartbody .= $endMPboundary;
        return $multipartbody;
    }
}

class KdtApiClient
{
    const VERSION = '1.0';

    private static $apiEntry = 'https://open.koudaitong.com/api/entry';

    private $appId;
    private $appSecret;
    private $format = 'json';
    private $signMethod = 'md5';

    public function __construct($appId, $appSecret)
    {
        if ('' == $appId || '' == $appSecret) throw new Exception('appId 和 appSecret 不能为空');

        $this->appId = $appId;
        $this->appSecret = $appSecret;
    }

    public function get($method, $params = array())
    {
        return $this->parseResponse(
            SimpleHttpClient::get(self::$apiEntry, $this->buildRequestParams($method, $params))
        );
    }

    public function post($method, $params = array(), $files = array())
    {
        return $this->parseResponse(
            SimpleHttpClient::post(self::$apiEntry, $this->buildRequestParams($method, $params), $files)
        );
    }


    public function setFormat($format)
    {
        if (!in_array($format, KdtApiProtocol::allowedFormat()))
            throw new Exception('设置的数据格式错误');

        $this->format = $format;

        return $this;
    }

    public function setSignMethod($method)
    {
        if (!in_array($method, KdtApiProtocol::allowedSignMethods()))
            throw new Exception('设置的签名方法错误');

        $this->signMethod = $method;

        return $this;
    }


    private function parseResponse($responseData)
    {
        $data = json_decode($responseData, true);
        if (null === $data) var_dump('response invalid, data!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!');
        return $data;
    }

    private function buildRequestParams($method, $apiParams)
    {
        if (!is_array($apiParams)) $apiParams = array();
        $pairs = $this->getCommonParams($method);
        foreach ($apiParams as $k => $v) {
            if (isset($pairs[$k])) throw new Exception('参数名冲突');
            $pairs[$k] = $v;
        }

        $pairs[KdtApiProtocol::SIGN_KEY] = KdtApiProtocol::sign($this->appSecret, $pairs, $this->signMethod);
        return $pairs;
    }

    private function getCommonParams($method)
    {
        $params = array();
        $params[KdtApiProtocol::APP_ID_KEY] = $this->appId;
        $params[KdtApiProtocol::METHOD_KEY] = $method;
        $params[KdtApiProtocol::TIMESTAMP_KEY] = date('Y-m-d H:i:s');
        $params[KdtApiProtocol::FORMAT_KEY] = $this->format;
        $params[KdtApiProtocol::SIGN_METHOD_KEY] = $this->signMethod;
        $params[KdtApiProtocol::VERSION_KEY] = self::VERSION;
        return $params;
    }
}
