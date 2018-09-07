<?php
/**
 * Created by PhpStorm.
 * User: shiwenyuan
 * Date: 2018/9/7 13341007105@163.com
 * Time: 下午12:18
 */

namespace RedisProxy;

use RedisProxy\Error\SysErrors;
use Redis;
use XdpLog\MeLog;
use RedisException;

/**
 * Class RedisConnector
 * @package RedisProxy
 */
class RedisConnector
{

    /**
     * log格式
     */
    const W_LOG = "NewRedisProxy method[%s] errno[%d] errMsg[%s] host[%s] port[%d] params[%s]";
    /**
     * redis client
     * @var null|Redis
     */
    private $_cache = null;
    /**
     * 主机名称
     * @var string
     */
    private $_host = '';
    /**
     * 端口号
     * @var int|string
     */
    private $_port = '';
    /**
     * 密码
     * @var string
     */
    private $_pwd = '';

    /**
     * 错误信息
     * @var null
     */
    private $errMsg = null;

    /**
     * 实例化数组
     * @var array
     */
    private static $inst = array();

    /**
     * 默认配置
     * @var array
     */
    private static $config = [
        'host'       => '127.0.0.1',
        'port'       => 6379,
        'prefix'     => 'atzcl',
    ];

    /**
     * 获取链接
     * @param array $config
     * @return mixed|RedisConnector
     */
    public static function getInstance($config = [])
    {
        $config = array_merge(self::$config, $config);
        $host = $config['host']??null;
        $port = $config['port']??null;
        $pwd = $config['pwd']??null;

        $tag = $host . $port;

        if (isset(self::$inst[$tag])) {
            return self::$inst[$tag];
        }

        $redis = new self($host, $port, $pwd);

        self::$inst[$tag] = $redis;
        return $redis;
    }

    /**
     * RedisConnector constructor.
     * @param $host
     * @param int $port
     * @param string $pwd
     */
    private function __construct($host, $port = 6379, $pwd = '')
    {
        $this->_host = $host;
        $this->_port = $port;
        $this->_pwd = $pwd;
        $this->_cache = new Redis();
        if ($this->_cache->pconnect($host, $port) === false) {
            MeLog::fatal(sprintf(self::W_LOG, 'connect', SysErrors::E_CACHED_CONNECTION_FAILURE, $this->_cache->getLastError(), $this->_host, $this->_port, 'pwd:' . $pwd));
            exit(0);
            //return SysErrors::E_CACHED_CONNECTION_FAILURE;
        }

        if (!empty($pwd)) {
            if ($this->_cache->auth($this->_pwd) === false) {
                MeLog::fatal(sprintf(self::W_LOG, 'auth', SysErrors::E_CACHED_AUTH_FAILURE, $this->_cache->getLastError(), $this->_host, $this->_port, 'pwd:' . $pwd));
                exit(0);
            }
        }
    }


    /**
     * 获取链接
     * @return null|Redis
     */
    public function getRedisInstance()
    {
        return $this->_cache;
    }


    /**
     * 重新获取链接
     */
    private function redis()
    {
        try {
            $this->_cache = new Redis();
            $this->_cache->pconnect($this->_host, $this->_port);
            if (!empty($this->_pwd)) {
                $this->_cache->auth($this->_pwd);
            }
            $this->_cache->ping();
        } catch (RedisException $redisException) {
            MeLog::fatal(sprintf(self::W_LOG, 'connect', SysErrors::E_CACHED_CONNECTION_FAILURE, $redisException->getMessage(), $this->_host, $this->_port, 'pwd:' . $this->_pwd));
            sleep(1);
            $this->redis();
        }
    }


    /**
     * 获取一个key的value
     * @param $key
     * @return bool|int|string
     */
    public function get($key)
    {
        if (empty($key)) {
            return SysErrors::E_CACHED_INVALID_ARGUMENTS;
        }

        try {
            $res = $this->_cache->get($key);
        } catch (RedisException $redisException) {
            $this->errMsg = "redis_error: redis is down or overload cmd[get] key[$key] " .
                "host[{$this->_host}] port[{$this->_port}] errno[{$redisException->getCode()}] errMsg[{$redisException->getMessage()}]";
            MeLog::fatal($this->errMsg);
            return SysErrors::E_CACHED_FAILURE;
        }

        if (false === $res) { // data not existed
            $this->errMsg = "redis_error: not exist cmd[get] key[$key] host[" .
                "{$this->_host}] port[{$this->_port}] errMsg[{$this->_cache->getLastError()}]";
            MeLog::warning($this->errMsg);
        }

        return $res;
    }


