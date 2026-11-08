<?php

namespace Tests\Feature\Status;

use App\Enums\MonitorStatus;
use App\Enums\WorkspaceRole;
use App\Events\MonitorStatusChanged;
use App\Jobs\BroadcastEvents;
use App\Listeners\StampStatusPages;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\StatusPageItem;
use App\Models\StatusPageSection;
use App\Models\User;
use App\Models\Workspace;
use App\Monitoring\Engine\PostCommit;
use App\Status\Pages\AffectedPages;
use App\Status\Pages\PageStamps;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PageStampsTest extends TestCase
{
    use RefreshDatabase;

    private PageStamps $stamps;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::store('status_pages')->flush();
        StampStatusPages::reset();
        $this->stamps = app(PageStamps::class);
    }

    protected function tearDown(): void
    {
        Cache::store('status_pages')->flush();
        parent::tearDown();
    }

    private function event(int $workspaceId): MonitorStatusChanged
    {
        $now = CarbonImmutable::now();

        return new MonitorStatusChanged($workspaceId, 1, MonitorStatus::Up, MonitorStatus::Down, $now, $now, 'down');
    }

    public function test_tests_use_a_private_status_page_store(): void
    {
        $path = config('cache.stores.status_pages.path');
        $this->assertIsString($path);
        $this->assertNotSame(storage_path('framework/cache/status-pages'), $path);
        $this->assertStringContainsString((string) getmypid(), $path);

        $this->stamps->bumpPage(1);
        $this->assertDirectoryExists($path);
    }

    public function test_versions_are_stable_until_bumped(): void
    {
        $first = $this->stamps->pageVersion(7);
        $this->assertSame($first, $this->stamps->pageVersion(7));
        $this->assertNotSame($first, $this->stamps->pageVersion(8));

        $this->stamps->bumpPage(7);
        $this->assertNotSame($first, $this->stamps->pageVersion(7));

        $stamp = $this->stamps->workspaceStamp(3);
        $this->assertSame($stamp, $this->stamps->workspaceStamp(3));
        $this->stamps->bumpWorkspace(3);
        $this->assertNotSame($stamp, $this->stamps->workspaceStamp(3));
    }

    public function test_status_change_bumps_workspace_stamp_without_queries(): void
    {
        $before = $this->stamps->workspaceStamp(5);
        $other = $this->stamps->workspaceStamp(6);

        DB::enableQueryLog();
        event($this->event(5));
        $this->assertSame([], DB::getQueryLog());

        $after = $this->stamps->workspaceStamp(5);
        $this->assertNotSame($before, $after);
        $this->assertSame($other, $this->stamps->workspaceStamp(6));

        // A second change in the same second is absorbed by the memo.
        event($this->event(5));
        $this->assertSame($after, $this->stamps->workspaceStamp(5));
    }

    public function test_page_edit_and_delete_invalidate_the_page(): void
    {
        config(['status.custom_domains.allowed' => ['*.example.org']]);
        $ws = Workspace::factory()->create();
        $owner = User::factory()->memberOf($ws, WorkspaceRole::Owner)->create();
        $page = StatusPage::factory()->for($ws)->create(['slug' => 'old-slug', 'custom_domain' => 'old.example.org']);
        $store = Cache::store('status_pages');
        $keys = [PageStamps::htmlKey($page->id), PageStamps::lastGoodKey($page->id), PageStamps::slugKey('old-slug'), PageStamps::domainKey('old.example.org'), PageStamps::slugKey('new-slug'), PageStamps::domainKey('new.example.org')];
        foreach ($keys as $key) {
            $store->forever($key, 'x');
        }
        $version = $this->stamps->pageVersion($page->id);

        $this->actingAs($owner)->patchJson("/api/v1/workspaces/{$ws->slug}/status-pages/{$page->id}", ['slug' => 'new-slug', 'custom_domain' => 'new.example.org'])->assertOk();

        foreach ($keys as $key) {
            $this->assertFalse($store->has($key), $key);
        }
        $edited = $this->stamps->pageVersion($page->id);
        $this->assertNotSame($version, $edited);

        $store->forever(PageStamps::htmlKey($page->id), 'x');
        $this->actingAs($owner)->deleteJson("/api/v1/workspaces/{$ws->slug}/status-pages/{$page->id}")->assertNoContent();
        $this->assertFalse($store->has(PageStamps::htmlKey($page->id)));
        $this->assertNotSame($edited, $this->stamps->pageVersion($page->id));
    }

    public function test_workspace_deletion_forgets_page_keys(): void
    {
        $ws = Workspace::factory()->create();
        $owner = User::factory()->memberOf($ws, WorkspaceRole::Owner)->create();
        $page = StatusPage::factory()->for($ws)->create(['slug' => 'doomed', 'custom_domain' => 'doomed.example.org']);
        $store = Cache::store('status_pages');
        $keys = [PageStamps::htmlKey($page->id), PageStamps::lastGoodKey($page->id), PageStamps::slugKey('doomed'), PageStamps::domainKey('doomed.example.org')];
        foreach ($keys as $key) {
            $store->forever($key, 'x');
        }

        $this->actingAs($owner)->deleteJson("/api/v1/workspaces/{$ws->slug}")->assertNoContent();

        foreach ($keys as $key) {
            $this->assertFalse($store->has($key), $key);
        }
    }

    public function test_affected_pages_cover_items_expanded_groups_and_links(): void
    {
        $ws = Workspace::factory()->create();
        $group = Monitor::factory()->for($ws)->create();
        $child = Monitor::factory()->for($ws)->create(['parent_id' => $group->id]);
        $plain = Monitor::factory()->for($ws)->create();
        $direct = StatusPage::factory()->for($ws)->create();
        $expanded = StatusPage::factory()->for($ws)->create();
        $collapsed = StatusPage::factory()->for($ws)->create();
        $unrelated = StatusPage::factory()->for($ws)->create();
        $linked = StatusPage::factory()->for($ws)->create();
        $this->item($direct, $child, false);
        $this->item($expanded, $group, true);
        $this->item($collapsed, $group, false);
        $this->item($unrelated, $plain, true);

        $ids = AffectedPages::showingMonitors([$child->id]);
        sort($ids);
        $this->assertSame([$direct->id, $expanded->id], $ids);

        $incident = Incident::factory()->for($ws)->create();
        DB::table('status_page_links')->insert(['status_page_id' => $linked->id, 'linkable_type' => 'incident', 'linkable_id' => $incident->id]);
        $this->assertSame([$linked->id], AffectedPages::forIncident($incident->id));
    }

    public function test_incident_write_bumps_linked_and_showing_pages(): void
    {
        $ws = Workspace::factory()->create();
        $editor = User::factory()->memberOf($ws, WorkspaceRole::Editor)->create();
        $monitor = Monitor::factory()->for($ws)->create();
        $showing = StatusPage::factory()->for($ws)->create();
        $linked = StatusPage::factory()->for($ws)->create();
        $other = StatusPage::factory()->for($ws)->create();
        $this->item($showing, $monitor, false);
        $versions = [$showing->id => $this->stamps->pageVersion($showing->id), $linked->id => $this->stamps->pageVersion($linked->id), $other->id => $this->stamps->pageVersion($other->id)];

        $this->actingAs($editor)->postJson("/api/v1/workspaces/{$ws->slug}/incidents", [
            'title' => 'Down', 'impact' => 'major', 'status' => 'investigating', 'message' => 'Looking.',
            'monitor_ids' => [$monitor->id], 'status_page_ids' => [$linked->id],
        ])->assertCreated();

        $this->assertNotSame($versions[$showing->id], $this->stamps->pageVersion($showing->id));
        $this->assertNotSame($versions[$linked->id], $this->stamps->pageVersion($linked->id));
        $this->assertSame($versions[$other->id], $this->stamps->pageVersion($other->id));
    }

    private function item(StatusPage $page, Monitor $monitor, bool $expand): void
    {
        $section = StatusPageSection::factory()->create(['status_page_id' => $page->id]);
        StatusPageItem::factory()->create(['status_page_section_id' => $section->id, 'monitor_id' => $monitor->id, 'expand_children' => $expand]);
    }

    /**
     * Points the status page store at a path below a regular file, so every write to it fails.
     */
    private function breakStore(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'iw-broken-');
        $this->assertIsString($file);
        $this->beforeApplicationDestroyed(fn () => @unlink($file));
        config(['cache.stores.status_pages.path' => $file.'/cache', 'cache.stores.status_pages.lock_path' => $file.'/locks']);
        Cache::forgetDriver('status_pages');
        $this->stamps = app(PageStamps::class);
    }

    public function test_a_failing_stamp_write_does_not_silence_the_status_broadcast(): void
    {
        $this->breakStore();
        Exceptions::fake();
        Queue::fake();
        $later = 0;
        Event::listen(MonitorStatusChanged::class, function () use (&$later): void {
            $later++;
        });

        PostCommit::dispatch([$this->event(1)]);

        $this->assertSame(1, $later, 'listeners after the stamp still run');
        Queue::assertPushed(BroadcastEvents::class);
        Exceptions::assertReported(\ErrorException::class);
    }

    public function test_a_failing_stamp_write_does_not_fail_a_committed_write(): void
    {
        $ws = Workspace::factory()->create();
        $editor = User::factory()->memberOf($ws, WorkspaceRole::Editor)->create();
        $page = StatusPage::factory()->create(['workspace_id' => $ws->id]);
        $this->breakStore();
        Exceptions::fake();

        $this->actingAs($editor)->postJson("/api/v1/workspaces/{$ws->slug}/incidents", [
            'title' => 'Outage', 'impact' => 'minor', 'status' => 'investigating', 'message' => 'Looking', 'status_page_ids' => [$page->id],
        ])->assertCreated();
        $this->actingAs($editor)->patchJson("/api/v1/workspaces/{$ws->slug}/status-pages/{$page->id}", ['title' => 'Renamed'])->assertOk();

        $this->assertSame(1, Incident::query()->count());
        Exceptions::assertReported(\ErrorException::class);
    }

    public function test_stamp_failure_after_commit_does_not_500(): void
    {
        $ws = Workspace::factory()->create();
        $editor = User::factory()->memberOf($ws, WorkspaceRole::Editor)->create();
        $page = StatusPage::factory()->create(['workspace_id' => $ws->id]);
        $monitor = Monitor::factory()->create(['workspace_id' => $ws->id]);
        $this->breakStore();
        Exceptions::fake();
        $base = "/api/v1/workspaces/{$ws->slug}";

        $this->actingAs($editor)->postJson("{$base}/status-pages", ['title' => 'Public', 'slug' => 'public-one'])->assertCreated();
        $createdId = StatusPage::query()->where('slug', 'public-one')->firstOrFail()->id;
        $this->assertSame(1, StatusPage::query()->where('slug', 'public-one')->count());
        $this->actingAs($editor)->patchJson("{$base}/status-pages/{$createdId}", ['slug' => 'public-two'])->assertOk();
        $this->assertSame(1, StatusPage::query()->where('slug', 'public-two')->count());
        $this->actingAs($editor)->deleteJson("{$base}/status-pages/{$page->id}")->assertNoContent();
        $this->assertNull(StatusPage::query()->find($page->id));
        $this->actingAs($editor)->deleteJson("{$base}/monitors/{$monitor->id}")->assertNoContent();
        $this->assertNull(Monitor::query()->find($monitor->id));
    }
}
