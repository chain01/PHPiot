<?php
/**
 * select选中
 *
 * @author    chain01
 * 
 */

namespace PHPiot\Events;

/**
 * select eventloop
 */
class Select implements EventInterface
{
    /**
     * 所有侦听/写入事件的侦听器
     * @var array
     */
    public $_allEvents = array();

    /**
     * select中断侦听器
     *
     * @var array
     */
    public $_signalEvents = array();

    /**
     * Fds 等待读取
     *
     * @var array
     */
    protected $_readFds = array();

    /**
     * Fds 等待写入
     *
     * @var array
     */
    protected $_writeFds = array();

    /**
     * Fds 异常事件
     *
     * @var array
     */
    protected $_exceptFds = array();

    /**
     * 定时器调度
     * {['data':timer_id, 'priority':run_timestamp], ..}
     *
     * @var \SplPriorityQueue
     */
    protected $_scheduler = null;

    /**
     * 所有定时器事件侦听器.
     * [[func, args, flag, timer_interval], ..]
     *
     * @var array
     */
    protected $_eventTimer = array();

    /**
     * 定时器id.
     *
     * @var int
     */
    protected $_timerId = 1;

    /**
     * 超时
     *
     * @var int
     */
    protected $_selectTimeout = 100000000;

    /**
     * 创建一对
     *
     * @var array
     */
    protected $channel = array();

