<?php

namespace Codercwm\QueueExport;

class Build{

    public $query = null;
    
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
        if(isset($this->query->count)){
            $count = $this->query->count;
        }else{
            $count = $this->query->paginate()->total();
        }
        return $count;
    }
}