    /**
     * @param $key
     * @param $val
     * @param string $opt
     * @param int $ttl
     * @return bool|int
     */
    public function set($key, $val, $opt = '', $ttl = 0)
    {
        try {
            if ($ttl && empty($opt)) {
                $res = $this->_cache->set($key, $val, $ttl);
            } elseif ($ttl && !empty($opt)) {
                $res = $this->_cache->set($key, $val, array($opt, 'EX' => $ttl));
            } else {
                $res = $this->_cache->set($key, $val);
            }
        } catch (RedisException $redisException) {
            $this->errMsg = "redis_error: redis is down or overload cmd[set] key[$key] " .
                "host[{$this->_host}] port[{$this->_port}] errno[{$redisException->getCode()}] errMsg[{$redisException->getMessage()}]";
            MeLog::fatal($this->errMsg);
            return SysErrors::E_CACHED_FAILURE;
        }

        if (false === $res) { // 数据写失败
            $this->errMsg = "redis_error: failed cmd[set] key[$key] host[" .
                "{$this->_host}] port[{$this->_port}] errMsg[{$this->_cache->getLastError()}]";
            MeLog::warning($this->errMsg);
            return SysErrors::E_CACHED_NOTSTORED;
        }

        return $res;
    }


    /**
     * 当key存在则不做任何操作 当key不存在则set key value
     * redis> SETNX mykey "Hello"
     * (integer) 1
     * redis> SETNX mykey "World"
     * (integer) 0
     * redis> GET mykey
     * "Hello"
     * redis>
     * @param $key
     * @param $val
     * @return bool|int
     */
    public function setnx($key, $val)
    {
        try {
            $this->_cache->setnx($key, $val);
        } catch (RedisException $redisException) {
            $this->errMsg = "redis_error: redis is down or overload cmd[set] key[$key] " .
                "host[{$this->_host}] port[{$this->_port}] errno[{$redisException->getCode()}] errMsg[{$redisException->getMessage()}]";
            MeLog::fatal($this->errMsg);
            return SysErrors::E_CACHED_FAILURE;
        }

        return true;
    }


    /**
     * 如果key存在则修改  不存在则插入
     * redis> INCR mycounter
     * (integer) 1
     * redis> GETSET mycounter "0"
     * "1"
     * redis> GET mycounter
     * "0"
     * @param $key
     * @param $val
     * @return int|string
     */
    public function getSet($key, $val)
    {
        try {
            $this->_cache->getSet($key, $val);
        } catch (RedisException $redisException) {
            $this->errMsg = "redis_error: redis is down or overload cmd[set] key[$key] " .
                "host[{$this->_host}] port[{$this->_port}] errno[{$redisException->getCode()}] errMsg[{$redisException->getMessage()}]";
            MeLog::fatal($this->errMsg);
            return SysErrors::E_CACHED_FAILURE;
        }
        return true;
    }


    /**
     * 自增KEY 增加VALUE
     * redis> SET mykey "10"
     * "OK"
     * redis> INCRBY mykey 5
     * (integer) 15
     * redis>
     * @param $key
     * @param int $step
     * @return bool|float|int
     */
    public function incrBy($key, $step = 1)
    {
        if (empty($key)) {
            return false;
        }

        $cmd = '';

        try {
            $type = gettype($step);
            if ($type == 'integer') {
                $cmd = 'incrBy';
                $res = $this->_cache->incrBy($key, $step);
            } elseif ($type == 'double') {
                $cmd = 'incrByFloat';
                $res = $this->_cache->incrByFloat($key, $step);
            } else {
                $this->errMsg = "redis_error: cmd[incrBy] invalid step type[{$step}]";
                return false;
            }
        } catch (RedisException $redisException) {
            $this->errMsg = "redis_error: redis is down or overload cmd[{$cmd}] key[{$key}] " .
                "host[{$this->_host}] port[{$this->_port}] errno[{$redisException->getCode()}] errMsg[{$redisException->getMessage()}]";
            MeLog::fatal($this->errMsg);
            return false;
        }

        if (false === $res) {
            $this->errMsg = "redis_error: failed or unsupported value type cmd[{$cmd}] " .
                "key[{$key}] step[{$step}] host[" .
                "{$this->_host}] port[{$this->_port}] errMsg[{$this->_cache->getLastError()}]";
            MeLog::warning($this->errMsg);
        }
        return $res;
    }


    /**
     * 自降key 降value
     * redis> SET mykey "10"
     * "OK"
     * redis> DECRBY mykey 3
     * (integer) 7
     * redis>
     * @param $key
     * @param $step
     * @return bool|int
     */
    public function decrBy($key, $step)
    {
        if (empty($key)) {
            return false;
        }

        try {
            $res = $this->_cache->decr($key, $step);
        } catch (RedisException $redisException) {
            $this->errMsg = "redis_error: redis is down or overload cmd[{decr}] key[{$key}] " .
                "host[{$this->_host}] port[{$this->_port}] errno[{$redisException->getCode()}] errMsg[{$redisException->getMessage()}]";
            MeLog::fatal($this->errMsg);
            return false;
        }


        if (false === $res) {
            $this->errMsg = "redis_error: failed cmd[decrBy] key[$key] host[" .
                "{$this->_host}] port[{$this->_port}]";
        }

        return $res;
    }


