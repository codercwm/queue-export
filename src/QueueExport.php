<?php
namespace Codercwm\QueueExport;

use Codercwm\QueueExport\Export\Export;
use Illuminate\Support\Collection;

//坑：如果导出过程中数据被删除，那么就完成不了，任务会一直执行下去
class QueueExport{
    private $urlParams;

    public function __construct() {
        $this->urlParams = request()->all();
    }

    public function config($key,$value){
        Config::set($key,$value);
        return $this;
    }

    /**
     * 设置cid
     * @param string $cid 每个用户的唯一id，用于获取每个用户的任务列表，一般传入用户cid
     */
    public function setCid(string $cid){
        Id::cid($cid);
        Id::set($cid . 'QUEUE_EXPORT' . uniqid());
        return $this;
    }

    /**
     * @param string $model
     * @param array $params 筛选条件
     * @param string $method model筛选方法
     */
    public function setModel(string $model,string $method=null,array $params=[],$count_method=null){
        if( !is_null($method) && !method_exists(new $model,$method) ){
            throw new Exception($model.'中不存在'.$method.'方法，请检查',true);
        }
        Info::set('model',$model);
        Info::set('model_method',$method);
        Info::set('model_params',$params);
        Info::set('count_method',$count_method);

        if(method_exists(new $model,'headers')){
            Info::set('headers', $model::headers());
        }
        if(method_exists(new $model,'fields')){
            Info::set('fields', $model::fields());
        }

        return $this;
    }

    /**
     * 设置文件名
     * @param $filename 传入一个唯一的文件名
     */
    public function setFilename(string $filename){
        if(!empty($this->urlParams['qExCreate'])){
            //创建任务时判断文件名是否已存在
            $task_all = Task::all();
            foreach ($task_all as $task){
                if($filename===$task['filename']) {
                    throw new Exception('已存在重复的文件名',true);
                }
            }
            Info::set('filename',$filename);
        }

        return $this;
    }

    /**
     * 设置表头以及要获取的字段名
     * @param $headers excel表头
     * @param $fields 要获取的字段名，如果是多维，用点“.”隔开
     */
    public function setHeadersFields(array $headers,array $fields){
        if(!empty($this->urlParams['qExCreate'])) {
            Info::set('headers', $headers);
            Info::set('fields', $fields);
        }
        return $this;
    }

    /**
     * 设置导出类型
     * Author: cwm
     * Date: 2019-11-8
     * @param $export_type queue：异步队列，syncXls：同步导出xlsx格式的数据，syncCsv：同步导出csv格式的数据
     */
    public function setExportType($export_type){
        if(!empty($this->urlParams['qExCreate'])) {
            Info::set('export_type', $export_type);
        }
        return $this;
    }

    /**
     * 通过url参数判断要做什么操作
     */
    public function export(){
        //创建任务
        if(!empty($this->urlParams['qExCreate'])){
            $export = new Export();
            $export->creation();
            return '正在生成数据';
        }

        //有list参数表示获取列表
        if(!empty($this->urlParams['qExList'])){
            $list = Task::all();
            return $list;
        }

        throw new Exception('非法操作');
    }

    //一个直接导出的方法
    public function exportCsvFromCollection(Collection $collction){
        set_time_limit(0);


        $datas = [];

        $collction
            ->each(function($item)use(&$datas){
                $datas[] = FieldValue::get($item);
            });

        //下载数据 ↓

        $filename = rtrim(Info::get('filename'),'/');

        header('Content-Type: application/vnd.ms-excel;charset=utf-8');
        header('Content-Disposition: attachment;filename="' . iconv("UTF-8", "GB2312", $filename) . '.csv"');
        header('Cache-Control: max-age=0');

        $fp = fopen('php://output', 'a');

        //设置标题
        $headers = Info::get('headers');

        foreach ($headers as $key => $item){
            $headers[$key] = iconv('utf8', 'gbk//IGNORE', $item);
        }

        fputcsv($fp, $headers);

        foreach ($datas as $row){
            foreach ($row as $key=>$value){
                $row[$key] = iconv('utf8', 'gbk//IGNORE', $value);
            }
            fputcsv($fp, $row);
        }
        flush();
        ob_flush();

        fclose($fp);
        exit;

    }
}