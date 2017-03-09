<?php

/**
 * 百度云PHP SDK 公共网络交互类
 *
 * 本文件百度云PHP版本SDK的公共网络交互功能
 *
 * @author 百度移动·云事业部
 * @copyright Copyright (c) 2012-2020 百度在线网络技术(北京)有限公司
 * @version 2.0.1
 * @package
 */
class RequestCore
{

    /**
     * The URL being requested.
     */
    public $request_url;

    /**
     * The headers being sent in the request.
     */
    public $request_headers;

    /**
     * The body being sent in the request.
     */
    public $request_body;

    /**
     * The response returned by the request.
     */
    public $response;

    /**
     * The headers returned by the request.
     */
    public $response_headers;

    /**
     * The body returned by the request.
     */
    public $response_body;

    /**
     * The HTTP status code returned by the request.
     */
    public $response_code;

    /**
     * Additional response data.
     */
    public $response_info;

    /**
     * The handle for the cURL object.
     */
    public $curl_handle;

    /**
     * The method by which the request is being made.
     */
    public $method;

    /**
     * Stores the proxy settings to use for the request.
     */
    public $proxy = null;

    /**
     * The username to use for the request.
     */
    public $username = null;

    /**
     * The password to use for the request.
     */
    public $password = null;

    /**
     * Custom CURLOPT settings.
     */
    public $curlopts = null;

    /**
     * The state of debug mode.
     */
    public $debug_mode = false;

    /**
     * The default class to use for HTTP Requests (defaults to <RequestCore>).
     */
    public $request_class = 'RequestCore';

    /**
     * The default class to use for HTTP Responses (defaults to <ResponseCore>).
     */
    public $response_class = 'ResponseCore';

    /**
     * Default useragent string to use.
     */
    public $useragent = 'RequestCore/1.4.2';

    /**
     * File to read from while streaming up.
     */
    public $read_file = null;

    /**
     * The resource to read from while streaming up.
     */
    public $read_stream = null;

    /**
     * The size of the stream to read from.
     */
    public $read_stream_size = null;

    /**
     * The length already read from the stream.
     */
    public $read_stream_read = 0;

    /**
     * File to write to while streaming down.
     */
    public $write_file = null;

    /**
     * The resource to write to while streaming down.
     */
    public $write_stream = null;

    /**
     * Stores the intended starting seek position.
     */
    public $seek_position = null;

    /**
     * The user-defined callback function to call when a stream is read from.
     */
    public $registered_streaming_read_callback = null;

    /**
     * The user-defined callback function to call when a stream is written to.
     */
    public $registered_streaming_write_callback = null;

    /* %******************************************************************************************% */
    // CONSTANTS
    /**
     * GET HTTP Method
     */

    const HTTP_GET = 'GET';
    /**
     * POST HTTP Method
     */
    const HTTP_POST = 'POST';
    /**
     * PUT HTTP Method
     */
    const HTTP_PUT = 'PUT';
    /**
     * DELETE HTTP Method
     */
    const HTTP_DELETE = 'DELETE';
    /**
     * HEAD HTTP Method
     */
    const HTTP_HEAD = 'HEAD';

    /* %******************************************************************************************% */

    // CONSTRUCTOR/DESTRUCTOR
    /**
     * Constructs a new instance of this class.
     *
     * @param string $url (Optional) The URL to request or service endpoint to query.
     * @param string $proxy (Optional) The faux-url to use for proxy settings. Takes the following format: `proxy://user:pass@hostname:port`
     * @param array $helpers (Optional) An associative array of classnames to use for request, and response functionality. Gets passed in automatically by the calling class.
     * @return $this A reference to the current instance.
     */
    public function __construct($url = null, $proxy = null, $helpers = null)
    {
        // Set some default values.
        $this->request_url = $url;
        $this->method = self::HTTP_GET;
        $this->request_headers = array();
        $this->request_body = '';
        // Set a new Request class if one was set.
        if (isset($helpers ['request']) && !empty($helpers ['request'])) {
            $this->request_class = $helpers ['request'];
        }
        // Set a new Request class if one was set.
        if (isset($helpers ['response']) && !empty($helpers ['response'])) {
            $this->response_class = $helpers ['response'];
        }
        if ($proxy) {
            $this->set_proxy($proxy);
        }
        return $this;
    }

    /**
     * Destructs the instance. Closes opened file handles.
     *
     * @return $this A reference to the current instance.
     */
    public function __destruct()
    {
        if (isset($this->read_file) && isset($this->read_stream)) {
            fclose($this->read_stream);
        }
        if (isset($this->write_file) && isset($this->write_stream)) {
            fclose($this->write_stream);
        }
        return $this;
    }

    /* %******************************************************************************************% */

    // REQUEST METHODS
    /**
     * Sets the credentials to use for authentication.
     *
     * @param string $user (Required) The username to authenticate with.
     * @param string $pass (Required) The password to authenticate with.
     * @return $this A reference to the current instance.
     */
    public function set_credentials($user, $pass)
    {
        $this->username = $user;
        $this->password = $pass;
        return $this;
    }

