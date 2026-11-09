<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DockerignoreTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function changelogFiles(): array
    {
        return ['base' => ['CHANGELOG.md'], 'hungarian' => ['CHANGELOG.hu.md']];
    }

    #[DataProvider('changelogFiles')]
    public function test_changelog_files_reach_the_build_context(string $file): void
    {
        $lines = file(dirname(__DIR__, 2).'/.dockerignore', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($lines);

        // Last matching pattern wins; "!" re-includes. Only root-level glob patterns matter here.
        $ignored = false;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $negated = $line[0] === '!';
            $pattern = ltrim($negated ? substr($line, 1) : $line, '/');
            if (fnmatch($pattern, $file)) {
                $ignored = ! $negated;
            }
        }

        $this->assertFalse($ignored, "{$file} is excluded by .dockerignore, so the image would serve an empty changelog.");
    }
}
