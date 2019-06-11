<?php

namespace vyants\daemon;

use vyants\daemon\DaemonController;

/**
 * Single point of entry for logging various messages
 *
 * @author mirek
 *
 */
class Logger extends \yii\base\BaseObject
{
    const LEVEL_INFO = 'info';
    const LEVEL_TRACE = 'trace';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';

    private $_firstRun = true;

    /**
     * @var DaemonController
     */
    public $controller;

    public function log($msg, $level)
    {
        $processName = $this->controller->getProcessName();
        if ($this->_firstRun) {
            openlog($processName, LOG_PID, LOG_DAEMON);
            $this->_firstRun = false;
        }
        if (in_array($level, [self::LEVEL_ERROR, self::LEVEL_INFO, self::LEVEL_TRACE, self::LEVEL_WARNING])) {
            switch ($level) {
                case self::LEVEL_INFO:
                    \Yii::info($msg, $processName);
                    $priority = LOG_INFO;
                    break;
                case self::LEVEL_TRACE:
                    \Yii::trace($msg, $processName);
                    $priority = LOG_DEBUG;
                    break;
                case self::LEVEL_ERROR:
                    \Yii::error($msg, $processName);
                    $priority = LOG_ERR;
                    break;
                case self::LEVEL_WARNING:
                    \Yii::warning($msg, $processName);
                    $priority = LOG_WARNING;
                    break;
            }
            if ($this->controller->debug || $priority != LOG_DEBUG) {
                syslog($priority, $msg);
            }
        }
    }
}