    /**
     * Adds a custom HTTP header to the cURL request.
     *
     * @param string $key (Required) The custom HTTP header to set.
     * @param mixed $value (Required) The value to assign to the custom HTTP header.
     * @return $this A reference to the current instance.
     */
    public function add_header($key, $value)
    {
        $this->request_headers [$key] = $value;
        return $this;
    }

    /**
     * Removes an HTTP header from the cURL request.
     *
     * @param string $key (Required) The custom HTTP header to set.
     * @return $this A reference to the current instance.
     */
    public function remove_header($key)
    {
        if (isset($this->request_headers [$key])) {
            unset($this->request_headers [$key]);
        }
        return $this;
    }

    /**
     * Set the method type for the request.
     *
     * @param string $method (Required) One of the following constants: <HTTP_GET>, <HTTP_POST>, <HTTP_PUT>, <HTTP_HEAD>, <HTTP_DELETE>.
     * @return $this A reference to the current instance.
     */
    public function set_method($method)
    {
        $this->method = strtoupper($method);
        return $this;
    }

    /**
     * Sets a custom useragent string for the class.
     *
     * @param string $ua (Required) The useragent string to use.
     * @return $this A reference to the current instance.
     */
    public function set_useragent($ua)
    {
        $this->useragent = $ua;
        return $this;
    }

    /**
     * Set the body to send in the request.
     *
     * @param string $body (Required) The textual content to send along in the body of the request.
     * @return $this A reference to the current instance.
     */
    public function set_body($body)
    {
        $this->request_body = $body;
        return $this;
    }

    /**
     * Set the URL to make the request to.
     *
     * @param string $url (Required) The URL to make the request to.
     * @return $this A reference to the current instance.
     */
    public function set_request_url($url)
    {
        $this->request_url = $url;
        return $this;
    }

    /**
     * Set additional CURLOPT settings. These will merge with the default settings, and override if
     * there is a duplicate.
     *
     * @param array $curlopts (Optional) A set of key-value pairs that set `CURLOPT` options. These will merge with the existing CURLOPTs, and ones passed here will override the defaults. Keys should be the `CURLOPT_*` constants, not strings.
     * @return $this A reference to the current instance.
     */
    public function set_curlopts($curlopts)
    {
        $this->curlopts = $curlopts;
        return $this;
    }

    /**
     * Sets the length in bytes to read from the stream while streaming up.
     *
     * @param integer $size (Required) The length in bytes to read from the stream.
     * @return $this A reference to the current instance.
     */
    public function set_read_stream_size($size)
    {
        $this->read_stream_size = $size;
        return $this;
    }

    /**
     * Sets the resource to read from while streaming up. Reads the stream from its current position until
     * EOF or `$size` bytes have been read. If `$size` is not given it will be determined by <php:fstat()> and
     * <php:ftell()>.
     *
     * @param resource $resource (Required) The readable resource to read from.
     * @param integer $size (Optional) The size of the stream to read.
     * @return $this A reference to the current instance.
     */
    public function set_read_stream($resource, $size = null)
    {
        if (!isset($size) || $size < 0) {
            $stats = fstat($resource);
            if ($stats && $stats ['size'] >= 0) {
                $position = ftell($resource);
                if ($position !== false && $position >= 0) {
                    $size = $stats ['size'] - $position;
                }
            }
        }
        $this->read_stream = $resource;
        return $this->set_read_stream_size($size);
    }

    /**
     * Sets the file to read from while streaming up.
     *
     * @param string $location (Required) The readable location to read from.
     * @return $this A reference to the current instance.
     */
    public function set_read_file($location)
    {
        $this->read_file = $location;
        $read_file_handle = fopen($location, 'r');
        return $this->set_read_stream($read_file_handle);
    }

    /**
     * Sets the resource to write to while streaming down.
     *
     * @param resource $resource (Required) The writeable resource to write to.
     * @return $this A reference to the current instance.
     */
    public function set_write_stream($resource)
    {
        $this->write_stream = $resource;
        return $this;
    }

    /**
     * Sets the file to write to while streaming down.
     *
     * @param string $location (Required) The writeable location to write to.
     * @return $this A reference to the current instance.
     */
    public function set_write_file($location)
    {
        $this->write_file = $location;
        $write_file_handle = fopen($location, 'w');
        return $this->set_write_stream($write_file_handle);
    }

    /**
     * Set the proxy to use for making requests.
     *
     * @param string $proxy (Required) The faux-url to use for proxy settings. Takes the following format: `proxy://user:pass@hostname:port`
     * @return $this A reference to the current instance.
     */
    public function set_proxy($proxy)
    {
        $proxy = parse_url($proxy);
        $proxy ['user'] = isset($proxy ['user']) ? $proxy ['user'] : null;
        $proxy ['pass'] = isset($proxy ['pass']) ? $proxy ['pass'] : null;
        $proxy ['port'] = isset($proxy ['port']) ? $proxy ['port'] : null;
        $this->proxy = $proxy;
        return $this;
    }

