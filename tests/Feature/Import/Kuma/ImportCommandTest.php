<?php

declare(strict_types=1);

namespace Tests\Feature\Import\Kuma;

use App\Models\AuditEvent;
use App\Models\Tag;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ImportCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_prints_counts_and_writes_report(): void
    {
        $ws = Workspace::factory()->create();
        $path = tempnam(sys_get_temp_dir(), 'import-report-');
        $this->assertNotFalse($path);

        try {
            $code = Artisan::call('intrawatch:import:kuma', [
                'source' => ImporterTest::fixture('kuma2.db'),
                '--workspace' => $ws->slug,
                '--report' => $path,
            ]);
            $output = Artisan::output();
            $this->assertSame(0, $code);
            $this->assertStringContainsString('tag', $output);
            $this->assertStringContainsString('Not migrated', $output);

            $report = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(['created' => 1], data_get($report, 'counts.tag'));
            $this->assertSame('2.x', data_get($report, 'source.version'));
            $this->assertSame(1, Tag::where('workspace_id', $ws->id)->count());

            $audit = AuditEvent::where('action', 'import.completed')->sole();
            $this->assertSame($ws->id, $audit->workspace_id);
        } finally {
            @unlink($path);
        }
    }

    public function test_dry_run_writes_no_audit_row(): void
    {
        $ws = Workspace::factory()->create();

        $code = Artisan::call('intrawatch:import:kuma', [
            'source' => ImporterTest::fixture('kuma2.db'),
            '--workspace' => $ws->slug,
            '--dry-run' => true,
        ]);

        $this->assertSame(0, $code);

        $this->assertSame(0, AuditEvent::where('action', 'import.completed')->count());
        $this->assertSame(0, Tag::count());
    }

    public function test_command_rejects_unknown_workspace_and_garbage_file(): void
    {
        $ws = Workspace::factory()->create();
        $garbage = tempnam(sys_get_temp_dir(), 'import-garbage-');
        $this->assertNotFalse($garbage);
        file_put_contents($garbage, 'this is not a kuma database');

        try {
            $code = Artisan::call('intrawatch:import:kuma', [
                'source' => ImporterTest::fixture('kuma2.db'),
                '--workspace' => 'no-such-workspace',
            ]);
            $this->assertSame(1, $code);
            $this->assertStringNotContainsString('Stack trace', Artisan::output());

            $code = Artisan::call('intrawatch:import:kuma', ['source' => $garbage, '--workspace' => $ws->slug]);
            $output = Artisan::output();
            $this->assertSame(1, $code);
            $this->assertStringContainsString('neither an Uptime Kuma', $output);
            $this->assertStringNotContainsString('Stack trace', $output);
            $this->assertStringNotContainsString('#0', $output);
        } finally {
            @unlink($garbage);
        }
    }
}
