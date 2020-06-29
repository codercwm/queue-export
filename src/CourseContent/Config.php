<?php

namespace Codercwm\QueueExport\CourseContent;

use Codercwm\QueueExport\Id;
use Illuminate\Support\Facades\Config as LaravelConfig;

class Config implements CourseContent {

    private function __construct() { }

    private function __clone() { }

    private static $config = [];

    public static function get($key=null,$refresh=false){
        if(is_null($key)){
            self::$config[Id::get()] = array_merge(
                Info::get('config')?? LaravelConfig::get('queue_export'),
                self::$config[Id::get()]??[]
            );
            return self::$config[Id::get()];
        }else{
            if(!isset(self::$config[Id::get()][$key]) || $refresh){
                self::$config[Id::get()] = array_merge(
                    Info::get('config')?? LaravelConfig::get('queue_export'),
                    self::$config[Id::get()]??[]
                );
            }
            return self::$config[Id::get()][$key]??null;
        }
    }

    //更改信息
    public static function set($key,$value){
        self::$config[Id::get()][$key] = $value;
    }

    public static function destroy(){
        if(isset(self::$config[Id::get()])){
            self::$config[Id::get()] = null;
            unset(self::$config[Id::get()]);
        }
    }
}