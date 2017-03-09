<?php

class Etcd
{
    /**
     * @var string
     */
    private $server;
    /**
     * @var bool
     */
    private $is_https = false;
    /**
     * @var string
     */
    private $api_version;
    /**
     * @var string
     */
    private $root = '/';
    /**
     * @var boolean
     */
    private $verify_ssl_peer = true;
    /**
     * @var string
     */
    private $custom_ca_file;

    /**
     * @param string $server
     * @param string $api_version
     */
    public function __construct()
    {
        $this->etcd_config = Yaconf::get("common")['etcd'];
        $this->set_server($this->etcd_config['server']);
        $this->set_api_version($this->etcd_config['api_version']);
    }

    /**
     * @return string
     */
    public function get_server()
    {
        return $this->server;
    }

    /**
     * @param  string $server
     * @return $this
     */
    public function &set_server($server)
    {
        if (filter_var($server, FILTER_VALIDATE_URL)) {
            $server = rtrim($server, '/');
            if ($server) {
                $this->server = $server;
                $this->is_https = strtolower(parse_url($this->server)['scheme']) == 'https';
            }
            return $this;
        } else {
            throw new \InvalidArgumentException("Value '$server' is not a valid server URL");
        }
    }

    /**
     * @return bool
     */
    public function get_verify_ssl_peer()
    {
        return $this->verify_ssl_peer;
    }

    /**
     * @return string
     */
    public function get_custom_ca_file()
    {
        return $this->custom_ca_file;
    }

    /**
     * Configure SSL connection parameters
     *
     * @param  bool|true $verify_ssl_peer
     * @param  string|null $custom_ca_file
     * @return $this
     */
    public function &verify_ssl_peer($verify_ssl_peer = true, $custom_ca_file = null)
    {
        if ($custom_ca_file) {
            if (!is_file($custom_ca_file)) {
                throw new \InvalidArgumentException('Custom CA file does not exist');
            }
            if (!$verify_ssl_peer) {
                throw new \LogicException('Custom CA file shoult not be set if SSL peer is not verified');
            }
        }
        $this->verify_ssl_peer = (boolean)$verify_ssl_peer;
        $this->custom_ca_file = $custom_ca_file;
        return $this;
    }

    /**
     * @return string
     */
    public function get_api_version()
    {
        return $this->api_version;
    }

    /**
     * @param  string $version
     * @return $this
     */
    public function &set_api_version($version)
    {
        $this->api_version = $version;
        return $this;
    }

    /**
     * @return string
     */
    public function get_sandbox_path()
    {
        return $this->root;
    }

    /**
     * Set the default root directory. the default is `/`
     * If the root is others e.g. /linkorb when you set new key,
     * or set dir, all of the key is under the root
     * e.g.
     * <code>
     *    $client->setRoot('/linkorb');
     *    $client->set('key1, 'value1');
     *    // the new key is /linkorb/key1
     * </code>
     *
     * @param string $root
     * @return Client
     */
    public function &set_sandbox_path($root)
    {
        if (substr($root, 0, 1) !== '/') {
            $root = '/' . $root;
        }
        $this->root = rtrim($root, '/');
        return $this;
    }

    /**
     * Build key space operations
     *
     * @param  string $key
     * @return string
     */
    public function get_key_path($key)
    {
        if (substr($key, 0, 1) !== '/') {
            $key = '/' . $key;
        }
        return rtrim('/' . $this->api_version . '/keys' . $this->root, '/') . $key;
    }

    /**
     * Return full key URI
     *
     * @param  string $key
     * @return string
     */
    public function get_key_url($key)
    {
        return $this->server . $this->get_key_path($key);
    }

    /**
     * @return array
     */
    public function get_version()
    {
        return $this->http_get($this->server . '/version');
    }

