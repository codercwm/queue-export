<?php
return [
    'expire' => 86400,   //任务过期时间
    'disk' => 'public',//disk
    'batch_size' => 1,//每批次查询多少条
    'file_ext' => 'xlsx',//文件后缀
    'multi' => false,//是否允许多任务并行
    'multi_file' => true,//是否允许多文件
    'allow_export_type' => ['queue','syncCsv','syncXls'],
    'file_size' => 1,//分文件时，一个文件多少条，必须比batch_size大
];