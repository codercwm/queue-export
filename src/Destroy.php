<?php

namespace Codercwm\QueueExport;

use Codercwm\QueueExport\CourseContent\CourseContent;
use Illuminate\Support\Facades\Cache as LaravelCache;
use Codercwm\QueueExport\CourseContent\Data;

class Destroy{
    public static function destroy (){
        $classes = get_declared_classes();
        foreach($classes as $class) {
            if (strstr( $class , 'Codercwm\QueueExport\CourseContent' ) !== false ){
                $reflect = new \ReflectionClass($class);
                if($reflect->implementsInterface(CourseContent::class)){
                    if(method_exists($class,'destroy')){
                        $class::destroy();
                    }
                }
            }
        }
    }
}