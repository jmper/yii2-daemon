<?php

namespace vyants\daemon;

use yii\base\NotSupportedException;
use yii\console\Controller;
use yii\helpers\Console;
use vyants\daemon\Logger;

/**
 * Class DaemonController
 *
 * @author Vladimir Yants <vladimir.yants@gmail.com>
 */
abstract class DaemonController extends Controller
{

    const EVENT_BEFORE_JOB = "EVENT_BEFORE_JOB";
    const EVENT_AFTER_JOB = "EVENT_AFTER_JOB";

    const EVENT_BEFORE_ITERATION = "event_before_iteration";
    const EVENT_AFTER_ITERATION = "event_after_iteration";

    /**
     * @var $demonize boolean Run controller as Daemon
     * @default false
     */
    public $demonize = false;

    /**
     * @var $isMultiInstance boolean allow daemon create a few instances
     * @see $maxChildProcesses
     * @default false
     */
    public $isMultiInstance = false;

    /**
     * @var directory to store pid files
     */
    public $pidDir = "@runtime/daemons/pids";

    /**
     * @var directory to store logs
     */
    public $logDir = "@runtime/daemons/logs";

    public $debug = false;

    /**
     * @var $parentPID int main procces pid
     */
    protected $parentPID;

    /**
     * @var $maxChildProcesses int max daemon instances
     * @default 10
     */
    public $maxChildProcesses = 10;

    /**
     * @var $currentJobs [] array of running instances
     */
    protected static $currentJobs = [];

    /**
     * @var int Memory limit for daemon, must bee less than php memory_limit
     * @default 256M
     */
    protected $memoryLimit = 268435456;

    protected $logger;

    /**
     * @var boolean used for soft daemon stop, set 1 to stop
     */
    protected static $stopFlag = false;

    protected static $debugMode = false;
    /**
     * @var int delay between task list checking (only if there is no tasks available)
     * @default 5sec
     */
    protected $sleep = 5;

    /**
     * @var int delay after each iteration (even if there are tasks to do)
     * @default 5sec
     */
    protected $slowDown = 5;

    private $stdIn;
    private $stdOut;
    private $stdErr;

    /**
     * Init function
     */
    public function init()
    {
        parent::init();
        $this->createLogger();
        self::$debugMode = $this->debug;
        //set PCNTL signal handlers
        pcntl_signal(SIGTERM, ['vyants\daemon\DaemonController', 'signalHandler']);
        pcntl_signal(SIGINT, ['vyants\daemon\DaemonController', 'signalHandler']);
        pcntl_signal(SIGHUP, ['vyants\daemon\DaemonController', 'signalHandler']);
        pcntl_signal(SIGUSR1, ['vyants\daemon\DaemonController', 'signalHandler']);
        pcntl_signal(SIGCHLD, ['vyants\daemon\DaemonController', 'signalHandler']);
    }

     /**
     * @param $pid
     *
     * @return bool
     */
    public function isProcessRunning($pid)
    {
        return file_exists("/proc/$pid");
    }

    public function __destruct()
    {
        $this->deletePid();
    }

    /**
     * Adjusting logger. You can override it.
     */
    protected function initLogger()
    {
        $targets = \Yii::$app->getLog()->targets;
        foreach ($targets as $name => $target) {
            $target->enabled = false;
        }
        $config = [
            'levels' => ['error', 'warning', 'trace', 'info'],
            'logFile' => \Yii::getAlias($this->logDir) . DIRECTORY_SEPARATOR . $this->getProcessName() . '.log',
            'logVars' => [],
            'except' => [
                'yii\db\*', // Don't include messages from db
            ],
        ];
        $targets['daemon'] = new \yii\log\FileTarget($config);
        \Yii::$app->getLog()->targets = $targets;
        \Yii::$app->getLog()->init();
    }

    /**
     * Daemon worker body
     *
     * @param $job
     *
     * @return boolean
     */
    abstract protected function doJob($job);

