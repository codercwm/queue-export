<?php
return [
    'expire' => 86400,   //任务过期时间
    'disk' => 'public',//disk
    'batch_size' => 200,//每批次查询多少条
    'file_ext' => 'xlsx',//文件后缀
    'multi' => false,//是否允许多任务并行
    'allow_export_type' => ['queue','syncCsv','syncXls'],
    'file_size' => 400,//分文件时，一个文件多少条，必须比batch_size大，设置为0即表示不允许多文件
    'hidden_keys' => ['config'],//返回给前端时隐藏哪些信息
    'upload_oss' => false,//是否上传到oss
    'route_prefix' => 'api',//路由前缀
    'queue_name' => 'CwmExportQueue',
    'cache_driver' => 'default',//使用的缓存驱动，从cache.php配置文件中选择，default即表示使用cache.php配置文件中的配置
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