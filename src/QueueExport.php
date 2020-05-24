<?php
namespace Codercwm\QueueExport;

use Codercwm\QueueExport\Export\Export;
use Codercwm\QueueExport\Jobs\ExportQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache as LaravelCache;
use Illuminate\Support\Facades\Config as LaravelConfig;
use Illuminate\Support\Facades\Redis;
use OSS\OssClient;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use \ZipArchive;
//坑：如果导出过程中数据被删除，那么就完成不了，任务会一直执行下去
class QueueExport{
    private $datas = [];//数据

    public function config($key,$value){
        Config::set($key,$value);
        return $this;
    }

    /**
     * 设置cid
     * @param string $cid 每个用户的唯一id，用于获取每个用户的任务列表，一般传入用户cid
     */
    public function setCid(string $cid){
        Info::set('cid',$cid);
        Id::set($cid.'QUEUE_EXPORT'.uniqid());
        return $this;
    }

    /**
     * @param string $model
     * @param array $params 筛选条件
     * @param string $method model筛选方法
     */
    public function setModel(string $model,string $method=null,array $params=[]){
        if( !is_null($method) && !method_exists(new $model,$method) ){
            throw new Exception($model.'中不存在'.$method.'方法，请检查',true);
        }
        Info::set('model',$model);
        Info::set('model_method',$method);
        Info::set('model_params',$params);
        return $this;
    }

    /**
     * 设置文件名
     * @param $filename 传入一个唯一的文件名
     */
    public function setFilename(string $filename){
        //判断文件名是否已存在
        $task_all = Task::all();
        foreach ($task_all as $task){
            if($filename===$task['filename']) {
                throw new Exception('已存在重复的文件名',true);
            }
        }
        Info::set('filename',$filename);
        return $this;
    }

    /**
     * 设置表头以及要获取的字段名
     * @param $headers excel表头
     * @param $fields 要获取的字段名，如果是多维，用点“.”隔开
     */
    public function setHeadersFields(array $headers,array $fields){
        Info::set('headers',$headers);
        Info::set('fields',$fields);
        return $this;
    }

    /**
     * 设置导出类型
     * Author: cwm
     * Date: 2019-11-8
     * @param $export_type queue：异步队列，syncXls：同步导出xlsx格式的数据，syncCsv：同步导出csv格式的数据
     */
    public function setExportType($export_type){
        Info::set('export_type',$export_type);
        return $this;
    }

    /**
     * 通过url参数判断要做什么操作
     */
    public function export(){

        $url_params = request()->all();

        //创建任务
        if(!empty($url_params['qExCreate'])){
            $export = new Export();
            $export->creation();
            return '正在生成数据';
        }

        //有list参数表示获取列表
        if(!empty($url_params['qExList'])){
            $list = Task::all();
            return $list;
        }

        throw new Exception('非法操作');
    }

    /**
     * 从数据库中读取数据
     * @param $batch_current
     */
    public function read($batch_current){

        //如果任务已经失败或已取消，就不再往下执行了
        if(Cache::isFail()||Cache::isCancel()){
            return false;
        }

        //构造查询实例
        $build = new Build();

        $query = $build->query();

        $datas = [];

        //如果时最后一批的话就获取最后一批的数据，避免数据库不断有数据插入，那么这个队列就停不下来了
        if($batch_current>=Info::get('batch_count')){
            $get_size = Info::get('last_batch_size');
        }else{
            $get_size = Config::get('batch_size');
        }

        $query
            ->skip(($batch_current-1)*Config::get('batch_size'))
            ->take($get_size)
            ->get()
            ->each(function($item)use(&$datas){
                $datas[] = FieldValue::get($item);
                Progress::incrRead(1);
            });

        $this->appendToCache($datas,$batch_current);
        unset($datas);
    }

    private function appendToCache($datas,$batch_current){
        LaravelCache::put(File::path(true).'_'.$batch_current,$datas,Cache::expire());

        if(Config::get('multi_file')){
            $this->writeOne($batch_current);
        }elseif(Progress::getRead()>=Info::get('total_count')){
            $this->writeAll();
        }
    }

