<?php
namespace Codercwm\QueueExport;

use Codercwm\QueueExport\Jobs\ExportQueue;
use Codercwm\QueueExport\Services\LogService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use OSS\OssClient;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use \ZipArchive;

class QueueExport{
    private $qExCid;//cid
    private $qExModel;//model
    private $qExModelParams;//筛选条件
    private $qExModelMethod = 'qExExport';//model筛选方法
    private $qExFilename;//文件名
    private $qExHeaders;//表头
    private $qExFields;//字段名，如果是多维，用点“.”隔开
    private $qExId;//qExId用于在不同的进程中识别同一个任务
    private $qExExportType;//导出类型，queue：异步队列，syncXls：同步导出xlsx格式的数据，syncCsv：同步导出csv格式的数据
    private $keyValue = [];//临时获取redis中的值
    private $datas = [];//数据
    private $taskInfo = [];//任务信息

    private $qExConfig = [
        'expire' => 86400,   //任务过期时间
        'disk' => 'public',//disk
        'batch_size' => 1,//每批次查询多少条
        'file_ext' => 'xlsx',//文件后缀
        'multi' => false,//是否允许多任务并行
        'multi_file' => true,//是否允许多文件
        'write_type' => 'append_to_redis',//append_to_file或者append_to_redis两种方式
        'allow_export_type' => ['queue','syncCsv','syncXls'],
        'file_size' => 1,//分文件时，一个文件多少条，必须比batch_size大
    ];

    public $qExMessage = '';//

    /**
     * 设置cid
     * @param string $cid 每个用户的唯一id，用于获取每个用户的任务列表，一般传入用户cid
     */
    public function setCid(string $cid){
        $this->qExCid = $cid;
        $q_ex_id = $this->qExCid.'QUEUE_EXPORT'.uniqid();
        $this->qExId = $q_ex_id;
        return $this;
    }

    /**
     * 不同的进程中，设置了qexid，就可以获取一个已存在的任务了，如果没有qexid，将什么都没有
     * @return mixed
     */
    public function setQExId($q_ex_id=null) {
        if(is_null($q_ex_id)){
            $q_ex_id = $this->qExCid.'QUEUE_EXPORT'.uniqid();
        }
        $this->qExId = $q_ex_id;
        return $this;
    }

    /**
     * 设置model，model中必须存在 qExExport 方法
     * @param $model model的类名
     */
    public function setModel(string $model,array $params=[],string $method='qExExport'){
        if(!method_exists(new $model,$method)){
            $this->exception($model.'::'.$method.'方法是必须的',true);
        }
        $this->qExModelParams = $params;
        $this->qExModel = $model;
        $this->qExModelMethod = $method;
        return $this;
    }

    /**
     * 设置文件名
     * @param $filename 传入一个唯一的文件名
     */
    public function setFilename(string $filename){
        //判断文件名是否已存在
        $q_ex_all = $this->qExAll();
        foreach ($q_ex_all as $task){
            if($filename===$task['filename']) {
                $this->exception('已存在重复的文件名',true);
            }
        }
        $this->qExFilename = $filename;
        return $this;
    }

    /**
     * 设置表头以及要获取的字段名
     * @param $headers excel表头
     * @param $fields 要获取的字段名，如果是多维，用点“.”隔开
     */
    public function setHeadersFields(array $headers,array $fields){
        $this->qExHeaders = $headers;
        $this->qExFields = $fields;
        return $this;
    }

