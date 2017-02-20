<?php

class MyMemcacheClient {
    private $host;
    private $port;
    private $socket;
    private $error;

    public function __construct() {
    }

    public function __destruct() {
        $this->close();
    }

    /**
     * 获取最后一次的socket错误
     * @return string 最后一次socket错误字符串
     */
    public function setSocketError() {
        $errno = socket_last_error($this->socket);
        $this->error = "[$errno]" . socket_strerror($errno);
    }

    /**
     * 获取最后一次错误信息;
     * @return string 最后一次错误信息
     */
    public function getLastError() {
        return $this->error;
    }

    /**
     * 链接memcached服务器
     * @param  string  $host memcached监听的ip
     * @param  integer $port memcached监听的端口
     * @return boolean     true表示连接成功，false表示连接失败
     */
    public function connect($host = '127.0.0.1', $port = 11211) {
        $this->host = $host;
        $this->port = $port;

        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->socket === false) {
            $this->setSocketError();
            return false;
        }

        $result = socket_connect($this->socket, $host, $port);
        if ($result === false) {
            $this->setSocketError();
            return false;
        } else {
            return true;
        }
    }

    /**
     * 执行set|add|replace命令
     * @param string  $cmd   命令(set|add|replace)
     * @param string  $key   键
     * @param string  $value 值
     * @param nteger   $ttl  生存时间
     * @return boolean true for success, false for fail
     */
    private function _set_add_replace($cmd, $key, $value, $ttl = 10) {
        $line1 = sprintf("$cmd %s 0 %d %d\r\n", $key, $ttl, strlen($value));
        $line2 = $value . "\r\n";

        $data = $line1 . $line2;

        $result = socket_write($this->socket, $data, strlen($data));
        if ($result === false) {
            $this->setSocketError();
            return false;
        }

        $response = socket_read($this->socket, 1024, PHP_NORMAL_READ);
        /** 读取最后一个 \n 字符 */
        socket_read($this->socket, 1, PHP_BINARY_READ);

        if ($response === false) {
            $this->setSocketError();
            return false;
        }

        /** 操作成功会返回STORED\r\n */
        if (!strncmp($response, 'STORED', 6)) {
            return true;
        }

        return false;
    }

    public function set($key, $value, $ttl = 10) {
        return $this->_set_add_replace('set', $key, $value, $ttl);
    }

    public function add($key, $value, $ttl = 10) {
        return $this->_set_add_replace('add', $key, $value, $ttl);
    }

    public function replace($key, $value, $ttl = 10) {
        return $this->_set_add_replace('replace', $key, $value, $ttl);
    }

    public function append($key, $value, $ttl = 10) {
        return $this->_set_add_replace('append', $key, $value, $ttl);
    }

    public function prepend($key, $value, $ttl = 10) {
        return $this->_set_add_replace('prepend', $key, $value, $ttl);
    }
    /**
     * 获取一个键的值
     * @param  string|array $key 键
     * @return string|boolean|array    值, false表示没有这个键或者已过期。当$key时数组时，返回关联数组。见 multiGet()函数。
     */
    public function get($key) {
        if (is_array($key)) {
            return call_user_func_array([$this, 'multiGet'], $key);
        }

        $data = sprintf("get %s\r\n", $key);

        $result = socket_write($this->socket, $data, strlen($data));
        if ($result === false) {
            $this->setSocketError();
            return false;
        }

        $line1 = socket_read($this->socket, 1024, PHP_NORMAL_READ);
        /** 读取最后一个 \n 字符 */
        socket_read($this->socket, 1, PHP_BINARY_READ);

        if ($line1 === false) {
            $this->setSocketError();
            return false;
        }

        /** 获取成功，第一行返回 VALUE <key> <flags> <bytes>\r\n */
        if (!strncmp($line1, "VALUE", 5)) {
            $line1 = rtrim($line1, "\r\n");
            $arr = explode(' ', $line1);
            /** 获取数据长度 */
            $dataLen = intval(end($arr));

            /** 获取数据 */
            $response = socket_read($this->socket, $dataLen, PHP_BINARY_READ);
            /** 读取最后7个字符 \r\nEND\r\n  */
            socket_read($this->socket, 7, PHP_BINARY_READ);

            if ($response === false) {
                $this->setSocketError();
                return false;
            }

            return $response;
        } else {
            return false;
        }
    }

    /**
     * 一次获取多个key的值, get key1 key2 key3 ...
     * @param  string $key1 第一个key
     * @param  string $keys 剩余参数
     * @return array        关联数组，结果格式 array('key1' => 'value1', 'key2' => 'value2')
     */
    public function multiGet($key1, ...$keys) {
        if (is_array($key1)) {
            return call_user_func_array([$this, 'multiGet'], $key1);
        }

        array_unshift($keys, $key1);
        array_unshift($keys, "get");

        $data = implode(' ', $keys) . "\r\n";

        $result = socket_write($this->socket, $data, strlen($data));
        if (false === $result) {
            $this->setSocketError();
            return false;
        }

        $values = [];
        while (true) {
            $line = socket_read($this->socket, 1024, PHP_NORMAL_READ);
            if (strncmp($line, "END", 3) === 0) {
                socket_read($this->socket, 1, PHP_BINARY_READ);
                break;
            }

            if (!strncmp($line, "VALUE", 5)) {
                $line = rtrim($line, "\r\n");
                $arr = explode(' ', $line);

                $dataLen = intval(end($arr));
                $response = socket_read($this->socket, $dataLen, PHP_BINARY_READ);
                $values[$arr[1]] = $response;
                socket_read($this->socket, 2, PHP_BINARY_READ);
            }
        }

        return $values;
    }

    /**
     * 实现cas命令
     * @param  [type]  $key   [description]
     * @param  [type]  $value [description]
     * @param  integer $ttl   [description]
     * @return [type]         [description]
     */
    public function cas($key, $value, $ttl = 10) {
        $getsCommand = "gets $key\r\n";
        $result = socket_write($this->socket, $getsCommand, strlen($getsCommand));
        if ($result === false) {
            $this->setSocketError();
            return -1;
        }

        $line = socket_read($this->socket, 1024, PHP_NORMAL_READ);
        if (!strncmp($line, "VALUE", 5)) {
            $arr = explode(' ', rtrim($line, "\r\n"));
            $casToken = end($arr);
            $response = socket_read($this->socket, intval($arr[3])+8, PHP_BINARY_READ);

            $casCommand = sprintf("cas %s 0 %d %d %s\r\n%s\r\n", $key, $ttl, strlen($value), $casToken, $value);

            $result = socket_write($this->socket, $casCommand, strlen($casCommand));
            if ($result === false) {
                $this->setSocketError();
                return -1;
            }

            $response = socket_read($this->socket, 1024, PHP_NORMAL_READ);
            socket_read($this->socket, 1, PHP_BINARY_READ);

            if (strncmp($response, "STORED", 6) === 0) {
                return 0; // STORED
            } elseif (strncmp($response, "ERROR", 5) === 0) {
                return -3; // ERROR
            } elseif (strncmp($response, "EXISTS", 6) === 0) {
                return 1; // EXISTS
            } else {
                return -2; // NOT_FOUND
            }
        } else {
            return -2; // NOT_FOUND
        }
    }

    /**
     * 设置所有的键过期
     * @return boolean success
     */
    public function flushAll() {
        $data = "flush_all\r\n";

        $result = socket_write($this->socket, $data, strlen($data));
        /** 读取返回结果，固定为 OK\r\n  */
        socket_read($this->socket, 4, PHP_BINARY_READ);

        return true;
    }

    /**
     * 删除一个键
     * @param  string  $key   键
     * @param  integer $delay 延时
     * @return boolean        true for success, false for fail
     */
    public function delete($key, $delay = 5) {
        $data = sprintf("delete %s %d\r\n", $key, $delay);

        $result = socket_write($this->socket, $data, strlen($data));
        if ($result === false) {
            $this->setSocketError();
            return false;
        }

        $response = socket_read($this->socket, 20, PHP_NORMAL_READ);
        if ($response === false) {
            $this->setSocketError();
            return false;
        }

        socket_read($this->socket, 1, PHP_BINARY_READ);
        if (!strncmp($response, "DELETED", 7)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * incr,decr命令的封装
     * @param  string $cmd   操作,incr|decr
     * @param  string $key   键
     * @param  integer $value 增加或减少的值
     * @return boolean|integer       操作失败，返回false，操作成功，返回整数(操作后的值)
     */
    private function _incr_decr($cmd, $key, $value) {
        $data = "$cmd $key $value\r\n";

        $result = socket_write($this->socket, $data, strlen($data));
        if ($result === false) {
            $this->setSocketError();
            return false;
        }

        $response = socket_read($this->socket, 1024, PHP_NORMAL_READ);
        if ($response === false) {
            $this->setSocketError();
            return false;
        }

        socket_read($this->socket, 1, PHP_BINARY_READ);
        if ($response != "NOT_FOUND\r") {
            return intval($response);
        } else {
            return false;
        }
    }

    public function incr($key, $value = 1) {
        return $this->_incr_decr("incr", $key, $value);
    }

    public function decr($key, $value = 1) {
        return $this->_incr_decr("decr", $key, $value);
    }

    /**
     * 返回统计信息
     * @return boolean|array 失败，返回false，成功，返回数组，格式 key => value
     */
    public function stats() {
        $data = "stats\r\n";

        $result = socket_write($this->socket, $data, strlen($data));
        if ($result === false) {
            $this->setSocketError();
            return false;
        }

        $stats = [];
        while(true) {
            $line = socket_read($this->socket, 1024, PHP_NORMAL_READ);
            if (false === $line) {
                break;
            }
            if ($line == "END\r") {
                socket_read($this->socket, 1, PHP_BINARY_READ);
                break;
            } else {
                $arr = explode(' ', rtrim($line, "\r"));
                $stats[$arr[1]] = $arr[2];

                socket_read($this->socket, 1, PHP_BINARY_READ);
            }
        }

        return $stats;
    }

    /**
     * 关闭连接
     * @return void 没有返回值
     */
    public function close() {
        if ($this->socket != false) {
            socket_close($this->socket);
            $this->socket = false;
        }
    }
}