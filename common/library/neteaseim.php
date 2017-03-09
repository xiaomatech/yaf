<?php

/**
 * @desc 网易云信
 */
class NeteaseIM
{
    protected $appKey;
    protected $appSecret;
    protected $nonce;
    protected $curTime;
    protected $checkSum;
    protected $token;

    protected $code = array(
        200 => '操作成功',
        201 => '客户端版本不对，需升级sdk',
        301 => '被封禁',
        302 => '用户名或密码错误',
        315 => 'IP限制',
        403 => '非法操作或没有权限',
        404 => '对象不存在',
        405 => '参数长度过长',
        406 => '对象只读',
        408 => '客户端请求超时',
        413 => '验证失败(短信服务)',
        414 => '参数错误',
        415 => '客户端网络问题',
        416 => '频率控制',
        417 => '重复操作',
        418 => '通道不可用(短信服务)',
        419 => '数量超过上限',
        422 => '账号被禁用',
        431 => 'HTTP重复请求',
        500 => '服务器内部错误',
        503 => '服务器繁忙',
        514 => '服务不可用',
        509 => '无效协议',
        998 => '解包错误',
        999 => '打包错误',
        801 => '群人数达到上限',
        802 => '没有权限',
        803 => '群不存在',
        804 => '用户不在群',
        805 => '群类型不匹配',
        806 => '创建群数量达到限制',
        807 => '群成员状态错误',
        808 => '申请成功',
        809 => '已经在群内',
        810 => '邀请成功',
        9102 => '通道失效',
        9103 => '已经在他端对这个呼叫响应过了',
        11001 => '通话不可达，对方离线状态',
        13001 => 'IM主连接状态异常',
        13002 => '聊天室状态异常',
        13003 => '账号在黑名单中,不允许进入聊天室',
        13004 => '在禁言列表中,不允许发言',
        10431 => '输入email不是邮箱',
        10432 => '输入mobile不是手机号码',
        10433 => '注册输入的两次密码不相同',
        10434 => '企业不存在',
        10435 => '登陆密码或帐号不对',
        10436 => 'app不存在',
        10437 => 'email已注册',
        10438 => '手机号已注册',
        10441 => 'app名字已经存在'
    );

    public function __construct()
    {
        $netease_im_config = Yaconf::get("common")['netease_im'];
        $this->appKey = $netease_im_config['appKey'];
        $this->appSecret = $netease_im_config['appSecret'];
        if (!empty($netease_im_config['token'])) {
            $this->token = $netease_im_config['token'];
        }
    }

    /**
     * 创建云信ID
     *
     * @param string $accid 云信ID，最大长度32字节，必须保证一个APP内唯一（只允许字母、
     * 数字、半角下划线_、@、半角点以及半角-组成，不区分大小写，会统一小写处理，请注意以此接口返回结果中的accid为准
     * @param string $name 云信ID昵称，最大长度64字节，用来PUSH推送时显示的昵称
     * @param string $icon 云信ID头像URL，第三方可选填，最大长度1024
     * @param string $props json属性，第三方可选填，最大长度1024字节
     * @return array
     */
    public function createUser($accid, $name = '', $icon = '', $props = '', $token = '')
    {
        $url = 'https://api.netease.im/nimserver/user/create.action';
        $param = array(
            'accid' => $accid,
            'name' => $name,
            'props' => $props,
            'icon' => $icon,
            'token' => !empty($token) ? $token : $this->token
        );
        $result = $this->post($url, $param);
        return $result;
    }

    /**
     * 更新云信ID
     *
     * @param string $accid 云信ID，最大长度32字节，必须保证一个APP内唯一
     * @param string $props json属性，第三方可选填，最大长度1024字节
     * @return array
     */
    public function updateUser($accid, $props = '', $token = '')
    {
        $url = 'https://api.netease.im/nimserver/user/update.action';
        $param = array(
            'accid' => $accid,
            'props' => $props,
            'token' => !empty($token) ? $token : $this->token
        );
        $result = $this->post($url, $param);
        return $result;
    }

    /*
     * 更新用户名片
     * @param string $accid
     * @param array $data 结构如下
     * name String 否 用户昵称，最大长度64字节
     * icon String 否 用户icon，最大长度1024字节
     * sign String 否 用户签名，最大长度256字节
     * email String 否 用户email，最大长度64字节
     * birth String 否 用户生日，最大长度16字节
     * mobile String 否 用户mobile，最大长度32字节，只支持国内号码
     * gender String 否 用户性别，0表示未知，1表示男，2女表示女，其它会报参数错误
     * ex String 否 用户名片扩展字段，最大长度1024字节，用户可自行扩展，建议封装成JSON字符串
     * @param string $token 用户token
     * @return array
     */
    public function updateUserInfo($accid, $data = array())
    {
        $url = 'https://api.netease.im/nimserver/user/updateUinfo.action';
        $param = array(
            'accid' => $accid,
            'name' => isset($data['name']) ? $data['name'] : '',
            'icon' => isset($data['icon']) ? $data['icon'] : '',
            'sign' => isset($data['sign']) ? $data['sign'] : '',
            'email' => isset($data['email']) ? $data['email'] : '',
            'birth' => isset($data['birth']) ? $data['birth'] : '',
            'mobile' => isset($data['mobile']) ? $data['mobile'] : '',
            'gender' => isset($data['gender']) ? $data['gender'] : 0,
            'ex' => isset($data['ex']) ? $data['ex'] : ''
        );
        $result = $this->post($url, $param);
        return $result;
    }

