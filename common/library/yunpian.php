<?php

class Base
{
    public $apikey;
    public $api_secret;

    function __construct($apikey = null, $api_secret = null)
    {
        $config = Yaconf::get("common")['yunpian'];

        $yunpian_config = array(
            'sms_uri' => 'https://sms.yunpian.com',
            'voice_uri' => 'https://voice.yunpian.com',
            'flow_uri' => 'https://flow.yunpian.com',
            'version' => '/v2',
        );

        $yunpian_config = array_merge($config, $yunpian_config);

        // 重试次数
        $yunpian_config['RETRY_TIMES'] = 3;
        // 短信
        $yunpian_config['URI_SEND_SINGLE_SMS'] = $yunpian_config['sms_uri'] . $yunpian_config['version'] . "/sms/single_send.json";
        $yunpian_config['URI_SEND_BATCH_SMS'] = $yunpian_config['sms_uri'] . $yunpian_config['version'] . "/sms/batch_send.json";
        $yunpian_config['URI_SEND_MULTI_SMS'] = $yunpian_config['sms_uri'] . $yunpian_config['version'] . "/sms/multi_send.json";
        $yunpian_config['URI_SEND_TPL_SMS'] = $yunpian_config['sms_uri'] . $yunpian_config['version'] . '/sms/tpl_send.json';
        $yunpian_config['URI_PULL_SMS_STATUS'] = $yunpian_config['sms_uri'] . $yunpian_config['version'] . "/sms/pull_status.json";
        //获取回复短信
        $yunpian_config['URI_PULL_SMS_REPLY'] = $yunpian_config['sms_uri'] . $yunpian_config['version'] . "/sms/pull_reply.json";
        //查询回复短信
        $yunpian_config['URI_GET_SMS_REPLY'] = $yunpian_config['sms_uri'] . $yunpian_config['version'] . "/sms/get_reply.json";
        //查短信发送记录
        $yunpian_config['URI_GET_SMS_RECORD'] = $yunpian_config['sms_uri'] . $yunpian_config['version'] . "/sms/get_record.json";

        // 语音
        $yunpian_config['URI_SEND_VOICE_SMS'] = $yunpian_config['voice_uri'] . $yunpian_config['version'] . "/voice/send.json";
        $yunpian_config['URI_PULL_VOICE_STATUS'] = $yunpian_config['voice_uri'] . $yunpian_config['version'] . "/voice/pull_status.json";

        // 流量
        $yunpian_config['URI_GET_FLOW_PACKAGE'] = $yunpian_config['flow_uri'] . $yunpian_config['version'] . "/flow/get_package.json";
        $yunpian_config['URI_PULL_FLOW_STATUS'] = $yunpian_config['flow_uri'] . $yunpian_config['version'] . "/flow/pull_status.json";
        $yunpian_config['URI_RECHARGE_FLOW'] = $yunpian_config['flow_uri'] . $yunpian_config['version'] . "/flow/recharge.json";

        // 用户操作
        $yunpian_config['URI_GET_USER_INFO'] = $yunpian_config['sms_uri'] . $yunpian_config['version'] . "/user/get.json";
        $yunpian_config['URI_SET_USER_INFO'] = $yunpian_config['sms_uri'] . $yunpian_config['version'] . "/user/set.json";


        // 模板操作
        $yunpian_config['URI_GET_DEFAULT_TEMPLATE'] = $yunpian_config['sms_uri'] . $yunpian_config['version'] . "/tpl/get_default.json";
        $yunpian_config['URI_GET_TEMPLATE'] = $yunpian_config['sms_uri'] . $yunpian_config['version'] . "/tpl/get.json";
        $yunpian_config['URI_ADD_TEMPLATE'] = $yunpian_config['sms_uri'] . $yunpian_config['version'] . "/tpl/add.json";
        $yunpian_config['URI_UPD_TEMPLATE'] = $yunpian_config['sms_uri'] . $yunpian_config['version'] . "/tpl/update.json";
        $yunpian_config['URI_DEL_TEMPLATE'] = $yunpian_config['sms_uri'] . $yunpian_config['version'] . "/tpl/del.json";

        $this->yunpian_config = $yunpian_config;

        if ($api_secret == null)
            $this->api_secret = $this->yunpian_config['API_SECRET'];
        else
            $this->api_secret = $api_secret;
        if ($apikey == null)
            $this->apikey = $this->yunpian_config['APIKEY'];
        else
            $this->apikey = $apikey;
    }
}

