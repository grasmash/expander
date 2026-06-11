<?php

declare(strict_types=1);

namespace Grasmash\Expander;

use Dflydev\DotAccessData\Data;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Expands property placeholders in arrays.
 */
class Expander implements LoggerAwareInterface
{
    /**
     * Maximum passes over a single string value during expansion. Guards
     * against unbounded growth from mutually recursive placeholders, e.g.
     * ['a' => 'x${b}', 'b' => 'y${a}'].
     */
    protected const MAX_ITERATIONS = 25;

    /**
     * Maximum length of an expanded string value. Circular references with
     * surrounding text double the value length on every pass; this caps that
     * growth before it exhausts memory.
     */
    protected const MAX_LENGTH = 1048576;

    protected StringifierInterface $stringifier;
    protected LoggerInterface $logger;

    public function __construct()
    {
        $this->setLogger(new NullLogger());
        $this->setStringifier(new Stringifier());
    }

    public function getStringifier(): StringifierInterface
    {
        return $this->stringifier;
    }

    public function setStringifier(StringifierInterface $stringifier): void
    {
        $this->stringifier = $stringifier;
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Expands property placeholders in an array.
     *
     * Placeholders should be formatted as ${parent.child}.
     *
     * @param array $array
     *   An array containing properties to expand.
     * @param array $reference_array
     *   An optional array of supplemental values used for property expansion.
     *
     * @return array
     *   The modified array in which placeholders have been replaced with
     *   values.
     */
    public function expandArrayProperties(array $array, array $reference_array = []): array
    {
        $data = new Data($array);
        if ($reference_array) {
            $reference_data = new Data($reference_array);
            $this->doExpandArrayProperties($data, $array, '', $reference_data);
        } else {
            $this->doExpandArrayProperties($data, $array);
        }

        return $data->export();
    }

    /**
     * Performs the actual property expansion.
     *
     * @param Data $data
     *   A data object, containing the $array.
     * @param array $array
     *   The original, unmodified array.
     * @param string $parent_keys
     *   The parent keys of the current key in dot notation. This is used to
     *   track the absolute path to the current key in recursive cases.
     * @param Data|null $reference_data
     *   A reference data object. This is not operated upon but is used as a
     *   reference to provide supplemental values for property expansion.
     */
    protected function doExpandArrayProperties(
        Data   $data,
        array  $array,
        string $parent_keys = '',
        ?Data $reference_data = null
    ): void {
        foreach ($array as $key => $value) {
            // Boundary condition(s).
            if ($value === null || is_bool($value)) {
                continue;
            }
            // Recursive case.
            if (is_array($value)) {
                $this->doExpandArrayProperties($data, $value, $parent_keys . "$key.", $reference_data);
            } else {
                // Base case.
                $this->expandStringProperties($data, $parent_keys, $reference_data, (string) $value, (string) $key);
            }
        }
    }

    /**
     * Expand a single property.
     *
     * @param Data $data
     *   A data object, containing the $array.
     * @param string $parent_keys
     *   The parent keys of the current key in dot notation. This is used to
     *   track the absolute path to the current key in recursive cases.
     * @param Data|null $reference_data
     *   A reference data object. This is not operated upon but is used as a
     *   reference to provide supplemental values for property expansion.
     * @param string $value
     *   The unexpanded property value.
     * @param string $key
     *   The immediate key of the property.
     */
    protected function expandStringProperties(
        Data   $data,
        string $parent_keys,
        ?Data  $reference_data,
        string $value,
        string $key
    ): mixed {
        $pattern = '/\$\{([^\$}]+)\}/';
        $iterations = 0;
        // We loop through all placeholders in a given string.
        // E.g., '${placeholder1} ${placeholder2}' requires two replacements.
        while (is_string($value) && str_contains($value, '${')) {
            if (++$iterations > static::MAX_ITERATIONS || strlen($value) > static::MAX_LENGTH) {
                $this->log("Aborting expansion of $key. Value may contain circular references.");
                break;
            }
            $original_value = $value;
            // If the value is a _single_ property reference, expand it
            // directly so that the replacement's data type is preserved.
            if (preg_match($pattern, $value, $matches) && $matches[0] === $value) {
                $value = $this->expandStringPropertiesCallback($matches, $data, $reference_data);
            } else {
                $value = $this->replacePlaceholders(
                    $pattern,
                    function ($matches) use ($data, $reference_data) {
                        return (string) $this->expandStringPropertiesCallback($matches, $data, $reference_data);
                    },
                    $value
                );
                // A null value indicates a PCRE error.
                if ($value === null) {
                    $this->log("Aborting expansion of $key: " . preg_last_error_msg());
                    return $original_value;
                }
            }

            // If no replacement occurred at all, break to prevent
            // infinite loop.
            if ($original_value === $value) {
                break;
            }

            // Set value on $data object.
            if ($parent_keys) {
                $full_key = $parent_keys . "$key";
            } else {
                $full_key = $key;
            }
            $data->set($full_key, $value);
        }
        return $value;
    }

    /**
     * Replaces all placeholders in a string.
     *
     * Returns null on a PCRE error, per preg_replace_callback().
     */
    protected function replacePlaceholders(string $pattern, callable $callback, string $subject): ?string
    {
        return preg_replace_callback($pattern, $callback, $subject);
    }

    /**
     * Expansion callback used by preg_replace_callback() in expandProperty().
     *
     * @param array $matches
     *   An array of matches created by preg_replace_callback().
     * @param Data $data
     *   A data object containing the complete array being operated upon.
     * @param Data|null $reference_data
     *   A reference data object. This is not operated upon but is used as a
     *   reference to provide supplemental values for property expansion.
     */
    public function expandStringPropertiesCallback(
        array $matches,
        Data  $data,
        ?Data $reference_data = null
    ): mixed {
        $property_name = $matches[1];
        $unexpanded_value = $matches[0];

        // Use only values within the subject array's data.
        if (!$reference_data) {
            return $this->expandProperty($property_name, $unexpanded_value, $data);
        } else {
            // Search both the subject array's data and the reference data for a value.
            return $this->expandPropertyWithReferenceData(
                $property_name,
                $unexpanded_value,
                $data,
                $reference_data
            );
        }
    }

    /**
     * Searches both the subject data and the reference data for value.
     *
     * @param string $property_name
     *   The name of the value for which to search.
     * @param string $unexpanded_value
     *   The original, unexpanded value, containing the placeholder.
     * @param Data $data
     *   A data object containing the complete array being operated upon.
     * @param Data|null $reference_data
     *   A reference data object. This is not operated upon but is used as a
     *   reference to provide supplemental values for property expansion.
     */
    public function expandPropertyWithReferenceData(
        string $property_name,
        string $unexpanded_value,
        Data   $data,
        ?Data $reference_data
    ): mixed {
        $expanded_value = $this->expandProperty(
            $property_name,
            $unexpanded_value,
            $data
        );
        // If the string was not changed using the subject data, try using
        // the reference data.
        if ($expanded_value === $unexpanded_value && $reference_data !== null) {
            $expanded_value = $this->expandProperty(
                $property_name,
                $unexpanded_value,
                $reference_data
            );
        }

        return $expanded_value;
    }

    /**
     * Searches a data object for a value.
     *
     * @param string $property_name
     *   The name of the value for which to search.
     * @param string $unexpanded_value
     *   The original, unexpanded value, containing the placeholder.
     * @param Data $data
     *   A data object containing possible replacement values.
     */
    public function expandProperty(string $property_name, string $unexpanded_value, Data $data): mixed
    {
        if (str_starts_with($property_name, "env.") &&
          !$data->has($property_name)) {
            $env_key = substr($property_name, 4);
            // Skip HTTP_* keys in $_SERVER: in a web context these come from
            // client-supplied request headers, not the environment.
            if (isset($_SERVER[$env_key]) && !str_starts_with($env_key, 'HTTP_')) {
                $data->set($property_name, $_SERVER[$env_key]);
            } elseif (($env_value = getenv($env_key)) !== false) {
                $data->set($property_name, $env_value);
            }
        }

        if (!$data->has($property_name)) {
            $this->log("Property \${'$property_name'} could not be expanded.");
            return $unexpanded_value;
        } else {
            $expanded_value = $data->get($property_name);
            if (is_array($expanded_value)) {
                return $this->getStringifier()->stringifyArray($expanded_value);
            }
            $this->log("Expanding property \${'$property_name'} => " . var_export($expanded_value, true) . ".");
            return $expanded_value;
        }
    }

    /**
     * Logs a message using the logger.
     *
     * @param string $message
     *   The message to log.
     */
    public function log(string $message): void
    {
        $this->getLogger()->debug($message);
    }
}
