<?php

namespace Codercwm\QueueExport\Services;
use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;

class LogService
{
    const LOG_EMERGENCY = 'EMERGENCY';  //紧急状况，比如系统挂掉
    const LOG_ALERT = 'ALERT';      //需要立即采取行动的问题，比如整站宕掉，数据库异常等，
    const LOG_CRITICAL = 'CRITICAL';   //严重问题，比如：应用组件无效，意料之外的异常
    const LOG_ERROR = 'ERROR';      //运行时错误，不需要立即处理但需要被记录和监控
    const LOG_WARNING = 'WARNING';    //警告但不是错误，比如使用了被废弃的API
    const LOG_NOTICE = 'NOTICE';     //普通但值得注意的事件
    const LOG_INFO = 'INFO';       //事件，比如登录、退出
    const LOG_DEBUG = 'DEBUG';      //调试日志
    const LOG_DATABASE = 'DATABASE';   //数据库日志
    const LOG_PAY = 'PAY';        //支付类日志
    const LOG_VIDEO = 'VIDEO';      //视频类日志
    const LOG_API = 'API';
    const LOG_QUEUE='QUEUE'; //队列日志
    const LOG_LISTENER='LISTENER'; //队列日志
    const LOG_EXCEL='EXCEL'; //队列日志
    const LOG_SCHEDULE = 'SCHEDULE'; //日程
    const LOG_CHANGE = 'CHANGE'; //数据变更

    private static $loggers = array();

    // 获取一个实例
    public static function getLogger($type = self::LOG_ERROR, $day = 30)
    {
        if (empty(self::$loggers[$type])) {
            self::$loggers[$type] = new Logger($type);
            $handler = (new RotatingFileHandler(storage_path("logs/" . $type . ".log"), $day))
                ->setFormatter(new LineFormatter(null, null, true, true));
            self::$loggers[$type]->pushHandler($handler);
        }

        $log = self::$loggers[$type];
        return $log;
    }

    public static function write($str, $name = LogService::LOG_NOTICE, $day = 30)
    {
        if (!is_string($str)) $str = json_encode($str);
        return LogService::getLogger($name, $day)->info($str);
    }

    public static function api($str)
    {
        return self::write($str, LogService::LOG_API, 30);
    }
    public static function excel($str)
    {
        return self::write($str, LogService::LOG_EXCEL, 30);
    }

    public static function schedule($str)
    {
        return self::write($str, LogService::LOG_SCHEDULE, 30);
    }

    public static function db($str)
    {
        return self::write($str, LogService::LOG_DATABASE, 30);
    }

    public static function debug($str)
    {
        return self::write($str, LogService::LOG_DEBUG);
    }

    public static function info($str)
    {
        return self::write($str, LogService::LOG_INFO);
    }

    public static function error($str)
    {
        return self::write($str, LogService::LOG_ERROR);
    }

    public static function alert($str)
    {
        return self::write($str, LogService::LOG_ALERT);
    }

    public static function emergency($str)
    {
        return self::write($str, LogService::LOG_EMERGENCY);
    }

    public static function critical($str)
    {
        return self::write($str, LogService::LOG_CRITICAL);
    }

    public static function warning($str)
    {
        return self::write($str, LogService::LOG_WARNING);
    }

    public static function notice($str)
    {
        return self::write($str, LogService::LOG_NOTICE);
    }

    public static function pay($str)
    {
        return self::write($str, LogService::LOG_PAY);
    }

    public static function video($str)
    {
        return self::write($str, LogService::LOG_VIDEO, 5);
    }

    public static function queue($str)
    {
        return self::write($str, LogService::LOG_QUEUE, 30);
    }
    public static function change($str)
    {
        return self::write($str, LogService::LOG_CHANGE, 30);
    }

    public static function listener($str)
    {
        return self::write($str, LogService::LOG_LISTENER, 30);
    }
}