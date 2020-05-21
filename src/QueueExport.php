<?php
namespace Codercwm\QueueExport;

use Codercwm\QueueExport\Jobs\ExportQueue;
use Codercwm\QueueExport\Services\LogService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Redis;
use OSS\OssClient;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use \ZipArchive;

class QueueExport{
    private $taskId;//taskId用于在不同的进程中识别同一个任务
    private $datas = [];//数据
    private $taskInfo = [];//任务信息
    private $config = [];//配置信息

    public function config($key=null,$value=null){
        if(empty($this->config)){
            $this->config = $this->get('config')??Config::get('queue_export');
        }
        
        if(is_null($key)){
            return $this->config;
        }

        if(is_null($value)){
            return $this->config[$key]??null;
        }

        $this->config[$key] = $value;

        return $this;
    }

    /**
     * 设置cid
     * @param string $cid 每个用户的唯一id，用于获取每个用户的任务列表，一般传入用户cid
     */
    public function setCid(string $cid){
        $this->set('cid',$cid);
        $this->taskId = $cid.'QUEUE_EXPORT'.uniqid();
        return $this;
    }

    /**
     * 不同的进程中，设置了task_id，就可以获取一个已存在的任务了，如果没有task_id，将什么都没有
     * @return mixed
     */
    public function setTaskId($task_id=null) {
        if(is_null($task_id)){
            $task_id = $this->get('cid').'QUEUE_EXPORT'.uniqid();
        }
        $this->taskId = $task_id;
        return $this;
    }

    /**
     * @param string $model
     * @param array $params 筛选条件
     * @param string $method model筛选方法
     */
    public function setModel(string $model,string $method=null,array $params=[]){
        if( !is_null($method) && !method_exists(new $model,$method) ){
            $this->exception($model.'中不存在'.$method.'方法，请检查',true);
        }
        $this->set('model',$model);
        $this->set('model_method',$method);
        $this->set('model_params',$params);
        return $this;
    }

    /**
     * 设置文件名
     * @param $filename 传入一个唯一的文件名
     */
    public function setFilename(string $filename){
        //判断文件名是否已存在
        $task_all = $this->allTask();
        foreach ($task_all as $task){
            if($filename===$task['filename']) {
                $this->exception('已存在重复的文件名',true);
            }
        }
        $this->set('filename',$filename);
        return $this;
    }

    /**
     * 设置表头以及要获取的字段名
     * @param $headers excel表头
     * @param $fields 要获取的字段名，如果是多维，用点“.”隔开
     */
    public function setHeadersFields(array $headers,array $fields){
        $this->set('headers',$headers);
        $this->set('fields',$fields);
        return $this;
    }

    /**
     * 抛出错误，并从redis清除这个任务
     * @param string $msg
     */
    public function exception(string $msg,$clear=false){
        if($clear){
            //一旦报错就从缓存清除这个任务
            $this->del();
        }
        throw new \Exception($msg);
    }

    /**
     * 设置导出类型
     * Author: cwm
     * Date: 2019-11-8
     * @param $export_type queue：异步队列，syncXls：同步导出xlsx格式的数据，syncCsv：同步导出csv格式的数据
     */
    public function setExportType($export_type){
        $this->set('export_type',$export_type);
        return $this;
    }

    //实例化model
    private function buildQuery(){
        $model = $this->get('model')??null;
        $model_method = $this->get('model_method')??null;

        if($model && $model_method && method_exists(new $model,$model_method)){
            $query = $model::$model_method($this->get('model_params')??[]);
        }else{
            $query = $model::query();
        }

        return $query;
    }
    
