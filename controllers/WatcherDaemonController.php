<?php

namespace vyants\daemon\controllers;

use vyants\daemon\DaemonController;
use vyants\daemon\Logger;

/**
 * watcher-daemon - check another daemons and run it if need
 *
 * @author Vladimir Yants <vladimir.yants@gmail.com>
 */
abstract class WatcherDaemonController extends DaemonController
{
    /**
     * @var string subfolder in console/controllers
     */
    public $daemonFolder = 'daemons';

    /**
     * Prevent double start
     */
    public function init()
    {
        $pid_file = $this->getPidPath();
        if (file_exists($pid_file) && ($pid = file_get_contents($pid_file)) && file_exists("/proc/$pid")) {
            $this->halt(self::EXIT_CODE_ERROR, 'Another Watcher is already running.');
        }
        parent::init();
    }

    /**
     * Job processing body
     *
     * @param $job array
     *
     * @return boolean
     */
    protected function doJob($job)
    {
        $pid_file = $this->getPidPath($job['daemon']);

        $this->log('checking status of ' . $job['daemon'], Logger::LEVEL_TRACE);
        if (file_exists($pid_file)) {
            $pid = file_get_contents($pid_file);
            if ($this->isProcessRunning($pid)) {
                if ($job['enabled']) {
                    $this->log($job['daemon'] . ' is running and working fine', Logger::LEVEL_TRACE);
                    return true;
                } else {
                    $this->log($job['daemon'] . ' is running but disabled in config. Sending SIGTERM signal.', Logger::LEVEL_WARNING);
                    if (isset($job['hardKill']) && $job['hardKill']) {
                        posix_kill($pid, SIGKILL);
                    } else {
                        posix_kill($pid, SIGTERM);
                    }

                    return true;
                }
            }
        }
        $this->log($job['daemon'] . ': pid not found.', Logger::LEVEL_TRACE);
        if ($job['enabled']) {
            $this->log('starting ' . $job['daemon'], Logger::LEVEL_TRACE);
            $command_name = $job['daemon'] . DIRECTORY_SEPARATOR . 'index';
            //flush log before fork
            $this->flushLog(true);
            //run daemon
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->halt(self::EXIT_CODE_ERROR, 'pcntl_fork() returned error');
            } elseif ($pid === 0) {
                $this->cleanLog();
                \Yii::$app->requestedRoute = $command_name;
                unset($job['daemon']);
                unset($job['enabled']);
                \Yii::$app->runAction("$command_name", $job);
                $this->halt(0);
            } else {
                $this->initLogger();
                $this->log('started ' . $job['daemon'] . ' (pid ' . $pid . ')', Logger::LEVEL_TRACE);
            }
        }

        return true;
    }

    /**
     * @return array
     */
    protected function defineJobs()
    {
        $this->log('getting job list for checking', Logger::LEVEL_TRACE);
        return $this->getDaemonsList();
    }

    /**
     * Daemons for check. Better way - get it from database
     * [
     *  ['daemon' => 'one-daemon', 'enabled' => true]
     *  ...
     *  ['daemon' => 'another-daemon', 'enabled' => false]
     * ]
     * @return array
     */
    abstract protected function getDaemonsList();

}
