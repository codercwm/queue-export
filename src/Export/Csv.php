<?php

namespace Codercwm\QueueExport\Export;

use Codercwm\QueueExport\Build;
use Codercwm\QueueExport\Cache;
use Codercwm\QueueExport\FieldValue;
use Codercwm\QueueExport\CourseContent\Info;
use Codercwm\QueueExport\Progress;
use Codercwm\QueueExport\CourseContent\Config;

class Csv{

    public function creation(){
        Progress::incrRead(Info::get('total_count'));
        Progress::incrWrite(Info::get('total_count'));
        Progress::incrMerge(Info::get('total_count'));
        Cache::complete(true);
        Cache::downloadUrl(request()->getSchemeAndHttpHost().'/'.Config::get('route_prefix').'/queue-export-csv?taskId='.Info::get('task_id'));
    }

    public function download(){
        //下载数据 ↓

        set_time_limit(0);

        $task_info = Info::get();

        //构造查询实例
        $build = new Build();

        $query = $build->query();

        //总数量
        $count = $build->count();

        $filename = rtrim($task_info['filename'],'/');

        header('Content-Type: application/vnd.ms-excel;charset=utf-8');
        header('Content-Disposition: attachment;filename="' . iconv("UTF-8", "GB2312", $filename) . '.csv"');
        header('Cache-Control: max-age=0');

        $fp = fopen('php://output', 'a');

        //设置标题
        $headers = $task_info['headers'];

        foreach ($headers as $key => $item){
            $headers[$key] = iconv('utf8', 'gbk//IGNORE', $item);
        }

        fputcsv($fp, $headers);

        $batch_size = $task_info['batch_size'];
        $batch_count = ceil($count/$batch_size);
        for($batch_current=1;$batch_current<=$batch_count;$batch_current++){
            //如果时最后一批的话就获取最后一批的数据，避免数据库不断有数据插入，那么这个队列就停不下来了
            if($batch_current>=$task_info['batch_count']){
                $get_size = $task_info['last_batch_size'];
            }else{
                $get_size = $batch_size;
            }
            $items = $query
                ->skip(($batch_current-1)*$batch_size)
                ->take($get_size)
                ->get();
            $rows = [];

            foreach($items as $item){
                $rows[] = FieldValue::get($item);

            }

            foreach ($rows as $row){
                foreach ($row as $key=>$value){
                    $row[$key] = iconv('utf8', 'gbk//IGNORE', $value);
                }
                fputcsv($fp, $row);
            }
            flush();
            ob_flush();
        }
        fclose($fp);
        exit;
    }
}