    /**
     * 自增hash key 增加value
     * redis> HSET myhash field 5
     * (integer) 1
     * redis> HINCRBY myhash field 1
     * (integer) 6
     * redis> HINCRBY myhash field -1
     * (integer) 5
     * redis> HINCRBY myhash field -10
     * (integer) -5
     * redis>
     * @param $key
     * @param $field
     * @param $step
     * @return bool|float|int
     */
    public function hIncrBy($key, $field, $step)
    {
        if (empty($key) || empty($field) || empty($step)) {
            return false;
        }

        $cmd = '';

        try {
            $type = gettype($step);
            if ($type == 'integer') {
                $cmd = 'hIncrBy';
                $res = $this->_cache->hIncrBy($key, $field, $step);
            } elseif ($type == 'double') {
                $cmd = 'hIncrByFloat';
                $res = $this->_cache->hIncrByFloat($key, $field, $step);
            } else {
                $this->errMsg = "redis_error: cmd[{$cmd}] invalid step type[{$step}]";
                MeLog::warning($this->errMsg);
                return false;
            }
        } catch (RedisException $redisException) {
            $this->errMsg = "redis_error: redis is down or overload cmd[{$cmd}] key[{$key}] " .
                "host[{$this->_host}] port[{$this->_port}] errno[{$redisException->getCode()}] errMsg[{$redisException->getMessage()}]";
            MeLog::fatal($this->errMsg);
            return false;
        }

        if (false === $res) {
            $this->errMsg = "redis_error: failed or unsupported value type cmd[{$cmd}] " .
                "key[{$key}] step[{$step}] host[" .
                "{$this->_host}] port[{$this->_port}] errMsg[{$this->_cache->getLastError()}]";
            MeLog::warning($this->errMsg);
        }

        return $res;
    }


    /**
     * 给某个指定hash field赋值
     * redis> HSET myhash field1 "Hello"
     * (integer) 1
     * redis> HGET myhash field1
     * "Hello"
     * redis>
     * @param $key
     * @param $field
     * @param $val
     * @return bool|int
     */
    public function hSet($key, $field, $val)
    {
        if (empty($key) || empty($field) || !isset($val)) {
            return false;
        }

        try {
            $res = $this->_cache->hSet($key, $field, $val);
        } catch (RedisException $redisException) {
            $this->errMsg = "redis_error: redis is down or overload cmd[{hSet}] key[{$key}] " .
                "host[{$this->_host}] port[{$this->_port}] errno[{$redisException->getCode()}] errMsg[{$redisException->getMessage()}]";
            MeLog::fatal($this->errMsg);
            return false;
        }

        if (false === $res) {
            $this->errMsg = "redis_error: failed cmd[hSet] key[$key] host[" .
                "{$this->_host}] port[{$this->_port}] errMsg[{$this->_cache->getLastError()}]";
            MeLog::warning($this->errMsg);
        }
        return $res;
    }


    /**
     * 获取某个hash 的指定字段值
     * redis> HSET myhash field1 "foo"
     * (integer) 1
     * redis> HGET myhash field1
     * "foo"
     * redis> HGET myhash field2
     * (nil)
     * redis>
     * @param $key
     * @param $field
     * @return bool|string
     */
    public function hGet($key, $field)
    {
        if (empty($key) || empty($field)) {
            return false;
        }

        try {
            $cmd = 'hGet';
            $ret = $this->_cache->hGet($key, $field);
        } catch (RedisException $redisException) {
            $this->errMsg = "redis_error: redis is down or overload cmd[{$cmd}] key[{$key}] " .
                "host[{$this->_host}] port[{$this->_port}] errno[{$redisException->getCode()}] errMsg[{$redisException->getMessage()}]";
            MeLog::fatal($this->errMsg);
            return false;
        }

        if ($ret === false) {
            $this->errMsg = "redis_error: failed cmd[hGet] key[$key] host[" .
                "{$this->_host}] port[{$this->_port}] errMsg[{$this->_cache->getLastError()}]";
            MeLog::warning($this->errMsg);
        }

        return $ret;
    }


    /**
     * 删除指定key下指定的field
     * redis> HSET myhash field1 "foo"
     * (integer) 1
     * redis> HDEL myhash field1
     * (integer) 1
     * redis> HDEL myhash field2
     * (integer) 0
     * redis> 
     * @param $key
     * @param $field
     * @return bool|int
     */
    public function hDel($key, $field)
    {
        if (empty($key) || empty($field)) {
            return false;
        }

        try {
            $cmd = 'hDel';
            $ret = $this->_cache->hDel($key, $field);
        } catch (RedisException $redisException) {
            $this->errMsg = "redis_error: redis is down or overload cmd[{$cmd}] key[{$key}] " .
                "host[{$this->_host}] port[{$this->_port}] errno[{$redisException->getCode()}] errMsg[{$redisException->getMessage()}]";
            MeLog::fatal($this->errMsg);
            return false;
        }

        if ($ret === false) {
            $this->errMsg = "redis_error: failed cmd[hGet] key[$key] host[" .
                "{$this->_host}] port[{$this->_port}] errMsg[{$this->_cache->getLastError()}]";
            MeLog::warning($this->errMsg);
        }

        return $ret;
    }

