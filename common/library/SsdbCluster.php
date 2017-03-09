<?php

/**
 * Ssdb 客户端，支持 Master/Slave  支持多主多从 
 *        
 */

class SsdbCluster
{
    
    // 是否使用 M/S 的读写集群方案
    private $_is_use_cluster = false;
    
    // master 句柄标记
    private $_mn = 0;
    
    // slave 句柄标记
    private $_sn = 0;
    
    // 服务器连接句柄
    private $_link_handle = array(
        'master' => array() , // 可以有多台 master
        'slave' => array() // 可以有多台 slave
    );

    /**
     * 构造函数
     *
     * @param array $config  Ssdb服务器配置    
     * @param boolean $is_use_cluster  是否采用 M/S 方案
     *           
     */
    public function __construct($config = array(), $is_use_cluster = false, $debug = false)
    {
        $this->_is_use_cluster = $is_use_cluster;
        if (empty($config)) {
            throw new Exception("No corresponding configuration item!");
        }
        
        $config_keys_arr = array_keys($this->_link_handle);
        //没有主库配置
        if (!array_key_exists($config_keys_arr[0], $config)) {
           throw new Exception("no master configuration item!");
        }
        
        //设置了使用主从，但没有从库配置
        if (!array_key_exists($config_keys_arr[1], $config) && $is_use_cluster) {
            throw new Exception("use master/slave way, but no slave configuration item!");
        }
        
        foreach ($config as $key => $val) {
              $this->create_ssdb_pool($key, $val, $is_use_cluster);
        }
        
        if ($debug){
            var_dump($this->_mn, $this->_sn);
            var_dump($this->_link_handle);
        }
        
    }
    
    /**
     * 创建连接对象
     * 
     * @param string $ssdb_type 链接类型 master/slave
     * @param array $ssdb_type_config 链接配置 主机/端口 等
     * @param boolean $is_use_cluster  true-使用主从  false-不使用主从
     * @throws Exception
     */
    private function create_ssdb_pool($ssdb_type, $ssdb_type_config, $is_use_cluster)
    {
        if (! empty($ssdb_type_config)) {
            $i = 0;
            foreach ($ssdb_type_config as $val) {
                $this->_link_handle[$ssdb_type][$i] = new SSDB($val['host'], $val['port'],$val['timeout']);
                // 链接创建对象后先ping服务器是否真实的可用
                try {
                    $this->_link_handle[$ssdb_type][$i]->ping();
                } catch (Exception $e) {
                    continue;
                }
                $i ++;
            }
            
            if ($ssdb_type == 'master') {
                 $this->_mn = $i;
            } elseif ($ssdb_type == 'slave') {
                 $this->_sn = $i;
            }
        } else { // 没有配置，抛出异常 滚粗
            if ($ssdb_type == 'master') {
                throw new Exception("no master configuration item!");
            } elseif ($ssdb_type == 'slave' && $is_use_cluster) {
                throw new Exception("use master/slave way, but no slave configuration item!");
            }
        }
    }
    
    /**
     * 关闭连接
     *
     * @param int $flag 关闭选择 0:关闭 Master 1:关闭 Slave 2:关闭所有
     *            
     * @return boolean
     */
    public function close($flag = 2)
    {
        switch ($flag) {
            // 关闭 Master
            case 0:
                
                $this->_link_handle['master']->close();
                break;
            // 关闭 Slave
            case 1:
                for ($i = 0; $i < $this->_sn; ++ $i) {
                    $this->_link_handle['slave'][$i]->close();
                }
                break;
            // 关闭所有
            case 2:
                $this->_link_handle['master']->close();
                for ($i = 0; $i < $this->_sn; ++ $i) {
                    $this->_link_handle['slave'][$i]->close();
                }
                break;
        }
        return true;
    }

    /**
     * 获取数据库记录数
     *
     * @return int $count；
     */
    public function count()
    {
        $count = $this->get_ssdb()->dbSize();
        return $count;
    }

    /**
     * *=[string]=**
     */
    
    /**
     *
     * @param string $key 组存key
     * @param string $value 缓存值
     * @param string $expire 过期时间 豪秒
     *            
     * @return boolean 失败返回 false, 成功返回 true
     */
    public function set($key, $value, $expire = 0)
    {
        return $this->get_ssdb()->set($key, $value, $expire);
    }

