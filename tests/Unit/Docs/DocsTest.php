<?php

namespace Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;

class DocsTest extends TestCase
{
    private const LINE = 'Developed and maintained by [IntrAgile Kft.](https://intragile.eu)';

    /**
     * @return list<string> Repository-relative paths of every tracked Markdown file. The in-app user
     *                      manual (resources/manual) is product content with its own guard (manual:check).
     */
    private static function markdownFiles(): array
    {
        $root = dirname(__DIR__, 3);
        $output = [];
        exec('git -C '.escapeshellarg($root).' ls-files -z -- '.escapeshellarg('*.md').' '.escapeshellarg(':(exclude)resources/manual'), $output, $status);
        self::assertSame(0, $status, 'git ls-files failed');

        return array_values(array_filter(explode("\0", implode("\0", $output))));
    }

    private static function read(string $relative): string
    {
        $content = file_get_contents(dirname(__DIR__, 3).'/'.$relative);
        self::assertIsString($content, $relative);

        return $content;
    }

    private static function withoutCodeBlocks(string $markdown): string
    {
        return (string) preg_replace('/^(```|~~~).*?^\1[^\n]*$/ms', '', $markdown);
    }

    public function test_there_are_markdown_files_to_check(): void
    {
        $this->assertContains('README.md', self::markdownFiles());
        $this->assertContains('README.hu.md', self::markdownFiles());
    }

    public function test_every_markdown_file_has_the_header_and_footer_line(): void
    {
        foreach (self::markdownFiles() as $file) {
            $lines = preg_split('/\R/', rtrim(self::read($file))) ?: [];

            $this->assertStringStartsWith('# ', $lines[0], "$file must start with an H1");
            $this->assertSame('', $lines[1] ?? null, "$file: blank line after the H1");
            $this->assertSame(self::LINE, $lines[2] ?? null, "$file: header line under the H1");
            $this->assertSame(self::LINE, end($lines), "$file: footer line");
        }
    }

    public function test_the_readmes_have_the_same_heading_structure(): void
    {
        $this->assertSame(
            self::headingLevels(self::read('README.md')),
            self::headingLevels(self::read('README.hu.md')),
            'headings appear in the same order and at the same levels',
        );
    }

    /**
     * @return list<string> The level markers (##, ###, ...) of every heading below the H1, in order.
     */
    private static function headingLevels(string $markdown): array
    {
        preg_match_all('/^(#{2,6}) \S/m', self::withoutCodeBlocks($markdown), $matches);

        return $matches[1];
    }

    public function test_the_readmes_link_to_each_other(): void
    {
        $this->assertStringContainsString('English | [Magyar](README.hu.md)', self::read('README.md'));
        $this->assertStringContainsString('[English](README.md) | Magyar', self::read('README.hu.md'));
    }

    public function test_every_relative_link_target_exists(): void
    {
        $root = dirname(__DIR__, 3);

        foreach (self::markdownFiles() as $file) {
            preg_match_all('/\]\(([^)\s]+)\)/', self::withoutCodeBlocks(self::read($file)), $matches);

            foreach ($matches[1] as $target) {
                if (preg_match('#^([a-z][a-z0-9+.-]*:|//|\#)#i', $target)) {
                    continue;
                }
                $path = explode('#', $target, 2)[0];
                $resolved = $root.'/'.ltrim(dirname($file) === '.' ? $path : dirname($file).'/'.$path, '/');

                $this->assertFileExists($resolved, "$file links to missing $target");
            }
        }
    }

    public function test_the_trusted_proxies_warning_is_in_both_readmes(): void
    {
        $this->assertStringContainsString('TRUSTED_PROXIES=127.0.0.1', self::read('README.md'));
        $this->assertStringContainsString('TRUSTED_PROXIES=127.0.0.1', self::read('README.hu.md'));
    }
}
