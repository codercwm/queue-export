<?php

namespace Codercwm\QueueExport;

use Codercwm\QueueExport\Services\LogService;

class Log{
    public static function write($exception){
        $str = '';
        if($exception instanceof Exception){
            $str = $exception->getMessage().' FILE : '.$exception->getFile().' LINE : '.$exception->getLine();
        }else{
            if(is_string($exception)){
                $str = $exception;
            }else{
                $str = Tool::enJson($exception);
            }
        }

        LogService::write($str,'EXPORT_QUEUE');
    }
}