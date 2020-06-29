<?php

namespace Codercwm\QueueExport\CourseContent;


use Codercwm\QueueExport\Build;
use Codercwm\QueueExport\Cache;
use Codercwm\QueueExport\FieldValue;
use Codercwm\QueueExport\File;
use Codercwm\QueueExport\Id;
use Codercwm\QueueExport\Progress;
use Illuminate\Support\Facades\Cache as LaravelCache;

class Data implements CourseContent {


    private function __construct() { }

    private function __clone() { }

    private static $data = [];

    //获取信息
    public static function get($b,$in_file=false) {

        $data = self::$data[Id::get()]??[];
        $data = $data[$b]??[];

        if($in_file){

        }

        return $data;
    }

    public static function set($data,$batch){
        if(empty($data)){
            unset(self::$data[Id::get()][$batch]);
        }else{
            self::$data[Id::get()][$batch] = $data;
        }
    }

    public static function destroy($batch=null){
        if(is_null($batch)&&isset(self::$data[Id::get()][$batch])){
            self::$data[Id::get()][$batch] = null;
            unset(self::$data[Id::get()][$batch]);
        }elseif(isset(self::$data[Id::get()])){
            self::$data[Id::get()] = null;
            unset(self::$data[Id::get()]);
        }
    }

    /**
     * 从数据库中读取数据
     * @param $batch_current
     */
    public static function read($batch_current){

        //如果任务已经失败或已取消，就不再往下执行了
        if( Cache::isFail() || Cache::isCancel() || !LaravelCache::has(Id::get())){
            return false;
        }

        //构造查询实例
        $build = new Build();

        $query = $build->query();

        $data = [];

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
            ->each(function($item)use(&$data){
                $data[] = FieldValue::get($item);
                Progress::incrRead(1);
            });

        self::appendToFile($data,$batch_current);
        unset($data);
    }

    private static function appendToFile($data,$batch_current){
        self::set($data,$batch_current);

        File::write($batch_current,$batch_current,'/'.Info::get('filename').'_'.$batch_current,1==Info::get('batch_count'));

        if(Progress::isCompleted()){
            File::merge();

            //上传到oss
            File::upload();
        }

    }
}