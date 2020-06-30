<?php

namespace Codercwm\QueueExport\Jobs;

use Codercwm\QueueExport\CourseContent\Data;
use Codercwm\QueueExport\Destroy;
use Codercwm\QueueExport\Exception;
use Codercwm\QueueExport\File;
use Codercwm\QueueExport\Id;
use Codercwm\QueueExport\Log;
use Codercwm\QueueExport\Progress;
use Codercwm\QueueExport\Services\LogService;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache as LaravelCache;

class ExportQueue implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $qExId,$batchCurrent,$action,$delDir;

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
    public $tries = 3;

    /**
     * Create a new job instance.
     *
     * @return void
     */

    public function __construct($action,$q_ex_id,$batch_current=null,$del_dir=null)
    {
        if(empty($q_ex_id) || !LaravelCache::has($q_ex_id)){
            throw new Exception('$q_ex_id错误');
        }
        if( ('readData'==$action) && (is_null($batch_current)) ){
            throw new Exception('读取数据时batch_current参数是必须的');
        }
        set_time_limit(0);
        $this->action = $action;
        $this->qExId = $q_ex_id;
        $this->batchCurrent = $batch_current;
        $this->delDir = $del_dir;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Id::set($this->qExId);
        $this->{$this->action}();
        $this->after();

    }

    private function readData(){
        try{
            //throw new \Exception('手动失败');
            Data::read($this->batchCurrent);
        }catch (Exception $exception){
            Progress::fail($exception);
        }
    }

    private function delDir(){
        try{
            $dir = $this->delDir;
            if(is_dir($dir)){
                $handler_del = opendir($dir);
                while (($file = readdir($handler_del)) !== false) {
                    if ($file != "." && $file != "..") {
                        $del_file = $dir . "/" . $file;

                        if(is_file($del_file)){
                            //删除文件
                            @unlink($del_file);
                        }
                    }
                }
                @closedir($dir);
                @rmdir($dir);
            }
        }catch (Exception $exception){

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
        $this->after();
    }

    public function after(){
        Destroy::destroy();
    }

}