    /**
     * 写入一个文件
     */
    private function writeOne($batch_current){

        $tack_info = Info::get();

        //每n条一个文件，如果数量不够n条而且还没结束导出，就不执行
        $file_size = Config::get('file_size');

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
            $file_current = ceil($tack_info['total_count']/Config::get('file_size'));
        }else{
            return;
        }

        File::write($batch_start,$batch_end,'/'.$tack_info['filename'].'_'.$file_current);

        if($this->isCompleted()){
            $this->zip();

            //上传到oss
            $this->upload();
        }
    }

    //压缩
    private function zip(){

        $dir = File::dir();
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
            Config::set('file_ext','zip');
            $path = $dir.'.zip';//压缩完成后文件的绝对路径

            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::OVERWRITE|ZipArchive::CREATE) === TRUE) {
                foreach ($file_arr as $file){
                    $zip->addFile($file,basename($file));
                }
            }
            @$zip->close();
        }else{//如果只有一个文件，不压缩
            $path = $dir.'.xlsx';
            copy($file_arr[0],$path);
        }

        //压缩后删除文件夹
        $this->delDir();
    }

    private function isCompleted(){

        //坑：因为数据库会被插入数据，所以读取出来的数量可能会大于一开始时统计的数量

        //导出的数量不等于总数量，不合并
        if(Progress::getRead()<Info::get('total_count')){
            return false;
        }

        //写入文件的行数不等于总数量，不合并
        if(Progress::getWrite()<Info::get('total_count')){
            return false;
        }

        //如果任务失败，不合并
        if(1==Cache::isFail()){
            return false;
        }

        //如果任务取消，不合并
        if(1==Cache::isCancel()){
            return false;
        }

        //把任务设置成已完成，如果设置失败，不合并
        if(!Cache::complete(true)){
            return false;
        }

        return true;
    }

    /**
     * 生成excel文件
     */
    private function writeAll(){
        File::write(1,Info::get('batch_count'));

        rmdir(File::dir());

        if($this->isCompleted()){
            //上传到oss
            $this->upload();
        }
    }

    //把文件上传到oss
    private function upload($del_local=true){
        $file_path = File::path();

        //上传到oss
        if(Config::get('upload_oss')){
            //文件名
            $file_name = basename($file_path);
            //文件在oss上的路径
            $oss_path =  'queue_export/'.$file_name;
            //上传文件
            $oss_client = new OssClient(Config::get('oss')['accessKeyId'], Config::get('oss')['accessKeySecret'], Config::get('oss')['endpoint']);
            $oss_client->uploadFile(Config::get('oss')['bucket'], $oss_path, $file_path);
            //删除文件
            if($del_local){
                unlink($file_path);
            }

            $download_url = Config::get('oss')['host'].'/'.$oss_path;
            Log::write('OSS文件上传成功: [' . $download_url . "] 总用时：".(time()-Info::get('timestamp')));
        }else{
            Cache::add('local_path',$file_path);
            $download_url = Info::get('http_host').'/'.Config::get('route_prefix').'/queue-export-download-local'.'?taskId='.Info::get('task_id');
        }

        Cache::downloadUrl($download_url);

    }

    /**
     * 任务失败处理
     */
    public function fail($exception){

        if(LaravelCache::has(Id::get()) && ('任务已取消'!=Cache::showName())){
            Cache::isFail(true);
            Cache::showName('任务执行失败');
            //清除数据缓存
            LaravelCache::forget(File::path(true));
        }
        Log::write($exception);

    }

    //取消任务
    public function cancel(){
        Cache::isCancel(true);
        Cache::showName('任务已取消');
        $this->delDir();
        LaravelCache::forget(File::path(true));
        Log::write('任务已取消');
    }

    private function delDir(){
        $dir = File::dir();
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
                $datas[] = FieldValue::get($item);
            });

        //下载数据 ↓

        $filename = rtrim(Info::get('filename'),'/');

        header('Content-Type: application/vnd.ms-excel;charset=utf-8');
        header('Content-Disposition: attachment;filename="' . iconv("UTF-8", "GB2312", $filename) . '.csv"');
        header('Cache-Control: max-age=0');

        $fp = fopen('php://output', 'a');

        //设置标题
        $headers = Info::get('headers');

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