    /**
     *
     * @param string $key  缓存key
     *           
     * @return string || boolean 失败返回 false, 成功返回字符串
     */
    public function get($key)
    {
        return $this->get_ssdb(false)->get($key);
    }

    /**
     * 自增操作
     *
     * @param string $key            
     * @param number $incr_num            
     * @return number | boolean
     */
    public function incr($key, $incr_num = 1)
    {
        return $this->get_ssdb()->incr($key, $incr_num);
    }

    /**
     *
     * @param string $key 缓存key
     *            
     * @return number | boolean
     */
    public function del($key)
    {
        return $this->get_ssdb()->del($key);
    }

    /**
     *一次存储多个key-value 对儿
     *
     * @param array $data 数组key－value对
     *            
     * @return number | boolean
     */
    public function multi_set($data)
    {
        return $this->get_ssdb()->multi_set($data);
    }

    /**
     *删除数组$data 中指定的一些key的对应值
     *
     * @param array $data  数组key
     *            
     * @return number | boolean
     */
    public function multi_del($data)
    {
        return $this->get_ssdb()->multi_del($data);
    }

    /**
     *获取数组$data 中指定的一些key的对应值
     *
     * @param array $data  数组key
     *            
     * @return array | boolean
     */
    public function multi_get($data)
    {
        return $this->get_ssdb(false)->multi_get($data);
    }

    /**
     *判断一个key是否存在
     *
     * @param string $key 缓存key
     *            
     * @return boolean true/false
     */
    public function exists($key)
    {
        return $this->get_ssdb(false)->exists($key);
    }

    /**
     * *=[hash]=**
     */
    
    /**
     *保存一条指定$hash_name和$key纪录
     *
     * @param string $hash_name            
     * @param string $key            
     * @param string $val            
     * @return boolean true/false
     */
    public function hset($hash_name, $key, $val)
    {
        return $this->get_ssdb()->hset($hash_name, $key, $val);
    }

    /**
     *获取一条指定$hash_name和$key纪录
     *
     * @param string $hash_name            
     * @param string $key            
     * @return string | false
     */
    public function hget($hash_name, $key)
    {
        $res = $this->get_ssdb(false)->hget($hash_name, $key);
        if(empty($res)){
            return false;
        }
        return $res;
    }

    /**
     *删除一条指定$hash_name和$key纪录
     *
     * @param string $hash_name            
     * @param string $key            
     * @return boolean true/false
     */
    public function hdel($hash_name, $key)
    {
        return $this->get_ssdb()->hdel($hash_name, $key);
    }

    /**
     * hash结构 下的自增长
     *
     * @param string $hash_name            
     * @param string $key            
     * @param number $incr_num  默认增加步长为1
     *            
     */
    public function hincr($hash_name, $key, $incr_num = 1)
    {
        return $this->get_ssdb()->hincr($hash_name, $key, $incr_num);
    }

    /**
     *判断hash结构下的key是否存在
     *
     * @param string $hash_key            
     * @param string $key            
     * @return boolean true/false
     */
    public function hexists($hash_name, $key)
    {
        return $this->get_ssdb(false)->hexists($hash_name, $key);
    }

    /**
     *获取指定$hash_name 的纪录数
     *
     * @param string $hash_name            
     *
     * @return number
     */
    public function hsize($hash_name)
    {
        return $this->get_ssdb(false)->hsize($hash_name);
    }

    /**
     *获取匹配 $hash_name_start 和 $hash_name_end区间的 hash_name部分
     *
     * @param string $hash_name_start            
     * @param string $hash_name_end            
     * @param int $limit            
     * @return array | false
     */
    public function hlist($hash_name_start = '', $hash_name_end = '', $limit)
    {
        $res = $this->get_ssdb(false)->hlist($hash_name_start, $hash_name_end, $limit);
        if(empty($res)){
            return false;
        }
        return $res;
    }

