<?php

namespace Codercwm\QueueExport;

use Exception as RootException;
use Illuminate\Support\Facades\Cache as LaravelCache;

class Exception extends RootException{

    public function __construct($message = "", $del = false)
    {
        if($del){
            //一旦报错就从缓存清除这个任务
            $this->del();
        }

        parent::__construct($message);
    }

    public function del(){
        LaravelCache::forget(Id::get());
        LaravelCache::forget(Id::get().'_download_url');
        LaravelCache::forget(Id::get().'_local_path');
        LaravelCache::forget(Id::get().'_show_name');
        LaravelCache::forget(Id::get().'_is_fail');
        LaravelCache::forget(Id::get().'_is_cancel');
        LaravelCache::forget(Id::get().'_complete');
        LaravelCache::forget(Id::get().'_progress_read');
        LaravelCache::forget(Id::get().'_progress_write');
        LaravelCache::forget(File::path(true));
    }
}