    /**
     * 获取key下多个field
     * redis> HSET myhash field1 "Hello"
     * (integer) 1
     * redis> HSET myhash field2 "World"
     * (integer) 1
     * redis> HMGET myhash field1 field2 nofield
     * 1) "Hello"
     * 2) "World"
     * 3) (nil)
     * @param $key
     * @param $fields
     * @return array|bool
     */
    public function hMGet($key, $fields)
    {
        if (empty($key) || empty($fields)) {
            return false;
        }

        try {
            $cmd = 'hMGet';
            $ret = $this->_cache->hMGet($key, $fields);
        } catch (RedisException $redisException) {
            $this->errMsg = "redis_error: redis is down or overload cmd[{$cmd}] key[{$key}] " .
                "host[{$this->_host}] port[{$this->_port}] errno[{$redisException->getCode()}] errMsg[{$redisException->getMessage()}]";
            MeLog::fatal($this->errMsg);
            return false;
        }

        if ($ret === false) {
            $this->errMsg = "redis_error: failed cmd[{$cmd}] key[$key] host[" .
                "{$this->_host}] port[{$this->_port}] errMsg[{$this->_cache->getLastError()}]";
            MeLog::warning($this->errMsg);
        }

        return $ret;
    }


    /**
     * 获取key下所有值
     * redis> HSET myhash field1 "Hello"
     * (integer) 1
     * redis> HSET myhash field2 "World"
     * (integer) 1
     * redis> HGETALL myhash
     * 1) "field1"
     * 2) "Hello"
     * 3) "field2"
     * 4) "World"
     * @param $key
     * @return array|bool
     */
    public function hGetAll($key)
    {
        if (empty($key)) {
            return false;
        }

        try {
            $cmd = 'hGetAll';
            $ret = $this->_cache->hGetAll($key);
        } catch (RedisException $redisException) {
            $this->errMsg = "redis_error: redis is down or overload cmd[{$cmd}] key[{$key}] " .
                "host[{$this->_host}] port[{$this->_port}] errno[{$redisException->getCode()}] errMsg[{$redisException->getMessage()}]";
            MeLog::fatal($this->errMsg);
            return false;
        }

        if ($ret === false) {
            $this->errMsg = "redis_error: failed cmd[hGetAll] key[$key] host[" .
                "{$this->_host}] port[{$this->_port}] errMsg[{$this->_cache->getLastError()}]";
            MeLog::warning($this->errMsg);
        }

        return $ret;
    }


    /**
     * set 多个 field
     * redis> HMSET myhash field1 "Hello" field2 "World"
     * "OK"
     * redis> HGET myhash field1
     * "Hello"
     * redis> HGET myhash field2
     * "World"
     * @param $key
     * @param array $values
     * @return bool
     */
    public function hMSet($key, array $values)
    {
        if (empty($key) || empty($values)) {
            return false;
        }

        try {
            $cmd = 'hMSet';
            $ret = $this->_cache->hMset($key, $values);
        } catch (RedisException $redisException) {
            $this->errMsg = "redis_error: redis is down or overload cmd[{$cmd}] key[{$key}] " .
                "host[{$this->_host}] port[{$this->_port}] errno[{$redisException->getCode()}] errMsg[{$redisException->getMessage()}]";
            MeLog::fatal($this->errMsg);
            return false;
        }

        if ($ret === false) {
            $this->errMsg = "redis_error: failed cmd[hMSet] key[$key] host[" .
                "{$this->_host}] port[{$this->_port}] errMsg[{$this->_cache->getLastError()}]";
            MeLog::warning($this->errMsg);
        }

        return $ret;
    }


    /**
     * 获取指定key的所有field的value
     * redis> HSET myhash field1 "Hello"
     * (integer) 1
     * redis> HSET myhash field2 "World"
     * (integer) 1
     * redis> HVALS myhash
     * 1) "Hello"
     * 2) "World"
     * @param $key
     * @return array|bool
     */
    public function hVals($key)
    {
        if (empty($key)) {
            return false;
        }
        try {
            $cmd = 'hVals';
            $rep = $this->_cache->hVals($key);
        } catch (RedisException $redisException) {
            $this->errMsg = "redis_error: redis is down or overload cmd[{$cmd}] key[{$key}] " .
                "host[{$this->_host}] port[{$this->_port}] errno[{$redisException->getCode()}] errMsg[{$redisException->getMessage()}]";
            MeLog::fatal($this->errMsg);
            return false;
        }

        if ($rep === false) {
            $this->errMsg = "redis_error: failed cmd[hVals] key[$key] host[" .
                "{$this->_host}] port[{$this->_port}] errMsg[{$this->_cache->getLastError()}]";
            MeLog::warning($this->errMsg);
        }
        return $rep;
    }


