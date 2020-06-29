<?php

namespace Codercwm\QueueExport;
use Codercwm\QueueExport\CourseContent\Info;

class FieldValue{
    public static function get($item){
        $fields = Info::get('fields');
        $data = [];
        foreach ($fields as $field){
            if(''===$field){
                $value = '';
            }else{
                //'status|1:未支付;2:已支付;3:退费中;4:已退费;5:已关闭'
                $strs = explode('|',$field);
                $field = array_shift($strs);
                $dict_arr = [];
                $tabs = false;//是否添加制表符
                foreach($strs as $str_item){
                    $str_item = explode(';',$str_item);
                    foreach($str_item as $str){
                        if(strpos($str,':')){
                            $dict = explode(':',$str);
                            if(isset($dict[0],$dict[1])){
                                $dict_arr[$dict[0]] = $dict[1];
                            }
                        }elseif('tabs'==$str){
                            $tabs = true;
                        }
                    }
                }

                $value = $field;
                if('`'==substr($value,0,1)&&'`'==substr($value,-1,1)){
                    //如果有``包围的就直接取值
                    $value = trim($value,'`');
                }else{
                    //看有没有函数，有就调用函数
                    $func_name = substr($value,0,(strpos($value,'(')));//func();
                    if(strpos($value,'(')&&strpos($value,')')&&function_exists($func_name)){
                        $sub_start = strpos($value,'(');
                        $sub_end = strripos($value,')');
                        $func_param_str = substr($value,$sub_start+1,$sub_end-$sub_start-1);
                        $func_param_arr = explode(',',$func_param_str);
                        $func_param_arr = array_map(function($va){return str_replace(['"',"'",],'',$va);},$func_param_arr);
                        $func_param_arr = array_map('trim',$func_param_arr);

                        $dot_value_arr = [];
                        foreach ($func_param_arr as $func_param){
                            $dot_value_arr[] = self::getDotValue($item,$func_param);
                        }
                        $value = call_user_func_array($func_name,$dot_value_arr);
                    }else{
                        $value = self::getDotValue($item,$value);
                    }
                    //如果有字典
                    if(!empty($dict_arr)&&isset($dict_arr[$value])){
                        $value = self::getDotValue($item,$dict_arr[$value]);//$dict_arr[$value];
                    }
                    //如果单元格的值是数组就转成json
                    if(is_array($value)){
                        $value = Tool::enJson($value);
                    }
                    //如果添加制表符，就是转换成字符串，不用科学计数法显示
                    if($tabs || is_object($value)){
                        $value = "\t".$value."\t";
                    }
                }
                if(trim($value,"\t")==$field){
                    $value = '';
                }
            }

            $data[] = $value;
        }
        return $data;
    }

    //获取用点分隔的多维数组的值
    private static function getDotValue($item,$field){
        $arr = explode('??',$field);
        $field = $arr[0];
        $default = $arr[1]??$field;
        if('`'==substr($field,0,1)&&'`'==substr($field,-1,1)){
            //如果有``包围的就直接返回
            $value = trim($field,'`');
            return $value;
        }
        $field = explode('.',$field);
        $value = $item;
        foreach ($field as $f){
            if(!isset($value[$f])){
                $value = $default;
                break;
            }
            $value = $value[$f];
        }
        return $value;
    }
}