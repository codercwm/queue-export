<?php

namespace Codercwm\QueueExport\Export;

use Codercwm\QueueExport\Cache;
use Codercwm\QueueExport\Id;
use Codercwm\QueueExport\Jobs\ExportQueue;
use Codercwm\QueueExport\Config;
use Codercwm\QueueExport\Info;

class Queue{
    public function creation(){
        for ($batch_current=1;$batch_current<=Info::get('batch_count');$batch_current++) {
            ExportQueue::dispatch(Id::get(),$batch_current)->onQueue(Config::get('queue_name'));
        }
    }

    public function download(){
        return Cache::get('local_path');
    }
}