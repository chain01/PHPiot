<?php
/**
 * 协议接口
 *
 * @author    chain01
 * 
 */
namespace PHPiot\Protocols;

use PHPiot\Connection\ConnectionInterface;

/**
 * 协议接口
 */
interface ProtocolInterface
{
    /**
     * 检查包装的完整性
     * 需要返回包的长度
     * 如果包的长度未知返回0，会请求更多数据
     * 如果包损坏返回false ，将关闭连接
     *
     * @param ConnectionInterface $connection
     * @param string              $recv_buffer
     * @return int|false
     */
    public static function input($recv_buffer, ConnectionInterface $connection);

    /**
     * 解包并触发onMessage($message)回调, $message 是包解码返回的结果
     *
     * @param ConnectionInterface $connection
     * @param string              $recv_buffer
     * @return mixed
     */
    public static function decode($recv_buffer, ConnectionInterface $connection);

    /**
     * 编码包发送到客户端
     *
     * @param ConnectionInterface $connection
     * @param mixed               $data
     * @return string
     */
    public static function encode($data, ConnectionInterface $connection);
}
