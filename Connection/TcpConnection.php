<?php
/**
 * tcp连接部分
 *
 * @author    chain01
 * 
 */
namespace PHPiot\Connection;

use PHPiot\Events\EventInterface;
use PHPiot\Worker;
use Exception;

/**
 * tcp连接
 */
class TcpConnection extends ConnectionInterface
{
    /**
     * 读取缓冲区大小
     *
     * @var int
     */
    const READ_BUFFER_SIZE = 65535;

    /**
     * 初始化状态
     *
     * @var int
     */
    const STATUS_INITIAL = 0;

    /**
     * 连接状态
     *
     * @var int
     */
    const STATUS_CONNECTING = 1;

    /**
     * 连接建立状态
     *
     * @var int
     */
    const STATUS_ESTABLISHED = 2;

    /**
     * 关闭状态中
     *
     * @var int
     */
    const STATUS_CLOSING = 4;

    /**
     * 已关闭状态
     *
     * @var int
     */
    const STATUS_CLOSED = 8;

    /**
     * 当接收到数据时
     *
     * @var callback
     */
    public $onMessage = null;

    /**
     * Emitted 当的socket另一端发送一个FIN数据包时
     *
     * @var callback
     */
    public $onClose = null;

    /**
     * 当连接发生错误时
     *
     * @var callback
     */
    public $onError = null;

    /**
     * 当发送缓冲区满时
     *
     * @var callback
     */
    public $onBufferFull = null;

    /**
     * 当发送缓冲区变为空时
     *
     * @var callback
     */
    public $onBufferDrain = null;

    /**
     * 应用层协议
     * 格式 PHPiot\\Protocols\\Http.
     *
     * @var \PHPiot\Protocols\ProtocolInterface
     */
    public $protocol = null;

    /**
     * 传输协议(tcp/udp/unix/ssl).
     *
     * @var string
     */
    public $transport = 'tcp';

    /**
     * 任务归属
     *
     * @var Worker
     */
    public $worker = null;

    /**
     * 读取字节
     *
     * @var int
     */
    public $bytesRead = 0;

    /**
     * 写入字节
     *
     * @var int
     */
    public $bytesWritten = 0;

    /**
     * 分配连接ID
     *
     * @var int
     */
    public $id = 0;

    /**
     * 备份 $worker->id 清除 worker->connections中的连接
     *
     * @var int
     */
    protected $_id = 0;

    /**
     * 设置当前连接的最大发送缓冲区大小
     * 当发送缓冲区已满时，将发出OnPuffer-Fulk回调
     *
     * @var int
     */
    public $maxSendBufferSize = 1048576;

    /**
     * 默认发送缓冲区大小
     *
     * @var int
     */
    public static $defaultMaxSendBufferSize = 1048576;

    /**
     * 最大可接受的数据包大小
     *
     * @var int
     */
    public static $maxPackageSize = 10485760;

    /**
     * Id 记录
     *
     * @var int
     */
    protected static $_idRecorder = 1;

    /**
     * Socket
     *
     * @var resource
     */
    protected $_socket = null;

    /**
     * 发送缓冲区
     *
     * @var string
     */
    protected $_sendBuffer = '';

    /**
     * 接收缓冲区
     *
     * @var string
     */
    protected $_recvBuffer = '';

    /**
     * 当前数据包长度
     *
     * @var int
     */
    protected $_currentPackageLength = 0;

    /**
     * 连接状态
     *
     * @var int
     */
    protected $_status = self::STATUS_ESTABLISHED;

    /**
     * 远程主机地址
     *
     * @var string
     */
    protected $_remoteAddress = '';

    /**
     * 暂停
     *
     * @var bool
     */
    protected $_isPaused = false;

    /**
     * SSL握手是否完成
     *
     * @var bool
     */
    protected $_sslHandshakeCompleted = false;

    /**
     * 所有连接实例
     *
     * @var array
     */
    public static $connections = array();

    /**
     * 字符串状态
     *
     * @var array
     */
    public static $_statusToString = array(
        self::STATUS_INITIAL     => 'INITIAL',
        self::STATUS_CONNECTING  => 'CONNECTING',
        self::STATUS_ESTABLISHED => 'ESTABLISHED',
        self::STATUS_CLOSING     => 'CLOSING',
        self::STATUS_CLOSED      => 'CLOSED',
    );

