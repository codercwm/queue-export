<?php
namespace Codercwm\QueueExport\CourseContent;

interface CourseContent{
    public static function get($key,$refresh);
    public static function set($key,$value);
    public static function destroy();
}