    /**
     * Set the intended starting seek position.
     *
     * @param integer $position (Required) The byte-position of the stream to begin reading from.
     * @return $this A reference to the current instance.
     */
    public function set_seek_position($position)
    {
        $this->seek_position = isset($position) ? (integer)$position : null;
        return $this;
    }

    /**
     * Register a callback function to execute whenever a data stream is read from using
     * <CFRequest::streaming_read_callback()>.
     *
     * The user-defined callback function should accept three arguments:
     *
     * <ul>
     * <li><code>$curl_handle</code> - <code>resource</code> - Required - The cURL handle resource that represents the in-progress transfer.</li>
     * <li><code>$file_handle</code> - <code>resource</code> - Required - The file handle resource that represents the file on the local file system.</li>
     * <li><code>$length</code> - <code>integer</code> - Required - The length in kilobytes of the data chunk that was transferred.</li>
     * </ul>
     *
     * @param string|array|function $callback (Required) The callback function is called by <php:call_user_func()>, so you can pass the following values: <ul>
     * <li>The name of a global function to execute, passed as a string.</li>
     * <li>A method to execute, passed as <code>array('ClassName', 'MethodName')</code>.</li>
     * <li>An anonymous function (PHP 5.3+).</li></ul>
     * @return $this A reference to the current instance.
     */
    public function register_streaming_read_callback($callback)
    {
        $this->registered_streaming_read_callback = $callback;
        return $this;
    }

    /**
     * Register a callback function to execute whenever a data stream is written to using
     * <CFRequest::streaming_write_callback()>.
     *
     * The user-defined callback function should accept two arguments:
     *
     * <ul>
     * <li><code>$curl_handle</code> - <code>resource</code> - Required - The cURL handle resource that represents the in-progress transfer.</li>
     * <li><code>$length</code> - <code>integer</code> - Required - The length in kilobytes of the data chunk that was transferred.</li>
     * </ul>
     *
     * @param string|array|function $callback (Required) The callback function is called by <php:call_user_func()>, so you can pass the following values: <ul>
     * <li>The name of a global function to execute, passed as a string.</li>
     * <li>A method to execute, passed as <code>array('ClassName', 'MethodName')</code>.</li>
     * <li>An anonymous function (PHP 5.3+).</li></ul>
     * @return $this A reference to the current instance.
     */
    public function register_streaming_write_callback($callback)
    {
        $this->registered_streaming_write_callback = $callback;
        return $this;
    }

    /* %******************************************************************************************% */

    // PREPARE, SEND, AND PROCESS REQUEST
    /**
     * A callback function that is invoked by cURL for streaming up.
     *
     * @param resource $curl_handle (Required) The cURL handle for the request.
     * @param resource $file_handle (Required) The open file handle resource.
     * @param integer $length (Required) The maximum number of bytes to read.
     * @return binary Binary data from a stream.
     */
    public function streaming_read_callback($curl_handle, $file_handle, $length)
    {
        // Once we've sent as much as we're supposed to send...
        if ($this->read_stream_read >= $this->read_stream_size) {
            // Send EOF
            return '';
        }
        // If we're at the beginning of an upload and need to seek...
        if ($this->read_stream_read == 0 && isset($this->seek_position) && $this->seek_position !== ftell($this->read_stream)) {
            if (fseek($this->read_stream, $this->seek_position) !== 0) {
                throw new RequestCore_Exception('The stream does not support seeking and is either not at the requested position or the position is unknown.');
            }
        }
        $read = fread($this->read_stream, min($this->read_stream_size - $this->read_stream_read, $length)); // Remaining upload data or cURL's requested chunk size
        $this->read_stream_read += strlen($read);
        $out = $read === false ? '' : $read;
        // Execute callback function
        if ($this->registered_streaming_read_callback) {
            call_user_func($this->registered_streaming_read_callback, $curl_handle, $file_handle, $out);
        }
        return $out;
    }

    /**
     * A callback function that is invoked by cURL for streaming down.
     *
     * @param resource $curl_handle (Required) The cURL handle for the request.
     * @param binary $data (Required) The data to write.
     * @return integer The number of bytes written.
     */
    public function streaming_write_callback($curl_handle, $data)
    {
        $length = strlen($data);
        $written_total = 0;
        $written_last = 0;
        while ($written_total < $length) {
            $written_last = fwrite($this->write_stream, substr($data, $written_total));
            if ($written_last === false) {
                return $written_total;
            }
            $written_total += $written_last;
        }
        // Execute callback function
        if ($this->registered_streaming_write_callback) {
            call_user_func($this->registered_streaming_write_callback, $curl_handle, $written_total);
        }
        return $written_total;
    }

