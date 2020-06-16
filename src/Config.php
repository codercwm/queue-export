<?php

namespace Codercwm\QueueExport;

use Illuminate\Support\Facades\Config as LaravelConfig;

class Config{

    private function __construct() { }

    private function __clone() { }

    private static $instance = null;

    private $config = [];

    //这个也私有化，为了::get()::set()用法
    private static function getInstance(){
        if(is_null(self::$instance)){
            self::$instance = new self();
        }
        return self::$instance;
    }

    //获取信息
    public static function get($key=null,$refresh=false){
        $instance = self::getInstance();
        if(is_null($key)){
            if(empty($instance->config) || $refresh){
                $instance->config = Info::get('config')?? LaravelConfig::get('queue_export');
            }
            return $instance->config;
        }else{
            if(!isset($instance->config[$key]) || $refresh){
                $instance->config = array_merge($instance->config,Info::get('config')?? LaravelConfig::get('queue_export'));
            }
            return $instance->config[$key]??null;
        }
    }

    //更改信息
    public static function set($key,$value){
        $instance = self::getInstance();
        $instance->config[$key] = $value;
    }
}