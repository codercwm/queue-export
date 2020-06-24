<?php

namespace Codercwm\QueueExport;

use Illuminate\Support\Facades\Config as LaravelConfig;

class Config{

    private function __construct() { }

    private function __clone() { }

    private static $config = [];

    public static function get($key=null,$refresh=false){
        if(is_null($key)){
            if(empty(self::$config[Id::get()]) || $refresh){
                self::$config[Id::get()] = Info::get('config')?? LaravelConfig::get('queue_export');
            }
            return self::$config[Id::get()];
        }else{
            if(!isset(self::$config[Id::get()][$key]) || $refresh){
                self::$config[Id::get()] = array_merge(self::$config[Id::get()]??[],Info::get('config')?? LaravelConfig::get('queue_export'));
            }
            return self::$config[Id::get()][$key]??null;
        }
    }

    //更改信息
    public static function set($key,$value){
        self::$config[Id::get()][$key] = $value;
    }
}