    /**
     *倒序获取匹配 $hash_name_start 和 $hash_name_end区间的 hash_name部分
     *
     * @param string $hash_name_start            
     * @param string $hash_name_end            
     * @param int $limit            
     * @return array | false
     */
    public function hrlist($hash_name_start = '', $hash_name_end = '', $limit)
    {
        $res = $this->get_ssdb(false)->hrlist($hash_name_start, $hash_name_end, $limit);
        if(empty($res)){
            return false;
        }
        return $res;
    }

    /**
     *获取指定$hash_name 中匹配 $key_start和$key_end 的所有key
     *
     * @param string $hash_name            
     * @param string $key_start            
     * @param string $key_end            
     * @param int $limit            
     * @return array | false
     */
    public function hkeys($hash_name, $key_start = '', $key_end = '', $limit)
    {
        $res = $this->get_ssdb(false)->hkeys($hash_name, $key_start, $key_end, $limit);
        if(empty($res)){
            return false;
        }
        return $res;
    }

    /**
     *获取指定$hash_name 的所有数据
     *
     * @param string $hash_name            
     * @return array | false
     */
    public function hgetall($hash_name)
    {
        $res = $this->get_ssdb(false)->hgetall($hash_name);
        if(empty($res)){
            return false;
        }
        return $res;
        
    }

    /**
     *清空$hash_name 指定的hash结构
     *
     * @param string $hash_name            
     * @return number
     */
    public function hclear($hash_name)
    {
        return $this->get_ssdb()->hclear($hash_name);
    }

    /**
     * 列出hash中key处于区间[key_start, key_end]的key-value数组
     * 没有结果返回空数组
     * @param string $hash_name            
     * @param string $key_start  可传空字符串表示所有的key        
     * @param string $key_end  可传空字符串表示所有的key        
     * @param number $limit            
     * @return array | false
     */
    public function hscan($hash_name, $key_start = '', $key_end = '', $limit = 10)
    {
        $res =  $this->get_ssdb(false)->hscan($hash_name, $key_start, $key_end, $limit);
        if(empty($res)){
            return false;
        }
        return $res;
    }

    /**
     * 倒序列出hash中key处于区间[key_start, key_end]的key-value数组
     *
     * @param string $hash_name            
     * @param string $key_start            
     * @param string $key_end            
     * @param number $limit            
     * @return array | false
     */
    public function hrscan($hash_name, $key_start = '', $key_end = '', $limit = 10)
    {
        $res =  $this->get_ssdb(false)->hrscan($hash_name, $key_start, $key_end, $limit);
        if(empty($res)){
            return false;
        }
        return $res;
        
    }

    /**
     *保存以$hash_name做name的，$data数组做数据的 hash结构
     *重复的$data数据不重复插入 
     *
     *
     * @param string $hash_name            
     * @param array $data key-value 对儿        
     * @return number   $hash_name中插入的条数  重复的$data数据返回0
     */
    public function multi_hset($hash_name, $data = array())
    {
        return $this->get_ssdb()->multi_hset($hash_name, $data);
    }

    /**
     *获取hash结构$hash_name中 以指定数组$data中元素为key的数据
     *如果某个key不存在, 则它不会出现在返回数组中 
     *
     * @param string $hash_name            
     * @param array $data  key元素    
     * @return array | false
     */
    public function multi_hget($hash_name, $data)
    {
        $res = $this->get_ssdb(false)->multi_hget($hash_name, $data);
        if(empty($res)){
            return false;
        }
        return $res;
    }

    /**
     *删除hash结构$hash_name中 以指定数组$data中元素为key的数据
     *
     * @param string $hash_name            
     * @param array $data  key元素
     * @return number 删除成功的条数
     */
    public function multi_hdel($hash_name, $data = array())
    {
        return $this->get_ssdb()->multi_hdel($hash_name, $data);
    }

    
    /**
     * *=[list]=**
     */
    
