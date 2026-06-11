<?php

declare(strict_types=1);

namespace Grasmash\Expander;

interface StringifierInterface
{
    /**
     * Converts an array to a string.
     *
     * @param array $array
     *   The array to convert.
     *
     * @return string
     *   The resultant string.
     */
    public function stringifyArray(array $array): string;
}
