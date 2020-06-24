<?php

namespace Codercwm\QueueExport;

use Illuminate\Support\Facades\Cache as LaravelCache;

class Data{


    private function __construct() { }

    private function __clone() { }

    private static $datas = [];

    //获取信息
    public static function get($b) {

        $datas = self::$datas[Id::get()]??[];
        if(empty($datas)){

            //如果这个批次的数据还未被其它进程取出就取出数据并设置已取出标识
            if(Cache::add($b.'_data_got',1)){//如果已被其它进程取出，这里是不会设置成功的
                $datas = LaravelCache::get(File::path(true).'_'.$b)??[];

                //如果队列开启了多个进程，执行的顺序是不一定的
                //有时候会出现已经执行到这里要写入数据了，数据却还没有读取出来的情况
                while (empty($datas)){
                    //等待2秒后进行再次获取
                    sleep(2);
                    $datas = LaravelCache::get(File::path(true).'_'.$b)??[];
                }
                //取出之后删除
                LaravelCache::forget(File::path(true).'_'.$b);
            }
        }

        return $datas;
    }

    public static function set($datas){
        self::$datas[Id::get()] = $datas;
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

        if(0<Config::get('file_size')){
            File::writeOne($batch_current);
        }elseif(Progress::getRead()>=Info::get('total_count')){
            File::writeAll();
        }
    }
}