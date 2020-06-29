<?php

namespace Codercwm\QueueExport;

use Box\Spout\Reader\Common\Creator\ReaderEntityFactory;
use Box\Spout\Writer\Common\Creator\WriterEntityFactory;
use Carbon\Carbon;
use Codercwm\QueueExport\Jobs\ExportQueue;
use OSS\OssClient;
use Codercwm\QueueExport\CourseContent\Config;
use Codercwm\QueueExport\CourseContent\Data;
use Codercwm\QueueExport\CourseContent\Info;

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
    public static function path(){
        return self::dir().'.'.Config::get('suffix_type');
    }

    public static function write($batch_start,$batch_end,$file_suffix='',$add_headers=true){

        //判断并设置当前文件是否已写入或正在写入，如果已写入就不能再次写入
        if(!Cache::add($batch_start.'_file_writed',1)){
            return false;
        }

        $dir = self::dir();
        $suffix_type = Config::get('suffix_type');
        if(!is_dir($dir)){
            mkdir($dir,0777,true);
        }
        $file = $dir.$file_suffix.'.'.$suffix_type;
        $writer = WriterEntityFactory::createWriter($suffix_type);
        $writer->openToFile($file);

        if($add_headers){
            $writer->addRow(WriterEntityFactory::createRowFromArray(Info::get('headers')));
        }

        for($b=$batch_start;$b<=$batch_end;$b++) {
            $data = Data::get($b);
            foreach ($data as $datum){
                $writer->addRow(WriterEntityFactory::createRowFromArray($datum));
                Progress::incrWrite(1);
            }
            unset($datas);
            Data::destroy($b);
        }

        $writer->close();

        return $file;
    }

    public static function merge(){
        $size1 = memory_get_usage();
        $time1 = time();

        $dir = self::dir();

        $filename = Info::get('filename');

        $batch_count = Info::get('batch_count');

        $suffix_type = Config::get('suffix_type');

        $write_file = self::path();

        if(1==$batch_count){
            //如果只有一个文件就直接移动就可以了
            $read_file = $dir.'/'.$filename.'_1.'.$suffix_type;
            if(!is_file($read_file)){
                throw new Exception("文件不存在:{$read_file}");
            }
            rename($read_file,$write_file);
        }else{
            //把文件中的数据全部读取出来放到一个新文件，spout组件使用此方式内存占用较少

            $writer = WriterEntityFactory::createWriter($suffix_type);
            $writer->openToFile($write_file);

            $writer->addRow(
                WriterEntityFactory::createRowFromArray(Info::get('headers'))
            );

            for($b=1;$b<=$batch_count;$b++){
                $read_file = $dir.'/'.$filename.'_'.$b.'.'.$suffix_type;

                $check_file_try = 1;
                while (!is_file($read_file)){
                    sleep(1);
                    $check_file_try++;
                    if(30<$check_file_try){
                        break;
                    }
                }

                $reader = ReaderEntityFactory::createReaderFromFile($read_file);

                $check_file_try = 1;
                while (true){
                    try{
                        $reader->open($read_file);
                        break;
                    }catch (\Exception $exception){
                        sleep(1);
                        $check_file_try++;
                        if(30<$check_file_try){
                            break;
                        }
                    }
                }

                foreach ($reader->getSheetIterator() as $sheet_index => $sheet) {
                    if ($sheet_index !== 1) {
                        $writer->addNewSheetAndMakeItCurrent();
                    }

                    foreach ($sheet->getRowIterator() as $row) {
                        $writer->addRow($row);
                        Progress::incrMerge(1);
                    }
                }
                $reader->close();
                unlink($read_file);
            }
            $writer->close();
        }

        self::delDir();

        $size2 = memory_get_usage();
        $time2 = time();

        Log::write(Info::get('total_count').'条数据 合并占用：'.Tool::formatMemorySize($size2-$size1).' 合并用时：'.($time2-$time1));
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
            $download_url = Info::get('http_host').'/'.Config::get('route_prefix').'/queue-export-download-local'.'?taskId='.Id::get();
        }

        Cache::downloadUrl($download_url);

    }

    public static function delDir(){
        //使用延迟的方式删除文件
        ExportQueue::dispatch('delDir',Id::get(),null,self::dir())
            ->onQueue(Config::get('queue_name'))
            ->delay(Carbon::now()->addSeconds(30))->onConnection(Config::get('queue_connection'));
    }

}