    /**
     * 抛出错误，并从redis清除这个任务
     * @param string $msg
     */
    public function exception(string $msg,$clear=false){
        if($clear){
            //一旦报错就从缓存清除这个任务
            Cache::forget($this->qExId);
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
        $this->qExExportType = $export_type;
        return $this;
    }

    /**
     * 开始创建任务
     * @return bool
     */
    public function create() {

        //不是如果是允许的类型
        if(!in_array($this->qExExportType,$this->qExConfig['allow_export_type'])){
            $this->exception('无效的类型');
        }

        /*if(!$this->qExConfig['multi']){
            //判断是否有未完成的
            $task_list = $this->qExAll($this->qExCid);
            foreach ($task_list as $task){
                if(
                    (0==$this->isFail())&&
                    (0==$this->isCancel())&&
                    ($this->qExModel==$task['model'])
                ){
                    if(100>$this->progress()) {
                        $this->exception('请等待当前任务完成');
                    }
                }
            }
        }*/

        $taskInfo = [];


        //任务id
        $taskInfo['q_ex_id'] = $this->qExId;
        //文件（夹）名
        $this->qExFilename &&  ($taskInfo['filename'] = $this->qExFilename) || $this->exception('文件名不能为空',true);
        //设置导出类型
        $taskInfo['export_type'] = $this->qExExportType;
        //前端显示的名称，任务开始后改为filename
        $taskInfo['show_name'] = $this->qExFilename;
        //excel表头
        $this->qExHeaders && ($taskInfo['headers'] = $this->qExHeaders) || $this->exception('请设置表头',true);
        //字段名
        $this->qExFields && ($taskInfo['fields'] = $this->qExFields) || $this->exception('请设置字段名',true);
        //实例化model
        $model_method = $this->qExModelMethod;
        $this->qExModel && ($instance = $this->qExModel::$model_method($this->qExModelParams)) || $this->exception('请设置Model',true);
        $taskInfo['model'] = $this->qExModel;
        $taskInfo['model_params'] = $this->qExModelParams;
        $taskInfo['model_method'] = $this->qExModelMethod;

        $config = $this->qExConfig;
        //每次条数
        $taskInfo['batch_size'] = $config['batch_size'];
        //总数量
        if(isset($instance->count)){
            $count = $instance->count;
        }else{
            $count = $instance->count();
        }
        $taskInfo['total_count'] = $count;
        //总共分了多少批
        $taskInfo['batch_count'] = ceil($count/$config['batch_size']);

        //任务开始的时间戳
        $taskInfo['timestamp'] = time();

        //任务过期的时间戳
        $taskInfo['expire_timestamp'] = $taskInfo['timestamp']+$config['expire'];

        $this->taskInfo = $taskInfo;

        //把任务保存到缓存
        //设置超时时间
        $expire = intval($this->qExConfig['expire']/60);
        Cache::put($this->qExId,$this->taskInfo, $expire);

        //保存所有qExId
        $q_ex_id_arr = Cache::pull($this->qExCid)??[];
        array_push($q_ex_id_arr,$this->qExId);
        Cache::forever($this->qExCid,$q_ex_id_arr);

        $this->progress(0);

        return true;
    }

    private function cacheAdd($key,$value){
        return Cache::add($this->qExId.'_'.$key,$value,intval(($this->get('expire_timestamp')-time())/60));
    }

    private function downloadUrl($download_url=null){
        if(is_null($download_url)){
            return Cache::get($this->qExId.'_download_url')??'';
        }
        return $this->cacheAdd('download_url',$download_url);
    }

    private function showName($show_name=null){
        if(is_null($show_name)){
            return Cache::get($this->qExId.'_show_name')??'';
        }
        return $this->cacheAdd('show_name',$show_name);
    }

    private function isFail($is_fail=false){
        if(!$is_fail){
            return Cache::get($this->qExId.'_is_fail')??0;
        }
        return $this->cacheAdd('is_fail',1);
    }

    private function isCancel($is_cancel=false){
        if(!$is_cancel){
            return Cache::get($this->qExId.'_is_cancel')??0;
        }
        return $this->cacheAdd('is_cancel',1);
    }

    private function complete($complete=false){
        if(!$complete){
            return Cache::get($this->qExId.'_complete')??0;
        }
        return $this->cacheAdd('complete',time());
    }

    private function progress($progress=null){
        if(is_null($progress)){
            return Cache::get($this->qExId.'_progress')??0;
        }
        return $this->cacheAdd('progress',$progress);
    }

    /**
     * 获取所有任务
     * @return array
     */
    public function qExAll($cid=''){
        if(''===$cid) $cid = $this->qExCid;
        if(is_null($cid)) return [];
        //获取所有key
        $keys = Cache::pull($cid)??[];
        $task_list = [];
        $q_ex_id_arr = [];
        foreach($keys as $key){
            if(!Cache::has($key)) continue;
            $data = Cache::pull($key);
            if(empty($data['filename'])) continue;
            $data['progress'] = Cache::get($key.'_progress')??0;
            $data['is_fail'] = Cache::get($key.'_is_fail')??0;
            $data['is_cancel'] = Cache::get($key.'_is_cancel')??0;
            $data['complete'] = Cache::get($key.'_complete')??0;
            $data['download_url'] = Cache::get($key.'_download_url')??'';
            if(
                (0==$data['is_fail'])&&
                (0==$data['is_cancel'])&&
                (0==$data['progress'])&&
                (10<=(time()-$data['timestamp']))
            ){
                $data['show_name'] = '任务正在排队';
            }
            if(!is_null($this->qExModel)){
                if($this->qExModel==$data['model']){
                    $task_list[] = $data;
                }
            }else{
                $task_list[] = $data;
            }
            $q_ex_id_arr[] = $key;
            Cache::put($key,$data,intval(($data['expire_timestamp']-time())/60));
        }
        Cache::forever($this->qExCid,$q_ex_id_arr);
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
            switch ($this->qExExportType){
                case 'queue':
                    $this->queue();//创建队列任务
                    break;
                case 'syncCsv':
                    $this->progress($this->get('total_count'));
                    $this->complete(true);
                    $this->downloadUrl(request()->getSchemeAndHttpHost().'/queue-export-csv'.'?qExId='.$this->taskInfo['q_ex_id']);
                    break;
                case 'syncXls':
                    $this->progress($this->get('total_count'));
                    $this->complete(true);
                    $this->downloadUrl(request()->getSchemeAndHttpHost().'/queue-export-xls'.'?qExId='.$this->taskInfo['q_ex_id']);
                    break;
                default:
                    $this->exception('非法操作1');
                    break;
            }
            return '正在生成数据';
        }

        //有list参数表示获取列表
        if(!empty($url_params['qExList'])){
            $list = $this->qExAll();
            return $list;
        }

        $this->exception('非法操作2');
    }

