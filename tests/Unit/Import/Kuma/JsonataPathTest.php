<?php

declare(strict_types=1);

namespace Tests\Unit\Import\Kuma;

use App\Import\Kuma\Mapping\JsonataPath;
use App\Monitoring\Types\JsonPath;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class JsonataPathTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function translatable(): array
    {
        return [
            'nested with index' => ['data.items[0].name', '$.data.items[0].name'],
            'single field' => ['status', '$.status'],
            'context prefix' => ['$.slideshow.author', '$.slideshow.author'],
            'root prefix' => ['$$.a.b', '$.a.b'],
            'quoted key' => ['data.`content-type`', "$.data['content-type']"],
            'quoted key with quote' => ["`it's`.x", "$['it\\'s'].x"],
            'nested indexes' => ['m[1][2]', '$.m[1][2]'],
            'surrounding blanks' => ['  a.b  ', '$.a.b'],
        ];
    }

    #[DataProvider('translatable')]
    public function test_plain_paths_translate(string $expr, string $expected): void
    {
        $path = JsonataPath::toJsonPath($expr);

        $this->assertSame($expected, $path);
        $this->assertTrue(JsonPath::isValid($expected));
    }

    public function test_translated_quoted_keys_select_the_same_value(): void
    {
        $path = JsonataPath::toJsonPath('data.`content-type`[1]');
        $this->assertNotNull($path);

        $this->assertSame(['b'], JsonPath::query(['data' => ['content-type' => ['a', 'b']]], $path));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function untranslatable(): array
    {
        return [
            'function' => ['$sum(x)'],
            'filter' => ['items[price > 5].name'],
            'wildcard' => ['items.*'],
            'descendant' => ['**.name'],
            'arithmetic' => ['a + b'],
            'comparison' => ['status = "ok"'],
            'negative index' => ['items[-1]'],
            'variable' => ['$foo.bar'],
            'empty' => [''],
            'trailing dot' => ['a.'],
            'leading digit' => ['1a.b'],
            'unterminated quote' => ['a.`b'],
            'string literal' => ['"x"'],
            'mapping' => ['items.(name)'],
        ];
    }

    #[DataProvider('untranslatable')]
    public function test_expressions_are_not_translated(string $expr): void
    {
        $this->assertNull(JsonataPath::toJsonPath($expr));
    }
}
