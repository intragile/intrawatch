<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DockerignoreTest extends TestCase
{
    /**
     * Files the image reads at runtime.
     *
     * @return array<string, array{string}>
     */
    public static function runtimeFiles(): array
    {
        return [
            'changelog' => ['CHANGELOG.md'],
            'hungarian changelog' => ['CHANGELOG.hu.md'],
            'manual contents' => ['resources/manual/_meta.php'],
            'hungarian manual chapter' => ['resources/manual/hu/start/getting-started.md'],
            'english manual chapter' => ['resources/manual/en/start/getting-started.md'],
            'english manual sections' => ['resources/manual/en/_sections.php'],
        ];
    }

    #[DataProvider('runtimeFiles')]
    public function test_runtime_files_reach_the_build_context(string $file): void
    {
        $this->assertFileExists(dirname(__DIR__, 2).'/'.$file);
        $this->assertFalse($this->ignored($file), "{$file} is excluded by .dockerignore, so the image would not ship it.");
    }

    /**
     * Paths whose fate was checked with a real build (a `FROM scratch` + `COPY . /` image exported with
     * `docker buildx build --output type=local`), so the model below stays honest about Docker's rules:
     * `*` stops at "/", a root pattern such as `*.md` does not reach into sub-directories, and an
     * excluded directory excludes everything in it.
     *
     * @return array<string, array{string, bool}>
     */
    public static function dockerVerdicts(): array
    {
        return [
            'root markdown' => ['NOTES.md', true],
            'readme' => ['README.md', false],
            'nested markdown' => ['spa/src/foo.md', false],
            'excluded directory' => ['docs/x.md', true],
            'committed spec' => ['docs/openapi.json', false],
            'vendor' => ['vendor/autoload.php', true],
            'nested env file' => ['spa/.env', true],
        ];
    }

    #[DataProvider('dockerVerdicts')]
    public function test_the_model_follows_docker(string $file, bool $ignored): void
    {
        $this->assertSame($ignored, $this->ignored($file));
    }

    /**
     * Whether Docker leaves the path out of the build context: the last matching pattern wins, "!"
     * re-includes, and a pattern also matches every path below a directory it matches. Patterns are
     * Go filepath.Match globs relative to the context root, plus "**" for any number of directories.
     */
    private function ignored(string $file): bool
    {
        $lines = file(dirname(__DIR__, 2).'/.dockerignore', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($lines);

        $parts = explode('/', $file);
        $candidates = [];
        for ($i = 1; $i <= count($parts); $i++) {
            $candidates[] = implode('/', array_slice($parts, 0, $i));
        }

        $ignored = false;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $negated = $line[0] === '!';
            $regex = $this->toRegex(trim($negated ? substr($line, 1) : $line, '/'));
            foreach ($candidates as $candidate) {
                if (preg_match($regex, $candidate) === 1) {
                    $ignored = ! $negated;
                    break;
                }
            }
        }

        return $ignored;
    }

    private function toRegex(string $pattern): string
    {
        $regex = '';
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];
            if ($char === '*' && ($pattern[$i + 1] ?? '') === '*') {
                $i++;
                if (($pattern[$i + 1] ?? '') === '/') {
                    $i++;
                    $regex .= '(?:.*/)?';
                } else {
                    $regex .= '.*';
                }
            } elseif ($char === '*') {
                $regex .= '[^/]*';
            } elseif ($char === '?') {
                $regex .= '[^/]';
            } elseif ($char === '\\' && $i + 1 < $length) {
                $regex .= preg_quote($pattern[++$i], '#');
            } else {
                $regex .= preg_quote($char, '#');
            }
        }

        return '#^'.$regex.'$#';
    }
}
