<?php

namespace Codercwm\QueueExport\CourseContent;


use Codercwm\QueueExport\Id;
use Illuminate\Support\Facades\Cache as LaravelCache;

class Info implements CourseContent {

    private function __construct() { }

    private function __clone() { }

    private static $info = [];

    public static function get($key=null,$refresh=false){
        if(is_null($key)){
            if(empty(self::$info[Id::get()]) || $refresh){
                self::$info[Id::get()] = LaravelCache::get(Id::get())??[];
            }
            return self::$info[Id::get()];
        }else{
            if(!isset(self::$info[Id::get()][$key]) || $refresh){
                self::$info[Id::get()] = array_merge(self::$info[Id::get()]??[],LaravelCache::get(Id::get())??[]);
            }
            return self::$info[Id::get()][$key]??null;
        }
    }

    public static function set($key,$value){
        self::$info[Id::get()][$key] = $value;
    }

    public static function destroy(){
        if(isset(self::$info[Id::get()])){
            self::$info[Id::get()] = null;
            unset(self::$info[Id::get()]);
        }
    }
}