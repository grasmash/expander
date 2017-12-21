<?php

namespace Grasmash\Expander;

interface StringifierInterface
{
    /**
     * @param $array
     *
     * @return string
     */
    public static function stringifyArray(array $array);
}
