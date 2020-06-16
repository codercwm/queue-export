<?php

use Illuminate\Support\Facades\Config;
use Codercwm\QueueExport\Id;
use Codercwm\QueueExport\Export\Export;
use Codercwm\QueueExport\Progress;
use \Illuminate\Http\Request;

Route::group(['prefix'=>Config::get('queue_export.route_prefix')??'queue-export'],function(){

    Route::get('hello',function(){
        echo 'helll';
    });

    Route::get('queue-export-csv',function(Request $request){
        $params = $request->all();
        Id::set($params['taskId']);
        $export = new Export();
        $export->download();
    });

    Route::get('queue-export-xls',function(Request $request){
        $params = $request->all();
        Id::set($params['taskId']);
        $export = new Export();
        $file = $export->download();
        return response()->download($file)->deleteFileAfterSend(true);
    });

    Route::get('queue-export-download-local',function(Request $request){
        $params = $request->all();
        Id::set($params['taskId']);
        $export = new Export();
        $file = $export->download();
        if(!file_exists($file)){
            return '文件不存在';
        }
        return response()->download($file)->deleteFileAfterSend(false);
    });

    Route::get('queue-export-cancel',function(Request $request){
        $params = $request->all();
        Id::set($params['taskId']);
        Progress::cancel();
    });
});