    /**
     * 开始创建任务
     * @return bool
     */
    public function create() {
        $config = array_merge(Config::get('queue_export'),$this->config());
        $this->set('config',$config);

        //不是如果是允许的类型
        if(!in_array($this->get('export_type'),$config['allow_export_type'])){
            $this->exception('无效的类型');
        }

        if(!$config['multi']){
            //判断是否有未完成的
            $task_list = $this->allTask($this->get('cid'));
            foreach ($task_list as $task){
                if(
                    (0==$task['is_fail'])&&
                    (0==$task['is_cancel'])&&
                    ($this->get('model')==$task['model'])
                ){
                    if(
                        $task['total_count']>$task['progress_read']
                        ||
                        $task['total_count']>$task['progress_write']
                    ) {
                        $this->exception('请等待当前任务完成');
                        return;
                    }
                }
            }
        }

        //任务id
        $this->set('task_id',$this->taskId);
        //文件（夹）名
        $this->get('filename') || $this->exception('文件名不能为空',true);

        //excel表头
        $this->get('headers') || $this->exception('请设置表头',true);
        //字段名
        $this->get('fields') || $this->exception('请设置字段名',true);

        //每次条数
        $this->set('batch_size',$config['batch_size']);

        $query = $this->buildQuery();

        //总数量
        if(isset($query->count)){
            $count = $query->count;
        }else{
            $count = $query->paginate()->total();
        }
        $this->set('total_count',$count);
        //总共分了多少批
        $this->set('batch_count',intval(ceil($count/$this->get('batch_size'))));

        //任务开始的时间戳
        $this->set('timestamp',time());

        //任务过期的时间戳
        $this->set('expire_timestamp',$this->get('timestamp')+$config['expire']);

        //获取域名
        $this->set('http_host',request()->getSchemeAndHttpHost());

        //用于取消任务的地址
        $this->set('cancel_url',request()->getSchemeAndHttpHost().'/queue-export-cancel?taskId='.$this->taskId);

        //把任务保存到缓存
        //设置超时时间
        Cache::put($this->taskId,$this->get(), $this->expire());

        //保存所有taskId
        $this->allTaskId(null,true);

        $this->progressRead(0);
        $this->progressWrite(0);

        return true;
    }

    private function cacheAdd($key,$value,$type='add'){
        return Cache::$type($this->taskId.'_'.$key,$value,$this->expire());
    }

    private function downloadUrl($download_url=null){
        if(is_null($download_url)){
            return Cache::get($this->taskId.'_download_url')??'';
        }
        return $this->cacheAdd('download_url',$download_url);
    }

    public function localPath($local_path=null){
        if(is_null($local_path)){
            return Cache::get($this->taskId.'_local_path')??'';
        }
        return $this->cacheAdd('local_path',$local_path);
    }

    private function showName($show_name=null){
        if(is_null($show_name)){
            return Cache::get($this->taskId.'_show_name')??'';
        }
        return $this->cacheAdd('show_name',$show_name,'put');
    }

    private function isFail($is_fail=false){
        if(!$is_fail){
            return Cache::get($this->taskId.'_is_fail')??0;
        }
        return $this->cacheAdd('is_fail',1);
    }

    private function isCancel($is_cancel=false){
        if(!$is_cancel){
            return Cache::get($this->taskId.'_is_cancel')??0;
        }
        return $this->cacheAdd('is_cancel',1);
    }

    private function complete($complete=false){
        if(!$complete){
            return Cache::get($this->taskId.'_complete')??0;
        }
        return $this->cacheAdd('complete',time());
    }

    private function progressRead($incr=null,$task_id=null){
        if(is_null($task_id)){
            $task_id = $this->taskId;
        }
        if(is_null($incr)){
            return Redis::get($task_id.'_progress_read');
        }
        Redis::incrby($task_id.'_progress_read',$incr);
        if(0==$incr){
            Redis::expire($task_id.'_progress_read',$this->expire(null,'seconds'));
        }
    }

    private function progressWrite($incr=null,$task_id=null){
        if(is_null($task_id)){
            $task_id = $this->taskId;
        }
        if(is_null($incr)){
            return Redis::get($task_id.'_progress_write');
        }
        Redis::incrby($task_id.'_progress_write',$incr);
        if(0==$incr){
            Redis::expire($task_id.'_progress_write',$this->expire(null,'seconds'));
        }
    }

    private function expire($expire_timestamp=null,$type='seconds'){
        if(is_null($expire_timestamp)){
            $expire_timestamp = $this->get('expire_timestamp');
        }
        if('seconds'==$type){
            return intval($expire_timestamp-time());
        }else{
            return intval(($expire_timestamp-time())/60);
        }
    }