    /**
     * Base action, you can\t override or create another actions
     * @return bool
     * @throws NotSupportedException
     */
    final public function actionIndex()
    {
        if ($this->demonize) {
            $pid = pcntl_fork();
            if ($pid == -1) {
                $this->halt(self::EXIT_CODE_ERROR, 'pcntl_fork() rise error');
            } elseif ($pid) {
                $this->cleanLog();
                $this->halt(self::EXIT_CODE_NORMAL);
            } else {
                posix_setsid();
                $this->closeStdStreams();
            }
        }
        $this->changeProcessName();

        //run loop
        $result = $this->loop();
        $this->cleanup();
        return $result;
    }

    protected function cleanup()
    {
        $this->terminateChildren();
    }

    /**
     * Set new process name
     */
    protected function changeProcessName()
    {
        //rename process
        if (version_compare(PHP_VERSION, '5.5.0') >= 0) {
            cli_set_process_title($this->getProcessName());
        } else {
            if (function_exists('setproctitle')) {
                setproctitle($this->getProcessName());
            } else {
                $this->log('Can\'t find cli_set_process_title or setproctitle function', Logger::LEVEL_ERROR);
            }
        }
    }

    /**
     * Close std streams and open to /dev/null
     * need some class properties
     */
    protected function closeStdStreams()
    {
        if (is_resource(STDIN)) {
            fclose(STDIN);
            $this->stdIn = fopen('/dev/null', 'r');
        }
        if (is_resource(STDOUT)) {
            fclose(STDOUT);
            $this->stdOut = fopen('/dev/null', 'ab');
        }
        if (is_resource(STDERR)) {
            fclose(STDERR);
            $this->stdErr = fopen('/dev/null', 'ab');
        }
    }

    protected function terminateChildren()
    {
        foreach (static::$currentJobs as $pid) {
            $this->terminateChild($pid);
        }
    }

    protected function terminateChild($pid)
    {
        if ($this->isProcessRunning($pid)) {
            if (isset($job['hardKill']) && $job['hardKill']) {
                posix_kill($pid, SIGKILL);
            } else {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }
        }
    }


    /**
     * Prevent non index action running
     *
     * @param \yii\base\Action $action
     *
     * @return bool
     * @throws NotSupportedException
     */
    public function beforeAction($action)
    {
        if (parent::beforeAction($action)) {
            $this->initLogger();
            if ($action->id != "index") {
                throw new NotSupportedException(
                    "Only index action is allowed in daemons. You can\'t create and call another"
                );
            }

            return true;
        } else {
            return false;
        }
    }

    /**
     * Return available options
     *
     * @param string $actionID
     *
     * @return array
     */
    public function options($actionID)
    {
        return [
            'demonize',
            'taskLimit',
            'isMultiInstance',
            'maxChildProcesses',
            'debug',
        ];
    }

    public function log($msg, $level)
    {
        $this->logger->log($msg, $level);
    }

    /**
     * Extract current unprocessed jobs
     * You can extract jobs from DB (DataProvider will be great), queue managers (ZMQ, RabbiMQ etc), redis and so on
     *
     * @return array with jobs
     */
    abstract protected function defineJobs();

    /**
     * Fetch one task from array of tasks
     *
     * @param Array
     *
     * @return mixed one task
     */
    protected function defineJobExtractor(&$jobs)
    {
        return array_shift($jobs);
    }