    /**
     * Prepares and adds the details of the cURL request. This can be passed along to a <php:curl_multi_exec()>
     * function.
     *
     * @return resource The handle for the cURL object.
     */
    public function prep_request()
    {
        $curl_handle = curl_init();
        // Set default options.
        curl_setopt($curl_handle, CURLOPT_URL, $this->request_url);
        curl_setopt($curl_handle, CURLOPT_FILETIME, true);
        curl_setopt($curl_handle, CURLOPT_FRESH_CONNECT, false);
        curl_setopt($curl_handle, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl_handle, CURLOPT_SSL_VERIFYHOST, true);
        curl_setopt($curl_handle, CURLOPT_CLOSEPOLICY, CURLCLOSEPOLICY_LEAST_RECENTLY_USED);
        curl_setopt($curl_handle, CURLOPT_MAXREDIRS, 5);
        curl_setopt($curl_handle, CURLOPT_HEADER, true);
        curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl_handle, CURLOPT_TIMEOUT, 5184000);
        curl_setopt($curl_handle, CURLOPT_CONNECTTIMEOUT, 120);
        curl_setopt($curl_handle, CURLOPT_NOSIGNAL, true);
        curl_setopt($curl_handle, CURLOPT_REFERER, $this->request_url);
        curl_setopt($curl_handle, CURLOPT_USERAGENT, $this->useragent);
        curl_setopt($curl_handle, CURLOPT_READFUNCTION, array(
            $this, 'streaming_read_callback'));
        if ($this->debug_mode) {
            curl_setopt($curl_handle, CURLOPT_VERBOSE, true);
        }
        if ($this->proxy) {
            curl_setopt($curl_handle, CURLOPT_HTTPPROXYTUNNEL, true);
            $host = $this->proxy ['host'];
            $host .= ($this->proxy ['port']) ? ':' . $this->proxy ['port'] : '';
            curl_setopt($curl_handle, CURLOPT_PROXY, $host);
            if (isset($this->proxy ['user']) && isset($this->proxy ['pass'])) {
                curl_setopt($curl_handle, CURLOPT_PROXYUSERPWD, $this->proxy ['user'] . ':' . $this->proxy ['pass']);
            }
        }
        // Set credentials for HTTP Basic/Digest Authentication.
        if ($this->username && $this->password) {
            curl_setopt($curl_handle, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
            curl_setopt($curl_handle, CURLOPT_USERPWD, $this->username . ':' . $this->password);
        }
        // Handle the encoding if we can.
        if (extension_loaded('zlib')) {
            curl_setopt($curl_handle, CURLOPT_ENCODING, '');
        }
        // Process custom headers
        if (isset($this->request_headers) && count($this->request_headers)) {
            $temp_headers = array();
            foreach ($this->request_headers as $k => $v) {
                $temp_headers [] = $k . ': ' . $v;
            }
            curl_setopt($curl_handle, CURLOPT_HTTPHEADER, $temp_headers);
        }
        switch ($this->method) {
            case self::HTTP_PUT :
                curl_setopt($curl_handle, CURLOPT_CUSTOMREQUEST, 'PUT');
                if (isset($this->read_stream)) {
                    if (!isset($this->read_stream_size) || $this->read_stream_size < 0) {
                        throw new RequestCore_Exception('The stream size for the streaming upload cannot be determined.');
                    }
                    curl_setopt($curl_handle, CURLOPT_INFILESIZE, $this->read_stream_size);
                    curl_setopt($curl_handle, CURLOPT_UPLOAD, true);
                } else {
                    curl_setopt($curl_handle, CURLOPT_POSTFIELDS, $this->request_body);
                }
                break;
            case self::HTTP_POST :
                curl_setopt($curl_handle, CURLOPT_POST, true);
                curl_setopt($curl_handle, CURLOPT_POSTFIELDS, $this->request_body);
                break;
            case self::HTTP_HEAD :
                curl_setopt($curl_handle, CURLOPT_CUSTOMREQUEST, self::HTTP_HEAD);
                curl_setopt($curl_handle, CURLOPT_NOBODY, 1);
                break;
            default : // Assumed GET
                curl_setopt($curl_handle, CURLOPT_CUSTOMREQUEST, $this->method);
                if (isset($this->write_stream)) {
                    curl_setopt($curl_handle, CURLOPT_WRITEFUNCTION, array(
                        $this, 'streaming_write_callback'));
                    curl_setopt($curl_handle, CURLOPT_HEADER, false);
                } else {
                    curl_setopt($curl_handle, CURLOPT_POSTFIELDS, $this->request_body);
                }
                break;
        }
        // Merge in the CURLOPTs
        if (isset($this->curlopts) && sizeof($this->curlopts) > 0) {
            foreach ($this->curlopts as $k => $v) {
                curl_setopt($curl_handle, $k, $v);
            }
        }
        return $curl_handle;
    }