    /*
    * 获取用户名片，可批量。用户帐号（例如：JSONArray对应的accid串，如：["zhangsan"]，如果解析出错，会报414）（一次查询最多为200）
    * @param array $accids
    * @return array
    */
    public function getUserInfo($accids)
    {
        $url = 'https://api.netease.im/nimserver/user/getUinfos.action';
        $param = array('accids' => json_encode($accids));
        $result = $this->post($url, $param);
        return $result;
    }

    //禁用用户登录
    public function disableUser($accid)
    {
        $url = 'https://api.netease.im/nimserver/user/block.action';
        $param = array('accid' => $accid);
        $result = $this->post($url, $param);
        return $result;
    }

    /*
     * @param $from String 是 发送者accid，用户帐号，最大32字节，必须保证一个APP内唯一
     * @param $to String 是 ope==0是表示accid即用户id，ope==1表示tid即群id
     * @param $ope String 是 0：点对点个人消息，1：群消息，其他返回414
     * @param $body String 是 请参考下方消息示例说明中对应消息的body字段，最大长度5000字节，为一个json串
     * @param $option String 是 发消息时特殊指定的行为选项,Json格式，
     * 可用于指定消息的漫游，存云端历史，发送方多端同步，推送，消息抄送等特殊行为;option中字段不填时表示默认值 option示例:
     * {"push":false,"roam":true,"history":false,"sendersync":true,"route":false,"badge":false,"needPushNick":true}
     * 字段说明：
     * 1. roam: 该消息是否需要漫游，默认true（需要app开通漫游消息功能）
     * 2. history: 该消息是否存云端历史，默认true
     * 3. sendersync: 该消息是否需要发送方多端同步，默认true
     * 4. push: 该消息是否需要APNS推送或安卓系统通知栏推送，默认true
     * 5. route: 该消息是否需要抄送第三方；默认true (需要app开通消息抄送功能)
     * 6. badge:该消息是否需要计入到未读计数中，默认true
     * 7. needPushNick: 推送文案是否需要带上昵称，不设置该参数时默认true;
     * @param $pushcontent String 否 ios推送内容，不超过150字节，option选项中允许推送（push=true），此字段可以指定推送内容
     * @param $payload String 否 ios 推送对应的payload,必须是JSON,不能超过2k字节
     * @param $ext String 否 开发者扩展字段，长度限制1024字节
     *
     */
    public function sendTextMsg($from, $to, $body = array(), $pushcontent = '', $payload = array(), $ope = 0, $option = array(), $ext = array())
    {
        $url = 'https://api.netease.im/nimserver/msg/sendMsg.action';
        $param = array(
            'from' => $from,
            'ope' => $ope,
            'to' => $to,
            'type' => 0,
            'body' => !empty($body) ? json_encode($body) : '',
            'option' => !empty($option) ? json_encode($option) : '',
            'pushcontent' => $pushcontent,
            'payload' => !empty($payload) ? json_encode($payload) : '',
            'ext' => !empty($ext) ? json_encode($ext) : ''
        );
        $result = $this->post($url, $param);
        return $result;
    }

    public function sendImageMsg($from, $to, $body = array(), $pushcontent = '', $payload = array(), $ope = 0, $option = array(), $ext = array())
    {
        $url = 'https://api.netease.im/nimserver/msg/sendMsg.action';
        $param = array(
            'from' => $from,
            'ope' => $ope,
            'to' => $to,
            'type' => 1,
            'body' => !empty($body) ? json_encode($body) : '',
            'option' => !empty($option) ? json_encode($option) : '',
            'pushcontent' => $pushcontent,
            'payload' => !empty($payload) ? json_encode($payload) : '',
            'ext' => !empty($ext) ? json_encode($ext) : ''
        );
        $result = $this->post($url, $param);
        return $result;
    }

    public function sendExtMsg($from, $to, $body = array(), $pushcontent = '', $payload = array(), $ope = 0, $option = array(), $ext = array())
    {
        $url = 'https://api.netease.im/nimserver/msg/sendMsg.action';
        $param = array(
            'from' => $from,
            'ope' => $ope,
            'to' => $to,
            'type' => 100,
            'body' => !empty($body) ? json_encode($body) : '',
            'option' => !empty($option) ? json_encode($option) : '',
            'pushcontent' => $pushcontent,
            'payload' => !empty($payload) ? json_encode($payload) : '',
            'ext' => !empty($ext) ? json_encode($ext) : ''
        );
        $result = $this->post($url, $param);
        return $result;
    }