    /**
     * Main Loop
     *
     * * @return boolean 0|1
     */
    final private function loop()
    {
        if (file_put_contents($this->getPidPath(), getmypid())) {
            $this->log('pid file created: ' . $this->getPidPath(), Logger::LEVEL_TRACE);
            $this->parentPID = getmypid();
            $this->log('started', Logger::LEVEL_INFO);
            while (!self::$stopFlag) {
                if (memory_get_usage() > $this->memoryLimit) {
                    $this->log($this->getProcessName() . ' (pid ' .
                        getmypid() . ') used ' . memory_get_usage() . ' bytes on ' . $this->memoryLimit .
                        ' bytes allowed by memory limit', Logger::LEVEL_WARNING);
                    break;
                }
                $this->trigger(self::EVENT_BEFORE_ITERATION);
                $this->renewConnections();
                $jobs = $this->defineJobs();
                if ($jobs && !empty($jobs)) {
                    while (($job = $this->defineJobExtractor($jobs)) !== null) {
                        //if no free workers, wait
                        if ($this->isMultiInstance && (count(static::$currentJobs) >= $this->maxChildProcesses)) {
                            $this->log('Reached maximum number of child processes. Waiting...', Logger::LEVEL_TRACE);
                            while (count(static::$currentJobs) >= $this->maxChildProcesses) {
                                sleep(1);
                                pcntl_signal_dispatch();
                            }
                            $this->log(
                                'Free workers found: ' .
                                ($this->maxChildProcesses - count(static::$currentJobs)) .
                                ' worker(s). Delegate tasks.', Logger::LEVEL_TRACE
                            );
                        }
                        pcntl_signal_dispatch();
                        $this->runDaemon($job);
                    }
                } else {
                    self::sleep($this->sleep);
                }
                $this->trigger(self::EVENT_AFTER_ITERATION);
                self::sleep($this->slowDown);
            }

            $this->log('terminated', Logger::LEVEL_INFO);

            return self::EXIT_CODE_NORMAL;
        }
        $this->halt(self::EXIT_CODE_ERROR, 'Can\'t create pid file ' . $this->getPidPath());
    }

    /**
     * Delete pid file
     */
    protected function deletePid()
    {
        $pid = $this->getPidPath();
        if (file_exists($pid)) {
            if (file_get_contents($pid) == getmypid()) {
                unlink($this->getPidPath());
                $this->log('Unlinked pid file ' . $this->getPidPath(), Logger::LEVEL_TRACE);
            }
        }
    }

    /**
     * PCNTL signals handler
     *
     * @param $signo
     * @param null $pid
     * @param null $status
     */
    final static function signalHandler($signo, $siginfo = null)
    {
        $status = null;
        switch ($signo) {
            case SIGINT:
            case SIGTERM:
                //shutdown
                self::$stopFlag = true;
                break;
            case SIGHUP:
                //restart, not implemented
                break;
            case SIGUSR1:
                //user signal, not implemented
                break;
            case SIGCHLD:
                if (!$siginfo) {
                    $pid = pcntl_waitpid(-1, $status, WNOHANG);
                } else {
                    $pid = $siginfo['pid'];
                }
                while ($pid > 0) {
                    if ($pid && isset(static::$currentJobs[$pid])) {
                        unset(static::$currentJobs[$pid]);
                    }
                    $pid = pcntl_waitpid(-1, $status, WNOHANG);
                }
                break;
        }
    }

    /**
     * Tasks runner
     *
     * @param string $job
     *
     * @return boolean
     */
    final public function runDaemon($job)
    {
        if ($this->isMultiInstance) {
            $this->flushLog();
            $pid = pcntl_fork();
            if ($pid == -1) {
                return false;
            } elseif ($pid !== 0) {
                static::$currentJobs[$pid] = true;

                return true;
            } else {
                $this->cleanLog();
                $this->renewConnections();
                //child process must die
                $this->trigger(self::EVENT_BEFORE_JOB);
                $status = $this->doJob($job);
                $this->trigger(self::EVENT_AFTER_JOB);
                if ($status) {
                    $this->halt(self::EXIT_CODE_NORMAL);
                } else {
                    $this->halt(self::EXIT_CODE_ERROR, 'Child process #' . $pid . ' returned error.');
                }
            }
        } else {
            $this->trigger(self::EVENT_BEFORE_JOB);
            $status = $this->doJob($job);
            $this->trigger(self::EVENT_AFTER_JOB);

            return $status;
        }
    }