class SmsOperator extends Base
{
    public function encrypt(&$data)
    {

    }

    public function single_send($data = array())
    {
        if (!array_key_exists('mobile', $data))
            return new Result(null, $data, null, 'mobile 为空');
        if (!array_key_exists('text', $data))
            return new Result(null, $data, null, 'text 为空');
        $data['apikey'] = $this->apikey;

        return HttpUtil::PostCURL($this->yunpian_config['URI_SEND_SINGLE_SMS'], $data);
    }

    public function batch_send($data = array())
    {
        if (!array_key_exists('mobile', $data))
            return new Result(null, $data, null, $error = 'mobile 为空');
        if (!array_key_exists('text', $data))
            return new Result(null, $data, null, $error = 'text 为空');
        $data['apikey'] = $this->apikey;

        return HttpUtil::PostCURL($this->yunpian_config['URI_SEND_BATCH_SMS'], $data);
    }

    public function multi_send($data = array())
    {
        if (!array_key_exists('mobile', $data))
            return new Result(null, $data, null, $error = 'mobile 为空');
        if (!array_key_exists('text', $data))
            return new Result(null, $data, null, $error = 'text 为空');
        if (count(explode(',', $data['mobile'])) != count(explode(',', $data['text'])))
            return new Result(null, $data, null, $error = 'mobile 与 text 个数不匹配');
        $data['apikey'] = $this->apikey;
        $text_array = explode(',', $data['text']);
        $data['text'] = '';
        for ($index = 0; $index < count($text_array); $index++) {
            $data['text'] .= urlencode($text_array[$index]) . ',';
        }
        $data['text'] = substr($data['text'], 0, -1);
        return HttpUtil::PostCURL($this->yunpian_config['URI_SEND_MULTI_SMS'], $data);
    }

    public function tpl_send($data = array())
    {
        if (!array_key_exists('mobile', $data))
            return new Result(null, $data, null, 'mobile 为空');
        if (!array_key_exists('tpl_id', $data))
            return new Result(null, $data, null, 'tpl_id 为空');
        if (!array_key_exists('tpl_value', $data))
            return new Result(null, $data, null, 'tpl_value 为空');

        $data['apikey'] = $this->apikey;

        return HttpUtil::PostCURL($this->yunpian_config['URI_SEND_TPL_SMS'], $data);
    }
}

class TplOperator extends Base
{
    public function encrypt(&$data)
    {

    }

    public function get_default($data = array())
    {
        $data['apikey'] = $this->apikey;

        return HttpUtil::PostCURL($this->yunpian_config['URI_GET_DEFAULT_TEMPLATE'], $data);
    }

    public function get($data = array())
    {
        $data['apikey'] = $this->apikey;

        return HttpUtil::PostCURL($this->yunpian_config['URI_GET_TEMPLATE'], $data);
    }

    public function add($data = array())
    {
        if (!array_key_exists('tpl_id', $data))
            return new Result(null, $data, null, $error = 'tpl_id 为空');
        if (!array_key_exists('tpl_content', $data))
            return new Result(null, $data, null, $error = 'tpl_content 为空');
        $data['apikey'] = $this->apikey;
        return HttpUtil::PostCURL($this->yunpian_config['URI_ADD_TEMPLATE'], $data);
    }

    public function upd($data = array())
    {
        if (!array_key_exists('tpl_id', $data))
            return new Result(null, $data, null, $error = 'tpl_id 为空');
        if (!array_key_exists('tpl_content', $data))
            return new Result(null, $data, null, $error = 'tpl_content 为空');
        $data['apikey'] = $this->apikey;
        return HttpUtil::PostCURL($this->yunpian_config['URI_UPD_TEMPLATE'], $data);
    }

