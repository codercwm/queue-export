<?php

namespace Codercwm\QueueExport;

use Illuminate\Support\Facades\Cache;

class Info{

    private function __construct() { }

    private function __clone() { }

    private static $instance = null;

    private $info = [];

    //这个也私有化，为了Info::get()用法
    private static function getInstance(array $info=[]){
        if(is_null(self::$instance)){
            self::$instance = new self();
        }
        return self::$instance;
    }

    //获取信息
    public static function get($key=null,$refresh=false){
        $instance = self::getInstance();
        if(is_null($key)){
            if(empty($instance->info) || $refresh){
                $instance->info = Cache::get(Id::get())??[];
            }
            return $instance->info;
        }else{
            if(!isset($instance->info[$key]) || $refresh){
                $instance->info = array_merge($instance->info,Cache::get(Id::get())??[]);
            }
            return $instance->info[$key]??null;
        }
    }

    //更改信息
    public static function set($key,$value){
        $instance = self::getInstance();
        $instance->info[$key] = $value;
    }
}