    /**
     * 主
     *
     * @param resource $socket
     * @param string   $remote_address
     */
    public function __construct($socket, $remote_address = '')
    {
        self::$statistics['connection_count']++;
        $this->id = $this->_id = self::$_idRecorder++;
        if(self::$_idRecorder === PHP_INT_MAX){
            self::$_idRecorder = 0;
        }
        $this->_socket = $socket;
        stream_set_blocking($this->_socket, 0);
        // hhvm兼容
        if (function_exists('stream_set_read_buffer')) {
            stream_set_read_buffer($this->_socket, 0);
        }
        Worker::$globalEvent->add($this->_socket, EventInterface::EV_READ, array($this, 'baseRead'));
        $this->maxSendBufferSize = self::$defaultMaxSendBufferSize;
        $this->_remoteAddress    = $remote_address;
        static::$connections[$this->id] = $this;
    }

    /**
     * 获取状态
     *
     * @param bool $raw_output
     *
     * @return int
     */
    public function getStatus($raw_output = true)
    {
        if ($raw_output) {
            return $this->_status;
        }
        return self::$_statusToString[$this->_status];
    }

    /**
     * 在连接上发送数据
     *
     * @param string $send_buffer
     * @param bool  $raw
     * @return void|bool|null
     */
    public function send($send_buffer, $raw = false)
    {
        if ($this->_status === self::STATUS_CLOSING || $this->_status === self::STATUS_CLOSED) {
            return false;
        }

        // 在发送前尝试调用协议::encode($send_buffer) .
        if (false === $raw && $this->protocol !== null) {
            $parser      = $this->protocol;
            $send_buffer = $parser::encode($send_buffer, $this);
            if ($send_buffer === '') {
                return null;
            }
        }

        if ($this->_status !== self::STATUS_ESTABLISHED ||
            ($this->transport === 'ssl' && $this->_sslHandshakeCompleted !== true)
        ) {
            if ($this->_sendBuffer) {
                if ($this->bufferIsFull()) {
                    self::$statistics['send_fail']++;
                    return false;
                }
            }
            $this->_sendBuffer .= $send_buffer;
            $this->checkBufferWillFull();
            return null;
        }


        // 尝试直接发送数据
        if ($this->_sendBuffer === '') {
            $len = @fwrite($this->_socket, $send_buffer, 8192);
            // 成功
            if ($len === strlen($send_buffer)) {
                $this->bytesWritten += $len;
                return true;
            }
            // 只发送部分数据
            if ($len > 0) {
                $this->_sendBuffer = substr($send_buffer, $len);
                $this->bytesWritten += $len;
            } else {
                // 关闭连接
                if (!is_resource($this->_socket) || feof($this->_socket)) {
                    self::$statistics['send_fail']++;
                    if ($this->onError) {
                        try {
                            call_user_func($this->onError, $this, PHPIOT_SEND_FAIL, 'client closed');
                        } catch (\Exception $e) {
                            Worker::log($e);
                            exit(250);
                        } catch (\Error $e) {
                            Worker::log($e);
                            exit(250);
                        }
                    }
                    $this->destroy();
                    return false;
                }
                $this->_sendBuffer = $send_buffer;
            }
            Worker::$globalEvent->add($this->_socket, EventInterface::EV_WRITE, array($this, 'baseWrite'));
            // 检查发送缓冲区状态，是否将满
            $this->checkBufferWillFull();
            return null;
        } else {
            if ($this->bufferIsFull()) {
                self::$statistics['send_fail']++;
                return false;
            }

            $this->_sendBuffer .= $send_buffer;
            // 检查发送缓冲区状态，是否已满
            $this->checkBufferWillFull();
        }
    }

    /**
     * 获取远程主机ip
     *
     * @return string
     */
    public function getRemoteIp()
    {
        $pos = strrpos($this->_remoteAddress, ':');
        if ($pos) {
            return substr($this->_remoteAddress, 0, $pos);
        }
        return '';
    }

