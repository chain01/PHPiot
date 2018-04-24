<?php
/**
 * tcp异步连接部分
 *
 * @author    chain01
 * 
 */
namespace PHPiot\Connection;

use PHPiot\Events\EventInterface;
use PHPiot\Lib\Timer;
use PHPiot\Worker;
use Exception;

/**
 * AsyncTcpConnection 异步连接.
 */
class AsyncTcpConnection extends TcpConnection
{
    /**
     * socket发送成功 
     *
     * @var 回调
     */
    public $onConnect = null;

    /**
     * 传输层协议
     *
     * @var string
     */
    public $transport = 'tcp';

    /**
     * 状态
     *
     * @var int
     */
    protected $_status = self::STATUS_INITIAL;

    /**
     * 远程主机
     *
     * @var string
     */
    protected $_remoteHost = '';

    /**
     * 主机端口
     *
     * @var int
     */
    protected $_remotePort = 80;

    /**
     * 连接开始时间
     *
     * @var string
     */
    protected $_connectStartTime = 0;

    /**
     * 远程主机 URI
     *
     * @var string
     */
    protected $_remoteURI = '';

    /**
     * 选项
     *
     * @var resource
     */
    protected $_contextOption = null;

    /**
     *重新连接定时器
     *
     * @var int
     */
    protected $_reconnectTimer = null;


    /**
     * PHP 自带的协议
     *
     * @var array
     */
    protected static $_builtinTransports = array(
        'tcp'   => 'tcp',
        'udp'   => 'udp',
        'unix'  => 'unix',
        'ssl'   => 'ssl',
        'sslv2' => 'sslv2',
        'sslv3' => 'sslv3',
        'tls'   => 'tls'
    );

    /**
     * 程序构建
     *
     * @param string $remote_address
     * @param array $context_option
     * @throws Exception
     */
    public function __construct($remote_address, $context_option = null)
    {
        $address_info = parse_url($remote_address);
        if (!$address_info) {
            list($scheme, $this->_remoteAddress) = explode(':', $remote_address, 2);
            if (!$this->_remoteAddress) {
                echo new \Exception('bad remote_address');
            }
        } else {
            if (!isset($address_info['port'])) {
                $address_info['port'] = 80;
            }
            if (!isset($address_info['path'])) {
                $address_info['path'] = '/';
            }
            if (!isset($address_info['query'])) {
                $address_info['query'] = '';
            } else {
                $address_info['query'] = '?' . $address_info['query'];
            }
            $this->_remoteAddress = "{$address_info['host']}:{$address_info['port']}";
            $this->_remoteHost    = $address_info['host'];
            $this->_remotePort    = $address_info['port'];
            $this->_remoteURI     = "{$address_info['path']}{$address_info['query']}";
            $scheme               = isset($address_info['scheme']) ? $address_info['scheme'] : 'tcp';
        }

        $this->id = $this->_id = self::$_idRecorder++;
        // 检查应用层协议类
        if (!isset(self::$_builtinTransports[$scheme])) {
            $scheme         = ucfirst($scheme);
            $this->protocol = '\\Protocols\\' . $scheme;
            if (!class_exists($this->protocol)) {
                $this->protocol = "\\PHPiot\\Protocols\\$scheme";
                if (!class_exists($this->protocol)) {
                    throw new Exception("class \\Protocols\\$scheme not exist");
                }
            }
        } else {
            $this->transport = self::$_builtinTransports[$scheme];
        }

        // 统计
        self::$statistics['connection_count']++;
        $this->maxSendBufferSize        = self::$defaultMaxSendBufferSize;
        $this->_contextOption           = $context_option;
        static::$connections[$this->id] = $this;
    }

    /**
     *建立连接
     *
     * @return void 
     */
    public function connect()
    {
        if ($this->_status !== self::STATUS_INITIAL && $this->_status !== self::STATUS_CLOSING &&
             $this->_status !== self::STATUS_CLOSED) {
            return;
        }
        $this->_status           = self::STATUS_CONNECTING;
        $this->_connectStartTime = microtime(true);
        // 开启异步socket连接
        if ($this->_contextOption) {
            $context = stream_context_create($this->_contextOption);
            $this->_socket = stream_socket_client("{$this->transport}://{$this->_remoteHost}:{$this->_remotePort}", $errno, $errstr, 0,
                STREAM_CLIENT_ASYNC_CONNECT, $context);
        } else {
            $this->_socket = stream_socket_client("{$this->transport}://{$this->_remoteHost}:{$this->_remotePort}", $errno, $errstr, 0,
                STREAM_CLIENT_ASYNC_CONNECT);
        }
        // 错误时返回
        if (!$this->_socket) {
            $this->emitError(PHPIOT_CONNECT_FAIL, $errstr);
            if ($this->_status === self::STATUS_CLOSING) {
                $this->destroy();
            }
            if ($this->_status === self::STATUS_CLOSED) {
                $this->onConnect = null;
            }
            return;
        }
        // 将socket添加到全局事件循环等待连接成功建立或失败 
        Worker::$globalEvent->add($this->_socket, EventInterface::EV_WRITE, array($this, 'checkConnection'));
        // windows系统下
        if(DIRECTORY_SEPARATOR === '\\') {
            Worker::$globalEvent->add($this->_socket, EventInterface::EV_EXCEPT, array($this, 'checkConnection'));
        }
    }

