<?php

namespace Codercwm\QueueExport;

use Illuminate\Support\Facades\Cache as LaravelCache;
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

    /*
     * 判断是否完成
     */
    public static function isCompleted(){

        //坑：因为数据库会被插入数据，所以读取出来的数量可能会大于一开始时统计的数量

        //导出的数量不等于总数量，不合并
        if(self::getRead()<Info::get('total_count')){
            return false;
        }

        //写入文件的行数不等于总数量，不合并
        if(self::getWrite()<Info::get('total_count')){
            return false;
        }

        //如果任务失败，不合并
        if(1==Cache::isFail()){
            return false;
        }

        //如果任务取消，不合并
        if(1==Cache::isCancel()){
            return false;
        }

        //把任务设置成已完成，如果设置失败，不合并
        if(!Cache::complete(true)){
            return false;
        }

        return true;
    }

    //取消任务
    public static function cancel(){
        Cache::isCancel(true);
        Cache::showName('任务已取消');
        File::delDir();
        LaravelCache::forget(File::path(true));
        Log::write('任务已取消');
        LaravelCache::forget(Id::get());
    }

    /**
     * 任务失败处理
     */
    public static function fail($exception){
        if(LaravelCache::has(Id::get()) && ('任务已取消'!=Cache::showName())){
            Cache::isFail(true);
            Cache::showName('任务执行失败');
            //清除数据缓存
            LaravelCache::forget(File::path(true));
        }
        Log::write($exception);
    }
}