    /**
     * Take the post-processed cURL data and break it down into useful header/body/info chunks. Uses the
     * data stored in the `curl_handle` and `response` properties unless replacement data is passed in via
     * parameters.
     *
     * @param resource $curl_handle (Optional) The reference to the already executed cURL request.
     * @param string $response (Optional) The actual response content itself that needs to be parsed.
     * @return ResponseCore A <ResponseCore> object containing a parsed HTTP response.
     */
    public function process_response($curl_handle = null, $response = null)
    {
        // Accept a custom one if it's passed.
        if ($curl_handle && $response) {
            $this->curl_handle = $curl_handle;
            $this->response = $response;
        }
        // As long as this came back as a valid resource...
        if (is_resource($this->curl_handle)) {
            // Determine what's what.
            $header_size = curl_getinfo($this->curl_handle, CURLINFO_HEADER_SIZE);
            $this->response_headers = substr($this->response, 0, $header_size);
            $this->response_body = substr($this->response, $header_size);
            $this->response_code = curl_getinfo($this->curl_handle, CURLINFO_HTTP_CODE);
            $this->response_info = curl_getinfo($this->curl_handle);
            // Parse out the headers
            $this->response_headers = explode("\r\n\r\n", trim($this->response_headers));
            $this->response_headers = array_pop($this->response_headers);
            $this->response_headers = explode("\r\n", $this->response_headers);
            array_shift($this->response_headers);
            // Loop through and split up the headers.
            $header_assoc = array();
            foreach ($this->response_headers as $header) {
                $kv = explode(': ', $header);
                //$header_assoc [strtolower ( $kv [0] )] = $kv [1];
                $header_assoc [$kv [0]] = $kv [1];
            }
            // Reset the headers to the appropriate property.
            $this->response_headers = $header_assoc;
            $this->response_headers ['_info'] = $this->response_info;
            $this->response_headers ['_info'] ['method'] = $this->method;
            if ($curl_handle && $response) {
                return new $this->response_class($this->response_headers, $this->response_body, $this->response_code, $this->curl_handle);
            }
        }
        // Return false
        return false;
    }

    /**
     * Sends the request, calling necessary utility functions to update built-in properties.
     *
     * @param boolean $parse (Optional) Whether to parse the response with ResponseCore or not.
     * @return string The resulting unparsed data from the request.
     */
    public function send_request($parse = false)
    {
        $curl_handle = $this->prep_request();
        $this->response = curl_exec($curl_handle);
        if ($this->response === false) {
            throw new RequestCore_Exception('cURL resource: ' . (string)$curl_handle . '; cURL error: ' . curl_error($curl_handle) . ' (' . curl_errno($curl_handle) . ')');
        }
        $parsed_response = $this->process_response($curl_handle, $this->response);
        curl_close($curl_handle);
        if ($parse) {
            return $parsed_response;
        }
        return $this->response;
    }

    /* %******************************************************************************************% */
    // RESPONSE METHODS
    /**
     * Get the HTTP response headers from the request.
     *
     * @param string $header (Optional) A specific header value to return. Defaults to all headers.
     * @return string|array All or selected header values.
     */
    public function get_response_header($header = null)
    {
        if ($header) {
            //			return $this->response_headers [strtolower ( $header )];
            return $this->response_headers [$header];
        }
        return $this->response_headers;
    }

    /**
     * Get the HTTP response body from the request.
     *
     * @return string The response body.
     */
    public function get_response_body()
    {
        return $this->response_body;
    }

    /**
     * Get the HTTP response code from the request.
     *
     * @return string The HTTP response code.
     */
    public function get_response_code()
    {
        return $this->response_code;
    }

}

/**
 * Container for all response-related methods.
 */
class ResponseCore
{

    /**
     * Stores the HTTP header information.
     */
    public $header;

    /**
     * Stores the SimpleXML response.
     */
    public $body;

    /**
     * Stores the HTTP response code.
     */
    public $status;

    /**
     * Constructs a new instance of this class.
     *
     * @param array $header (Required) Associative array of HTTP headers (typically returned by <RequestCore::get_response_header()>).
     * @param string $body (Required) XML-formatted response from AWS.
     * @param integer $status (Optional) HTTP response status code from the request.
     * @return object Contains an <php:array> `header` property (HTTP headers as an associative array), a <php:SimpleXMLElement> or <php:string> `body` property, and an <php:integer> `status` code.
     */
    public function __construct($header, $body, $status = null)
    {
        $this->header = $header;
        $this->body = $body;
        $this->status = $status;
        return $this;
    }

    /**
     * Did we receive the status code we expected?
     *
     * @param integer|array $codes (Optional) The status code(s) to expect. Pass an <php:integer> for a single acceptable value, or an <php:array> of integers for multiple acceptable values.
     * @return boolean Whether we received the expected status code or not.
     */
    public function isOK($codes = array(200, 201, 204, 206))
    {
        if (is_array($codes)) {
            return in_array($this->status, $codes);
        }
        return $this->status === $codes;
    }

}

/**
 * Default RequestCore Exception.
 */
class RequestCore_Exception extends Exception
{

}


/**
 * @desc PCS文件数据接口SDK, 要求PHP运行环境为5.2.0及以上
 * @package  baidu.pcs
 * @author   duanzhenxing(duanzhenxing@baidu.com)
 * @version  2.1.0
 */

/**
 * @desc BaiduPCS类
 */
class BaiduPCS
{

    /**
     * 百度PCS RESTFUL API SERVER调用地址前缀
     * @var array
     */
    private $_pcs_uri_prefixs = array('https' => 'https://pcs.baidu.com/rest/2.0/pcs/');

