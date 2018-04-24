<?php
/**
 * Text协议
 * 自定义的一种叫做text的文本协议，协议格式为 数据包+换行符
 * 即在每个数据包末尾加上一个换行符表示包的结束。
 *
 * @author    chain01
 * 
 */
namespace PHPiot\Protocols;

use PHPiot\Connection\TcpConnection;

/**
 * Text协议
 */
class Text
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
        // 判断包装长度是否超过最大值
        if (strlen($buffer) >= TcpConnection::$maxPackageSize) {
            $connection->close();
            return 0;
        }
        //  寻找  "\n".
        $pos = strpos($buffer, "\n");
        // 没有 "\n", 包的长度未知,继续等待数据并返回 0.
        if ($pos === false) {
            return 0;
        }
        // 返回当前包长度
        return $pos + 1;
    }

    /**
     * 编码
     *
     * @param string $buffer
     * @return string
     */
    public static function encode($buffer)
    {
        // 添加 "\n"
        return $buffer . "\n";
    }

    /**
     * 解码
     *
     * @param string $buffer
     * @return string
     */
    public static function decode($buffer)
    {
        // 删除 "\n"
        return trim($buffer);
    }
}
