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

    /**
     * 从数据库中读取数据
     * @param $batch_current
     */
    public static function read($batch_current){

        //如果任务已经失败或已取消，就不再往下执行了
        if(Cache::isFail()||Cache::isCancel()){
            return false;
        }

        //构造查询实例
        $build = new Build();

        $query = $build->query();

        $datas = [];

        //如果时最后一批的话就获取最后一批的数据，避免数据库不断有数据插入，那么这个队列就停不下来了
        if($batch_current>=Info::get('batch_count')){
            $get_size = Info::get('last_batch_size');
        }else{
            $get_size = Config::get('batch_size');
        }

        $query
            ->skip(($batch_current-1)*Config::get('batch_size'))
            ->take($get_size)
            ->get()
            ->each(function($item)use(&$datas){
                $datas[] = FieldValue::get($item);
                Progress::incrRead(1);
            });

        self::appendToCache($datas,$batch_current);
        unset($datas);
    }

    private static function appendToCache($datas,$batch_current){
        LaravelCache::put(File::path(true).'_'.$batch_current,$datas,Cache::expire());

        if(Config::get('multi_file')){
            File::writeOne($batch_current);
        }elseif(Progress::getRead()>=Info::get('total_count')){
            File::writeAll();
        }
    }
}