<?php

declare(strict_types=1);

namespace Grasmash\Expander;

/**
 * Converts arrays to strings during property expansion.
 */
class Stringifier implements StringifierInterface
{
    /**
     * Converts an array to a comma-delimited string.
     *
     * @param array $array
     *   The array to convert.
     *
     * @return string
     *   The resultant string.
     */
    public function stringifyArray(array $array): string
    {
        return implode(',', $array);
    }
}