    /**
     * 获取远程主机port.
     *
     * @return int
     */
    public function getRemotePort()
    {
        if ($this->_remoteAddress) {
            return (int)substr(strrchr($this->_remoteAddress, ':'), 1);
        }
        return 0;
    }

    /**
     * 获取远程主机地址
     *
     * @return string
     */
    public function getRemoteAddress()
    {
        return $this->_remoteAddress;
    }

    /**
     * 获取本地 IP.
     *
     * @return string
     */
    public function getLocalIp()
    {
        $address = $this->getLocalAddress();
        $pos = strrpos($address, ':');
        if (!$pos) {
            return '';
        }
        return substr($address, 0, $pos);
    }

    /**
     * 获取本地 port.
     *
     * @return int
     */
    public function getLocalPort()
    {
        $address = $this->getLocalAddress();
        $pos = strrpos($address, ':');
        if (!$pos) {
            return 0;
        }
        return (int)substr(strrchr($address, ':'), 1);
    }

    /**
     * 获取本地地址
     *
     * @return string
     */
    public function getLocalAddress()
    {
        return (string)@stream_socket_get_name($this->_socket, false);
    }

    /**
     * 获取发送缓冲区队列大小
     *
     * @return integer
     */
    public function getSendBufferQueueSize()
    {
        return strlen($this->_sendBuffer);
    }

    /**
     * 获取recv缓冲区队列大小
     *
     * @return integer
     */
    public function getRecvBufferQueueSize()
    {
        return strlen($this->_recvBuffer);
    }

    /**
     * ipv4.
     *
     * return bool.
     */
    public function isIpV4()
    {
        if ($this->transport === 'unix') {
            return false;
        }
        return strpos($this->getRemoteIp(), ':') === false;
    }

    /**
     *  ipv6.
     *
     * return bool.
     */
    public function isIpV6()
    {
        if ($this->transport === 'unix') {
            return false;
        }
        return strpos($this->getRemoteIp(), ':') !== false;
    }

    /**
     * 暂停数据的读取。也就是说，不会发出消息。 Useful to throttle back an upload.
     *
     * @return void
     */
    public function pauseRecv()
    {
        Worker::$globalEvent->del($this->_socket, EventInterface::EV_READ);
        $this->_isPaused = true;
    }

    /**
     * 恢复数据读取后 暂停Recv.
     *
     * @return void
     */
    public function resumeRecv()
    {
        if ($this->_isPaused === true) {
            Worker::$globalEvent->add($this->_socket, EventInterface::EV_READ, array($this, 'baseRead'));
            $this->_isPaused = false;
            $this->baseRead($this->_socket, false);
        }
    }

    /**
     * 基本读取处理程序
     *
     * @param resource $socket
     * @param bool $check_eof
     * @return void
     */
    public function baseRead($socket, $check_eof = true)
    {
        // SSL 握手
        if ($this->transport === 'ssl' && $this->_sslHandshakeCompleted !== true) {
            $ret = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_SSLv2_SERVER |
                STREAM_CRYPTO_METHOD_SSLv3_SERVER | STREAM_CRYPTO_METHOD_SSLv23_SERVER);
            // 失败
            if(false === $ret) {
                if (!feof($socket)) {
                    echo "\nSSL Handshake fail. ╮(๑•́ ₃•̀๑)╭ \nBuffer:".bin2hex(fread($socket, 8182))."\n";
                }
                return $this->destroy();
            } elseif(0 === $ret) {
                // 没有足够的数据，应该再试一次 ◔ ‸◔？
                return;
            }
            if (isset($this->onSslHandshake)) {
                try {
                    call_user_func($this->onSslHandshake, $this);
                } catch (\Exception $e) {
                    Worker::log($e);
                    exit(250);
                } catch (\Error $e) {
                    Worker::log($e);
                    exit(250);
                }
            }
            $this->_sslHandshakeCompleted = true;
            if ($this->_sendBuffer) {
                Worker::$globalEvent->add($socket, EventInterface::EV_WRITE, array($this, 'baseWrite'));
            }
            return;
        }

        $buffer = @fread($socket, self::READ_BUFFER_SIZE);