    /**
     * 重新连接
     *
     * @param int $after
     * @return void
     */
    public function reConnect($after = 0) {
        $this->_status = self::STATUS_INITIAL;
        if ($this->_reconnectTimer) {
            Timer::del($this->_reconnectTimer);
        }
        if ($after > 0) {
            $this->_reconnectTimer = Timer::add($after, array($this, 'connect'), null, false);
            return;
        }
        $this->connect();
    }

    /**
     * 获取远程主机地址
     *
     * @return string 
     */
    public function getRemoteHost()
    {
        return $this->_remoteHost;
    }

    /**
     * 获取远程主机URI
     *
     * @return string
     */
    public function getRemoteURI()
    {
        return $this->_remoteURI;
    }

    /**
     * 异常回调
     *
     * @param int    $code
     * @param string $msg
     * @return void
     */
    protected function emitError($code, $msg)
    {
        $this->_status = self::STATUS_CLOSING;
        if ($this->onError) {
            try {
                call_user_func($this->onError, $this, $code, $msg);
            } catch (\Exception $e) {
                Worker::log($e);
                exit(250);
            } catch (\Error $e) {
                Worker::log($e);
                exit(250);
            }
        }
    }

    /**
     * 检查连接是否成功
     *
     * @param resource $socket
     * @return void
     */
    public function checkConnection($socket)
    {
        // 对windows系统字符串的处理
        if(DIRECTORY_SEPARATOR === '\\') {
            Worker::$globalEvent->del($socket, EventInterface::EV_EXCEPT);
        }
        // 检查socket状态
        if ($address = stream_socket_get_name($socket, true)) {
            // 移除监听
            Worker::$globalEvent->del($socket, EventInterface::EV_WRITE);
            // Nonblocking.
            stream_set_blocking($socket, 0);
            // 对hhvm的兼容
			// hhvm是啥传送门https://zh.wikipedia.org/wiki/HipHop_for_PHP#HHVM
            if (function_exists('stream_set_read_buffer')) {
                stream_set_read_buffer($socket, 0);
            }
            // 开启tcp禁用Nagle算法
            if (function_exists('socket_import_stream') && $this->transport === 'tcp') {
                $raw_socket = socket_import_stream($socket);
                socket_set_option($raw_socket, SOL_SOCKET, SO_KEEPALIVE, 1);
                socket_set_option($raw_socket, SOL_TCP, TCP_NODELAY, 1);
            }
            // 注册侦听器等待读取事件
            Worker::$globalEvent->add($socket, EventInterface::EV_READ, array($this, 'baseRead'));
            // 发送数据缓存
            if ($this->_sendBuffer) {
                Worker::$globalEvent->add($socket, EventInterface::EV_WRITE, array($this, 'baseWrite'));
            }
            $this->_status                = self::STATUS_ESTABLISHED;
            $this->_remoteAddress         = $address;
            $this->_sslHandshakeCompleted = true;

            // 尝试回调
            if ($this->onConnect) {
                try {
                    call_user_func($this->onConnect, $this);
                } catch (\Exception $e) {
                    Worker::log($e);
                    exit(250);
                } catch (\Error $e) {
                    Worker::log($e);
                    exit(250);
                }
            }
            // 发送协议::onConnect
            if (method_exists($this->protocol, 'onConnect')) {
                try {
                    call_user_func(array($this->protocol, 'onConnect'), $this);
                } catch (\Exception $e) {
                    Worker::log($e);
                    exit(250);
                } catch (\Error $e) {
                    Worker::log($e);
                    exit(250);
                }
            }
        } else {
            // 连接失败
            $this->emitError(PHPIOT_CONNECT_FAIL, 'connect ' . $this->_remoteAddress . ' fail after ' . round(microtime(true) - $this->_connectStartTime, 4) . ' seconds');
            if ($this->_status === self::STATUS_CLOSING) {
                $this->destroy();
            }
            if ($this->_status === self::STATUS_CLOSED) {
                $this->onConnect = null;
            }
        }
    }
}