    /**
     * {@inheritdoc}
     */
    public function exists($key)
    {
        try {
            $response = $this->http_get($this->get_key_url($key));
            return !empty($response['node']) && array_key_exists('value', $response['node']);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function dir_exists($key)
    {
        try {
            return !empty($this->http_get($this->get_key_url($key))['node']['dir']);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function set($key, $value, $ttl = null, $condition = [])
    {
        $data = ['value' => $value];
        if ($ttl) {
            $data['ttl'] = $ttl;
        }
        return $this->http_put($this->get_key_url($key), $data, $condition);
    }

    /**
     * Retrieve the value of a key
     *
     * @param string $key
     * @param array $flags the extra query params
     * @return array
     * @throws KeyNotFoundException
     * @throws EtcdException
     */
    public function get_node($key, array $flags = null)
    {
        $query = [];
        if ($flags) {
            $query = ['query' => $flags];
        }
        $response = $this->http_get($this->get_key_url($key), $query);
        if (empty($response['node'])) {
            throw new Exception('Node field expected in response');
        } else {
            return $response['node'];
        }
    }

    /**
     * Retrieve the value of a key
     *
     * @param string $key
     * @param array $flags the extra query params
     * @return string the value of the key.
     * @throws KeyNotFoundException
     */
    public function get($key, array $flags = null)
    {
        try {
            $node = $this->get_node($key, $flags);
            return $node['value'];
        } catch (Exception $ex) {
            throw $ex;
        }
    }

    /**
     * Create a new key with a given value
     *
     * @param string $key
     * @param string $value
     * @param int $ttl
     * @return array $body
     * @throws KeyExistsException
     */
    public function create($key, $value, $ttl = 0)
    {
        return $request = $this->set($key, $value, $ttl, ['prevExist' => 'false']);
    }

    /**
     * make a new directory
     *
     * @param string $key
     * @param int $ttl
     * @return array $body
     * @throws KeyExistsException
     */
    public function create_dir($key, $ttl = 0)
    {
        $data = ['dir' => 'true'];
        if ($ttl) {
            $data['ttl'] = $ttl;
        }
        return $this->http_put($this->get_key_url($key), $data, ['prevExist' => 'false']);
    }

    /**
     * Update an existing key with a given value.
     *
     * @param string $key
     * @param string $value
     * @param int $ttl
     * @param array $condition The extra condition for updating
     * @return array $body
     * @throws KeyNotFoundException
     */
    public function update($key, $value, $ttl = 0, $condition = [])
    {
        $extra = ['prevExist' => 'true'];
        if ($condition) {
            $extra = array_merge($extra, $condition);
        }
        return $this->set($key, $value, $ttl, $extra);
    }

    /**
     * Update directory
     *
     * @param  string $key
     * @param  int $ttl
     * @return array
     * @throws EtcdException
     */
    public function update_dir($key, $ttl)
    {
        if (!$ttl) {
            throw new Exception('TTL is required', 204);
        }
        return $this->http_put($this->get_key_url($key), ['ttl' => (int)$ttl], [
            'dir' => 'true',
            'prevExist' => 'true',
        ]);
    }

    /**
     * remove a key
     *
     * @param string $key
     * @return array
     * @throws EtcdException
     */
    public function remove($key)
    {
        return $this->http_delete($this->get_key_url($key));
    }

    /**
     * Removes the key if it is directory
     *
     * @param string $key
     * @param boolean $recursive
     * @return mixed
     * @throws EtcdException
     */
    public function remove_dir($key, $recursive = false)
    {
        $query = ['dir' => 'true'];
        if ($recursive === true) {
            $query['recursive'] = 'true';
        }
        return $this->http_delete($this->server . $this->get_key_path($key), $query);
    }

    /**
     * Retrieve a directory
     *
     * @param string $key
     * @param boolean $recursive
     * @return mixed
     * @throws KeyNotFoundException
     */
    public function dir_info($key = '/', $recursive = false)
    {
        $query = [];
        if ($recursive === true) {
            $query['recursive'] = 'true';
        }
        return $this->http_get($this->get_key_url($key), $query);
    }

    /**
     * Retrieve a directories key
     *
     * @param string $key
     * @param boolean $recursive
     * @return array
     * @throws EtcdException
     */
    public function list_subdirs($key = '/', $recursive = false)
    {
        try {
            $data = $this->dir_info($key, $recursive);
        } catch (Exception $e) {
            throw $e;
        }
        $iterator = new RecursiveArrayIterator($data);
        return $this->traversal_dir($iterator);
    }

    /**
     * @var array
     */
    private $dirs = [];
    /**
     * @var array
     */
    private $values = [];

    /**
     * Traversal the directory to get the keys.
     *
     * @param RecursiveArrayIterator $iterator
     * @return array
     */
    private function traversal_dir(RecursiveArrayIterator $iterator)
    {
        $key = '';
        while ($iterator->valid()) {
            if ($iterator->hasChildren()) {
                $this->traversal_dir($iterator->getChildren());
            } else {
                if ($iterator->key() == 'key' && ($iterator->current() != '/')) {
                    $this->dirs[] = $key = $iterator->current();
                }
                if ($iterator->key() == 'value') {
                    $this->values[$key] = $iterator->current();
                }
            }
            $iterator->next();
        }
        return $this->dirs;
    }

    /**
     * Get all key-value pair that the key is not directory.
     *
     * @param string $root
     * @param boolean $recursive
     * @param string $key
     * @return array
     */
    public function get_key_value_map($root = '/', $recursive = true, $key = null)
    {
        $this->list_subdirs($root, $recursive);
        if (isset($this->values[$key])) {
            return $this->values[$key];
        }
        return $this->values;
    }

    /**
     * {@inheritdoc}
     */
    public function sandboxed($sandbox_path, callable $callback)
    {
        $current_sandbox_path = $this->get_sandbox_path();
        if ($sandbox_path != $current_sandbox_path) {
            if (mb_substr($sandbox_path, 0, 2) == './') {
                $this->set_sandbox_path($current_sandbox_path . mb_substr($sandbox_path, 1));
            } else {
                $this->set_sandbox_path($sandbox_path);
            }
        }
        call_user_func_array($callback, [&$this]);
        if ($sandbox_path != $current_sandbox_path) {
            $this->set_sandbox_path($current_sandbox_path);
        }
    }
    // ---------------------------------------------------
    //  Make requests
    // ---------------------------------------------------
    /**
     * Make a GET request
     *
     * @param  string $url
     * @param  array $query_arguments
     * @return array
     */
    private function http_get($url, $query_arguments = [])
    {
        if (!empty($query_arguments)) {
            $url .= '?' . http_build_query($query_arguments);
        }
        return $this->execute_curl_request($this->get_curl_handle($url), $url);
    }

    /**
     * Make a POST request
     *
     * @param  string $url
     * @param  array $payload
     * @param  array $query_arguments
     * @return array|mixed
     * @throws EtcdException
     */
    private function http_post($url, $payload = [], $query_arguments = [])
    {
        if (!empty($query_arguments)) {
            $url .= '?' . http_build_query($query_arguments);
        }
        $curl = $this->get_curl_handle($url);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($payload));
        return $this->execute_curl_request($curl, $url);
    }

    /**
     * Make a PUT request
     *
     * @param  string $url
     * @param  array $payload
     * @param  array $query_arguments
     * @return array|mixed
     * @throws EtcdException
     */
    private function http_put($url, $payload = [], $query_arguments = [])
    {
        if (!empty($query_arguments)) {
            $url .= '?' . http_build_query($query_arguments);
        }
        $curl = $this->get_curl_handle($url);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($payload));
        return $this->execute_curl_request($curl, $url);
    }

    /**
     * Make a DELETE request
     *
     * @param  string $url
     * @param  array $query_arguments
     * @return array|mixed
     * @throws EtcdException
     */
    private function http_delete($url, $query_arguments = [])
    {
        if (!empty($query_arguments)) {
            $url .= '?' . http_build_query($query_arguments);
        }
        $curl = $this->get_curl_handle($url);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
        return $this->execute_curl_request($curl, $url);
    }

    /**
     * Initialize curl handle
     *
     * @param  string $url
     * @return resource
     */
    private function get_curl_handle($url)
    {
        if ($curl = curl_init($url)) {
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 15);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
            if ($this->is_https && $this->verify_ssl_peer) {
                curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
                if ($this->custom_ca_file) {
                    curl_setopt($curl, CURLOPT_CAINFO, $this->custom_ca_file);
                }
            } else {
                curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
            }
            return $curl;
        } else {
            throw new \RuntimeException("Can't create curl handle");
        }
    }

    /**
     * @param  resource $curl
     * @param  string $url
     * @param  bool|true $decode_etcd_json
     * @return array|mixed
     * @throws EtcdException
     */
    private function execute_curl_request($curl, $url, $decode_etcd_json = true)
    {
        $response = curl_exec($curl);
        if ($error_code = curl_errno($curl)) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new \RuntimeException("$url request failed. Reason: $error", $error_code);
        } else {
            curl_close($curl);
            if ($decode_etcd_json) {
                $response = json_decode($response, true);
                if (isset($response['errorCode']) && $response['errorCode']) {
                    $message = $response['message'];
                    if (isset($response['cause']) && $response['cause']) {
                        $message .= '. Cause: ' . $response['cause'];
                    }
                    if ($response['errorCode']) {
                        throw new Exception($message);
                    }
                }
            }
            return $response;
        }
    }

}