<?php

namespace Codercwm\QueueExport\Concerns;

use Illuminate\Support\Collection;

interface Query
{
    public function builder();

    public static function filter($params = []);

    public function count():int;

    public function skip($num=0):Query;

    public function take($num=0):Query;

    public function get():Collection;
}