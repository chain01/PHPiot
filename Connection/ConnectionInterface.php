<?php
/**
 * 连接接口部分
 *
 * @author    chain01
 * 
 */
namespace PHPiot\Connection;

/**
 * 连接接口
 */
abstract class  ConnectionInterface
{
    /**
     * command状态统计
     *
     * @var array
     */
    public static $statistics = array(
        'connection_count' => 0,
        'total_request'    => 0,
        'throw_exception'  => 0,
        'send_fail'        => 0,
    );

    /**
     * 收到数据时发出时
     *
     * @var callback
     */
    public $onMessage = null;

    /**
     * socket另一端发送FIN packet时.
     *
     * @var callback
     */
    public $onClose = null;

    /**
     * 连接错误时
     *
     * @var callback
     */
    public $onError = null;

    /**
     * 发送数据
     *
     * @param string $send_buffer
     * @return void|boolean
     */
    abstract public function send($send_buffer);

    /**
     * 获取远程主机IP
     *
     * @return string
     */
    abstract public function getRemoteIp();

    /**
     * 获取远程主机端口
     *
     * @return int
     */
    abstract public function getRemotePort();

    /**
     * 获取远程主机地址
     *
     * @return string
     */
    abstract public function getRemoteAddress();

    /**
     * 获取本地IP
     *
     * @return string
     */
    abstract public function getLocalIp();

    /**
     * 获取本地端口
     *
     * @return int
     */
    abstract public function getLocalPort();

    /**
     * 获取本地地址
     *
     * @return string
     */
    abstract public function getLocalAddress();

    /**
     * ipv4.
     *
     * @return bool
     */
    abstract public function isIPv4();

    /**
     * ipv6.
     *
     * @return bool
     */
    abstract public function isIPv6();

    /**
     * 断开连接.
     *
     * @param $data
     * @return void
     */
    abstract public function close($data = null);
}