    /**
     * 获取key
     * redis> MSET firstname Jack lastname Stuntman age 35
     * "OK"
     * redis> KEYS *name*
     * 1) "lastname"
     * 2) "firstname"
     * redis> KEYS a??
     * 1) "age"
     * redis> KEYS *
     * 1) "age"
     * 2) "lastname"
     * 3) "firstname"
     * @param string $pattern
     * @return array|bool
     */
    public function keys($pattern = "*")
    {
        if (empty($pattern)) {
            return false;
        }

        try {
            $cmd = 'keys';
            $ret = $this->_cache->keys($pattern);
        } catch (RedisException $redisException) {
            $this->errMsg = "redis_error: redis is down or overload cmd[{$cmd}] key[{$pattern}] " .
                "host[{$this->_host}] port[{$this->_port}] errno[{$redisException->getCode()}] errMsg[{$redisException->getMessage()}]";
            MeLog::fatal($this->errMsg);
            return false;
        }

        if ($ret === false) {
            $this->errMsg = "redis_error: failed cmd[hMSet] key[$pattern] host[" .
                "{$this->_host}] port[{$this->_port}] errMsg[{$this->_cache->getLastError()}]";
            MeLog::warning($this->errMsg);
        }

        return $ret;
    }

    /**
     * 删除当前所选数据库的所有键
     * @return bool
     */
    public function flushDB()
    {
        try {
            $ret = $this->_cache->flushDB();
        } catch (RedisException $redisException) {
            $this->errMsg = "redis_error: redis is down or overload cmd[{flushDB}] key[*] " .
                "host[{$this->_host}] port[{$this->_port}] errno[{$redisException->getCode()}] errMsg[{$redisException->getMessage()}]";
            return false;
        }

        return $ret;
    }


    /**
     * 设置超时key。超时过期后自动过期
     * redis> SET mykey "Hello"
     * "OK"
     * redis> EXPIRE mykey 10
     * (integer) 1
     * redis> TTL mykey
     * (integer) 10
     * redis> SET mykey "Hello World"
     * "OK"
     * redis> TTL mykey
     * (integer) -1
     * redis>
     * @param $key
     * @param $ttl
     * @return bool
     */
    public function expire($key, $ttl)
    {
        return $this->_cache->expire($key, $ttl);
    }


    /**
     * 删除一个key
     * redis> SET key1 "Hello"
     *  "OK"
     * redis> SET key2 "World"
     * "OK"
     * redis> DEL key1 key2 key3
     * (integer) 2
     * redis>
     * @param $key
     * @return bool|int
     */
    public function del($key)
    {
        try {
            $ret = $this->_cache->del($key);
        } catch (RedisException $redisException) {
            $this->errMsg = "redis_error: redis is down or overload cmd[{del}] key[*] " .
                "host[{$this->_host}] port[{$this->_port}] errno[{$redisException->getCode()}] errMsg[{$redisException->getMessage()}]";
            MeLog::fatal($this->errMsg);
            return false;
        }

        return $ret;
    }

    /**
     * 删除并返回存储在列表中的最后一个元素key。
     * redis> RPUSH mylist "one"
     * (integer) 1
     * redis> RPUSH mylist "two"
     * (integer) 2
     * redis> RPUSH mylist "three"
     * (integer) 3
     * redis> RPOP mylist
     * "three"
     * redis> LRANGE mylist 0 -1
     * 1) "one"
     * 2) "two"
     * redis>
     * @param $key
     * @return bool|string
     */
    public function rPop($key)
    {
        try {
            $ret = $this->_cache->rPop($key);
        } catch (RedisException $redisException) {
            $this->errMsg = "redis_error: redis is down or overload cmd[{rPop}] key[*] " .
                "host[{$this->_host}] port[{$this->_port}] errno[{$redisException->getCode()}] errMsg[{$redisException->getMessage()}]";
            MeLog::fatal($this->errMsg);
            return false;
        }

        return $ret;
    }


    /**
     * 向列表头部推送
     * redis> LPUSH mylist "world"
     * (integer) 1
     * redis> LPUSH mylist "hello"
     * (integer) 2
     * redis> LRANGE mylist 0 -1
     * 1) "hello"
     * 2) "world"
     * redis>
     * @param $key
     * @param $value1
     * @return bool|mixed
     */
    public function lPush($key, $value1)
    {
        $args = func_get_args();

        try {
            $ret = call_user_func_array(
                array($this->_cache, 'lPush'),
                $args
            );
        } catch (RedisException $redisException) {
            $this->errMsg = "redis_error: redis is down or overload cmd[{lPush}] key[*] " .
                "host[{$this->_host}] port[{$this->_port}] errno[{$redisException->getCode()}] errMsg[{$redisException->getMessage()}]";
            MeLog::fatal($this->errMsg);
            return false;
        }

        return $ret;
    }


