<?php

class LDAP
{

    private $ds;
    private $root_binded = FALSE;

    function __construct($opt)
    {
        $config = Yaconf::get("common")['ldap'];
        $this->config = array_merge($config,array(
            'server_type' => '',
            'pass_algo' => 'md5',
            'enable_shadow' => true,
            'default_uid' => 10000,
            'default_gid' => 10000,
        ));

        $ds = @ldap_connect($this->config['host']);
        if (!$ds) throw new Exception('无法连接LDAP, 请检查您的LDAP配置');

        @ldap_set_option($ds, LDAP_OPT_PROTOCOL_VERSION, 3);
        @ldap_set_option($ds, LDAP_OPT_NETWORK_TIMEOUT, 3);
        $this->ds = $ds;
        $this->bind_root();
    }

    function __destruct()
    {
        if ($this->ds) {
            @ldap_close($this->ds);
            $this->root_binded = FALSE;
        }
    }

    function bind($dn, $password)
    {
        $ret = @ldap_bind($this->ds, $dn, $password);
        if ($dn != $this->config ['root_dn']) {
            $this->bind_root();
        }
        return $ret;
    }

    private function bind_root()
    {
        return $this->bind($this->config['root_dn'], $this->config['root_pass']);
    }

    function rename($dn, $dn_new, $base = NULL, $deleteoldrdn = TRUE)
    {
        return @ldap_rename($this->ds, $dn, $dn_new, $base, $deleteoldrdn);
    }

    function mod_replace($dn, $data)
    {
        return @ldap_mod_replace($this->ds, $dn, $data);
    }

    function mod_add($dn, $data)
    {
        return @ldap_mod_add($this->ds, $dn, $data);
    }

    function mod_del($dn, $data)
    {
        return @ldap_mod_del($this->ds, $dn, $data);
    }

    function add($dn, $data)
    {
        return @ldap_add($this->ds, $dn, $data);
    }

    function modify($dn, $data)
    {
        return @ldap_modify($this->ds, $dn, $data);
    }

    function delete($dn)
    {
        return @ldap_delete($this->ds, $dn);
    }

    function search()
    {
        $args = func_get_args();
        array_unshift($args, $this->ds);
        return @call_user_func_array('ldap_search', $args);
    }

    function entries($sr)
    {
        return @ldap_get_entries($this->ds, $sr);
    }

    function first_entry($sr)
    {
        return @ldap_first_entry($this->ds, $sr);
    }

    function next_entry($er)
    {
        return @ldap_next_entry($this->ds, $er);
    }

    function entry_dn($er)
    {
        return @ldap_get_dn($this->ds, $er);
    }

    function attributes($er)
    {
        return @ldap_get_attributes($this->ds, $er);
    }

    function set_password($dn, $password)
    {
        return $this->mod_replace($dn, $this->get_password_attrs($password));
    }

    function add_account($base_dn, $account, $password)
    {
        $server_type = $this->config['server_type'];
        switch ($server_type) {
            case 'ads':
                $dn = 'cn=' . $account . ',' . $base_dn;
                $data = array(
                    'objectClass' => array('top', 'person', 'organizationalPerson', 'user'),
                    'cn' => $account,
                    'sAMAccountName' => $account,
                );
                break;
            default:
                $dn = 'cn=' . $account . ',' . $base_dn;
                $data = array(
                    'objectClass' => array('top', 'person', 'organizationalPerson', 'posixAccount'),
                    'cn' => $account,
                    'sn' => $account,
                    'uid' => $account,
                    'loginShell' => '/bin/false',
                    'homeDirectory' => '/home/samba/users/' . $account,
                    'uidNumber' => $this->posix_get_new_uid(),
                    'gidNumber' => $this->config['default_gid'],
                );

                if ($this->config['enable_shadow']) {
                    $data['objectClass'][] = 'shadowAccount';
                    $data += array(
                        'shadowExpire' => 99999,
                        'shadowFlag' => 0,
                        'shadowInactive' => 99999,
                        'shadowMax' => 99999,
                        'shadowMin' => 0,
                        'shadowWarning' => 0,
                    );
                }
                break;
        }

        $data += $this->get_password_attrs($password);

        $ret = $this->add($dn, $data);
        if ($ret) $this->enable_account($dn, TRUE);
        return $ret;
    }

    function enable_account($dn, $enable = TRUE)
    {
        switch ($this->config['server_type']) {
            case 'ads':
                $sr = $this->search($dn, '(objectClass=*)', array('useraccountcontrol'), TRUE);
                $entries = $this->entries($sr);
                $uac = $entries[0]['useraccountcontrol'][0];
                if ($enable) {
                    $uac = $uac & ~0x22;
                    $uac = $uac | 0x10000;    //Password never expires
                    $this->mod_replace($dn, array('useraccountcontrol' => $uac));
                } else {
                    //禁用帐号
                    if (!($uac & 0x2)) {
                        $this->mod_replace($dn, array('useraccountcontrol' => $uac | 0x2));
                    }
                }
                break;
            default:
                break;
        }
    }

    private function get_password_attrs($password)
    {
        switch ($this->config['pass_algo']) {
            case 'plain':    //不加密
                $secret = $password;
                break;
            case 'md5':
                $secret = '{MD5}' . base64_encode(md5($password, TRUE));
                break;
            case 'sha':
            default:
                $secret = '{SHA}' . base64_encode(sha1($password, TRUE));
                break;
        }

        $data = array(
            'userPassword' => $secret,
        );

        return $data;
    }

    private function posix_get_new_uid()
    {
        static $default_uid = 0;
        if (!$default_uid) $default_uid = $this->config['default_uid'];
        $account = $default_uid + 1;
        while (posix_getpwuid($account)) {
            $account++;
        }
        return $default_uid = $account;
    }

}
