<?php

namespace Codercwm\QueueExport;

use Illuminate\Support\Facades\Cache as LaravelCache;
use Illuminate\Support\Facades\Config as LaravelConfig;
use Codercwm\QueueExport\CourseContent\Info;

class Task{

    public static function all($cid=''){
        if(''===$cid) $cid = Id::cid();
        if(is_null($cid)) return [];
        //获取所有key
        $keys = self::ids($cid);
        $task_list = [];
        $task_id_arr = [];
        $hidden_keys = LaravelConfig::get('queue_export')['hidden_keys'];
        foreach($keys as $key){
            if(!LaravelCache::has($key)) continue;
            $data = LaravelCache::get($key);
            if(empty($data['filename'])) continue;
            $key = $data['task_id'];
            $data['progress_read'] = Progress::getRead($key);
            $data['progress_write'] = Progress::getWrite($key);
            $data['progress_merge'] = Progress::getMerge($key);
            $data['is_fail'] = LaravelCache::get($key.'_is_fail')??0;
            $data['is_cancel'] = LaravelCache::get($key.'_is_cancel')??0;
            $data['complete'] = LaravelCache::get($key.'_complete')??0;
            $data['download_url'] = LaravelCache::get($key.'_download_url')??'';
            $data['local_path'] = LaravelCache::get($key.'_local_path')??'';
            $data['show_name'] = LaravelCache::get($key.'_show_name')??$data['filename'];
            //计算百分比
            $percent = 0;
            if($data['total_count']>0){
                //如果download_url不为空就说明肯定是100%了
                if(''==$data['download_url']){
                    $percent = bcmul(
                        ($data['progress_read']+$data['progress_write']+$data['progress_merge'])
                        /
                        ($data['total_count']+$data['total_count']+$data['total_count'])
                        ,100,0);
                    if($percent>98) $percent = 96;
                }else{
                    $percent = 100;
                }
            }
            $data['percent'] = $percent.'%';

            LaravelCache::put($key,$data,Cache::expire(false,$data['expire_timestamp']));//把任务重新放进缓存中
            $task_id_arr[] = $key;

            //如果过了10秒钟还未开始读取，而且该任务未取消，未失败，就显示成任务正在排队（只是显示的时候改而已，缓存中的值还是不变的，因为上面已经把它放进缓存了）
            if(
                (0==$data['is_fail'])&&
                (0==$data['is_cancel'])&&
                (0==$data['progress_read'])&&
                (10<=(time()-$data['timestamp']))&&
                (1>$percent)
            ){
                $data['show_name'] = '任务正在排队';
            }

            //去掉要隐藏的keys
            foreach($hidden_keys as $hidden_key){
                if(isset($data[$hidden_key])){
                    unset($data[$hidden_key]);
                }
            }

            //如果model为空就全部放进去，如果不为空就放匹配的进去
            if(is_null(Info::get('model')) || (Info::get('model')==$data['model'])){
                $task_list[] = $data;
            }

        }
        //把全部tack_id重新保存到缓存
        LaravelCache::forever($cid.'_allTaskId',array_unique($task_id_arr));
        //显示时排序
        array_multisort(array_column($task_list,'timestamp'),SORT_DESC,$task_list);
        return $task_list;
    }

    public static function ids($cid='',$push=null){
        if(is_null($cid)){
            $cid = Id::cid();
        }
        if($push){
            $task_id_arr = LaravelCache::get($cid.'_allTaskId')??[];
            array_push($task_id_arr,$push);
            LaravelCache::forever($cid.'_allTaskId',array_filter(array_unique($task_id_arr)));
            return $task_id_arr;
        }
        return LaravelCache::get($cid.'_allTaskId')??[];
    }
}