    private $_accessToken = '';

    /**
     * 初始化accessToken
     * @param string $accessToken
     */
    public function __construct($accessToken)
    {
        $this->_accessToken = $accessToken;
    }

    /**
     * 设置accessToken
     * @param string $_accessToken
     * @return BaiduPCS
     */
    public function setAccessToken($accessToken)
    {
        $this->_accessToken = $accessToken;
        return $this;
    }

    /**
     * 获取accessToken
     * @return string
     */
    public function getAccessToken()
    {
        return $this->_accessToken;
    }

    /**
     * 调用API
     * @param string $apiMethod api方法名
     * @param array || string  $params 请求参数
     * @param string $method HTTP请求类型
     * @param string $headers 附加的HTTP HEADER信息
     * @return string
     */
    private function _baseControl($apiMethod, $params, $method = 'GET', $headers = array())
    {

        $method = strtoupper($method);

        if (is_array($params)) {
            $params = http_build_query($params, '', '&');
        }

        $url = $this->_pcs_uri_prefixs ['https'] . $apiMethod . ($method == 'GET' ? '&' . $params : '');

        $requestCore = new RequestCore ();
        $requestCore->set_request_url($url);

        $requestCore->set_method($method);
        if ($method == 'POST') {
            $requestCore->set_body($params);
        }

        foreach ($headers as $key => $value) {
            $requestCore->add_header($key, $value);
        }

        $requestCore->send_request();
        $result = $requestCore->get_response_body();

        return $result;
    }

    /**
     * 获取当前用户空间配额信息
     * @return string
     */
    public function getQuota()
    {
        $result = $this->_baseControl('quota?method=info' . '&access_token=' . $this->_accessToken, array());
        return $result;
    }

    /**
     * 上传文件
     * 注意：此方法适用于上传不大于2G的单个文件。
     * @param string $fileContent 文件内容字符串
     * @param string $targetPath 上传文件的目标保存路径
     * @param string $fileName 文件名
     * @param string $newFileName 新文件名
     * @param boolean $isCreateSuperFile 是否分片上传
     * @return string
     */
    public function upload($fileContent, $targetPath, $fileName, $newFileName = null, $isCreateSuperFile = FALSE)
    {
        $boundary = md5(time());
        $postContent .= "--" . $boundary . "\r\n";
        $postContent .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$fileName}\"\r\n";
        $postContent .= "Content-Type: application/octet-stream\r\n\r\n";
        $postContent .= $fileContent . "\r\n";
        $postContent .= "--" . $boundary . "\r\n";

        $requestStr = 'file?method=upload&path=' . urlencode($targetPath . (empty ($newFileName) ? $fileName : $newFileName)) . '&access_token=' . $this->_accessToken;

        if ($isCreateSuperFile === TRUE) {
            $requestStr .= '&type=tmpfile';
        }

