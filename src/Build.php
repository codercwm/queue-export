<?php

namespace Codercwm\QueueExport;
use Codercwm\QueueExport\CourseContent\Info;

class Build{

    private $query = null;

    public function query(){
        if(is_null($this->query)){
            $model = Info::get('model')??null;
            $model_method = Info::get('model_method')??null;

            if($model && $model_method && method_exists(new $model,$model_method)){
                $this->query = $model::$model_method(Info::get('model_params')??[]);
            }else{
                $this->query = $model::query();
            }
        }

        return $this->query;
    }

    public function count(){
        $count_method = Info::get('count_method')??null;

        if($count_method && method_exists($this->query(),$count_method)){
            $count = $this->query()->{$count_method}();
        }else{
            if(isset($this->query()->count)){
                $count = $this->query()->count;
            }else{
                $count = $this->query()->paginate()->total();
            }
            if($count<1){
                throw new Exception('数据列表为空',true);
            }
        }

        return $count;
    }
}