    /**
     * 向列表尾部推送
     * redis>  RPUSH mylist“你好”
     * （整数）1
     * redis>  RPUSH mylist“世界”
     * （整数）2
     * redis>  LRANGE mylist 0 -1
     * 1）“你好”
     * 2）“世界”
     * Redis的>
     * @param $key
     * @param $value1
     * @return bool|mixed
     */
    public function rPush($key, $value1)
    {
        $args = func_get_args();

        try {
            $ret = call_user_func_array(
                array($this->_cache, 'rPush'),
                $args
            );
        } catch (RedisException $redisException) {
            $this->errMsg = "redis_error: redis is down or overload cmd[{rPush}] key[*] " .
                "host[{$this->_host}] port[{$this->_port}] errno[{$redisException->getCode()}] errMsg[{$redisException->getMessage()}]";
            MeLog::fatal($this->errMsg);
            return false;
        }

        return $ret;
    }

    /**
     * 获取列表区间
     * redis> RPUSH mylist "one"
     * (integer) 1
     * redis> RPUSH mylist "two"
     * (integer) 2
     * redis> RPUSH mylist "three"
     * (integer) 3
     * redis> LRANGE mylist 0 0
     * 1) "one"
     * redis> LRANGE mylist -3 2
     * 1) "one"
     * 2) "two"
     * 3) "three"
     * redis> LRANGE mylist -100 100
     * 1) "one"
     * 2) "two"
     * 3) "three"
     * redis> LRANGE mylist 5 10
     * (empty list or set)
     * redis>
     * @param $key
     * @param int $offset
     * @param int $len
     * @return bool|mixed
     */
    public function lRange($key, $offset = 0, $len = -1)
    {
        $args = func_get_args();

        try {
            $ret = call_user_func_array(
                array($this->_cache, 'lRange'),
                $args
            );
        } catch (RedisException $redisException) {
            $this->errMsg = "redis_error: redis is down or overload cmd[{lRange}] key[*] " .
                "host[{$this->_host}] port[{$this->_port}] errno[{$redisException->getCode()}] errMsg[{$redisException->getMessage()}]";
            MeLog::fatal($this->errMsg);
            return false;
        }

        return $ret;
    }


    /**
     * 返回列表中指定index的数据
     * redis> LPUSH mylist "World"
     * (integer) 1
     * redis> LPUSH mylist "Hello"
     * (integer) 2
     * redis> LINDEX mylist 0
     * "Hello"
     * redis> LINDEX mylist -1
     * "World"
     * redis> LINDEX mylist 3
     * (nil)
     * redis>
     * @param $key
     * @param $index
     * @return bool|mixed
     */
    public function lIndex($key, $index)
    {

        try {
            $ret = $this->_cache->lIndex($key, $index);
        } catch (RedisException $redisException) {
            $this->errMsg = "redis_error: redis is down or overload cmd[{lIndex}] key[*] " .
                "host[{$this->_host}] port[{$this->_port}] errno[{$redisException->getCode()}] errMsg[{$redisException->getMessage()}]";
            MeLog::fatal($this->errMsg);
            return false;
        }

        return $ret;
    }


    /**
     * 从列表中删除指定数量的指定元素
     * redis> RPUSH mylist "hello"
     * (integer) 1
     * redis> RPUSH mylist "hello"
     * (integer) 2
     * redis> RPUSH mylist "foo"
     * (integer) 3
     * redis> RPUSH mylist "hello"
     * (integer) 4
     * redis> LREM mylist -2 "hello"
     * (integer) 2
     * redis> LRANGE mylist 0 -1
     * 1) "hello"
     * 2) "foo"
     * redis>
     * @param $key
     * @param $count
     * @param $value
     * @return bool|int
     */
    public function lRem($key, $count, $value)
    {
        try {
            $ret = $this->_cache->lRem($key, $value, $count);
        } catch (RedisException $redisException) {
            $this->errMsg = "redis_error: redis is down or overload cmd[{lRem}] key[*] " .
                "host[{$this->_host}] port[{$this->_port}] errno[{$redisException->getCode()}] errMsg[{$redisException->getMessage()}]";
            MeLog::fatal($this->errMsg);
            return false;
        }

        return $ret;
    }


    /**
     *
     * redis> DEL list1 list2
     * (integer) 0
     * redis> RPUSH list1 a b c
     * (integer) 3
     * redis> BLPOP list1 list2 0
     * 1) "list1"
     * 2) "a"
     * @param array $keys
     * @param int $count
     * @return bool|mixed
     */
    public function blPop(array $keys, $count = 100)
    {
        try {
            $ret = $this->_cache->blPop($keys, $count);
        } catch (RedisException $redisException) {
            $this->errMsg = "redis_error: redis is down or overload cmd[{lRem}] key[*] " .
                "host[{$this->_host}] port[{$this->_port}] errno[{$redisException->getCode()}] errMsg[{$redisException->getMessage()}]";
            MeLog::fatal($this->errMsg);
            return false;
        }
        return $ret;
    }


