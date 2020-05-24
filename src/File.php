<?php

namespace Codercwm\QueueExport;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

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

        $file = self::dir().$file_suffix.'.'.Config::get('file_ext');

        if(!is_dir(self::dir())){
            @mkdir(self::dir());
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
            $datas = Data::get($b);

            //如果没有获取到数据，就等待
            //因为如果队列开启了多个进程，执行的顺序是不一定的
            //有时候会出现已经执行到这里要写入数据了，数据却还没有读取出来的情况
            while (0==intval(count($datas))){
                sleep(2);
                $datas = Data::get($b);
            }

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

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');

        $writer->save($file);

        $size2 = memory_get_usage();
        $time2 = time();

        Log::write('占用：'.Tool::formatMemorySize($size2-$size1).' 用时：'.($time2-$time1));

        return $file;
    }
}