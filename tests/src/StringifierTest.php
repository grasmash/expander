<?php

declare(strict_types=1);

namespace Grasmash\Expander\Tests;

use Grasmash\Expander\Stringifier;
use Grasmash\Expander\StringifierInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StringifierTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(StringifierInterface::class, new Stringifier());
    }

    #[DataProvider('providerStringifyArray')]
    public function testStringifyArray(array $array, string $expected): void
    {
        $this->assertSame($expected, (new Stringifier())->stringifyArray($array));
    }

    /**
     * @return array
     */
    public static function providerStringifyArray(): array
    {
        return [
            'empty array' => [[], ''],
            'single element' => [['one'], 'one'],
            'multiple elements' => [['one', 'two', 'three'], 'one,two,three'],
            'non-sequential keys' => [[5 => 'a', 9 => 'b'], 'a,b'],
            'mixed scalar types' => [[1, true, null, 'x'], '1,1,,x'],
        ];
    }
}
