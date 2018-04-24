<?php
/**
 * Udp连接部分
 *
 * @author    chain01
 * 
 */
names
namespace PHPiot\Connection;

/**
 * Udp连接
 */
class UdpConnection extends ConnectionInterface
{
    /**
     *应用层协议
     * 格式PHPiot\\Protocols\\Http.
     *
     * @var \PHPiot\Protocols\ProtocolInterface
     */
    public $protocol = null;

    /**
     * Udp socket.
     *
     * @var resource
     */
    protected $_socket = null;

    /**
     * 远程主机地址
     *
     * @var string
     */
    protected $_remoteAddress = '';

    /**
     * 建立
     *
     * @param resource $socket
     * @param string   $remote_address
     */
    public function __construct($socket, $remote_address)
    {
        $this->_socket        = $socket;
        $this->_remoteAddress = $remote_address;
    }

    /**
     * 发送数据
     *
     * @param string $send_buffer
     * @param bool   $raw
     * @return void|boolean
     */
    public function send($send_buffer, $raw = false)
    {
        if (false === $raw && $this->protocol) {
            $parser      = $this->protocol;
            $send_buffer = $parser::encode($send_buffer, $this);
            if ($send_buffer === '') {
                return null;
            }
        }
        return strlen($send_buffer) === stream_socket_sendto($this->_socket, $send_buffer, 0, $this->_remoteAddress);
    }

    /**
     * 获取远程主机IP
     *
     * @return string
     */
    public function getRemoteIp()
    {
        $pos = strrpos($this->_remoteAddress, ':');
        if ($pos) {
            return trim(substr($this->_remoteAddress, 0, $pos), '[]');
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
     * 获取远程主机address.
     *
     * @return string
     */
    public function getRemoteAddress()
    {
        return $this->_remoteAddress;
    }

    /**
     * 获取本地IP.
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
     * 获取本地 address.
     *
     * @return string
     */
    public function getLocalAddress()
    {
        return (string)@stream_socket_get_name($this->_socket, false);
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
     * ipv6.
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
     * 关闭连接
     *
     * @param mixed $data
     * @param bool  $raw
     * @return bool
     */
    public function close($data = null, $raw = false)
    {
        if ($data !== null) {
            $this->send($data, $raw);
        }
        return true;
    }
}