    private function allTaskId($cid=null,$refresh=false){
        if(is_null($cid)){
            $cid = $this->get('cid');
        }
        if($refresh){
            $task_id_arr = $this->allTaskId($cid);
            array_push($task_id_arr,$this->taskId);
            Cache::forever($cid.'_allTaskId',$task_id_arr);
            return $task_id_arr;
        }
        return Cache::get($cid.'_allTaskId')??[];
    }

    /**
     * 获取所有任务
     * @return array
     */
    public function allTask($cid=''){
        if(''===$cid) $cid = $this->get('cid');
        if(is_null($cid)) return [];
        //获取所有key
        $keys = $this->allTaskId($cid);
        $task_list = [];
        $task_id_arr = [];
        $hidden_keys = $this->config('hidden_keys');
        foreach($keys as $key){
            if(!Cache::has($key)) continue;
            $data = Cache::get($key);
            if(empty($data['filename'])) continue;
            $data['progress_read'] = $this->progressRead(null,$key);
            $data['progress_write'] = $this->progressWrite(null,$key);
            $data['is_fail'] = Cache::get($key.'_is_fail')??0;
            $data['is_cancel'] = Cache::get($key.'_is_cancel')??0;
            $data['complete'] = Cache::get($key.'_complete')??0;
            $data['download_url'] = Cache::get($key.'_download_url')??'';
            $data['local_path'] = Cache::get($key.'_local_path')??'';
            $data['show_name'] = Cache::get($key.'_show_name')??$data['filename'];
            //计算百分比
            $percent = 0;
            if($data['total_count']>0){
                //如果download_url不为空就说明肯定是100%了
                if(''==$data['download_url']){
                    $percent = bcmul(
                        ($data['progress_read']+$data['progress_write'])
                        /
                        ($data['total_count']+$data['total_count'])
                    ,100,0);
                    if($percent>98) $percent = 96;
                }else{
                    $percent = 100;
                }
            }
            $data['percent'] = $percent.'%';

            Cache::put($key,$data,$this->expire($data['expire_timestamp']));//把任务重新放进缓存中
            $task_id_arr[] = $key;

            //如果过了10秒钟还未开始读取，而且该任务未取消，未失败，就显示成任务正在排队（只是显示的时候改而已，缓存中的值还是不变的，因为上面已经把它放进缓存了）
            if(
                (0==$data['is_fail'])&&
                (0==$data['is_cancel'])&&
                (0==$data['progress_read'])&&
                (10<=(time()-$data['timestamp']))&&
                (1>$percent)
            ){
                $data['show_name'] = '任务正在排队';
            }

            //去掉要隐藏的keys
            foreach($hidden_keys as $hidden_key){
                if(isset($data[$hidden_key])){
                    unset($data[$hidden_key]);
                }
            }

            //如果model为空就全部放进去，如果不为空就放匹配的进去
            if(is_null($this->get('model')) || ($this->get('model')==$data['model'])){
                $task_list[] = $data;
            }

        }
        //把全部tack_id重新保存到缓存
        Cache::forever($cid.'_allTaskId',$task_id_arr);
        //显示时排序
        array_multisort(array_column($task_list,'timestamp'),SORT_DESC,$task_list);
        return $task_list;
    }

    /**
     * 通过url参数判断要做什么操作
     */
    public function export(){

        $url_params = request()->all();

        //创建任务
        if(!empty($url_params['qExCreate'])){
            $this->create();
            switch ($this->get('export_type')){
                case 'queue':
                    $this->queue();//创建队列任务
                    break;
                case 'syncCsv':
                    $this->progressRead($this->get('total_count'));
                    $this->progressWrite($this->get('total_count'));
                    $this->complete(true);
                    $this->downloadUrl(request()->getSchemeAndHttpHost().'/queue-export-csv'.'?taskId='.$this->get('task_id'));
                    break;
                case 'syncXls':
                    $this->progressRead($this->get('total_count'));
                    $this->progressWrite($this->get('total_count'));
                    $this->complete(true);
                    $this->downloadUrl(request()->getSchemeAndHttpHost().'/queue-export-xls'.'?taskId='.$this->get('task_id'));
                    break;
                default:
                    $this->exception('请设置导出类型');
                    break;
            }
            return '正在生成数据';
        }

        //有list参数表示获取列表
        if(!empty($url_params['qExList'])){
            $list = $this->allTask();
            return $list;
        }

        $this->exception('非法操作');
    }