    /**
     * Stop process and show or write message
     *
     * @param $code int -1|0|1
     * @param $message string
     */
    protected function halt($code, $message = null)
    {
        if ($message !== null) {
            if ($code == self::EXIT_CODE_ERROR) {
                $this->log($message, Logger::LEVEL_ERROR);
                if (!$this->demonize) {
                    $message = Console::ansiFormat($message, [Console::FG_RED]);
                }
            } else {
                $this->log($message, Logger::LEVEL_INFO);
            }
            if (!$this->demonize) {
                $this->writeConsole($message);
            }
        }
        if ($code !== -1) {
            \Yii::$app->end($code);
        }
    }

    /**
     * Renew connections
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\db\Exception
     */
    protected function renewConnections()
    {
        if (!isset(\Yii::$app->db)) {
            return;
        }
        $retryCount = 0;
        $ok = false;
        while (!$ok) {
            try {
                \Yii::$app->db->close();
                \Yii::$app->db->open();
                $ok = true;
            } catch (\Exception $e) {
                $this->log('retrying db connection renewal due to error: ' . $e->getMessage(), Logger::LEVEL_TRACE);
                $retryCount++;
                if ($retryCount > 5) {
                    throw $e;
                }
                usleep(100);
            }
        }
        if ($retryCount > 0) {
            $this->log(sprintf('DB connection reopened after %d retries', $retryCount), Logger::LEVEL_TRACE);
        }
    }

    /**
     * Show message in console
     *
     * @param $message
     */
    private function writeConsole($message)
    {
        $out = Console::ansiFormat('[' . date('d.m.Y H:i:s') . '] ', [Console::BOLD]);
        $this->stdout($out . $message . "\n");
    }

    /**
     * @param string $daemon
     *
     * @return string
     */
    public function getPidPath($daemon = null)
    {
        $dir = \Yii::getAlias($this->pidDir);
        if (!file_exists($dir)) {
            mkdir($dir, 0744, true);
        }
        $daemon = $this->getProcessName($daemon);

        return $dir . DIRECTORY_SEPARATOR . $daemon;
    }

    /**
     * @return string
     */
    public function getProcessName($route = null)
    {
        if (is_null($route)) {
            if (!empty(\Yii::$app)) {
                $route = \Yii::$app->requestedRoute;
            } else {
                return null;
            }
        }

        return str_replace(['/index', '/'], ['', '.'], $route);
    }

    /**
     *  If in daemon mode - no write to console
     *
     * @param string $string
     *
     * @return bool|int
     */
    public function stdout($string)
    {
        if (!$this->demonize && is_resource(STDOUT)) {
            return parent::stdout($string);
        } else {
            return false;
        }
    }

    /**
     * If in daemon mode - no write to console
     *
     * @param string $string
     *
     * @return int
     */
    public function stderr($string)
    {
        if (!$this->demonize && is_resource(\STDERR)) {
            return parent::stderr($string);
        } else {
            return false;
        }
    }

    protected function createLogger()
    {
        $this->logger = \Yii::createObject(['class' => Logger::class, 'controller' => $this]);
    }

    /**
     * Empty log queue
     */
    protected function cleanLog()
    {
        \Yii::$app->log->logger->messages = [];
    }

    /**
     * Empty log queue
     */
    protected function flushLog($final = false)
    {
        \Yii::$app->log->logger->flush($final);
    }

    protected function sleep($seconds)
    {
        $remaining = $seconds;
        while ($remaining > 0 && !self::$stopFlag) {
            pcntl_signal_dispatch();
            if (!self::$stopFlag) {
                $remaining = sleep($remaining);
            }
            if ($remaining) {
                $this->log('sleep interrupted, remaining ' . $remaining . ' seconds, stop flag is ' . (self::$stopFlag ? 'true' : 'false'), Logger::LEVEL_TRACE);
            }
        }
    }
}
