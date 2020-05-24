<?php

use Codercwm\QueueExport\QueueExport;
use Illuminate\Support\Facades\Config;
use Codercwm\QueueExport\Id;
use Codercwm\QueueExport\Cache;
use Codercwm\QueueExport\Export\Export;

Route::group(['prefix'=>Config::get('queue_export.route_prefix')??'queue-export'],function(){

    Route::get('hello',function(){
        echo 'helll';
    });

    Route::get('queue-export-csv',function(\Illuminate\Http\Request $request){
        $params = $request->all();
        Id::set($params['taskId']);
        $export = new Export();
        $export->download();
        /*$queue_export = new QueueExport();
        $queue_export
            //->setTaskId($params['taskId'])
            ->exportCsv();*/
    });

    Route::get('queue-export-xls',function(\Illuminate\Http\Request $request){
        $params = $request->all();
        Id::set($params['taskId']);
        $export = new Export();
        $file = $export->download();
        return response()->download($file)->deleteFileAfterSend(true);
    });

    Route::get('queue-export-download-local',function(\Illuminate\Http\Request $request){
        $params = $request->all();
        Id::set($params['taskId']);
        $export = new Export();
        $file = $export->download();
        if(!file_exists($file)){
            return '文件不存在';
        }
        return response()->download($file)->deleteFileAfterSend(false);
    });

    Route::get('queue-export-cancel',function(\Illuminate\Http\Request $request){
        $params = $request->all();
        $queue_export = new QueueExport();
        Id::set($params['taskId']);
        $queue_export
            //->setTaskId($params['taskId'])
            ->cancel();
    });
});
