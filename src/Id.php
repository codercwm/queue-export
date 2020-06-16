<?php

namespace Codercwm\QueueExport;

class Id{

    private function __construct() { }

    private function __clone() { }

    private static $instance = null;

    private $taskId = null;

    //设置任务id
    public static function set(string $taskId){
        /*if(!is_null(self::$instance)){
            $instance = self::$instance;
            if($instance->taskId!=$taskId){
                throw new Exception('不能够更改taskId : '.$instance->taskId.'!='.$taskId);
            }
        }*/
        //每次设置都认为是一个新请求，重新获取本例化
        $instance = new self();
        $instance->taskId = $taskId;
        //设置完成后赋值到属性中
        self::$instance = $instance;
        return $instance->taskId;
    }

    public static function get(){

        //获取之前必须已经有了实例，也就是已经调用过set
        if(!is_null(self::$instance)){
            $instance = self::$instance;
            return $instance->taskId;
        }
        
        return null;
    }

}