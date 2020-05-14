1.安装：
`composer require "codercwm/queue-export"`

2.控制器中的代码：
```php
namespace App\Http\Controllers\Home;
use Codercwm\QueueExport\QueueExport;
class IndexController extends Controller
{
    public function test(Request $request){
        $queue_export = new QueueExport();
        $params = $request->all();
		
		//设置表头
        $headers = [
            '编号',
            '名称',
            '可以使用字典',
            '可以使用函数',
            '单元格转换成字符串',
            '使用``符号包围可以原样输出',
            '双问号??可以指定默认值',
            '这些功能可以同时使用',
        ];
		//设置对应的字段
        $fields = [
            'id',
            'name',
            'id|1:原来的值是1;9:原来的值是9;',
            "sprintf('%s%s%s',   id,  '-',  name )",
            'id|tabs',
            '`name`',
            'name??name为空时默认输出',
            "sprintf('%s%s%s',   `id`,  '-',  name??啊 )|1:一;2:二;3:三",
        ];
        $res = $queue_export
			//每个用户的标识，用户用这个标识可以获取导出历史，一般用user_id
			->setCid($this->user->id)
			//设置model
            ->setModel(User::class,$params,'filter')
			//设置文件名
            ->setFilename('User'.date('Y_m_d_H_i_s').'_'.rand(1000,9999))
			//设置表头和数据字段
            ->setHeadersFields($headers,$fields)
			//设置导出类型
            ->setExportType('queue')
            ->export();

        return $res;
    }
}
```
3.Model中的代码：
```php
class User extends Model implements JWTSubject
{
    public static function filter($params=[]){
        return self::query();
    }
}

```
4.路由中的代码：
```php
Route::get('test', 'Home\IndexController@test');
```
5.发起请求：

创建导出任务：http://study.test/test?qExCreate=1

查看任务列表：http://study.test/test?qExList=1

**至此一个简单的使用就完成了**

**下面对各个参数或方法做个详细解释**

```php
->setCid(string $cid)
```
设置用户的cid，至少保证每个用户的cid是不一样的，因为获取任务列表时，会用cid进行标识，相同cid的任务都会被拿出来

------------


```php
->setModel(string $model,array $params=[],string $method='qExExport')
```
设置Model类，`$model`表示传入一个Model类，`$params`表示用作筛选条件的请求参数，一般从请求中获取，`$method`表示`$model`类中的方法，方法中需要返回一个query，`$params`将会传入`$method`中，`$method`中可以用`$params`构造where查询条件，达到筛选效果：
```php
public static function qExExport($params=[]){
        $query = self::query();
        if(!empty($params['name'])){
            $query->where('name','like','%'.$params['name'].'%');
        }
		//...
        return $query;
    }
```

------------



```php
->setFilename(string $filename)
```
设置文件名，传入一个唯一的文件名

------------


```php
->setHeadersFields(array $headers,array $fields)
```
`$headers`即Excel的表头，`$fields`表示要获取的数据字段，`$fields`的传入方式有多种，可以获得不同的返回格式：
```php
用竖线“|”分隔表示可以使用字典：'id|1:原来的值是1;9:原来的值是9;'
直接使用函数：sprintf('%s%s%s',   id,  '-',  name ),
tabs表示强制转换为字符串，适用于Excel单元格中的长数字不希望显示为科学计数法时'id|tabs',
“``”表示原样输出，例如数据库中存在name字段，但不希望获取其值'`name`',
双问号“??”后面的值是值为空时的默认输出'name??name为空时默认输出',

以上功能可以混合使用：
"sprintf('%s%s%s',   `id`,  '-',  name??啊 )|1:一;2:二;3:三",
```

------------


```php
->setExportType($export_type)
```
设置导出类型，总共有三种方式：
syncXls：同步导出xlsx格式的数据
syncCsv：同步导出csv格式的数据
queue：异步队列（使用此方式必须要开启redis）

------------

```php
->export()
```
执行导出操作

------------

接着，在客户端中请求`http://domain/route?qExCreate=1`
domain表示域名，route表示路由，而重点在于qExCreate参数，传入此参数表示告诉系统要创建一个任务；
创建任务后，会在cache中写入任务信息；
客户端获取任务信息可以请求：`http://domain/route?qExList=1`,此请求将会获取到以cid为标识的所有任务列表
```json
[
  {
    "cid": "sakldfj2",	//cid
    "model": "App\\Models\\User",	//model类
    "model_method": "qExExport",	//model类中的方法
    "model_params": {	//传入model_method中的参数
      "qExCreate": "1"
    },
    "filename": "User2020_05_14_08_28_48_1181",	//文件名
    "headers": [//表头
      "编号",
      "名称",
      "可以使用字典",
      "可以使用函数",
      "单元格转换成字符串",
      "使用``符号包围可以原样输出",
      "双问号??可以指定默认值",
      "这些功能可以同时使用"
    ],
    "fields": [//要获取的数据
      "id",
      "name",
      "id|1:原来的值是1;9:原来的值是9;",
      "sprintf('%s%s%s',   id,  '-',  name )",
      "id|tabs",
      "`name`",
      "name??name为空时默认输出",
      "sprintf('%s%s%s',   `id`,  '-',  name??啊 )|1:一;2:二;3:三"
    ],
    "export_type": "syncCsv",//导出方式
    "config": {//配置信息
      "expire": 86400,
      "disk": "public",
      "batch_size": 200,
      "file_ext": "xlsx",
      "multi": false,
      "multi_file": true,
      "write_type": "append_to_redis",
      "allow_export_type": [
        "queue",
        "syncCsv",
        "syncXls"
      ],
      "file_size": 400
    },
    "task_id": "sakldfj2QUEUE_EXPORT5ebd0140cdd1d",//每个任务唯一的id
    "batch_size": 200,//每个批次多少条数据
    "total_count": 10002,//总条数
    "batch_count": 51,//总批次树
    "timestamp": 1589444929,//任务创建时间
    "expire_timestamp": 1589531329,//任务过期时间
    "http_host": "http://study.test",//系统域名
    "cancel_url": "http://study.test/queue-export-cancel?taskId=sakldfj2QUEUE_EXPORT5ebd0140cdd1d",//取消任务的链接，客户端请求此链接可以取消任务
    "progress_read": "10002",//读取进度，表示当前从数据库中读了多少条
    "progress_write": "10002",//写入进度，表示已经往excel里写了多少条
    "is_fail": 0,//任务是否已失败，0：否；1：是
    "is_cancel": 0,//任务是否已取消，0：否；1：是
    "complete": "1589444929",//任务完成时间
    "download_url": "http://study.test/queue-export-csv?taskId=sakldfj2QUEUE_EXPORT5ebd0140cdd1d",//文件下载地址，访问此地址可以下载excel文件
    "local_path": "",//文件储存在服务器中的路径，如果开启了oss，此为空
    "show_name": "User2020_05_14_08_28_48_1181",//用于前端显示的文件名，如果已取消即会显示“任务已取消”，已失败即会显示任务已失败
    "percent": "100%"//导出进度
  }
]
```

[![](https://chengweiming.com/wp-content/uploads/2020/05/dd69401721b128fe0bf3b0fc6b010efb.png)](https://chengweiming.com/wp-content/uploads/2020/05/dd69401721b128fe0bf3b0fc6b010efb.png)