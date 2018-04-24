<?php
/**
 * Frame协议
 * 定义了一种叫做frame的协议，协议格式为 总包长+包体，其中包长为4字节网络字节序的整数，包体可以是普通文本或者二进制数据
 * @author    chain01
 * 
 */
namespace PHPiot\Protocols;

use PHPiot\Connection\TcpConnection;

/**
 * Frame 协议
 */
class Frame
{
    /**
     * 检查包装的完整性
     *
     * @param string        $buffer
     * @param TcpConnection $connection
     * @return int
     */
    public static function input($buffer, TcpConnection $connection)
    {
        if (strlen($buffer) < 4) {
            return 0;
        }
        $unpack_data = unpack('Ntotal_length', $buffer);
        return $unpack_data['total_length'];
    }

    /**
     * 解码
     *
     * @param string $buffer
     * @return string
     */
    public static function decode($buffer)
    {
        return substr($buffer, 4);
    }

    /**
     * 编码
     *
     * @param string $buffer
     * @return string
     */
    public static function encode($buffer)
    {
        $total_length = 4 + strlen($buffer);
        return pack('N', $total_length) . $buffer;
    }
}
