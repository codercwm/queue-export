<?php

namespace Codercwm\QueueExport;

use Illuminate\Support\Facades\Cache as LaravelCache;
use Codercwm\QueueExport\CourseContent\Info;

class Cache{
    
    public static function add($key,$value){
        return LaravelCache::add(Id::get().'_'.$key,$value,self::expire());
    }

    public static function put($key,$value){
        return LaravelCache::put(Id::get().'_'.$key,$value,self::expire());
    }

    public static function get($key){
        return LaravelCache::get(Id::get().'_'.$key)??null;
    }

    public static function downloadUrl($download_url=null){
        if(is_null($download_url)){
            return self::get('download_url')??'';
        }
        return self::add('download_url',$download_url);
    }

    public static function showName($show_name=null){
        if(is_null($show_name)){
            return self::get('show_name')??'';
        }
        return self::put('show_name',$show_name);
    }

    public static function isFail($is_fail=false){
        if(!$is_fail){
            return self::get('is_fail')??0;
        }
        return self::add('is_fail',1);
    }

    public static function isCancel($is_cancel=false){
        if(!$is_cancel){
            return self::get('is_cancel')??0;
        }
        return self::add('is_cancel',1);
    }

    public static function complete($complete=false){
        if(!$complete){
            return self::get('complete')??0;
        }
        return self::add('complete',time());
    }
    
    public static function expire($seconds=false,$expire_timestamp=null){
        if(is_null($expire_timestamp)){
            $expire_timestamp = Info::get('expire_timestamp');
        }
        if($seconds){
            return intval($expire_timestamp-time());
        }else{
            return intval(($expire_timestamp-time())/60);
        }
    }

    public static function increment($key,$incr=1){
        LaravelCache::increment(Id::get().'_'.$key,$incr);
    }
}