    /**
     * 如果数据量较大，就要考虑导出csv了
     */
    public function exportCsv(){

        //下载数据 ↓

        set_time_limit(0);

        $task_info = $this->get();

        $model = $task_info['model'];
        $model_method = $task_info['model_method'];
        $model_params = $task_info['model_params'];
        $query = $model::$model_method($model_params);
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

        $task_info = $this->get();

        $model = $task_info['model'];
        $model_method = $task_info['model_method'];
        $model_params = $task_info['model_params'];
        $query = $model::$model_method($model_params);

        $datas = [];

        $query
            ->get()
            ->each(function($item)use(&$datas){
                $datas[] = $this->getFieldValue($item);
            });

        $this->setDatas($datas);

        $file = $this->write(1,1);

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

        $file = $this->fileDir().$file_suffix.'.'.$this->qExConfig['file_ext'];

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
            ExportQueue::dispatch($this->qExId,$batch_current)->onQueue(ExportQueue::QUEUE_NAME);
        }
    }

    /**
     * 从数据库中读取数据
     * @param $batch_current
     */
    public function read($batch_current){
        $task_info = $this->get();
        //如果任务已经失败或已取消，就不再往下执行了
        if($this->isFail()||$this->isCancel()){
            return false;
        }

        //开始读取之后把show_name改成文件名，因为开始读取前show_name可能是“任务正在排队”
        if($task_info['show_name']!=$task_info['filename']){
            $this->set('show_name',$task_info['filename']);
        }

        $model_method = $task_info['model_method'];
        $model = $task_info['model'];
        $model_params = $task_info['model_params'];

        $query = $model::$model_method($model_params);

        $datas = [];

        $query
            ->skip(($batch_current-1)*$this->qExConfig['batch_size'])
            ->take($this->qExConfig['batch_size'])
            ->get()
            ->each(function($item)use(&$datas){
                $datas[] = $this->getFieldValue($item);
                Cache::increment($this->qExId.'_progress',1);
            });

        $this->appendToCache($datas,$batch_current);
        unset($datas);
    }

    private function appendToCache($datas,$batch_current){
        Cache::put($this->filePath(true).'_'.$batch_current,$datas,intval($this->qExConfig['expire']/60));

        if($this->qExConfig['multi_file']){
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
        $file_size = $this->qExConfig['file_size'];

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
            $file_current = ceil($tack_info['total_count']/$this->qExConfig['file_size']);
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
                $this->taskInfo = Cache::get($this->qExId);
            }
            return $this->taskInfo;
        }else{
            if(!isset($this->taskInfo[$key]) || $refresh){
                $this->taskInfo = Cache::get($this->qExId);
            }
            return $this->taskInfo[$key];
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

                //                $value = $this->qExGetFieldValueFromFunc($item,$field);

                $field = explode('.',$field);
                $value = $item;
                foreach ($field as $f){
                    if(!isset($value[$f])){
                        $value = '';
                        break;
                    }
                    $value = $value[$f];
                }

                //如果有字典
                if(!empty($dict_arr)&&isset($dict_arr[$value])){
                    $value = $dict_arr[$value];
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
            $this->qExConfig['file_ext'] = 'zip';
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
        $handler_del = opendir($dir);
        while (($file = readdir($handler_del)) !== false) {
            if ($file != "." && $file != "..") {
                unlink($dir . "/" . $file);
            }
        }
        @closedir($dir);
        rmdir($dir);
    }

    private function isCompleted(){
        //导出的数量不等于总数量，不合并
        if($this->progress()!=$this->get('total_count')){
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
        if($this->isCompleted()){
            $this->write(1,$this->get('batch_count'));
            //上传到oss
            $this->upload();
        }
    }

    //把文件上传到oss
    private function upload($del_local=true){
        $file_path = $this->filePath();
        $this->downloadUrl($file_path);

        /*//文件名
        $file_name = basename($file_path);
        //文件在oss上的路径
        $oss_path =  'queue_export/'.$file_name;
        //上传文件
        $oss_client = new OssClient(config('oss.accessKeyId'), config('oss.accessKeySecret'), config('oss.endpoint'));
        $oss_client->uploadFile(config('oss.bucket'), $oss_path, $file_path);
        //删除文件
        if($del_local){
            unlink($file_path);
        }

        $download_url = config('oss.host').'/'.$oss_path;
        $this->log('OSS文件上传成功: [' . $download_url . "] 总用时：".(time()-$this->qExGet('timestamp')));


        $this->qExSet('url',$download_url);*/
    }

    /**
     * 任务失败处理
     */
    public function fail($exception){

        if(Cache::has($this->qExId)){
            $this->isFail(true);
            $this->showName('任务执行失败');
            //清除缓存
            Redis::del($this->filePath(true));
        }
        $this->log($exception);

    }

    //取消任务
    public function cancel(){
        $this->isCancel(true);
        $this->showName('任务已取消');
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
        $dir = config("filesystems.disks.{$this->qExConfig['disk']}.root").'/'.$this->get('filename');
        return rtrim($dir,'/');
    }

    /**
     * 获取文件路径
     * @return string
     */
    public function filePath($md5=false){
        $path = $this->fileDir().'.'.$this->qExConfig['file_ext'];
        if($md5){
            $path = md5($path);
        }
        return $path;
    }

    public function del(){
        Cache::forget($this->qExId);
        Cache::forget($this->qExId.'_download_url');
        Cache::forget($this->qExId.'_show_name');
        Cache::forget($this->qExId.'_is_fail');
        Cache::forget($this->qExId.'_is_cancel');
        Cache::forget($this->qExId.'_complete');
        Cache::forget($this->qExId.'_progress');
    }

    public function qExExportFromCollection(Collection $collction){
        set_time_limit(0);


        $datas = [];

        $fields = $this->qExFields;

        $collction
            ->each(function($item)use($fields,&$datas){
                $datas[] = $this->getFieldValue($item,$fields);
            });

        //下载数据 ↓

        $filename = rtrim($this->qExFilename,'/');

        header('Content-Type: application/vnd.ms-excel;charset=utf-8');
        header('Content-Disposition: attachment;filename="' . iconv("UTF-8", "GB2312", $filename) . '.csv"');
        header('Cache-Control: max-age=0');

        $fp = fopen('php://output', 'a');

        //设置标题
        $headers = $this->qExHeaders;

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

    /**
     * 删除所有任务
     * @return array
     */
    public function delAll($cid=''){
        if(''===$cid) $cid = $this->qExCid;
        //模糊搜索key
        $keys = Redis::keys($cid.'QUEUE_EXPORT*');
        foreach($keys as $key){
            if(!Redis::exists($key)) continue;
            if('hash'!=strval(Redis::type($key))) continue;
            if(empty(Redis::hget($key,'filename'))) continue;
            Redis::del($key);
        }
    }

    private function qExSetProgress(){
        $inc = sprintf('%.2f',45/$this->qExGet('batch_count'));
        $this->log($inc);
        Redis::hincrbyfloat($this->qExId, 'progress',$inc);
    }
}