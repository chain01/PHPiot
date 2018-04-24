<?php
/**
 *定时器
 *
 * @author    chain01
 * 
 */
namespace PHPiot\Lib;

use PHPiot\Events\EventInterface;
use Exception;

/**
 * 定时器
 *
 * example:
 * PHPiot\Lib\Timer::add($time_interval, callback, array($arg1, $arg2..));
 */
class Timer
{
    /**
     * 基于ALARM signal任务的
     * [
     *   run_time => [[$func, $args, $persistent, time_interval],[$func, $args, $persistent, time_interval],..]],
     *   run_time => [[$func, $args, $persistent, time_interval],[$func, $args, $persistent, time_interval],..]],
     *   ..
     * ]
     *
     * @var array
     */
    protected static $_tasks = array();

    /**
     * event
     *
     * @var \PHPiot\Events\EventInterface
     */
    protected static $_event = null;

    /**
     * 初始化
     *
     * @param \PHPiot\Events\EventInterface $event
     * @return void
     */
    public static function init($event = null)
    {
        if ($event) {
            self::$_event = $event;
        } else {
            pcntl_signal(SIGALRM, array('\PHPiot\Lib\Timer', 'signalHandle'), false);
        }
    }

    /**
     * ALARM signal 部分
     *
     * @return void
     */
    public static function signalHandle()
    {
        if (!self::$_event) {
            pcntl_alarm(1);
            self::tick();
        }
    }

    /**
     * 添加一个定时器
     *
     * @param int      $time_interval
     * @param callback $func
     * @param mixed    $args
     * @param bool     $persistent
     * @return int/false
     */
    public static function add($time_interval, $func, $args = array(), $persistent = true)
    {
        if ($time_interval <= 0) {
            echo new Exception("bad time_interval");
            return false;
        }

        if (self::$_event) {
            return self::$_event->add($time_interval,
                $persistent ? EventInterface::EV_TIMER : EventInterface::EV_TIMER_ONCE, $func, $args);
        }

        if (!is_callable($func)) {
            echo new Exception("not callable");
            return false;
        }

        if (empty(self::$_tasks)) {
            pcntl_alarm(1);
        }

        $time_now = time();
        $run_time = $time_now + $time_interval;
        if (!isset(self::$_tasks[$run_time])) {
            self::$_tasks[$run_time] = array();
        }
        self::$_tasks[$run_time][] = array($func, (array)$args, $persistent, $time_interval);
        return 1;
    }


    /**
     * 记录
     *
     * @return void
     */
    public static function tick()
    {
        if (empty(self::$_tasks)) {
            pcntl_alarm(0);
            return;
        }

        $time_now = time();
        foreach (self::$_tasks as $run_time => $task_data) {
            if ($time_now >= $run_time) {
                foreach ($task_data as $index => $one_task) {
                    $task_func     = $one_task[0];
                    $task_args     = $one_task[1];
                    $persistent    = $one_task[2];
                    $time_interval = $one_task[3];
                    try {
                        call_user_func_array($task_func, $task_args);
                    } catch (\Exception $e) {
                        echo $e;
                    }
                    if ($persistent) {
                        self::add($time_interval, $task_func, $task_args);
                    }
                }
                unset(self::$_tasks[$run_time]);
            }
        }
    }

    /**
     * 移除定时器
     *
     * @param mixed $timer_id
     * @return bool
     */
    public static function del($timer_id)
    {
        if (self::$_event) {
            return self::$_event->del($timer_id, EventInterface::EV_TIMER);
        }

        return false;
    }

    /**
     * 移除所有定时器
     *
     * @return void
     */
    public static function delAll()
    {
        self::$_tasks = array();
        pcntl_alarm(0);
        if (self::$_event) {
            self::$_event->clearAllTimer();
        }
    }
}
