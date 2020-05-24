<?php

namespace Codercwm\QueueExport\Export;

use Codercwm\QueueExport\Build;
use Codercwm\QueueExport\Cache;
use Codercwm\QueueExport\Config;
use Codercwm\QueueExport\Data;
use Codercwm\QueueExport\FieldValue;
use Codercwm\QueueExport\File;
use Codercwm\QueueExport\Info;
use Codercwm\QueueExport\Progress;

class Xls{
    public function creation(){
        Progress::incrRead(Info::get('total_count'));
        Progress::incrWrite(Info::get('total_count'));
        Cache::complete(true);
        Cache::downloadUrl(request()->getSchemeAndHttpHost().'/'.Config::get('route_prefix').'/queue-export-xls?taskId='.Info::get('task_id'));
    }

    public function download(){

        //下载数据 ↓

        set_time_limit(0);

        //构造查询实例
        $build = new Build();

        $query = $build->query();

        $datas = [];

        $query
            ->get()
            ->each(function($item)use(&$datas){
                $datas[] = FieldValue::get($item);
            });

        Data::set($datas);

        $file = File::write(1,1);

        rmdir(File::dir());

        return $file;
    }
}