    /**
     * 返回队列中元素个数
     * 
     * @param string $queue_name
     * @return number | 0
     */
    public function qsize($queue_name)
    {
        return $this->get_ssdb(false)->qsize($queue_name);
    }
    
    
    /**
     * 正序返回队列$queue_name 中匹配 $key_start 和 $key_start 指定条数 $limit的数据
     * 
     * @param string $queue_name
     * @param string $key_start
     * @param string $key_end
     * @param number $limit
     * @return array | false
     */
    public function qlist($queue_name, $key_start = '', $key_end = '', $limit = 10)
    {
        $res = $this->get_ssdb(false)->qlist($queue_name, $key_start, $key_end, $limit);
        if(empty($res)){
            return false;
        }
        return $res;
    }
    
    
    /**
     * 倒序返回队列$queue_name 中匹配 $key_start 和 $key_start 指定条数 $limit的数据
     * 
     * @param string $queue_name
     * @param string $key_start
     * @param string $key_end
     * @param number $limit
     * @return  array | false
     */
    public function qrlist($queue_name, $key_start = '', $key_end = '', $limit = 10)
    {
        $res = $this->get_ssdb(false)->qrlist($queue_name, $key_start, $key_end, $limit);
        
        if(empty($res)){
            return false;
        }
        return $res;
        
    }
    
    /**
     * 清空队列
     * 
     * @param string $queue_name
     * @return  number
     */
    public function qclear($queue_name)
    {
        return $this->get_ssdb()->qclear($queue_name);
    }
 
    
    /**
     * 从队列尾部压入一个或者多个元素
     * 
     * @param string $queue_name
     * @param mixed $data   字符串/数字/数组
     * @return number
     * @eg 
     * $ssdb->qpush('queue', 'zhangsan');
     * $ssdb->qpush('queue', array('lisi', 'wangwu'));  
     */
    public function qpush($queue_name, $data) //qpush_back
    {
        return $this->get_ssdb()->qpush($queue_name, $data);
    }
    
    /**
     *  qpush的别名
     * 
     * @param string $queue_name
     * @param mixed $data   字符串/数字/数组
     * @return number
     * @eg 
     * $ssdb->qpush('queue', 'zhangsan');
     * $ssdb->qpush('queue', array('lisi', 'wangwu'));  
     */
    public function qpush_back($queue_name, $data) //qpush_back
    {
        return $this->get_ssdb()->qpush_back($queue_name, $data);
    }

    /**
     * 从队列头部弹出一个或者多个元素
     *
     * @param string $queue_name            
     * @param number $size  条数
     *           
     * @return string or array
     */
    public function qpop($queue_name, $size = 1) // qpop_back
    {
        return $this->get_ssdb()->qpop($queue_name, $size);
    }

    /**
     * qpop的别名
     *
     * @param string $queue_name            
     * @param number $size 条数
     *            
     * @return string or array
     */
    public function qpop_back($queue_name, $size = 1) // qpop_back
    {
        return $this->get_ssdb()->qpop_back($queue_name, $size);
    }
    
 
    /**
     * 从队列头部添加一个或多个元素
     * 
     * @param string $queue_name
     * @param mixed $data  字符串/数字/数组
     * @return  number
     * @eg
     * $ssdb->qpush_front('queue', 'zhangsan');
     * $ssdb->qpush_front('queue', array('lisi', 'wangwu'));
     */
    public function qpush_front($queue_name, $data)
    {
        return $this->get_ssdb()->qpush_front($queue_name, $data);
    }
    
    /**
     * 从队列尾部删除一个或者多个元素
     * 
     * @param string $queue_name
     * @param number $size  条数
     * @return string or array
     */
    public function qpop_front($queue_name, $size =1)
    {
        return $this->get_ssdb()->qpop_front($queue_name, $size);
    }
    
    
    /**
     * 返回队列的第一个元素
     * 
     * @param string $queue_name
     * @return string or NULL
     */
    public function qfront($queue_name)
    {
        
        return $this->get_ssdb(false)->qfront($queue_name);
    }
    
    /**
     * 返回队列的最后一个元素
     * 
     * @param string $queue_name
     * @return string or NULL
     */
    public function qback($queue_name)
    {
        return $this->get_ssdb(false)->qback($queue_name);
    }
    
