<?php

namespace Codercwm\QueueExport;

use Illuminate\Support\Facades\Config as LaravelConfig;

class Id{

    private function __construct() { }

    private function __clone() { }

    private static $taskId = null;

    private static $cid = null;

    public static function set(string $taskId=null){
        $cache_driver = LaravelConfig::get('queue_export')['cache_driver'];
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
        self::$taskId = $taskId;
        return self::$taskId;
    }

    public static function get(){
        if(self::$taskId){
            return self::$taskId;
        }else{
            throw new Exception('Id::set()设置了null将不能获取');
        }

    }

    public static function cid($cid=null){
        if(is_null($cid)){
            return self::$cid;
        }
        self::$cid = $cid;
    }
}