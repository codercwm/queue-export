<?php

namespace Codercwm\QueueExport\Jobs;

use Codercwm\QueueExport\Data;
use Codercwm\QueueExport\Exception;
use Codercwm\QueueExport\File;
use Codercwm\QueueExport\Id;
use Codercwm\QueueExport\Info;
use Codercwm\QueueExport\Log;
use Codercwm\QueueExport\Progress;
use Codercwm\QueueExport\QueueExport;
use Codercwm\QueueExport\Services\LogService;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use \Illuminate\Support\Facades\Cache;

class ExportQueue implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $qExId,$batchCurrent,$action;

    /**
     * 任务运行的超时时间。
     *
     * @var int
     */
    public $timeout = 86400;

    /**
     * 任务最大尝试次数。
     *
     * @var int
     */
    public $tries = 2;

    /**
     * Create a new job instance.
     *
     * @return void
     */

    public function __construct($action,$q_ex_id,$batch_current=null)
    {
        if( ('readData'==$action) && (is_null($batch_current)) ){
            throw new \Exception('读取数据时batch_current参数是必须的');
        }
        set_time_limit(0);
        $this->action = $action;
        $this->qExId = $q_ex_id;
        $this->batchCurrent = $batch_current;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $this->{$this->action}();
    }

    private function readData(){
        try{
            //            throw new \Exception('手动失败');
            Id::set($this->qExId);
            Data::read($this->batchCurrent);
        }catch (Exception $exception){
            Progress::fail($exception);
        }

        $expire_timestamp = Info::get('expire_timestamp');
        Cache::put($this->qExId,Info::get(), ($expire_timestamp-time())/60);
    }

    private function delDir(){
        Id::set($this->qExId);
        $dir = File::dir();
        if(is_dir($dir)){
            $handler_del = opendir($dir);
            while (($file = readdir($handler_del)) !== false) {
                if ($file != "." && $file != "..") {
                    $try_del = 0;
                    //文件在压缩的时候，系统会给它加上一个随机的前缀，有时候导致这里删除时找不到文件
                    //把它奇怪的后缀去掉
                    $del_file = $dir . "/" . $file;
                    if(!file_exists($del_file)){
                        $del_file = rtrim($dir . "/" . $file,'.'.substr(strrchr($file, '.'), 1));
                    }
                    while (true){
                        if(file_exists($del_file)){
                            //删除文件
                            unlink($del_file);
                            break;
                        }elseif($try_del>5){
                            //'超过重试次数，不删了'
                            break;
                        }else{
                            sleep(1);
                            //'开始重试'
                            $try_del++;
                        }
                    }
                }
            }
            @closedir($dir);
            @rmdir($dir);
        }
    }

    /**
     * 处理失败任务。
     *
     * @param  \Exception  $exception
     * @return void
     */
    public function failed(\Exception $exception)
    {
        Progress::fail($exception);
    }
}