    /**
     * 如果数据量较大，就要考虑导出csv了
     */
    public function exportCsv(){

        //下载数据 ↓

        set_time_limit(0);

        $task_info = $this->get();

        $query = $this->buildQuery();
        if(isset($query->count)){
            $total_count = $query->count;
        }else{
            $total_count = $query->count();
        }

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
        $batch_count = ceil($total_count/$batch_size);
        for($batch_current=1;$batch_current<=$batch_count;$batch_current++){

            $items = $query
                ->skip(($batch_current-1)*$batch_size)
                ->take($batch_size)
                ->get();
            $rows = [];

            foreach($items as $item){
                $rows[] = $this->getFieldValue($item);

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

    public function exportXls(){

        //下载数据 ↓

        set_time_limit(0);

        $query = $this->buildQuery();
        $datas = [];

        $query
            ->get()
            ->each(function($item)use(&$datas){
                $datas[] = $this->getFieldValue($item);
            });

        $this->setDatas($datas);

        $file = $this->write(1,1);

        rmdir($this->fileDir());

        return $file;
    }

    private function write($batch_start,$batch_end,$file_suffix=''){
        ini_set('memory_limit','1024M');

        $size1 = memory_get_usage();
        $time1 = time();

        $coordinate = range('A','Z');
        $coordinate2 = array_map(function($var){return 'A'.$var;},$coordinate);
        $coordinate3 = array_map(function($var){return 'B'.$var;},$coordinate);
        $coordinate = array_merge($coordinate,$coordinate2);
        $coordinate = array_merge($coordinate,$coordinate3);

        $task_info = $this->get();

        $headers = $task_info['headers'];

        $file = $this->fileDir().$file_suffix.'.'.$this->config('file_ext');

        if(!is_dir($this->fileDir())){
            mkdir($this->fileDir());
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->setActiveSheetIndex(0);

        //设置表头
        $title = $sheet->setCellValue('A1', $headers[0]);
        array_shift($headers);
        $coordinate_i = 1;
        foreach ($headers as $header){
            $title->setCellValue($coordinate[$coordinate_i].'1', $header);
            $coordinate_i++;
        }

        $set_data = $spreadsheet->getActiveSheet();

        $row = 2;

        for($b=$batch_start;$b<=$batch_end;$b++){
            $datas = $this->getDatas($b);
            foreach ($datas as $row_data){
                $coordinate_i = 0;
                foreach ($row_data as $value){
                    $set_data->setCellValue($coordinate[$coordinate_i].$row, $value);
                    $coordinate_i++;
                }
                $this->progressWrite(1);
                $row++;
            }
            unset($datas);
        }

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');

        $writer->save($file);

        $size2 = memory_get_usage();
        $time2 = time();

        $this->log('占用：'.$this->formatMemorySize($size2-$size1).' 用时：'.($time2-$time1));

        return $file;

    }

    private function getDatas($b=null){
        $datas = $this->datas;
        if(empty($datas)){
            $datas = Cache::pull($this->filePath(true).'_'.$b)??[];
        }

        return $datas;
    }

    private function setDatas($datas){
        $this->datas = $datas;
    }

    /**
     * 执行队列
     */
    public function queue(){
        for ($batch_current=1;$batch_current<=$this->get('batch_count');$batch_current++) {
            ExportQueue::dispatch($this->taskId,$batch_current)->onQueue($this->config('queue_name'));
        }
    }

    /**
     * 从数据库中读取数据
     * @param $batch_current
     */
    public function read($batch_current){

        //如果任务已经失败或已取消，就不再往下执行了
        if($this->isFail()||$this->isCancel()){
            return false;
        }

        //开始读取之后把show_name改成文件名，因为开始读取前show_name可能是“任务正在排队”
        /*$task_info = $this->get();
        if($this->showName()!=$task_info['filename']){
            $this->showName($task_info['filename']);
        }*/

        $query = $this->buildQuery();

        $datas = [];

        $query
            ->skip(($batch_current-1)*$this->config('batch_size'))
            ->take($this->config('batch_size'))
            ->get()
            ->each(function($item)use(&$datas){
                $datas[] = $this->getFieldValue($item);
                $this->progressRead(1);
            });

        $this->appendToCache($datas,$batch_current);
        unset($datas);
    }

    private function appendToCache($datas,$batch_current){
        Cache::put($this->filePath(true).'_'.$batch_current,$datas,$this->expire());

        if($this->config('multi_file')){
            $this->writeOne($batch_current);
        }else{
            $this->writeAll();
        }
    }

    /**
     * 写入一个文件
     */
    private function writeOne($batch_current){

        $tack_info = $this->get();

        //每n条一个文件，如果数量不够n条而且还没结束导出，就不执行
        $file_size = $this->config('file_size');

        //每多少批次获取一次数据
        $get_batch = ceil($file_size/$tack_info['batch_size']);
        $batch_count = $tack_info['batch_count'];
        if($get_batch>$batch_count){
            $get_arr = [0];
        }else{
            //这个数组的值就表示了每次要获取的最后一个批次
            $get_arr = range(0,$batch_count,$get_batch);
        }

        $batch_end_key = array_search($batch_current,$get_arr);
        if( (false!==$batch_end_key) && (isset($get_arr[$batch_end_key-1])) ){
            $batch_start = $get_arr[$batch_end_key-1]+1;
            $batch_end = $get_arr[$batch_end_key];

            //当前是第几个文件
            $file_current = ($batch_end/($batch_end-$batch_start+1));
        }elseif($batch_current==$tack_info['batch_count']){//如果是最后一批

            $batch_start = end($get_arr)+1;
            $batch_end = $batch_current;

            //也就是最后一个文件
            $file_current = ceil($tack_info['total_count']/$this->config('file_size'));
        }else{
            return;
        }

        $this->write($batch_start,$batch_end,'/'.$tack_info['filename'].'_'.$file_current);

        if($this->isCompleted()){
            $this->zip();

            //上传到oss
            $this->upload();
        }
    }

    /**
     * 获取当前任务的信息
     */
    public function get($key=null,$refresh=false){
        if(is_null($key)){
            if(empty($this->taskInfo) || $refresh){
                $this->taskInfo = Cache::get($this->taskId)??[];
            }
            return $this->taskInfo;
        }else{
            if(!isset($this->taskInfo[$key]) || $refresh){
                $this->taskInfo = array_merge($this->taskInfo,Cache::get($this->taskId)??[]);
            }
            return $this->taskInfo[$key]??null;
        }
    }

    public function set($key,$value){
        $this->taskInfo[$key] = $value;
    }

    /**
     * 记录日志
     */
    public function log($exception){
        $str = '';
        if($exception instanceof \Exception){
            $str = $exception->getMessage().' FILE : '.$exception->getFile().' LINE : '.$exception->getLine();
        }else{
            if(is_string($exception)){
                $str = $exception;
            }else{
                $str = $this->enJson($exception);
            }
        }

        LogService::write($str,'EXPORT_QUEUE');
    }

    //获取用点分隔的多维数组的值
    private function getDotValue($item,$field){
        $arr = explode('??',$field);
        $field = $arr[0];
        $default = $arr[1]??$field;
        if('`'==substr($field,0,1)&&'`'==substr($field,-1,1)){
            //如果有``包围的就直接返回
            $value = trim($field,'`');
            return $value;
        }
        $field = explode('.',$field);
        $value = $item;
        foreach ($field as $f){
            if(!isset($value[$f])){
                $value = $default;
                break;
            }
            $value = $value[$f];
        }
        return $value;
    }

    //获取一行数据
    private function getFieldValue($item){

        $fields = $this->get('fields');
        $data = [];
        foreach ($fields as $field){
            if(''===$field){
                $value = '';
            }else{
                //'status|1:未支付;2:已支付;3:退费中;4:已退费;5:已关闭'
                $strs = explode('|',$field);
                $field = array_shift($strs);
                $dict_arr = [];
                $tabs = false;//是否添加制表符
                foreach($strs as $str_item){
                    $str_item = explode(';',$str_item);
                    foreach($str_item as $str){
                        if(strpos($str,':')){
                            $dict = explode(':',$str);
                            if(isset($dict[0],$dict[1])){
                                $dict_arr[$dict[0]] = $dict[1];
                            }
                        }elseif('tabs'==$str){
                            $tabs = true;
                        }
                    }
                }

                $value = $field;
                if('`'==substr($value,0,1)&&'`'==substr($value,-1,1)){
                    //如果有``包围的就直接取值
                    $value = trim($value,'`');
                }else{
                    //看有没有函数，有就调用函数
                    $func_name = substr($value,0,(strpos($value,'(')));//func();
                    if(strpos($value,'(')&&strpos($value,')')&&function_exists($func_name)){
                        $sub_start = strpos($value,'(');
                        $sub_end = strripos($value,')');
                        $func_param_str = substr($value,$sub_start+1,$sub_end-$sub_start-1);
                        $func_param_arr = explode(',',$func_param_str);
                        $func_param_arr = array_map(function($va){return str_replace(['"',"'",],'',$va);},$func_param_arr);
                        $func_param_arr = array_map('trim',$func_param_arr);

                        $dot_value_arr = [];
                        foreach ($func_param_arr as $func_param){
                            $dot_value_arr[] = $this->getDotValue($item,$func_param);
                        }
                        $value = call_user_func_array($func_name,$dot_value_arr);
                    }else{
                        $value = $this->getDotValue($item,$value);
                    }
                    //如果有字典
                    if(!empty($dict_arr)&&isset($dict_arr[$value])){
                        $value = $this->getDotValue($item,$dict_arr[$value]);//$dict_arr[$value];
                    }
                    //如果单元格的值是数组就转成json
                    if(is_array($value)){
                        $value = $this->enJson($value);
                    }
                    //如果添加制表符，就是转换成字符串，不用科学计数法显示
                    if($tabs || is_object($value)){
                        $value = "\t".$value."\t";
                    }
                }
                if($value==$field) $value = '';
            }

            $data[] = $value;
        }
        return $data;
    }

    //压缩
    private function zip(){

        $dir = $this->fileDir();
        //读取文件夹下的全部文件
        $file_arr = [];
        $handler = opendir($dir);
        while (($file = readdir($handler)) !== false) {
            if ($file != "." && $file != "..") {
                $file_arr[] = $dir . "/" . $file;
            }
        }
        @closedir($dir);

        //文件大于1个才进行压缩，否则直接改名、移动
        if(1<count($file_arr)){
            $this->config('file_ext','zip');
            $path = $dir.'.zip';//压缩完成后文件的绝对路径

            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::OVERWRITE|ZipArchive::CREATE) === TRUE) {
                foreach ($file_arr as $file){
                    $zip->addFile($file,basename($file));
                }
            }
            $zip->close();
        }else{//如果只有一个文件，不压缩
            $path = $dir.'.xlsx';
            copy($file_arr[0],$path);
        }

        //压缩后删除文件夹
        $this->delDir();
    }

    private function isCompleted(){
        //导出的数量不等于总数量，不合并
        if($this->progressRead()!=$this->get('total_count')){
            return false;
        }

        //写入文件的行数不等于总数量，不合并
        if($this->progressWrite()!=$this->get('total_count')){
            return false;
        }

        //如果任务失败，不合并
        if(1==$this->isFail()){
            return false;
        }

        //如果任务取消，不合并
        if(1==$this->isCancel()){
            return false;
        }

        //把任务设置成已完成，如果设置失败，不合并
        if(!$this->complete(true)){
            return false;
        }

        return true;
    }

    /**
     * 生成excel文件
     */
    private function writeAll(){
        $this->write(1,$this->get('batch_count'));

        rmdir($this->fileDir());

        if($this->isCompleted()){
            //上传到oss
            $this->upload();
        }
    }

    //把文件上传到oss
    private function upload($del_local=true){
        $file_path = $this->filePath();

        //上传到oss
        if($this->config('upload_oss')){
            //文件名
            $file_name = basename($file_path);
            //文件在oss上的路径
            $oss_path =  'queue_export/'.$file_name;
            //上传文件
            $oss_client = new OssClient($this->config('oss')['accessKeyId'], $this->config('oss')['accessKeySecret'], $this->config('oss')['endpoint']);
            $oss_client->uploadFile($this->config('oss')['bucket'], $oss_path, $file_path);
            //删除文件
            if($del_local){
                unlink($file_path);
            }

            $download_url = $this->config('oss')['host'].'/'.$oss_path;
            $this->log('OSS文件上传成功: [' . $download_url . "] 总用时：".(time()-$this->get('timestamp')));
        }else{
            $this->localPath($file_path);
            $download_url = $this->get('http_host').'/queue-export-download-local'.'?taskId='.$this->get('task_id');
        }

        $this->downloadUrl($download_url);

    }

    /**
     * 任务失败处理
     */
    public function fail($exception){

        if(Cache::has($this->taskId) && ('任务已取消'!=$this->showName())){
            $this->isFail(true);
            $this->showName('任务执行失败');
            //清除数据缓存
            Cache::forget($this->filePath(true));
        }
        $this->log($exception);

    }

    //取消任务
    public function cancel(){
        $this->isCancel(true);
        $this->showName('任务已取消');
        $this->delDir();
        Cache::forget($this->filePath(true));
        $this->log('任务已取消');
    }

    /**
     * 格式化内存大小
     */
    private function formatMemorySize($size) {
        $unit = array('b', 'kb', 'mb', 'gb', 'tb', 'pb');
        return @round($size / pow(1024, ($i = floor(log($size, 1024)))), 2) . ' ' . $unit[$i];
    }

    public function enJson($data){
        return json_encode($data,JSON_UNESCAPED_UNICODE);
    }

    public function deJson($data){
        return json_decode($data,true);
    }

    /**
     * 获取文件保存目录
     * @return string
     */
    public function fileDir(){
        $dir = config("filesystems.disks.{$this->config('disk')}.root").'/'.$this->get('filename');
        return rtrim($dir,'/');
    }

    /**
     * 获取文件路径
     * @return string
     */
    public function filePath($md5=false){
        $path = $this->fileDir().'.'.$this->config('file_ext');
        if($md5){
            $path = md5($path);
        }
        return $path;
    }

    public function del(){
        Cache::forget($this->taskId);
        Cache::forget($this->taskId.'_download_url');
        Cache::forget($this->taskId.'_local_path');
        Cache::forget($this->taskId.'_show_name');
        Cache::forget($this->taskId.'_is_fail');
        Cache::forget($this->taskId.'_is_cancel');
        Cache::forget($this->taskId.'_complete');
        Redis::del($this->taskId.'_progress_read');
        Redis::del($this->taskId.'_progress_write');
        Cache::forget($this->filePath(true));
    }

    private function delDir(){
        $dir = $this->fileDir();
        if(is_dir($dir)){
            $handler_del = opendir($dir);
            while (($file = readdir($handler_del)) !== false) {
                if ($file != "." && $file != "..") {
                    unlink($dir . "/" . $file);
                }
            }
            @closedir($dir);
            @rmdir($dir);
        }
    }

    //一个直接导出的方法
    public function exportCsvFromCollection(Collection $collction){
        set_time_limit(0);


        $datas = [];

        $collction
            ->each(function($item)use(&$datas){
                $datas[] = $this->getFieldValue($item);
            });

        //下载数据 ↓

        $filename = rtrim($this->get('filename'),'/');

        header('Content-Type: application/vnd.ms-excel;charset=utf-8');
        header('Content-Disposition: attachment;filename="' . iconv("UTF-8", "GB2312", $filename) . '.csv"');
        header('Cache-Control: max-age=0');

        $fp = fopen('php://output', 'a');

        //设置标题
        $headers = $this->get('headers');

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