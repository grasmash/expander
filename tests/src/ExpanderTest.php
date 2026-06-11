<?php

declare(strict_types=1);

namespace Grasmash\Expander\Tests;

use Dflydev\DotAccessData\Data;
use Grasmash\Expander\Expander;
use Grasmash\Expander\Stringifier;
use Grasmash\Expander\StringifierInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ExpanderTest extends TestCase
{
    /**
     * Environment variable keys set during a test, cleaned up in tearDown().
     *
     * @var string[]
     */
    private array $envVarFixtures = [];

    protected function tearDown(): void
    {
        foreach ($this->envVarFixtures as $key) {
            putenv($key);
            unset($_SERVER[$key]);
        }
        $this->envVarFixtures = [];
        parent::tearDown();
    }

    /**
     * Tests Expander::expandArrayProperties().
     */
    #[DataProvider('providerSourceData')]
    public function testExpandArrayProperties(array $array, array $reference_array): void
    {
        $expander = new Expander();

        $this->setEnvVarFixture('test', 'gomjabbar');

        $expanded = $expander->expandArrayProperties($array);
        $this->assertEquals('gomjabbar', $expanded['env-test']);
        $this->assertEquals('Frank Herbert 1965', $expanded['book']['copyright']);
        $this->assertEquals('Paul Atreides', $expanded['book']['protaganist']);
        $this->assertEquals('Dune by Frank Herbert', $expanded['summary']);
        $this->assertEquals('${book.media.1}, hardcover', $expanded['available-products']);
        $this->assertEquals('Dune', $expanded['product-name']);
        $this->assertEquals('one,two,three', $expanded['expand-array']);
        $this->assertEquals('${not.real.property}', $expanded['publisher']);
        $this->assertEquals('${book.expanded_to_null}', $expanded['test_expanded_to_null']);

        $this->assertTrue($expanded['boolean-value']);
        $this->assertIsBool($expanded['boolean-value']);
        $this->assertTrue($expanded['expand-boolean']);
        $this->assertIsBool($expanded['expand-boolean']);

        $this->assertSame(5, $expanded['expand-int']);
        $this->assertSame(99.99, $expanded['expand-float']);

        $expanded = $expander->expandArrayProperties($array, $reference_array);
        $this->assertEquals('Dune Messiah, and others.', $expanded['sequels']);
        $this->assertEquals('Dune Messiah', $expanded['book']['nested-reference']);
        $this->assertNull($expanded['test_expanded_to_null']);
        // Types must be preserved in reference-data mode too.
        $this->assertTrue($expanded['expand-boolean']);
        $this->assertSame(5, $expanded['expand-int']);
        $this->assertSame(99.99, $expanded['expand-float']);
    }

    /**
     * @return array
     *   An array of values to test.
     */
    public static function providerSourceData(): array
    {
        return [
          [
            [
              'type' => 'book',
              'book' => [
                'title' => 'Dune',
                'author' => 'Frank Herbert',
                'copyright' => '${book.author} 1965',
                'protaganist' => '${characters.0.name}',
                'media' => [
                  0 => 'hardcover',
                ],
                'nested-reference' => '${book.sequel}',
              ],
              'characters' => [
                0 => [
                  'name' => 'Paul Atreides',
                  'occupation' => 'Kwisatz Haderach',
                  'aliases' => [
                    0 => 'Usul',
                    1 => "Muad'Dib",
                    2 => 'The Preacher',
                  ],
                ],
                1 => [
                  'name' => 'Duncan Idaho',
                  'occupation' => 'Swordmaster',
                ],
              ],
              'summary' => '${book.title} by ${book.author}',
              'publisher' => '${not.real.property}',
              'sequels' => '${book.sequel}, and others.',
              'available-products' => '${book.media.1}, ${book.media.0}',
              'product-name' => '${${type}.title}',
              'boolean-value' => true,
              'expand-boolean' => '${boolean-value}',
              'int-value' => 5,
              'expand-int' => '${int-value}',
              'float-value' => 99.99,
              'expand-float' => '${float-value}',
              'null-value' => null,
              'inline-array' => [
                0 => 'one',
                1 => 'two',
                2 => 'three',
              ],
              'expand-array' => '${inline-array}',
              'env-test' => '${env.test}',
              'test_expanded_to_null' => '${book.expanded_to_null}'
            ],
            [
              'book' => [
                'sequel' => 'Dune Messiah',
                'expanded_to_null' => null,
              ]
            ]
          ],
        ];
    }

    /**
     * Tests Expander::expandProperty().
     */
    #[DataProvider('providerTestExpandProperty')]
    public function testExpandProperty(array $array, string $property_name, string $unexpanded_string, mixed $expected): void
    {
        $data = new Data($array);
        $expander = new Expander();
        $expanded_value = $expander->expandProperty($property_name, $unexpanded_string, $data);

        $this->assertEquals($expected, $expanded_value);
    }

    /**
     * @return array
     */
    public static function providerTestExpandProperty(): array
    {
        return [
            [ ['author' => 'Frank Herbert'], 'author', '${author}', 'Frank Herbert' ],
            [ ['book' =>  ['author' => 'Frank Herbert' ]], 'book.author', '${book.author}', 'Frank Herbert' ],
        ];
    }

    /**
     * Tests the getenv() fallback when the variable is absent from $_SERVER.
     */
    public function testExpandEnvPropertyGetenvFallback(): void
    {
        putenv('getenv_only=fallback_value');
        $this->envVarFixtures[] = 'getenv_only';
        $this->assertArrayNotHasKey('getenv_only', $_SERVER);

        $expander = new Expander();
        $expanded = $expander->expandArrayProperties(['env-fallback' => '${env.getenv_only}']);
        $this->assertEquals('fallback_value', $expanded['env-fallback']);
    }

    /**
     * Tests that falsy (but set) environment variables like "0" expand.
     */
    public function testExpandEnvPropertyFalsyValue(): void
    {
        putenv('falsy_env=0');
        $this->envVarFixtures[] = 'falsy_env';
        $this->assertArrayNotHasKey('falsy_env', $_SERVER);

        $expander = new Expander();
        $expanded = $expander->expandArrayProperties(['timeout' => '${env.falsy_env}']);
        $this->assertEquals('0', $expanded['timeout']);
    }

    /**
     * Tests that HTTP_* keys in $_SERVER (client-controlled request headers)
     * are never used for ${env.*} expansion.
     */
    public function testExpandEnvPropertyIgnoresHttpHeaders(): void
    {
        $_SERVER['HTTP_X_INJECTED'] = 'attacker-value';
        $this->envVarFixtures[] = 'HTTP_X_INJECTED';

        $expander = new Expander();
        $expanded = $expander->expandArrayProperties(['header' => '${env.HTTP_X_INJECTED}']);
        $this->assertEquals('${env.HTTP_X_INJECTED}', $expanded['header']);
    }

    /**
     * Tests that circular references terminate rather than looping forever.
     */
    public function testCircularReferencesTerminate(): void
    {
        $expander = new Expander();

        $expanded = $expander->expandArrayProperties(['a' => '${a}']);
        $this->assertEquals('${a}', $expanded['a']);

        $expanded = $expander->expandArrayProperties(['a' => '${b}', 'b' => '${a}']);
        $this->assertEquals('${a}', $expanded['a']);
        $this->assertEquals('${a}', $expanded['b']);

        $expanded = $expander->expandArrayProperties(['a' => '${b}', 'b' => '${c}', 'c' => '${a}']);
        $this->assertEquals('${a}', $expanded['a']);
        $this->assertEquals('${a}', $expanded['b']);
        $this->assertEquals('${a}', $expanded['c']);
    }

    /**
     * Tests that mutually recursive placeholders with surrounding text do not
     * grow unboundedly (previously caused memory exhaustion).
     */
    public function testCircularReferencesWithTextTerminate(): void
    {
        $expander = new Expander();
        $expanded = $expander->expandArrayProperties(['a' => 'x${b}', 'b' => 'y${a}']);
        $this->assertIsString($expanded['a']);
        $this->assertIsString($expanded['b']);
    }

    /**
     * Tests that a PCRE failure during replacement aborts expansion and
     * preserves the original value.
     */
    public function testPcreErrorAbortsExpansion(): void
    {
        $expander = new class extends Expander {
            protected function replacePlaceholders(string $pattern, callable $callback, string $subject): ?string
            {
                return null;
            }
        };
        $logger = $this->createMock(LoggerInterface::class);
        $expander->setLogger($logger);
        $logger->expects($this->once())
            ->method('debug')
            ->with($this->stringContains('Aborting expansion of a'));

        $expanded = $expander->expandArrayProperties(['a' => 'text ${b} more', 'b' => 'val']);
        $this->assertSame('text ${b} more', $expanded['a']);
    }

    /**
     * Tests logger and stringifier accessors and their use during expansion.
     */
    public function testSettersAndGetters(): void
    {
        $expander = new Expander();
        $logger = $this->createMock(LoggerInterface::class);
        $stringifier = $this->createMock(StringifierInterface::class);

        $expander->setLogger($logger);
        $expander->setStringifier($stringifier);
        $this->assertSame($logger, $expander->getLogger());
        $this->assertSame($stringifier, $expander->getStringifier());

        $logger->expects($this->atLeastOnce())
            ->method('debug')
            ->with($this->stringContains('not.real.property'));
        $stringifier->expects($this->once())
            ->method('stringifyArray')
            ->with(['one', 'two'])
            ->willReturn('one, two');

        $expanded = $expander->expandArrayProperties([
            'missing' => '${not.real.property}',
            'list' => ['one', 'two'],
            'joined' => '${list}',
        ]);
        $this->assertSame('one, two', $expanded['joined']);
    }

    /**
     * Sets an environment variable fixture, registered for tearDown cleanup.
     */
    protected function setEnvVarFixture(string $key, string $value): void
    {
        putenv("$key=$value");
        $_SERVER[$key] = $value;
        $this->envVarFixtures[] = $key;
    }
}
