<?php

namespace Codercwm\QueueExport\Jobs;

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
    const QUEUE_NAME = 'CodercwmExportQueue';

    private $qExId,$batchCurrent,$queueExport;

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

    public function __construct($q_ex_id,$batch_current)
    {
        set_time_limit(0);
        $this->qExId = $q_ex_id;
        $this->batchCurrent = $batch_current;
        $this->queueExport = new QueueExport();
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try{
//            throw new \Exception('手动失败');
            $this->queueExport->setTaskId($this->qExId);
            $this->queueExport->read($this->batchCurrent);
        }catch (\Exception $exception){
            $this->queueExport->fail($exception);
        }

        $expire_timestamp = $this->queueExport->get('expire_timestamp');
        Cache::put($this->qExId,$this->queueExport->get(), ($expire_timestamp-time())/60);
    }

    /**
     * 处理失败任务。
     *
     * @param  \Exception  $exception
     * @return void
     */
    public function failed(\Exception $exception)
    {
        $this->queueExport->fail($exception);
    }
}