    /**
     * 获取列表长度
     * redis> LPUSH mylist "World"
     * (integer) 1
     * redis> LPUSH mylist "Hello"
     * (integer) 2
     * redis> LLEN mylist
     * (integer) 2
     * redis>
     * @param $key
     * @return array|bool|int
     */
    public function lLen($key)
    {
        try {
            if (is_array($key)) {
                $keyCountNum = [];
                foreach ($key as $v) {
                    $keyCountNum[$v] = $this->_cache->lLen($v);
                }
            } else {
                $keyCountNum = $this->_cache->lLen($key);
            }
        } catch (RedisException $redisException) {
            $this->errMsg = "redis_error: redis is down or overload cmd[lLen] key[*] " .
                "host[{$this->_host}] port[{$this->_port}] errno[{$redisException->getCode()}] errMsg[{$redisException->getMessage()}]";
            MeLog::fatal($this->errMsg);
            return false;
        }
        return $keyCountNum;
    }


    /**
     * 向有序集合中添加一个数据
     * redis> ZADD myzset 1 "one"
     * (integer) 1
     * redis> ZADD myzset 1 "uno"
     * (integer) 1
     * redis> ZADD myzset 2 "two" 3 "three"
     * (integer) 2
     * redis> ZRANGE myzset 0 -1 WITHSCORES
     * 1) "one"
     * 2) "1"
     * 3) "uno"
     * 4) "1"
     * 5) "two"
     * 6) "2"
     * 7) "three"
     * 8) "3"
     * redis>
     * @param $key
     * @param $score
     * @param $value
     * @return bool|int
     */
    public function zAdd($key, $score, $value)
    {
        try {
            $ret = $this->_cache->zAdd($key, $score, $value);
        } catch (RedisException $redisException) {
            $this->errMsg = "redis_error: redis is down or overload cmd[{zAdd}] key[*] " .
                "host[{$this->_host}] port[{$this->_port}] errno[{$redisException->getCode()}] errMsg[{$redisException->getMessage()}]";
            MeLog::fatal($this->errMsg);
            return false;
        }

        return $ret;
    }

    /**
     * 获取指定区间的value (正向排序)
     * redis> ZADD myzset 1 "one"
     * (integer) 1
     * redis> ZADD myzset 2 "two"
     * (integer) 1
     * redis> ZADD myzset 3 "three"
     * (integer) 1
     * redis> ZRANGE myzset 0 -1
     * 1) "one"
     * 2) "two"
     * 3) "three"
     * redis> ZRANGE myzset 2 3
     * 1) "three"
     * redis> ZRANGE myzset -2 -1
     * 1) "two"
     * 2) "three"
     * redis>
     * @param $key
     * @param $start
     * @param $stop
     * @param bool $withscores
     * @return array|bool
     */
    public function zRange($key, $start, $stop, $withscores = false)
    {
        try {
            $ret = $this->_cache->zRange($key, $start, $stop, $withscores);
        } catch (RedisException $redisException) {
            $this->errMsg = "redis_error: redis is down or overload cmd[{zRange}] key[*] " .
                "host[{$this->_host}] port[{$this->_port}] errno[{$redisException->getCode()}] errMsg[{$redisException->getMessage()}]";
            MeLog::fatal($this->errMsg);
            return false;
        }

        return $ret;
    }

    /**
     * 获取指定区间的value(反向排序)
     * redis> ZADD myzset 1 "one"
     * (integer) 1
     * redis> ZADD myzset 2 "two"
     * (integer) 1
     * redis> ZADD myzset 3 "three"
     * (integer) 1
     * redis> ZREVRANGE myzset 0 -1
     * 1) "three"
     * 2) "two"
     * 3) "one"
     * redis> ZREVRANGE myzset 2 3
     * 1) "one"
     * redis> ZREVRANGE myzset -2 -1
     * 1) "two"
     * 2) "one"
     * redis>
     * @param $key
     * @param $start
     * @param $stop
     * @param bool $withscores
     * @return array|bool
     */
    public function zRevRange($key, $start, $stop, $withscores = false)
    {
        try {
            $ret = $this->_cache->zRevRange($key, $start, $stop, $withscores);
        } catch (RedisException $redisException) {
            $this->errMsg = "redis_error: redis is down or overload cmd[{zRevRange}] key[{$key}] " .
                "host[{$this->_host}] port[{$this->_port}] errno[{$redisException->getCode()}] errMsg[{$redisException->getMessage()}]";
            MeLog::fatal($this->errMsg);
            return false;
        }

        return $ret;
    }


