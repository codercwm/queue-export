<?php

namespace Codercwm\QueueExport;

use Carbon\Carbon;
use Codercwm\QueueExport\Jobs\ExportQueue;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use OSS\OssClient;

class File{
    /**
     * 获取文件保存目录
     * @return string
     */
    public static function dir(){
        $dir = config("filesystems.disks.".Config::get('disk').".root").'/'.Info::get('filename');
        return rtrim($dir,'/');
    }

    /**
     * 获取文件路径
     * @return string
     */
    public static function path($md5=false){
        $path = self::dir().'.'.Config::get('file_ext');
        if($md5){
            $path = md5($path);
        }
        return $path;
    }

    public static function write($batch_start,$batch_end,$file_suffix=''){

        //判断并设置当前文件是否已写入或正在写入，如果已写入就不能再次写入
        if(!Cache::add($batch_start.'_file_writed',1)){
            return false;
        }

        ini_set('memory_limit','1024M');

        $size1 = memory_get_usage();
        $time1 = time();

        $coordinate = range('A','Z');
        $coordinate2 = array_map(function($var){return 'A'.$var;},$coordinate);
        $coordinate3 = array_map(function($var){return 'B'.$var;},$coordinate);
        $coordinate = array_merge($coordinate,$coordinate2);
        $coordinate = array_merge($coordinate,$coordinate3);

        $task_info = Info::get();

        $headers = $task_info['headers'];

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
            $datas = Data::get($b);

            foreach ($datas as $row_data){
                $coordinate_i = 0;
                foreach ($row_data as $value){
                    $set_data->setCellValue($coordinate[$coordinate_i].$row, $value);
                    $coordinate_i++;
                }
                Progress::incrWrite(1);
                $row++;
            }
            unset($datas);
        }


        $file = self::dir().$file_suffix.'.'.Config::get('file_ext');

        //如果文件已存在就不再写入
        if(file_exists($file)){
            return false;
        }

        if(!is_dir(self::dir())){
            @mkdir(self::dir(),0777,true);
        }

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($file);

        $size2 = memory_get_usage();
        $time2 = time();

        Log::write('占用：'.Tool::formatMemorySize($size2-$size1).' 用时：'.($time2-$time1));

        return $file;
    }

    /**
     * 写入一个文件
     */
    public static function writeOne($batch_current){

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

        self::write($batch_start,$batch_end,'/'.$tack_info['filename'].'_'.$file_current);

        if(Progress::isCompleted()){
            self::zip();

            //上传到oss
            self::upload();
        }
    }

    /**
     * 生成excel文件
     */
    public static function writeAll(){
        $res = self::write(1,Info::get('batch_count'));

        if($res){
            rmdir(self::dir());

            if(Progress::isCompleted()){
                //上传到oss
                self::upload();
            }
        }
    }


    //把文件上传到oss
    public static function upload($del_local=true){
        $file_path = self::path();

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

    //压缩
    public static function zip(){

        $dir = self::dir();
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

            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::OVERWRITE|\ZipArchive::CREATE) === TRUE) {
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
        self::delDir();
    }

    public static function delDir(){
        //使用延迟的方式删除文件
        ExportQueue::dispatch('delDir',Id::get())
            ->onQueue(Config::get('queue_name'))
            ->delay(Carbon::now()->addSeconds(30));;

    }

}