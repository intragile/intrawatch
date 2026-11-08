<?php

declare(strict_types=1);

namespace Tests\Feature\Import\Kuma;

use App\Import\Kuma\Source\JsonBackupSource;
use App\Import\Kuma\Source\SourceFactory;
use App\Import\Kuma\Source\UnreadableSource;
use Tests\TestCase;

/**
 * A JSON backup is decoded completely in memory, so its size and the number of objects and lists in it are
 * limited before json_decode() runs.
 */
final class JsonBackupLimitsTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function file(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'iw-kuma-limit-');
        $this->assertNotFalse($path);
        file_put_contents($path, $contents);

        return $this->files[] = $path;
    }

    private function rejection(string $path): string
    {
        try {
            SourceFactory::open($path)->close();
        } catch (UnreadableSource $e) {
            return $e->getMessage();
        }
        $this->fail('The source was accepted.');
    }

    public function test_backup_larger_than_the_size_limit_is_rejected_before_decoding(): void
    {
        config()->set('intrawatch.import.max_json_mb', 1);
        // Valid start, broken end: a full decode would report invalid JSON instead of the size.
        $path = $this->file('{"monitorList":[], "padding":"'.str_repeat('x', 1024 * 1024).'"'.' BROKEN');

        $message = $this->rejection($path);

        $this->assertStringContainsString('larger than the limit of 1 MB', $message);
        $this->assertStringContainsString('IMPORT_MAX_JSON_MB', $message);
        $this->assertStringNotContainsString('not valid JSON', $message);
    }

    public function test_backup_just_under_the_size_limit_is_read(): void
    {
        config()->set('intrawatch.import.max_json_mb', 1);
        $path = $this->file('{"monitorList":[], "padding":"'.str_repeat('x', 1024 * 1024 - 64).'"}');

        $source = SourceFactory::open($path);
        $this->assertSame([], $source->monitors());
    }

    public function test_monitor_list_with_more_items_than_the_limit_is_rejected(): void
    {
        config()->set('intrawatch.import.max_json_items', 10);
        $path = $this->file('{"monitorList":['.implode(',', array_fill(0, 11, '{}')).']}');

        $message = $this->rejection($path);

        $this->assertStringContainsString('more than 10 objects or lists', $message);
        $this->assertStringContainsString('IMPORT_MAX_JSON_ITEMS', $message);
    }

    public function test_small_file_with_many_nested_containers_is_rejected_before_decoding(): void
    {
        config()->set('intrawatch.import.max_json_items', 100);
        // About 10 KB of {"a":[]} entries: tiny on disk, but each one costs memory when decoded. The broken end
        // shows that the decoder never ran.
        $path = $this->file('{"monitorList":[], "x":['.implode(',', array_fill(0, 1200, '{"a":[]}')).'] BROKEN');

        $this->assertStringContainsString('more than 100 objects or lists', $this->rejection($path));
    }

    public function test_object_with_more_values_than_twenty_per_item_is_rejected_before_decoding(): void
    {
        config()->set('intrawatch.import.max_json_items', 10);
        // 3 containers but 201 values (200 commas): over 10 × 20. The broken end shows the decoder never ran.
        $keys = [];
        for ($i = 0; $i <= 200; $i++) {
            $keys[] = '"k'.$i.'":0';
        }
        $path = $this->file('{"monitorList":[], "x":{'.implode(',', $keys).'} BROKEN');

        $message = $this->rejection($path);

        $this->assertStringContainsString('more than 200 values', $message);
        $this->assertStringContainsString('IMPORT_MAX_JSON_ITEMS', $message);
    }

    public function test_brackets_inside_strings_are_not_counted(): void
    {
        config()->set('intrawatch.import.max_json_items', 5);
        $name = str_repeat('{[,', 50).'\\"{[,\\\\';
        $path = $this->file((string) json_encode(['monitorList' => [['id' => 1, 'name' => $name, 'type' => 'http']]]));

        $source = SourceFactory::open($path);
        $this->assertSame($name, $source->monitors()[0]['name']);
    }

    public function test_fixture_is_read_with_the_default_limits(): void
    {
        config()->set('intrawatch.import.max_json_mb', 20);
        config()->set('intrawatch.import.max_json_items', 50000);

        $source = JsonBackupSource::fromFile(ImporterTest::fixture('kuma1-backup.json'));

        $this->assertCount(8, $source->monitors());
    }

    public function test_defaults_apply_when_the_keys_are_not_configured(): void
    {
        config()->set('intrawatch.import', []);

        $this->assertCount(8, JsonBackupSource::fromFile(ImporterTest::fixture('kuma1-backup.json'))->monitors());

        // 50 001 empty objects exceed the default item limit.
        $path = $this->file('{"monitorList":['.implode(',', array_fill(0, 50001, '{}')).']}');
        $this->assertStringContainsString('more than 50000 objects or lists', $this->rejection($path));
    }
}