        $result = $this->_baseControl($requestStr, $postContent, 'POST', array('Content-Type' => 'multipart/form-data; boundary=' . $boundary));
        return $result;
    }

    /**
     * 合并分片上传的文件块
     * 注意：如果本地已有分片的文件块，可以调用upload接口按顺序上传之后，
     * 再调用createSuperFile接口将各文件块合并成文件。（此方法一般适用于超大文件，>2G）
     * @param string $targetPath 上传文件的目标保存路径
     * @param string $fileName 文件名
     * @param array $params 分片文件md5值数组
     * @param string $newFileName 新文件名
     * @return string
     */
    public function createSuperFile($targetPath, $fileName, array $params, $newFileName = null)
    {
        $result = $this->_baseControl('file?method=createsuperfile&path=' . urlencode($targetPath . (empty ($newFileName) ? $fileName : $newFileName)) . '&access_token=' . $this->_accessToken, array('param' => json_encode(array('block_list' => $params))), 'POST');
        return $result;
    }

    /**
     * 下载文件
     * @param string $path 文件路径
     * @return 文件内容
     */
    public function download($path)
    {
        $result = $this->_baseControl('file?method=download' . '&access_token=' . $this->_accessToken, array('path' => $path), 'GET');
        return $result;
    }

    /**
     * 创建文件夹
     * @param string $path 文件路径
     * @return string
     */
    public function makeDirectory($path)
    {
        $result = $this->_baseControl('file?method=mkdir' . '&access_token=' . $this->_accessToken, array('path' => $path), 'POST');
        return $result;
    }

    /**
     * 获取单个文件/目录meta信息
     * @param string $path 文件路径
     * @return string
     */
    public function getMeta($path)
    {
        $result = $this->_baseControl('file?method=meta' . '&access_token=' . $this->_accessToken, array('path' => $path));
        return $result;
    }

    /**
     * 批量获取文件/目录meta信息
     * @param array $paths 文件路径数组
     * @return string
     */
    public function getBatchMeta(array $paths)
    {
        $list = array();
        foreach ($paths as $value) {
            array_push($list, array('path' => $value));
        }
        $list = array('list' => $list);
        $list = json_encode($list);
        $result = $this->_baseControl('file?method=meta' . '&access_token=' . $this->_accessToken, array('param' => $list), 'POST');
        return $result;
    }

    /**
     * 获取指定文件夹下的文件列表
     * @param string $path 文件路径
     * @param string $by 排序字段，缺省根据文件类型排序，time（修改时间），name（文件名），size（大小，注意目录无大小）
     * @param string $order asc或desc，缺省采用降序排序
     * @param string $limit 返回条目控制，参数格式为：n1-n2。返回结果集的[n1, n2)之间的条目，缺省返回所有条目。n1从0开始。
     * @return string
     */
    public function listFiles($path, $by = 'name', $order = 'asc', $limit = '0-9')
    {
        $result = $this->_baseControl('file?method=list' . '&access_token=' . $this->_accessToken, array('path' => $path, 'by' => $by, 'order' => $order, 'limit' => $limit));
        return $result;
    }

    /**
     * 移动单个文件/目录
     * @param string $from 源路径
     * @param string $to 目标路径
     * @return string
     */
    public function moveSingle($from, $to)
    {
        $result = $this->_baseControl('file?method=move' . '&access_token=' . $this->_accessToken, array('from' => $from, 'to' => $to), 'POST');
        return $result;
    }

    /**
     * 批量移动文件/目录
     * @param array $from 源路径数组
     * @param array $to 目标路径数组
     * @return string
     */
    public function moveBatch(array $from, array $to)
    {
        $list = array();
        for ($i = 0; $i < count($from); $i++) {
            array_push($list, array('from' => $from [$i], 'to' => $to [$i]));
        }
        $list = array('list' => $list);
        $list = json_encode($list);
        $result = $this->_baseControl('file?method=move' . '&access_token=' . $this->_accessToken, array('param' => $list), 'POST');
        return $result;
    }

    /**
     * 拷贝单个文件/目录
     * @param string $from 源路径
     * @param string $to 目标路径
     * @return string
     */
    public function copySingle($from, $to)
    {
        $result = $this->_baseControl('file?method=copy' . '&access_token=' . $this->_accessToken, array('from' => $from, 'to' => $to), 'POST');
        return $result;
    }

    /**
     * 批量拷贝文件/目录
     * @param array $from 源路径数组
     * @param array $to 目标路径数组
     * @return string
     */
    public function copyBatch(array $from, array $to)
    {
        $list = array();
        for ($i = 0; $i < count($from); $i++) {
            array_push($list, array('from' => $from [$i], 'to' => $to [$i]));
        }
        $list = array('list' => $list);
        $list = json_encode($list);

        $result = $this->_baseControl('file?method=copy' . '&access_token=' . $this->_accessToken, array('param' => $list), 'POST');
        return $result;
    }

    /**
     * 删除单个文件/目录
     * @param string $path 文件路径
     * @return string
     */
    public function deleteSingle($path)
    {
        $result = $this->_baseControl('file?method=delete' . '&access_token=' . $this->_accessToken, array('path' => $path), 'POST');
        return $result;
    }

    /**
     * 批量删除文件/目录
     * @param array $paths 文件路径数组
     * @return string
     */
    public function deleteBatch(array $paths)
    {
        $list = array();
        foreach ($paths as $value) {
            array_push($list, array('path' => $value));
        }
        $list = array('list' => $list);
        $list = json_encode($list);

        $result = $this->_baseControl('file?method=delete' . '&access_token=' . $this->_accessToken, array('param' => $list), 'POST');
        return $result;
    }

    /**
     * 按文件名搜索文件
     * @param string $path 文件路径
     * @param string $wd 搜索关键字
     * @param int $re 是否递归
     * @return string
     */
    public function search($path, $wd, $re = 1)
    {
        $result = $this->_baseControl('file?method=search' . '&access_token=' . $this->_accessToken, array('path' => $path, 'wd' => $wd, 're' => $re));
        return $result;
    }

    /**
     * 生成缩略图
     * @param string $path 图片路径
     * @param int $width
     * @param int $height
     * @param int32 $quality
     * @return 文件内容
     */
    public function thumbnail($path, $width, $height, $quality = 100)
    {
        $result = $this->_baseControl('thumbnail?method=generate' . '&access_token=' . $this->_accessToken, array('path' => $path, 'width' => $width, 'height' => $height, 'quality' => $quality), 'GET');
        return $result;
    }

    /**
     * 文件增量更新操作查询
     * @param string $cursor 用于标记更新断点。首次调用cursor=null；非首次调用，使用最后一次调用diff接口的返回结果中的cursor
     * @return string
     */
    public function diff($cursor)
    {
        $result = $this->_baseControl('file?method=diff' . '&access_token=' . $this->_accessToken, array('cursor' => $cursor));
        return $result;
    }

    /**
     * 为当前用户下载一个流式文件
     * @param string $path
     * @return 文件内容
     */
    public function downloadStream($path)
    {
        $result = $this->_baseControl('stream?method=download' . '&access_token=' . $this->_accessToken, array('path' => $path));
        return $result;
    }

    /**
     * 获取应用目录下所有流式文件列表
     * @param string $type 取值为video，audio，image，doc四种
     * @param string $start
     * @param string $limit
     * @param string $filterPath
     * @return string
     */
    public function listStream($type, $start = 0, $limit = '1000', $filterPath = '')
    {
        $result = $this->_baseControl('stream?method=list' . '&access_token=' . $this->_accessToken, array('type' => $type, 'start' => $start, 'limit' => $limit, 'filter_path' => $filterPath));
        return $result;
    }

    /**
     * 为当前用户进行视频转码并实现在线实时观看
     * @param string $path 格式必须为m3u8,m3u,asf,avi,flv,gif,mkv,mov,mp4,m4a,3gp,3g2,mj2,mpeg,ts,rm,rmvb,webm
     * @param string $type M3U8_320_240、M3U8_480_224、M3U8_480_360、M3U8_640_480和M3U8_854_480
     * @return 文件播放列表URL
     */
    public function streaming($path, $type)
    {
        $result = $this->_baseControl('file?method=streaming' . '&access_token=' . $this->_accessToken, array('path' => $path, 'type' => $type));
        return $result;
    }

    /**
     * 秒传一个文件
     * 注意事项：
     * 1. 被秒传文件必须大于256KB（即 256*1024 B）
     * 2. 校验段为文件的前256KB，秒传接口需要提供待秒传文件CRC32，校验段的MD5
     * @param string $path
     * @param int $contentLength
     * @param string $contentMd5
     * @param string $sliceMd5
     * @param string $contentCrc32
     * @return string
     */
    public function cloudMatch($path, $contentLength, $contentMd5, $sliceMd5, $contentCrc32)
    {
        $result = $this->_baseControl('file?method=rapidupload' . '&access_token=' . $this->_accessToken, array('path' => $path, 'content-length' => $contentLength, 'content-md5' => $contentMd5, 'slice-md5' => $sliceMd5, 'content-crc32' => $contentCrc32));
        return $result;
    }

    /**
     * 添加离线下载任务
     * @param string $savePath 离线下载数据在PCS中存放的路径
     * @param string $sourceUrl 要下载数据的URL
     * @param int $rateLimit 下载速度， byte/s
     * @param int $timeout 下载的超时时间
     * @param string $callback 回调URL，回调过程不处理302跳转
     * @param int $expires 请求失效时间
     * @return string
     */
    public function addOfflineDownloadTask($savePath, $sourceUrl, $rateLimit = '', $timeout = 3600, $callback = '', $expires = '')
    {
        $result = $this->_baseControl('services/cloud_dl?method=add_task' . '&access_token=' . $this->_accessToken, array('save_path' => $savePath, 'source_url' => $sourceUrl, 'rate_limit' => $rateLimit, 'timeout' => $timeout, 'callback' => $callback), 'POST');
        return $result;
    }

    /**
     * 精确查询离线下载任务
     * @param string $taskIds 要查询的task_id列表，如：'1,2,3,4'
     * @param int $expires 请求失效时间
     * @param int $opType 0：查任务信息，1：查进度信息
     * @return string
     */
    public function queryOfflineDownloadTask($taskIds, $opType = 1, $expires = '')
    {
        $result = $this->_baseControl('services/cloud_dl?method=query_task' . '&access_token=' . $this->_accessToken, array('task_ids' => $taskIds, 'op_type' => $opType));
        return $result;
    }

    /**
     * 查询离线下载任务列表
     * @param int $start 起始位置
     * @param int $limit 返回多少个
     * @param int $asc 按开始时间升序 or 降序
     * @param string $sourceURL 目标地址URL
     * @param string $savePath 存放路径
     * @param string $createTime STARTTIMESTMAP, ENDTIMESTAMP, 如果不限制下限可写成"NULL, 1235", 不限制上线，可写成'1234,NULL'
     * @param int $status 任务状态过滤
     * @param int $needTaskInfo 是否需要返回任务信息
     * @param int $expires 请求失效时间
     * @return string
     */
    public function listOfflineDownloadTask($start = 0, $limit = 10, $asc = 0, $sourceURL = '', $savePath = '', $createTime = '', $status = 1, $needTaskInfo = 1, $expires = '')
    {
        $result = $this->_baseControl('services/cloud_dl?method=list_task' . '&access_token=' . $this->_accessToken,
            array('start' => $start, 'limit' => $limit, 'asc' => $asc, 'source_url' => $sourceURL,
                'save_path' => $savePath, 'create_time' => $createTime, 'status' => $status, 'need_task_info' => $needTaskInfo), 'POST');
        return $result;
    }

    /**
     * 取消离线下载任务
     * @param int $taskId 要取消的任务Id
     * @param int $expires 请求失效时间
     * @return string
     */
    public function cancelOfflineDownloadTask($taskId, $expires = '')
    {
        $result = $this->_baseControl('services/cloud_dl?method=cancel_task' . '&access_token=' . $this->_accessToken, array('task_id' => $taskId));
        return $result;
    }
}

?>