    /**
     * 返回排序集合中的所有元素，key得分在min 和之间max（包括得分等于min或的元素max）。这些元素被认为是从低分到高分。
     * ZRANGEBYSCORE zset (1 5
     * 将返回所有元素1 < score <= 5：
     * ZRANGEBYSCORE zset (5 (10
     * 将返回所有元素5 < score < 10（排除5和10）
     * @param $key
     * @param $start
     * @param $redisExceptionnd
     * @param bool $withscores
     * @param int $limit
     * @return array|bool
     */
    public function zRangeByScore($key, $start, $redisExceptionnd, $withscores = false, $limit = 0)
    {
        try {
            $ret = $this->_cache->zRangeByScore($key, $start, $redisExceptionnd, array($withscores, $limit));
        } catch (RedisException $redisException) {
            $this->errMsg = "redis_error: redis is down or overload cmd[{zRangeByScore}] key[*] " .
                "host[{$this->_host}] port[{$this->_port}] errno[{$redisException->getCode()}] errMsg[{$redisException->getMessage()}]";
            MeLog::fatal($this->errMsg);
            return false;
        }

        return $ret;
    }


    /**
     * 删除一个指定集合下的指定成员
     * redis> ZADD myzset 1 "one"
     * (integer) 1
     * redis> ZADD myzset 2 "two"
     * (integer) 1
     * redis> ZADD myzset 3 "three"
     * (integer) 1
     * redis> ZREM myzset "two"
     * (integer) 1
     * redis> ZRANGE myzset 0 -1 WITHSCORES
     * 1) "one"
     * 2) "1"
     * 3) "three"
     * 4) "3"
     * @param $key
     * @param $member
     * @return bool|int
     */
    public function zRem($key, $member)
    {
        try {
            $ret = $this->_cache->zRem($key, $member);
        } catch (RedisException $redisException) {
            $this->errMsg = "redis_error: redis is down or overload cmd[{zRange}] key[*] " .
                "host[{$this->_host}] port[{$this->_port}] errno[{$redisException->getCode()}] errMsg[{$redisException->getMessage()}]";
            MeLog::fatal($this->errMsg);
            return false;
        }

        return $ret;
    }


    /**
     * 获取集合长度
     * redis> ZADD myzset 1 "one"
     * (integer) 1
     * redis> ZADD myzset 2 "two"
     * (integer) 1
     * redis> ZCARD myzset
     * (integer) 2
     * redis>
     * @param $key
     * @return bool|int
     */
    public function zCard($key)
    {
        if (empty($key) || !is_string($key)) {
            return false;
        }

        try {
            $ret = $this->_cache->zCard($key);
        } catch (RedisException $redisException) {
            $this->errMsg = "redis_error: redis is down or overload cmd[{zCard}] key[{$key}] " .
                "host[{$this->_host}] port[{$this->_port}] errno[{$redisException->getCode()}] errMsg[{$redisException->getMessage()}]";
            MeLog::fatal($this->errMsg);
            return false;
        }
        return $ret;
    }

    /**
     * redis lua 脚本执行命令
     * @param string $script 执行脚本的文件
     * @param array  $param [KEYS,KEYS,ARGV,ARGV]
     * @param  int   $numberKeys $param数组中 KEYS 的个数 上面的例子含义就是 hSet 的keys值有两个  其余两个为附属参数
     * @return bool
     */
    public function evalLua($script, array $param, $numberKeys)
    {
        if (!is_array($param)) {
            return false;
        }
        try {
            $ret = $this->_cache->eval($script, $param, $numberKeys);
        } catch (RedisException $redisException) {
            $this->errMsg = "redis_error: redis is down or overload cmd[{evalLua}] " .
                "host[{$this->_host}] port[{$this->_port}] errno[{$redisException->getCode()}] errMsg[{$redisException->getMessage()}]";
            MeLog::fatal($this->errMsg);
            return false;
        }

        return $ret;
    }


    /**
     * 检测指定KEY是否存在
     * @param $redisKey
     * @return bool
     */
    public function existsKey($redisKey)
    {
        try {
            $ret = $this->_cache->exists($redisKey);
        } catch (RedisException $redisException) {
            $this->errMsg = "redis_error: redis is down or overload cmd[{evalLua} key[{$redisKey}]] " .
                "host[{$this->_host}] port[{$this->_port}] errno[{$redisException->getCode()}] errMsg[{$redisException->getMessage()}]";
            MeLog::fatal($this->errMsg);
            return false;
        }
        return $ret;
    }


    /**
     * 以毫秒为单位返回剩余时间量。
     * redis> SET mykey "Hello"
     * "OK"
     * redis> EXPIRE mykey 1
     * (integer) 1
     * redis> PTTL mykey
     * (integer) 1000
     * redis>
     * @param $redisKey
     * @return bool|int
     */
    public function getKeyPttl($redisKey)
    {
        try {
            $ret = $this->_cache->pttl($redisKey);
        } catch (RedisException $redisException) {
            $this->errMsg = "redis_error: redis is down or overload cmd[{evalLua} key[{$redisKey}]] " .
                "host[{$this->_host}] port[{$this->_port}] errno[{$redisException->getCode()}] errMsg[{$redisException->getMessage()}]";
            MeLog::fatal($this->errMsg);
            return false;
        }
        return $ret;
    }
}