    /**
     * 构建
     */
    public function __construct()
    {
        // 创建通道防止空询
        $this->channel = stream_socket_pair(DIRECTORY_SEPARATOR === '/' ? STREAM_PF_UNIX : STREAM_PF_INET,
            STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if($this->channel) {
            stream_set_blocking($this->channel[0], 0);
            $this->_readFds[0] = $this->channel[0];
        }
        // 初始化 SplPriorityQueue.
        $this->_scheduler = new \SplPriorityQueue();
        $this->_scheduler->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
    }

    /**
     * {@inheritdoc}
     */
    public function add($fd, $flag, $func, $args = array())
    {
        switch ($flag) {
            case self::EV_READ:
                $fd_key                           = (int)$fd;
                $this->_allEvents[$fd_key][$flag] = array($func, $fd);
                $this->_readFds[$fd_key]          = $fd;
                break;
            case self::EV_WRITE:
                $fd_key                           = (int)$fd;
                $this->_allEvents[$fd_key][$flag] = array($func, $fd);
                $this->_writeFds[$fd_key]         = $fd;
                break;
            case self::EV_EXCEPT:
                $fd_key = (int)$fd;
                $this->_allEvents[$fd_key][$flag] = array($func, $fd);
                $this->_exceptFds[$fd_key] = $fd;
                break;
            case self::EV_SIGNAL:
                // Windows不支持
                if(DIRECTORY_SEPARATOR !== '/') {
                    return false;
                }
                $fd_key                              = (int)$fd;
                $this->_signalEvents[$fd_key][$flag] = array($func, $fd);
                pcntl_signal($fd, array($this, 'signalHandler'));
                break;
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:
                $timer_id = $this->_timerId++;
                $run_time = microtime(true) + $fd;
                $this->_scheduler->insert($timer_id, -$run_time);
                $this->_eventTimer[$timer_id] = array($func, (array)$args, $flag, $fd);
                $select_timeout = ($run_time - microtime(true)) * 1000000;
                if( $this->_selectTimeout > $select_timeout ){ 
                    $this->_selectTimeout = $select_timeout;   
                }  
                return $timer_id;
        }

        return true;
    }

    /**
     * Signal 处理部分
     *
     * @param int $signal
     */
    public function signalHandler($signal)
    {
        call_user_func_array($this->_signalEvents[$signal][self::EV_SIGNAL][0], array($signal));
    }

    /**
     * {@inheritdoc}
     */
    public function del($fd, $flag)
    {
        $fd_key = (int)$fd;
        switch ($flag) {
            case self::EV_READ:
                unset($this->_allEvents[$fd_key][$flag], $this->_readFds[$fd_key]);
                if (empty($this->_allEvents[$fd_key])) {
                    unset($this->_allEvents[$fd_key]);
                }
                return true;
            case self::EV_WRITE:
                unset($this->_allEvents[$fd_key][$flag], $this->_writeFds[$fd_key]);
                if (empty($this->_allEvents[$fd_key])) {
                    unset($this->_allEvents[$fd_key]);
                }
                return true;
            case self::EV_EXCEPT:
                unset($this->_allEvents[$fd_key][$flag], $this->_exceptFds[$fd_key]);
                if(empty($this->_allEvents[$fd_key]))
                {
                    unset($this->_allEvents[$fd_key]);
                }
                return true;
            case self::EV_SIGNAL:
                if(DIRECTORY_SEPARATOR !== '/') {
                    return false;
                }
                unset($this->_signalEvents[$fd_key]);
                pcntl_signal($fd, SIG_IGN);
                break;
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE;
                unset($this->_eventTimer[$fd_key]);
                return true;
        }
        return false;
    }

    /**
     * 定时器
     *
     * @return void
     */
    protected function tick()
    {
        while (!$this->_scheduler->isEmpty()) {
            $scheduler_data       = $this->_scheduler->top();
            $timer_id             = $scheduler_data['data'];
            $next_run_time        = -$scheduler_data['priority'];
            $time_now             = microtime(true);
            $this->_selectTimeout = ($next_run_time - $time_now) * 1000000;
            if ($this->_selectTimeout <= 0) {
                $this->_scheduler->extract();

                if (!isset($this->_eventTimer[$timer_id])) {
                    continue;
                }

                // [func, args, flag, timer_interval]
                $task_data = $this->_eventTimer[$timer_id];
                if ($task_data[2] === self::EV_TIMER) {
                    $next_run_time = $time_now + $task_data[3];
                    $this->_scheduler->insert($timer_id, -$next_run_time);
                }
                call_user_func_array($task_data[0], $task_data[1]);
                if (isset($this->_eventTimer[$timer_id]) && $task_data[2] === self::EV_TIMER_ONCE) {
                    $this->del($timer_id, self::EV_TIMER_ONCE);
                }
                continue;
            }
            return;
        }
        $this->_selectTimeout = 100000000;
    }

    /**
     * {@inheritdoc}
     */
    public function clearAllTimer()
    {
        $this->_scheduler = new \SplPriorityQueue();
        $this->_scheduler->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
        $this->_eventTimer = array();
    }

    /**
     * {@inheritdoc}
     */
    public function loop()
    {
        $e = null;
        while (1) {
            if(DIRECTORY_SEPARATOR === '/') {
                // 调用未处理的signals中断
                pcntl_signal_dispatch();
            }

            $read  = $this->_readFds;
            $write = $this->_writeFds;
            $except = $this->_writeFds;

            // Waiting read/write/signal/timeout events.
            $ret = @stream_select($read, $write, $except, 0, $this->_selectTimeout);

            if (!$this->_scheduler->isEmpty()) {
                $this->tick();
            }

            if (!$ret) {
                continue;
            }

            if ($read) {
                foreach ($read as $fd) {
                    $fd_key = (int)$fd;
                    if (isset($this->_allEvents[$fd_key][self::EV_READ])) {
                        call_user_func_array($this->_allEvents[$fd_key][self::EV_READ][0],
                            array($this->_allEvents[$fd_key][self::EV_READ][1]));
                    }
                }
            }

            if ($write) {
                foreach ($write as $fd) {
                    $fd_key = (int)$fd;
                    if (isset($this->_allEvents[$fd_key][self::EV_WRITE])) {
                        call_user_func_array($this->_allEvents[$fd_key][self::EV_WRITE][0],
                            array($this->_allEvents[$fd_key][self::EV_WRITE][1]));
                    }
                }
            }

            if($except) {
                foreach($except as $fd) {
                    $fd_key = (int) $fd;
                    if(isset($this->_allEvents[$fd_key][self::EV_EXCEPT])) {
                        call_user_func_array($this->_allEvents[$fd_key][self::EV_EXCEPT][0],
                            array($this->_allEvents[$fd_key][self::EV_EXCEPT][1]));
                    }
                }
            }
        }
    }

    /**
     * 注销
     *
     * @return void
     */
    public function destroy()
    {

    }

    /**
     * 获取定时器
     *
     * @return integer
     */
    public function getTimerCount()
    {
        return count($this->_eventTimer);
    }
}
