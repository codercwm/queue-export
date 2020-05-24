<?php

namespace Codercwm\QueueExport;

use Illuminate\Support\Facades\Redis;

class Progress{

    //设置读取进度
    public static function incrRead($incr,$task_id=null){
        if(is_null($task_id)){
            $task_id = Id::get();
        }

        Redis::incrby($task_id.'_progress_read',$incr);
        if(0==$incr){
            Redis::expire($task_id.'_progress_read',Cache::expire(true));
        }

        return $incr;
    }

    //获取读取进度
    public static function getRead($task_id=null){
        if(is_null($task_id)){
            $task_id = Id::get();
        }
        return Redis::get($task_id.'_progress_read');
    }

    //设置写入进度
    public static function incrWrite($incr,$task_id=null){
        if(is_null($task_id)){
            $task_id = Id::get();
        }

        Redis::incrby($task_id.'_progress_write',$incr);
        if(0==$incr){
            Redis::expire($task_id.'_progress_write',Cache::expire(true));
        }

        return $incr;
    }

    //获取写入进度
    public static function getWrite($task_id=null){
        if(is_null($task_id)){
            $task_id = Id::get();
        }
        return Redis::get($task_id.'_progress_write');
    }
}