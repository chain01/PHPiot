<?php
/**
 * Event接口
 *
 * @author    chain01
 * 
 */
namespace PHPiot\Events;

interface EventInterface
{
    /**
     * 获取 event.
     *
     * @var int
     */
    const EV_READ = 1;

    /**
     * 写入 event.
     *
     * @var int
     */
    const EV_WRITE = 2;

    /**
     * 异常event
     *
     * @var int
     */
    const EV_EXCEPT = 3;

    /**
     * event 信号.
     *
     * @var int
     */
    const EV_SIGNAL = 4;

    /**
     * event 定时器.
     *
     * @var int
     */
    const EV_TIMER = 8;

    /**
     * 长定时器 event.
     *
     * @var int
     */
    const EV_TIMER_ONCE = 16;

    /**
     * 将事件侦听器添加到事件循环
     *
     * @param mixed    $fd
     * @param int      $flag
     * @param callable $func
     * @param mixed    $args
     * @return bool
     */
    public function add($fd, $flag, $func, $args = null);

    /**
     * 从事件循环中移除事件侦听器
     *
     * @param mixed $fd
     * @param int   $flag
     * @return bool
     */
    public function del($fd, $flag);

    /**
     * 删除所有定时器.
     *
     * @return void
     */
    public function clearAllTimer();

    /**
     * 回到主函数
     *
     * @return void
     */
    public function loop();

    /**
     * 注销循环
     *
     * @return mixed
     */
    public function destroy();

    /**
     * 获取计时器计数.
     *
     * @return mixed
     */
    public function getTimerCount();
}