    public function del($data = array())
    {
        if (!array_key_exists('tpl_id', $data))
            return new Result(null, $data, null, $error = 'tpl_id 为空');
        $data['apikey'] = $this->apikey;

        return HttpUtil::PostCURL($this->yunpian_config['URI_DEL_TEMPLATE'], $data);
    }

}

class UserOperator extends Base
{
    public function encrypt(&$data)
    {

    }

    public function get($data = array())
    {
        $data['apikey'] = $this->apikey;

        return HttpUtil::PostCURL($this->yunpian_config['URI_GET_USER_INFO'], $data);
    }

    public function set($data = array())
    {
        $data['apikey'] = $this->apikey;
        return HttpUtil::PostCURL($this->yunpian_config['URI_SET_USER_INFO'], $data);
    }
}

class VoiceOperator extends Base
{
    public function encrypt(&$data)
    {

    }

    public function send($data = array())
    {
        if (!array_key_exists('mobile', $data))
            return new Result($error = 'mobile 为空');
        if (!array_key_exists('code', $data))
            return new Result($error = 'code 为空');
        $data['apikey'] = $this->apikey;
        return HttpUtil::PostCURL($this->yunpian_config['URI_SEND_VOICE_SMS'], $data);
    }

    public function pull_status($data = array())
    {
        $data['apikey'] = $this->apikey;
        return HttpUtil::PostCURL($this->yunpian_config['URI_PULL_VOICE_STATUS'], $data);
    }

}

class FlowOperator extends Base
{
    public function encrypt(&$data)
    {

    }

    public function get_package($data = array())
    {
        $data['apikey'] = $this->apikey;

        return HttpUtil::PostCURL($this->yunpian_config['URI_GET_FLOW_PACKAGE'], $data);
    }

    public function pull_status($data = array())
    {
        $data['apikey'] = $this->apikey;
        return HttpUtil::PostCURL($this->yunpian_config['URI_PULL_FLOW_STATUS'], $data);
    }

    public function recharge($data = array())
    {
        if (!array_key_exists('mobile', $data))
            return new Result(null, $data, null, $error = 'mobile 为空');

        $data['apikey'] = $this->apikey;
        return HttpUtil::PostCURL($this->yunpian_config['URI_RECHARGE_FLOW'], $data);
    }
}

class Result
{
    public $success;
    public $statusCode;
    public $requestData;
    public $responseData;
    public $error;

    public function __construct($statusCode = null, $requestData = null, $responseData = null, $error = null)
    {
        $this->success = false;
        if ($statusCode == 200)
            $this->success = true;
        $this->statusCode = $statusCode;
        $this->requestData = $requestData;
        $this->responseData = $responseData;
        $this->error = $error;
    }

    public function getData()
    {
        return $this->responseData;
    }

    public function isSuccess()
    {
        return $this->success;
    }
}

class HttpUtil
{
    public static function PostCURL($url, $post_data)
    {
        global $yunpian_config;
        $ch = curl_init();

        /* 设置验证方式 */

        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept:text/plain;charset=utf-8', 'Content-Type:application/x-www-form-urlencoded', 'charset=utf-8'));

        /* 设置返回结果为流 */
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        /* 设置超时时间*/
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        /* 设置通信方式 */
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
        $retry = 0;
        // 若执行失败则重试
        do {
            $output = curl_exec($ch);
            $retry++;
        } while ((curl_errno($ch) !== 0) && $retry < $yunpian_config['RETRY_TIMES']);

        if (curl_errno($ch) !== 0) {
            $r = new Result(null, $post_data, null, curl_error($ch));
            curl_close($ch);
            return $r;
        }
        $output = trim($output, "\xEF\xBB\xBF");
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ret = new Result($statusCode, $post_data, json_decode($output, true), null);
        curl_close($ch);
        return $ret;
    }
}
