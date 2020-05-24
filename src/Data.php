<?php

namespace Codercwm\QueueExport;

use Illuminate\Support\Facades\Cache as LaravelCache;

class Data{
    private $datas = [];
    private static $instance = null;

    private function __construct() { }

    private function __clone() { }


    //这个也私有化，为了::get()::set()用法
    private static function getInstance(array $info=[]){
        if(is_null(self::$instance)){
            self::$instance = new self();
        }
        return self::$instance;
    }
    //获取信息
    public static function get($b=null) {
        $instance = self::getInstance();

        $datas = $instance->datas;
        if(empty($datas)){
            $datas = LaravelCache::pull(File::path(true).'_'.$b)??[];
        }

        return $datas;
    }

    public static function set($datas){
        $instance = self::getInstance();

        $instance->datas = $datas;
    }
}