    public function sendBatchTextMsg($from, $to, $body = array(), $pushcontent = '', $payload = array(), $option = array(), $ext = array())
    {
        $url = 'https://api.netease.im/nimserver/msg/sendBatchMsg.action';
        $param = array(
            'fromAccid' => $from,
            'toAccids' => json_encode($to),
            'type' => 0,
            'body' => !empty($body) ? json_encode($body) : '',
            'option' => !empty($option) ? json_encode($option) : '',
            'pushcontent' => $pushcontent,
            'payload' => !empty($payload) ? json_encode($payload) : '',
            'ext' => !empty($ext) ? json_encode($ext) : ''
        );
        $result = $this->post($url, $param);
        return $result;
    }

    public function sendBatchImageMsg($from, $to, $body = array(), $pushcontent = '', $payload = array(), $option = array(), $ext = array())
    {
        $url = 'https://api.netease.im/nimserver/msg/sendBatchMsg.action';
        $param = array(
            'fromAccid' => $from,
            'toAccids' => json_encode($to),
            'type' => 1,
            'body' => !empty($body) ? json_encode($body) : '',
            'option' => !empty($option) ? json_encode($option) : '',
            'pushcontent' => $pushcontent,
            'payload' => !empty($payload) ? json_encode($payload) : '',
            'ext' => !empty($ext) ? json_encode($ext) : ''
        );
        $result = $this->post($url, $param);
        return $result;
    }

    public function sendBatchExtMsg($from, $to, $body = array(), $pushcontent = '', $payload = array(), $option = array(), $ext = array())
    {
        $url = 'https://api.netease.im/nimserver/msg/sendBatchMsg.action';
        $param = array(
            'fromAccid' => $from,
            'toAccids' => json_encode($to),
            'type' => 100,
            'body' => !empty($body) ? json_encode($body) : '',
            'option' => !empty($option) ? json_encode($option) : '',
            'pushcontent' => $pushcontent,
            'payload' => !empty($payload) ? json_encode($payload) : '',
            'ext' => !empty($ext) ? json_encode($ext) : ''
        );
        $result = $this->post($url, $param);
        return $result;
    }

    /*
     * 添加好友 两人保持好友关系
     * @param string $accid     加好友发起者accid
     * @param string $faccid    加好友接收者accid
     * @param string $type      1直接加好友，2请求加好友，3同意加好友，4拒绝加好友
     * @param string $msg       加好友对应的请求消息，第三方组装，最长256字节
     * @return array
     */
    public function addFriend($accid, $faccid, $type, $msg = '')
    {
        $url = 'https://api.netease.im/nimserver/friend/add.action';
        $param = array(
            'accid' => $accid,
            'faccid' => $faccid,
            'type' => $type,
            'msg' => $msg,
        );
        $result = $this->post($url, $param);
        return $result;
    }

    /*
     * 更新好友相关信息，如加备注名，必须是好友才可以
     * @param string $accid     加好友发起者accid
     * @param string $faccid    加好友接收者accid
     * @param string $alias     给好友增加备注名
     * @return array
     */
    public function updateFriend($accid, $faccid, $alias = '')
    {
        $url = 'https://api.netease.im/nimserver/friend/update.action';
        $param = array(
            'accid' => $accid,
            'faccid' => $faccid,
            'alias' => $alias,
        );
        $result = $this->post($url, $param);
        return $result;
    }

    /*
     * 获取好友关系，查询某时间点起到现在有更新的双向好友
     * @param string $accid     加好友发起者accid
     * @param string $createtime    查询的时间点
     * @return array
     */
    public function getFriend($accid, $createtime)
    {
        $url = 'https://api.netease.im/nimserver/friend/get.action';
        $param = array(
            'accid' => $accid,
            'createtime' => $createtime,
        );
        $result = $this->post($url, $param);
        return $result;
    }

    private function post($url, $params = array())
    {
        $this->nonce = md5('kemai' . time());
        $this->curTime = time();
        $this->checkSum = sha1($this->appSecret . $this->nonce . $this->curTime);
        $header = array(
            "Content-Type:application/x-www-form-urlencoded;charset=utf-8",
            "AppKey:{$this->appKey}",
            "Nonce:{$this->nonce}",
            "CurTime:{$this->curTime}",
            "CheckSum:$this->checkSum"
        );
        $opts = array(
            CURLOPT_TIMEOUT => 60,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => $header,
            CURLOPT_URL => $url,
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => http_build_query($params)
        );
        $ch = curl_init();
        curl_setopt_array($ch, $opts);
        $result = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        return $this->parseResult($result);
    }

    private function parseResult($result)
    {
        $result = json_decode($result, true);
        $code = isset($result['code']) ? $result['code'] : 0;
        if ($code == 200) {
            return $result;
        } else {
            return false;
        }
    }
}