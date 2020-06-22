<?php

namespace Codercwm\QueueExport\Export;

use Codercwm\QueueExport\Build;
use Codercwm\QueueExport\Cache;
use Codercwm\QueueExport\Config;
use Codercwm\QueueExport\Exception;
use Codercwm\QueueExport\Id;
use Codercwm\QueueExport\Info;
use Codercwm\QueueExport\Progress;
use Codercwm\QueueExport\Task;
use Illuminate\Support\Facades\Cache as LaravelCache;
use Illuminate\Support\Facades\Config as LaravelConfig;

class Export{

    private $export;

    public function __construct(){
        $type = Info::get('export_type');
        switch ($type){
            case 'queue':
                $this->export = new Queue();
                break;
            case 'syncCsv':
                $this->export = new Csv();
                break;
            case 'syncXls':
                $this->export = new Xls();
                break;
            default:
                throw new Exception('请设置导出类型');
                break;
        }
    }

    public function creation(){
        $this->verify();
        $this->create();
        return $this->export->creation();
    }

    private function exception($msg,$del=false){
        throw new Exception($msg,$del);
    }

    private function verify(){
        //不是如果是允许的类型
        if(!in_array(Info::get('export_type'),Config::get('allow_export_type'))){
            $this->exception('无效的类型');
        }

        if(!Config::get('multi')){
            //判断是否有未完成的
            $task_list = Task::all(Info::get('cid'));
            foreach ($task_list as $task){
                if(
                    (0==$task['is_fail'])&&
                    (0==$task['is_cancel'])&&
                    (Info::get('model')==$task['model'])
                ){
                    if(
                        $task['percent']!='100%'
                    ) {
                        $this->exception('请等待当前任务完成');
                        return;
                    }
                }
            }
        }
    }

    private function create(){
        //构造查询实例
        $build = new Build();

        //总数量
        $count = $build->count();

        $config = array_merge(LaravelConfig::get('queue_export'),Config::get());

        Info::set('config',$config);

        //任务id
        Info::set('task_id',Id::get());
        //文件（夹）名
        Info::get('filename') || $this->exception('文件名不能为空',true);

        //excel表头
        Info::get('headers') || $this->exception('请设置表头',true);
        //字段名
        Info::get('fields') || $this->exception('请设置字段名',true);

        //每次条数
        Info::set('batch_size',$config['batch_size']);

        Info::set('total_count',$count);
        //总共分了多少批
        Info::set('batch_count',intval(ceil($count/Info::get('batch_size'))));

        //坑：因为数据库会被插入数据，所以读取出来的数量可能会大于一开始时统计的数量
        //计算最后一批有多少条
        $last_batch_size = intval($count%Info::get('batch_size'));
        Info::set('last_batch_size',$last_batch_size>0?$last_batch_size:Info::get('batch_size'));

        //任务开始的时间戳
        Info::set('timestamp',time());

        //任务过期的时间戳
        Info::set('expire_timestamp',Info::get('timestamp')+$config['expire']);

        //获取域名
        Info::set('http_host',request()->getSchemeAndHttpHost());

        //用于取消任务的地址
        Info::set('cancel_url',request()->getSchemeAndHttpHost().'/'.Config::get('route_prefix').'/queue-export-cancel?taskId='.Id::get());

        //把任务保存到缓存
        //设置超时时间
        LaravelCache::put(Info::get('task_id'),Info::get(), Cache::expire());

        //保存所有taskId
        Task::ids(null,true);

        Progress::incrRead(0);
        Progress::incrWrite(0);

        return true;
    }

    public function download(){
        return $this->export->download();
    }

}