        // 检查连接是否关闭
        if ($buffer === '' || $buffer === false) {
            if ($check_eof && (feof($socket) || !is_resource($socket) || $buffer === false)) {
                $this->destroy();
                return;
            }
        } else {
            $this->bytesRead += strlen($buffer);
            $this->_recvBuffer .= $buffer;
        }

        // 如果已经建立了应用层协议
        if ($this->protocol !== null) {
            $parser = $this->protocol;
            while ($this->_recvBuffer !== '' && !$this->_isPaused) {
                // 当前的packet长度是已知的
                if ($this->_currentPackageLength) {
                    //  package数据不足
                    if ($this->_currentPackageLength > strlen($this->_recvBuffer)) {
                        break;
                    }
                } else {
                    // 获取当前package长度
                    $this->_currentPackageLength = $parser::input($this->_recvBuffer, $this);
                    // packet长度未知
                    if ($this->_currentPackageLength === 0) {
                        break;
                    } elseif ($this->_currentPackageLength > 0 && $this->_currentPackageLength <= self::$maxPackageSize) {
                        // package数据不足
                        if ($this->_currentPackageLength > strlen($this->_recvBuffer)) {
                            break;
                        }
                    } // 错误的 package.
                    else {
                        echo 'error package. package_length=' . var_export($this->_currentPackageLength, true);
                        $this->destroy();
                        return;
                    }
                }

                // 数据对于一个数据包来说已经足够了
                self::$statistics['total_request']++;
                // 当前package长度等于缓冲器的长度
                if (strlen($this->_recvBuffer) === $this->_currentPackageLength) {
                    $one_request_buffer = $this->_recvBuffer;
                    $this->_recvBuffer  = '';
                } else {
                    // 从缓冲区获得完整的package
                    $one_request_buffer = substr($this->_recvBuffer, 0, $this->_currentPackageLength);
                    // 从接收缓冲器中提取当前package
                    $this->_recvBuffer = substr($this->_recvBuffer, $this->_currentPackageLength);
                }
                // 重置当前packet为0
                $this->_currentPackageLength = 0;
                if (!$this->onMessage) {
                    continue;
                }
                try {
					//在发出回调之前解码请求缓冲区
                    call_user_func($this->onMessage, $this, $parser::decode($one_request_buffer, $this));
                } catch (\Exception $e) {
                    Worker::log($e);
                    exit(250);
                } catch (\Error $e) {
                    Worker::log($e);
                    exit(250);
                }
            }
            return;
        }

        if ($this->_recvBuffer === '' || $this->_isPaused) {
            return;
        }

