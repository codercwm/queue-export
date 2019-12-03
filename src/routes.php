<?php

use Codercwm\QueueExport\QueueExport;

Route::get('hello',function(){
    echo 'helll';
});

Route::get('queue-export-csv',function(\Illuminate\Http\Request $request){
    $params = $request->all();
    $queue_export = new QueueExport();
    $queue_export
        ->setQExId($params['qExId'])
        ->exportCsv();
});

Route::get('queue-export-xls',function(\Illuminate\Http\Request $request){
    $params = $request->all();
    $queue_export = new QueueExport();
    $file = $queue_export
        ->setQExId($params['qExId'])
        ->exportXls();

    return response()->download($file)->deleteFileAfterSend(true);
});