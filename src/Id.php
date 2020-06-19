<?php

namespace Codercwm\QueueExport;

use Illuminate\Support\Facades\Config as LaravelConfig;

class Id{

    private function __construct() { }

    private function __clone() { }

    private static $instance = null;

    private $taskId = null;

    //设置任务id
    //这里是一个新线程的开始
    public static function set(string $taskId){
        $cache_driver = Config::get('cache_driver');
        if('default'!=$cache_driver){
            LaravelConfig::set('cache.default',$cache_driver);
            $current_cache_driver = LaravelConfig::get('cache.stores')[$cache_driver]['driver'];
        }else{
            $current_cache_driver = LaravelConfig::get('cache.stores')[LaravelConfig::get('cache.default')]['driver'];

        }

        if(!in_array($current_cache_driver,['redis','memcached'])){
            throw new Exception('必须要使用 redis 或 memcached',true);
        }

        //每次设置都认为是一个新请求，重新获取本例化
        $instance = new self();
        $instance->taskId = $taskId;
        //设置完成后赋值到属性中
        self::$instance = $instance;
        return $instance->taskId;
    }

    public static function get(){

        //获取之前必须已经有了实例，也就是已经调用过set
        if(!is_null(self::$instance)){
            $instance = self::$instance;
            return $instance->taskId;
        }
        
        return null;
    }

}