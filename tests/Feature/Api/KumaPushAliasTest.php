<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\MonitorStatus;
use App\Import\Kuma\ImportOptions;
use App\Import\Kuma\KumaImporter;
use App\Import\Kuma\Source\SourceFactory;
use App\Models\CheckResult;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\Workspace;
use App\Status\Pages\PageStamps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Import\Kuma\ImporterTest;
use Tests\TestCase;

final class KumaPushAliasTest extends TestCase
{
    use RefreshDatabase;

    public function test_push_token_is_preserved_and_alias_route_accepts_kuma_url(): void
    {
        $ws = Workspace::factory()->create();
        $source = SourceFactory::open(ImporterTest::fixture('kuma2.db'));
        try {
            app(KumaImporter::class)->run($source, $ws, new ImportOptions, null);
        } finally {
            $source->close();
        }
        $monitor = Monitor::where('workspace_id', $ws->id)->where('push_token', 'researchPushToken01')->sole();

        // The URL Kuma shows for a push monitor, with an empty ping as its clients send it.
        $this->getJson('/api/push/researchPushToken01?status=down&msg=x&ping=')->assertOk()->assertExactJson(['ok' => true]);

        $result = CheckResult::where('monitor_id', $monitor->id)->sole();
        $this->assertSame(MonitorStatus::Down->value, $result->getRawOriginal('status'));
        $this->assertSame('x', $result->message);
    }

    public function test_alias_accepts_post_and_head_like_the_versioned_route(): void
    {
        $monitor = Monitor::factory()->for(Workspace::factory()->create())->push()->create(['interval_seconds' => 60]);

        $this->postJson("/api/push/{$monitor->push_token}", ['status' => 'up', 'msg' => 'OK'])->assertOk();
        $this->call('HEAD', "/api/push/{$monitor->push_token}")->assertOk();
        $this->getJson("/api/v1/push/{$monitor->push_token}")->assertOk();

        $this->assertSame(3, CheckResult::where('monitor_id', $monitor->id)->count());
    }

    public function test_alias_misses_are_the_same_404(): void
    {
        $this->getJson('/api/push/unknownToken')->assertNotFound();
        $this->getJson('/api/push/bad-token')->assertNotFound();
        $this->getJson('/api/push/'.Monitor::factory()->for(Workspace::factory()->create())->push()->create()->push_token.'/fail')->assertNotFound();
    }

    public function test_alias_is_not_served_on_a_published_custom_status_domain(): void
    {
        $ws = Workspace::factory()->create();
        $monitor = Monitor::factory()->for($ws)->push()->create(['interval_seconds' => 60]);
        $page = StatusPage::factory()->create(['workspace_id' => $ws->id, 'slug' => 'acme', 'published' => true]);
        $page->update(['custom_domain' => 'status.example.test']);
        app(PageStamps::class)->forgetPage($page);

        foreach (["/api/push/{$monitor->push_token}?status=down&msg=x", "/api/v1/push/{$monitor->push_token}"] as $path) {
            $this->get('http://status.example.test'.$path)->assertNotFound();
        }
        $this->assertSame(0, CheckResult::where('monitor_id', $monitor->id)->count());

        // The application host still accepts the alias.
        $this->get("http://other.test/api/push/{$monitor->push_token}")->assertOk();
    }
}