        // 没有设置的应用协议
        self::$statistics['total_request']++;
        if (!$this->onMessage) {
            $this->_recvBuffer = '';
            return;
        }
        try {
            call_user_func($this->onMessage, $this, $this->_recvBuffer);
        } catch (\Exception $e) {
            Worker::log($e);
            exit(250);
        } catch (\Error $e) {
            Worker::log($e);
            exit(250);
        }
        // 清除接收缓冲区
        $this->_recvBuffer = '';
    }

    /**
     * 基本处理程序
     *
     * @return void|bool
     */
    public function baseWrite()
    {
        $len = @fwrite($this->_socket, $this->_sendBuffer, 8192);
        if ($len === strlen($this->_sendBuffer)) {
            $this->bytesWritten += $len;
            Worker::$globalEvent->del($this->_socket, EventInterface::EV_WRITE);
            $this->_sendBuffer = '';
            // 当缓冲区为空时通过onBufferDrain回调  
            if ($this->onBufferDrain) {
                try {
                    call_user_func($this->onBufferDrain, $this);
                } catch (\Exception $e) {
                    Worker::log($e);
                    exit(250);
                } catch (\Error $e) {
                    Worker::log($e);
                    exit(250);
                }
            }
            if ($this->_status === self::STATUS_CLOSING) {
                $this->destroy();
            }
            return true;
        }
        if ($len > 0) {
            $this->bytesWritten += $len;
            $this->_sendBuffer = substr($this->_sendBuffer, $len);
        } else {
            self::$statistics['send_fail']++;
            $this->destroy();
        }
    }

    /**
     * 此方法将所有数据从可读流中拉出来，并将其写入所提供的变量
     *
     * @param TcpConnection $dest
     * @return void
     */
    public function pipe($dest)
    {
        $source              = $this;
        $this->onMessage     = function ($source, $data) use ($dest) {
            $dest->send($data);
        };
        $this->onClose       = function ($source) use ($dest) {
            $dest->destroy();
        };
        $dest->onBufferFull  = function ($dest) use ($source) {
            $source->pauseRecv();
        };
        $dest->onBufferDrain = function ($dest) use ($source) {
            $source->resumeRecv();
        };
    }

    /**
     * 从接收缓冲区中删除$length长度的数据
     *
     * @param int $length
     * @return void
     */
    public function consumeRecvBuffer($length)
    {
        $this->_recvBuffer = substr($this->_recvBuffer, $length);
    }

    /**
     * 关闭连接
     *
     * @param mixed $data
     * @param bool $raw
     * @return void
     */
    public function close($data = null, $raw = false)
    {
        if ($this->_status === self::STATUS_CLOSING || $this->_status === self::STATUS_CLOSED) {
            return;
        } else {
            if ($data !== null) {
                $this->send($data, $raw);
            }
            $this->_status = self::STATUS_CLOSING;
        }
        if ($this->_sendBuffer === '') {
            $this->destroy();
        }
    }

    /**
     * 获取真实socket
     *
     * @return resource
     */
    public function getSocket()
    {
        return $this->_socket;
    }

    /**
     * 检查发送缓冲区
     *
     * @return void
     */
    protected function checkBufferWillFull()
    {
        if ($this->maxSendBufferSize <= strlen($this->_sendBuffer)) {
            if ($this->onBufferFull) {
                try {
                    call_user_func($this->onBufferFull, $this);
                } catch (\Exception $e) {
                    Worker::log($e);
                    exit(250);
                } catch (\Error $e) {
                    Worker::log($e);
                    exit(250);
                }
            }
        }
    }

    /**
     * 是否发送缓冲区已满
     *
     * @return bool
     */
    protected function bufferIsFull()
    {
        // 缓冲区已被标记为满，但仍有数据要发送，然后丢弃该数据包
        if ($this->maxSendBufferSize <= strlen($this->_sendBuffer)) {
            if ($this->onError) {
                try {
                    call_user_func($this->onError, $this, PHPIOT_SEND_FAIL, 'send buffer full and drop package');
                } catch (\Exception $e) {
                    Worker::log($e);
                    exit(250);
                } catch (\Error $e) {
                    Worker::log($e);
                    exit(250);
                }
            }
            return true;
        }
        return false;
    }

    /**
     * 销毁连接
     *
     * @return void
     */
    public function destroy()
    {
        //避免重复连接
        if ($this->_status === self::STATUS_CLOSED) {
            return;
        }
        // 删除事件监听器
        Worker::$globalEvent->del($this->_socket, EventInterface::EV_READ);
        Worker::$globalEvent->del($this->_socket, EventInterface::EV_WRITE);
        // 关闭socket.
        @fclose($this->_socket);
        // 移除 worker->connections.
        if ($this->worker) {
            unset($this->worker->connections[$this->_id]);
        }
        unset(static::$connections[$this->_id]);
        $this->_status = self::STATUS_CLOSED;
        // 发出关闭回调
        if ($this->onClose) {
            try {
                call_user_func($this->onClose, $this);
            } catch (\Exception $e) {
                Worker::log($e);
                exit(250);
            } catch (\Error $e) {
                Worker::log($e);
                exit(250);
            }
        }
        // 通过协议发送关闭
        if (method_exists($this->protocol, 'onClose')) {
            try {
                call_user_func(array($this->protocol, 'onClose'), $this);
            } catch (\Exception $e) {
                Worker::log($e);
                exit(250);
            } catch (\Error $e) {
                Worker::log($e);
                exit(250);
            }
        }
        if ($this->_status === self::STATUS_CLOSED) {
            // 清除回调以避免内存泄漏.
            $this->onMessage = $this->onClose = $this->onError = $this->onBufferFull = $this->onBufferDrain = null;
        }
    }

    /**
     * 销毁
     *
     * @return void
     */
    public function __destruct()
    {
        self::$statistics['connection_count']--;
    }
}