    /**
     * 删除队列中$offset指定的位置的一条数据
     * 
     * @param string $queue_name
     * @param number $offset   队列位置 默认从0开始
     * @return string or NULL
     */
    public function qget($queue_name, $offset = 0)
    {
       return $this->get_ssdb(false)->qget($queue_name, $offset);
    }
    
   
    /**
     * 在队列中$offset指定的位置插入一条数据
     * 
     * @param string $queue_name
     * @param number $offset  队列位置 默认从0开始
     * @param string $data
     * @return boolean true/false
     */
    public function qset($queue_name, $offset = 0, $data)
    {
        return $this->get_ssdb()->qset($queue_name, $offset, $data);
    }
    
    
    /**
     * 指定范围位置取值
     * 
     * @param string $queue_name
     * @param number $offset
     * @param number $limit
     * @return array | false
     */
    public function qrange($queue_name, $offset = 0, $limit = 10)
    {
        $res = $this->get_ssdb(false)->qrange($queue_name, $offset, $limit);
        if(empty($res)){
            return false;
        }
        return $res;
    }
    
    /**
     * 队列首删除指定 $size 条数据
     * 
     * @param string $queue_name
     * @param number $size 条数
     * @return number 返回删除条数
     */
    public function qtrim_front($queue_name, $size = 1)
    {
        return $this->get_ssdb()->qtrim_front($queue_name, $size);
    }
    
    
    /**
     * 队列尾删除制定 $size 条数据
     * 
     * @param string $queue_name
     * @param number $size
     * @return number 返回删除条数
     */
    public function qtrim_back($queue_name, $size = 1)
    {
        return $this->get_ssdb()->qtrim_front($queue_name, $size);
    }
    
    
    /**
     * *=[set]=**
     * zset
     * zget
     * zincr
     * zsize
     * zlist 
     * zrlist
     * zexists
     * zkeys
     * zscan 
     * zrscan
     * zpop_front
     * zpop_back
     */
    
    
    /**
     * *=[geo] 地理位置=**
     */
    // geo_set
    // geo_get
    // geo_neighbour
    // geo_del
    // geo_clear
    
    
    public function __call($name, $arguments){
        $arg_str = json_encode($arguments);
        throw new Exception("method [{$name}] is not fonud, arguments is [$arg_str]!");
    }
    
    /**
     * ssdb认证密码
     *
     * @param string $password            
     */
    private function auth($password)
    {
        if (empty($password)) {
            return false;
        }
        $this->get_ssdb()->auth($password);
        for ($i = 0; $i < $this->_sn; ++ $i) {
            $this->_link_handle['slave'][$i]->auth($password);
        }
    }

    /**
     * 得到 Ssdb 原始对象  
     *
     * @param boolean $isMaster  返回服务器的类型 true:返回Master false:返回Slave (强制主库)
     * @return ssdb object
     */
    private function get_ssdb($isMaster = true)
    {
        if ($this->_is_use_cluster && !$isMaster) {//使用了集群且非强制主库 
            return $this->_get_slave_ssdb();
        } else {//没有使用集群 或者 强制主库
            return $this->_get_master_ssdb();
        }
    }

    /**
     * 随机 HASH 得到 Ssdb Slave 服务器句柄
     *
     * @return ssdb object
     */
    private function _get_slave_ssdb()
    {
        // 就一台 Slave 机直接返回
        if ($this->_sn == 1) {
            return $this->_link_handle['slave'][0];
        }
        // 随机 Hash 得到 Slave 的句柄
        $hash = $this->_hash_id(mt_rand(), $this->_sn);
        return $this->_link_handle['slave'][$hash];
    }
    
    /**
     * 随机 HASH 得到 Ssdb master 服务器句柄
     *
     * @return ssdb object
     */
    private function _get_master_ssdb()
    {
        // 就一台 Slave 机直接返回
        if ($this->_mn == 1) {
            return $this->_link_handle['master'][0];
        }
        // 随机 Hash 得到 Slave 的句柄
        $hash = $this->_hash_id(mt_rand(), $this->_mn);
        return $this->_link_handle['master'][$hash];
    }

    /**
     * 根据ID得到 hash 后 0～m-1 之间的值
     *
     * @param string $id            
     * @param int $m            
     * @return int
     */
    private function _hash_id($id, $m = 10)
    {
        // 把字符串K转换为 0～m-1 之间的一个值作为对应记录的散列地址
        $k = md5($id);
        $l = strlen($k);
        $b = bin2hex($k);
        $h = 0;
        for ($i = 0; $i < $l; $i ++) {
            // 相加模式HASH
            $h += substr($b, $i * 2, 2);
        }
        $hash = ($h * 1) % $m;
        return $hash;
    }
}// End Class       