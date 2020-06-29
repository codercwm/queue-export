<?php
return [
    'expire' => 86400,   //任务过期时间
    'disk' => 'public',//disk
    'batch_size' => 500,//每批次查询多少条
    'multi' => false,//是否允许多任务并行，设置成false，必须要等一个任务完成后才能添加下一个任务
    'allow_export_type' => ['queue','syncCsv','syncXls'],
    'suffix_type' => \Box\Spout\Common\Type::XLSX,//xlsx/csv
    'hidden_keys' => ['config'],//返回给前端时隐藏哪些信息
    'upload_oss' => false,//是否上传到oss
    'route_prefix' => 'api',//路由前缀
    'queue_name' => 'CwmExportQueue',//使用的队列名称
    'cache_driver' => 'default',//使用的缓存驱动，从cache.php配置文件中选择，default即表示使用cache.php配置文件中的配置
    'queue_connection' => 'queue_export',//使用queue.php配置文件中的哪个连接
    'oss' => [//暂时只支持阿里云OSS
        'accessKeyId' => env('ALIYUN_OSS_ID',''),
        'accessKeySecret' => env('ALIYUN_OSS_SECRET',''),
        'endpoint'   =>env('ALIYUN_OSS_ENDPOINT',''),
        'bucket'   => env('ALIYUN_OSS_BUCKET',''),
        'path' =>env('ALIYUN_OSS_PATH',''),
        'host'    =>env('ALIYUN_OSS_HOST',''),
        //stsToken
        'sts_key'=>env('ALIYUN_STS_KEY',''),
        'sts_secret'=>env('ALIYUN_STS_SECRET',''),
        'sts_expire_time'=>env('ALIYUN_STS_EXPIRE_TIME',''),
        'sts_region_id'    =>env('ALIYUN_STS_REGION_ID',''),
        'sts_end_point' => env('ALIYUN_STS_END_POINT',''),
        'sts_role'=>env('ALIYUN_STS_ROLE',''),
    ],
];