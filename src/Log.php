<?php

namespace Codercwm\QueueExport;

use Codercwm\QueueExport\Services\LogService;
use Codercwm\QueueExport\CourseContent\Info;

class Log{
    public static function write($exception){
        $str = '';
        if($exception instanceof \Exception){
            $str = $exception->getMessage().' FILE : '.$exception->getFile().' LINE : '.$exception->getLine();
        }else{
            if(is_string($exception)){
                $str = $exception;
            }else{
                $str = Tool::enJson($exception);
            }
        }

        LogService::write('['.Id::get().']'.$str,'EXPORT_QUEUE');
    }
}