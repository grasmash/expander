<?php

namespace Grasmash\Expander;

use function implode;

class Stringifier implements StringifierInterface
{
    /**
     * @param $array
     *
     * @return string
     */
    public static function stringifyArray(array $array)
    {
        return implode(',', $array);
    }
}
