<?php

namespace Tests\Unit\Alerting;

use App\Alerting\Engine\AlertClass;
use App\Alerting\Engine\AlertDecision;
use App\Alerting\Engine\AlertMachine;
use App\Alerting\Engine\AlertPolicy;
use App\Alerting\Engine\AlertSnapshot;
use App\Alerting\Engine\ExpiryBand;
use App\Alerting\Engine\PlannedAlert;
use App\Alerting\Engine\Signals\ChangeSignal;
use App\Alerting\Engine\Signals\StatusSignal;
use App\Alerting\Engine\Signals\TickSignal;
use App\Enums\AlertKind;
use App\Enums\MonitorStatus;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AlertMachineTest extends TestCase
{
    private CarbonImmutable $t0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t0 = CarbonImmutable::parse('2026-11-20 10:00:00', 'UTC');
    }

    private function policy(
        int $resend = 0,
        bool $notifyDegraded = true,
        int $degradedAfter = 300,
        bool $notifyChanges = true,
    ): AlertPolicy {
        return new AlertPolicy($resend, $notifyDegraded, $degradedAfter, $notifyChanges, 4, 600, 600, 60);
    }

    private function at(int $seconds): CarbonImmutable
    {
        return $this->t0->addSeconds($seconds);
    }

    private function statusChange(
        AlertPolicy $policy,
        AlertSnapshot $state,
        MonitorStatus $from,
        MonitorStatus $to,
        int $at,
        ?int $previousSince = null,
        string $message = 'check message',
        bool $suppressed = false,
    ): AlertDecision {
        $signal = new StatusSignal($from, $to, $this->at($at), $this->at($previousSince ?? $at - 60), $message);

        return AlertMachine::apply($policy, $state, $signal, $suppressed);
    }

    private function tick(AlertPolicy $policy, AlertSnapshot $state, int $at, bool $suppressed = false): AlertDecision
    {
        return AlertMachine::apply($policy, $state, new TickSignal($this->at($at)), $suppressed);
    }

    /**
     * @param  list<array{key: string, old: ?string, new: string}>  $changes
     */
    private function change(AlertPolicy $policy, AlertSnapshot $state, int $at, array $changes, bool $suppressed = false): AlertDecision
    {
        return AlertMachine::apply($policy, $state, new ChangeSignal($this->at($at), $changes), $suppressed);
    }

    /**
     * A monitor that has been up (and notified ok) since one hour before t0.
     */
    private function upMonitor(AlertPolicy $policy): AlertSnapshot
    {
        return $this->statusChange($policy, AlertSnapshot::initial(), MonitorStatus::Pending, MonitorStatus::Up, -3600)->state;
    }

    /**
     * @param  list<PlannedAlert>  $alerts
     * @return list<AlertKind>
     */
    private function kinds(array $alerts): array
    {
        return array_map(static fn (PlannedAlert $alert): AlertKind => $alert->kind, $alerts);
    }

    public function test_down_then_up_reports_duration_from_first_failure(): void
    {
        $policy = $this->policy();
        $state = $this->upMonitor($policy);

        $pending = $this->statusChange($policy, $state, MonitorStatus::Up, MonitorStatus::Pending, 0);
        $this->assertSame([], $pending->alerts);

        $down = $this->statusChange($policy, $pending->state, MonitorStatus::Pending, MonitorStatus::Down, 60, previousSince: 0, message: 'HTTP 500');
        $this->assertCount(1, $down->alerts);
        $alert = $down->alerts[0];
        $this->assertSame(AlertKind::Down, $alert->kind);
        $this->assertSame(MonitorStatus::Down, $alert->status);
        $this->assertSame(MonitorStatus::Up, $alert->previousStatus);
        $this->assertSame('HTTP 500', $alert->message);
        $this->assertFalse($alert->suppressed);
        $this->assertSame(AlertClass::Down, $down->state->notified);
        $this->assertEquals($this->at(0), $down->state->downSince);
        $this->assertEquals($this->at(0), $down->state->problemSince);

        $up = $this->statusChange($policy, $down->state, MonitorStatus::Down, MonitorStatus::Up, 1860, message: 'HTTP 200');
        $this->assertSame([AlertKind::Up], $this->kinds($up->alerts));
        $this->assertSame(1860, $up->alerts[0]->durationSeconds);
        $this->assertSame(MonitorStatus::Down, $up->alerts[0]->previousStatus);
        $this->assertSame(MonitorStatus::Up, $up->alerts[0]->status);
        $this->assertSame(AlertClass::Ok, $up->state->notified);
        $this->assertNull($up->state->problemSince);
        $this->assertNull($up->state->downSince);
    }

    public function test_first_up_of_new_monitor_is_silent(): void
    {
        $policy = $this->policy();
        $decision = $this->statusChange($policy, AlertSnapshot::initial(), MonitorStatus::Pending, MonitorStatus::Up, 0);

        $this->assertSame([], $decision->alerts);
        $this->assertSame(AlertClass::Ok, $decision->state->notified);
        $this->assertSame(AlertClass::Ok, $decision->state->observed);
        $this->assertSame(MonitorStatus::Up, $decision->state->current);
        $this->assertSame([], $decision->state->recentChanges, 'a first observation is not a class change');

        // A new monitor whose first confirmed status is down is news.
        $down = $this->statusChange($policy, AlertSnapshot::initial(), MonitorStatus::Pending, MonitorStatus::Down, 0, previousSince: -120);
        $this->assertSame([AlertKind::Down], $this->kinds($down->alerts));
        $this->assertNull($down->alerts[0]->previousStatus);
        $this->assertEquals($this->at(-120), $down->state->downSince);
    }

    public function test_pending_and_stale_signals_send_nothing(): void
    {
        $policy = $this->policy();
        $state = $this->upMonitor($policy);

        $pending = $this->statusChange($policy, $state, MonitorStatus::Up, MonitorStatus::Pending, 0);
        $this->assertSame([], $pending->alerts);
        $this->assertSame(AlertClass::Ok, $pending->state->notified);
        $this->assertSame(AlertClass::Ok, $pending->state->observed);
        $this->assertSame(MonitorStatus::Pending, $pending->state->current);

        $down = $this->statusChange($policy, $pending->state, MonitorStatus::Pending, MonitorStatus::Down, 300);
        $stale = $this->statusChange($policy, $down->state, MonitorStatus::Down, MonitorStatus::Up, 240);
        $this->assertSame([], $stale->alerts);
        $this->assertSame($down->state, $stale->state);

        $staleChange = $this->change($policy, $down->state, 240, [['key' => 'dns.records', 'old' => 'a', 'new' => 'b']]);
        $this->assertSame([], $staleChange->alerts);
        $this->assertSame($down->state, $staleChange->state);

        // Re-processing the same signal is harmless.
        $again = $this->statusChange($policy, $down->state, MonitorStatus::Pending, MonitorStatus::Down, 300);
        $this->assertSame([], $again->alerts);
        $this->assertEquals($down->state, $again->state);
    }

    public function test_maintenance_mutes_but_recovery_after_maintenance_is_sent(): void
    {
        $policy = $this->policy(resend: 30);
        $down = $this->statusChange($policy, $this->upMonitor($policy), MonitorStatus::Up, MonitorStatus::Down, 0);
        $this->assertSame([AlertKind::Down], $this->kinds($down->alerts));

        $maintenance = $this->statusChange($policy, $down->state, MonitorStatus::Down, MonitorStatus::Maintenance, 60);
        $this->assertSame([], $maintenance->alerts);
        $this->assertSame(AlertClass::Down, $maintenance->state->notified);

        $reminder = $this->tick($policy, $maintenance->state, 3600);
        $this->assertSame([], $reminder->alerts, 'no reminders inside maintenance');

        $up = $this->statusChange($policy, $reminder->state, MonitorStatus::Maintenance, MonitorStatus::Up, 4000);
        $this->assertSame([AlertKind::Up], $this->kinds($up->alerts));
        $this->assertSame(4000, $up->alerts[0]->durationSeconds);

        // A monitor that is only down inside maintenance sends nothing.
        $state = $this->upMonitor($policy);
        $muted = $this->statusChange($policy, $state, MonitorStatus::Up, MonitorStatus::Maintenance, 0);
        $back = $this->statusChange($policy, $muted->state, MonitorStatus::Maintenance, MonitorStatus::Up, 600);
        $this->assertSame([], [...$muted->alerts, ...$back->alerts]);

        // Paused mutes the same way.
        $paused = $this->statusChange($policy, $state, MonitorStatus::Up, MonitorStatus::Paused, 0);
        $this->assertSame([], $paused->alerts);
        $this->assertSame(AlertClass::Ok, $paused->state->notified);
    }

    public function test_degraded_needs_to_persist(): void
    {
        $policy = $this->policy();
        $state = $this->upMonitor($policy);

        $blip = $this->statusChange($policy, $state, MonitorStatus::Up, MonitorStatus::Degraded, 0);
        $this->assertSame([], $blip->alerts);
        $this->assertEquals($this->at(300), $blip->state->dueAt);
        $recovered = $this->statusChange($policy, $blip->state, MonitorStatus::Degraded, MonitorStatus::Up, 200);
        $this->assertSame([], $recovered->alerts);
        $this->assertNull($recovered->state->degradedSince);
        $this->assertNull($recovered->state->dueAt);

        $degraded = $this->statusChange($policy, $recovered->state, MonitorStatus::Up, MonitorStatus::Degraded, 1000, message: 'slow');
        $early = $this->tick($policy, $degraded->state, 1299);
        $this->assertSame([], $early->alerts);
        $held = $this->tick($policy, $early->state, 1300);
        $this->assertSame([AlertKind::Degraded], $this->kinds($held->alerts));
        $this->assertSame(MonitorStatus::Degraded, $held->alerts[0]->status);
        $this->assertSame(MonitorStatus::Up, $held->alerts[0]->previousStatus);
        $this->assertSame(300, $held->alerts[0]->durationSeconds);
        $this->assertSame(AlertClass::Degraded, $held->state->notified);
        $this->assertEquals($this->at(1000), $held->state->problemSince);
        $this->assertSame([], $this->tick($policy, $held->state, 1400)->alerts, 'sent once');

        $up = $this->statusChange($policy, $held->state, MonitorStatus::Degraded, MonitorStatus::Up, 1600);
        $this->assertSame([AlertKind::Up], $this->kinds($up->alerts));
        $this->assertSame(MonitorStatus::Degraded, $up->alerts[0]->previousStatus);
        $this->assertSame(600, $up->alerts[0]->durationSeconds);

        // Maintenance restarts the hold: degraded again afterwards is held from its own start.
        $before = $this->statusChange($policy, $state, MonitorStatus::Up, MonitorStatus::Degraded, 0);
        $muted = $this->statusChange($policy, $before->state, MonitorStatus::Degraded, MonitorStatus::Maintenance, 100);
        $this->assertSame([], $this->tick($policy, $muted->state, 400)->alerts);
        $after = $this->statusChange($policy, $muted->state, MonitorStatus::Maintenance, MonitorStatus::Degraded, 500);
        $this->assertSame([], $this->tick($policy, $after->state, 799)->alerts);
        $this->assertSame([AlertKind::Degraded], $this->kinds($this->tick($policy, $after->state, 800)->alerts));

        // Without a hold the alert is immediate; with notify_degraded off degraded is ok.
        $immediate = $this->statusChange($this->policy(degradedAfter: 0), $state, MonitorStatus::Up, MonitorStatus::Degraded, 0, message: 'slow');
        $this->assertSame([AlertKind::Degraded], $this->kinds($immediate->alerts));
        $this->assertSame('slow', $immediate->alerts[0]->message);
        $ignored = $this->statusChange($this->policy(notifyDegraded: false), $state, MonitorStatus::Up, MonitorStatus::Degraded, 0);
        $this->assertSame([], $ignored->alerts);
        $this->assertSame([], $this->tick($this->policy(notifyDegraded: false), $ignored->state, 3600)->alerts);
    }

    public function test_down_to_degraded_closes_the_down_alert(): void
    {
        $policy = $this->policy();
        $down = $this->statusChange($policy, $this->upMonitor($policy), MonitorStatus::Up, MonitorStatus::Down, 0);
        $this->assertEquals($this->at(0), $down->state->downSince);

        $degraded = $this->statusChange($policy, $down->state, MonitorStatus::Down, MonitorStatus::Degraded, 600, message: 'slow');
        $this->assertSame([AlertKind::Degraded], $this->kinds($degraded->alerts));
        $this->assertSame(600, $degraded->alerts[0]->durationSeconds);
        $this->assertSame(MonitorStatus::Down, $degraded->alerts[0]->previousStatus);
        $this->assertSame('slow', $degraded->alerts[0]->message);
        $this->assertSame(AlertClass::Degraded, $degraded->state->notified);
        $this->assertNull($degraded->state->downSince);

        $up = $this->statusChange($policy, $degraded->state, MonitorStatus::Degraded, MonitorStatus::Up, 1200);
        $this->assertSame([AlertKind::Up], $this->kinds($up->alerts));
        $this->assertSame(MonitorStatus::Degraded, $up->alerts[0]->previousStatus);
        $this->assertSame(1200, $up->alerts[0]->durationSeconds);
    }

    public function test_reminders_while_down_only(): void
    {
        $policy = $this->policy(resend: 30);
        $down = $this->statusChange($policy, $this->upMonitor($policy), MonitorStatus::Up, MonitorStatus::Down, 0);
        $this->assertEquals($this->at(1800), $down->state->dueAt);

        $early = $this->tick($policy, $down->state, 29 * 60);
        $this->assertSame([], $early->alerts);

        $first = $this->tick($policy, $early->state, 30 * 60);
        $this->assertSame([AlertKind::StillDown], $this->kinds($first->alerts));
        $this->assertSame(1800, $first->alerts[0]->durationSeconds);
        $this->assertSame(MonitorStatus::Down, $first->alerts[0]->status);
        $this->assertSame(1, $first->state->remindersSent);
        $this->assertEquals($this->at(3600), $first->state->dueAt);

        $second = $this->tick($policy, $first->state, 60 * 60);
        $this->assertSame([AlertKind::StillDown], $this->kinds($second->alerts));
        $this->assertSame(2, $second->state->remindersSent);
        $this->assertSame(3600, $second->alerts[0]->durationSeconds);

        $muted = $this->statusChange($policy, $second->state, MonitorStatus::Down, MonitorStatus::Maintenance, 3700);
        $this->assertNull($muted->state->dueAt);
        $this->assertSame([], $this->tick($policy, $muted->state, 90 * 60)->alerts);

        // Reminders are off by default and never sent while up.
        $off = $this->statusChange($this->policy(), $this->upMonitor($this->policy()), MonitorStatus::Up, MonitorStatus::Down, 0);
        $this->assertSame([], $this->tick($this->policy(), $off->state, 86400)->alerts);
        $this->assertSame([], $this->tick($policy, $this->upMonitor($policy), 86400)->alerts);

        // A new outage starts a new reminder count.
        $up = $this->statusChange($policy, $second->state, MonitorStatus::Down, MonitorStatus::Up, 3700);
        $again = $this->statusChange($policy, $up->state, MonitorStatus::Up, MonitorStatus::Down, 4000);
        $this->assertSame(0, $again->state->remindersSent);
        $this->assertEquals($this->at(4000), $again->state->downSince);
    }

    public function test_flapping_sends_one_alert_per_episode(): void
    {
        $policy = $this->policy(resend: 5);
        $state = $this->upMonitor($policy);
        $alerts = [];
        $current = MonitorStatus::Up;

        for ($i = 1; $i <= 30; $i++) {
            $next = $i % 2 === 1 ? MonitorStatus::Down : MonitorStatus::Up;
            $decision = $this->statusChange($policy, $state, $current, $next, $i * 60);
            $tick = $this->tick($policy, $decision->state, $i * 60 + 30);
            $alerts = [...$alerts, ...$decision->alerts, ...$tick->alerts];
            $state = $tick->state;
            $current = $next;
        }

        $this->assertSame([AlertKind::Down, AlertKind::Up, AlertKind::Down, AlertKind::Flapping], $this->kinds($alerts));
        $flapping = $alerts[3];
        $this->assertSame('4 status changes in 10 min', $flapping->message);
        $this->assertSame(MonitorStatus::Up, $flapping->status);
        $this->assertTrue($state->flapping);
        $this->assertEquals($this->at(240), $state->flappingSince);
        // The change before the last one leaves the window at 1740 + 601, before the 600 s quiet period ends.
        $this->assertEquals($this->at(1740 + 601), $state->dueAt);

        $this->assertSame([], $this->tick($policy, $state, 1740 + 600)->alerts);
        $stable = $this->tick($policy, $state, 1740 + 601);
        $this->assertSame([AlertKind::Stable], $this->kinds($stable->alerts));
        $this->assertSame('Fewer than 2 status changes in 10 min', $stable->alerts[0]->message);
        $this->assertSame(MonitorStatus::Up, $stable->alerts[0]->status);
        $this->assertSame(MonitorStatus::Down, $stable->alerts[0]->previousStatus);
        $this->assertFalse($stable->state->flapping);
        $this->assertSame([], $stable->state->recentChanges);
        $this->assertSame(AlertClass::Ok, $stable->state->notified);
        $this->assertNull($stable->state->problemSince);
        $this->assertSame([], $this->tick($policy, $stable->state, 1800 + 660)->alerts, 'one stable per episode');

        // The next outage is a fresh one, timed from its own start.
        $down = $this->statusChange($policy, $stable->state, MonitorStatus::Up, MonitorStatus::Down, 3000);
        $this->assertSame([AlertKind::Down], $this->kinds($down->alerts));
        $this->assertEquals($this->at(3000), $down->state->problemSince);
    }

    public function test_flapping_that_settles_down_resumes_the_outage(): void
    {
        $policy = $this->policy(resend: 30);
        $state = $this->upMonitor($policy);
        $current = MonitorStatus::Up;
        for ($i = 1; $i <= 5; $i++) {
            $next = $i % 2 === 1 ? MonitorStatus::Down : MonitorStatus::Up;
            $state = $this->statusChange($policy, $state, $current, $next, $i * 60)->state;
            $current = $next;
        }
        $this->assertTrue($state->flapping);
        $this->assertSame(AlertClass::Down, $state->notified, 'the last alert before the episode was a down');

        $stable = $this->tick($policy, $state, 841);
        $this->assertSame([AlertKind::Stable], $this->kinds($stable->alerts));
        $this->assertSame(MonitorStatus::Down, $stable->alerts[0]->status);
        $this->assertSame(MonitorStatus::Down, $stable->alerts[0]->previousStatus);
        $this->assertSame(AlertClass::Down, $stable->state->notified);
        $this->assertEquals($this->at(300), $stable->state->problemSince);
        $this->assertEquals($this->at(841), $stable->state->lastNotifiedAt);
        $this->assertEquals($this->at(841 + 1800), $stable->state->dueAt);

        $up = $this->statusChange($policy, $stable->state, MonitorStatus::Down, MonitorStatus::Up, 1000);
        $this->assertSame([AlertKind::Up], $this->kinds($up->alerts));
        $this->assertSame(700, $up->alerts[0]->durationSeconds);
    }

    public function test_suppressed_alerts_are_recorded_but_not_notified_and_caught_up(): void
    {
        $policy = $this->policy();
        $down = $this->statusChange($policy, $this->upMonitor($policy), MonitorStatus::Up, MonitorStatus::Down, 0, suppressed: true);
        $this->assertSame([AlertKind::Down], $this->kinds($down->alerts));
        $this->assertTrue($down->alerts[0]->suppressed);
        $this->assertSame(AlertClass::Ok, $down->state->notified);
        $this->assertSame(AlertClass::Down, $down->state->observed);
        $this->assertNull($down->state->problemSince);
        $this->assertNull($down->state->lastNotifiedAt);
        $this->assertTrue($down->state->suppressed);
        $this->assertEquals($this->at(60), $down->state->dueAt);

        $replayed = $this->statusChange($policy, $down->state, MonitorStatus::Up, MonitorStatus::Down, 0, suppressed: true);
        $this->assertSame([], $replayed->alerts, 'a withheld alert is recorded once');

        $stillSuppressed = $this->tick($policy, $down->state, 60, suppressed: true);
        $this->assertSame([], $stillSuppressed->alerts);
        $this->assertTrue($stillSuppressed->state->suppressed);

        $caughtUp = $this->tick($policy, $stillSuppressed->state, 120);
        $this->assertSame([AlertKind::Down], $this->kinds($caughtUp->alerts));
        $this->assertFalse($caughtUp->alerts[0]->suppressed);
        $this->assertSame(AlertClass::Down, $caughtUp->state->notified);
        $this->assertFalse($caughtUp->state->suppressed);
        $this->assertEquals($this->at(0), $caughtUp->state->problemSince);
        $this->assertNull($caughtUp->state->dueAt);

        // Recovery from a delivered down is never held back by a suppressing ancestor.
        $up = $this->statusChange($policy, $caughtUp->state, MonitorStatus::Down, MonitorStatus::Up, 600, suppressed: true);
        $this->assertSame([AlertKind::Up], $this->kinds($up->alerts));
        $this->assertFalse($up->alerts[0]->suppressed);
        $this->assertSame(600, $up->alerts[0]->durationSeconds);
        $this->assertSame(AlertClass::Ok, $up->state->notified);
        $this->assertFalse($up->state->suppressed);
        $degraded = $this->statusChange($policy, $caughtUp->state, MonitorStatus::Down, MonitorStatus::Degraded, 600, suppressed: true);
        $this->assertSame([AlertKind::Degraded], $this->kinds($degraded->alerts));
        $this->assertFalse($degraded->alerts[0]->suppressed);
        $this->assertSame(AlertClass::Degraded, $degraded->state->notified);

        // Down and back up entirely while suppressed: the catch-up has nothing to tell.
        $quiet = $this->statusChange($policy, $down->state, MonitorStatus::Down, MonitorStatus::Up, 30, suppressed: true);
        $this->assertSame([], $quiet->alerts);
        $settled = $this->tick($policy, $quiet->state, 90);
        $this->assertSame([], $settled->alerts);
        $this->assertFalse($settled->state->suppressed);
        $later = $this->statusChange($policy, $settled->state, MonitorStatus::Up, MonitorStatus::Down, 1000);
        $this->assertEquals($this->at(1000), $later->state->problemSince);
    }

    public function test_change_signals(): void
    {
        $policy = $this->policy();
        $state = $this->upMonitor($policy);

        $expiring = $this->change($policy, $state, 0, [['key' => 'tls.expiry_band', 'old' => 'ok', 'new' => '30']]);
        $this->assertSame([AlertKind::Expiring], $this->kinds($expiring->alerts));
        $this->assertSame('Certificate expires within 30 days', $expiring->alerts[0]->message);
        $this->assertSame([['key' => 'tls.expiry_band', 'old' => 'ok', 'new' => '30']], $expiring->alerts[0]->changes);

        $expired = $this->change($policy, $state, 0, [['key' => 'tls.expiry_band', 'old' => '7', 'new' => 'expired']]);
        $this->assertSame('Certificate expired', $expired->alerts[0]->message);

        $domain = $this->change($policy, $state, 0, [['key' => 'domain.expiry_band', 'old' => '14', 'new' => '7']]);
        $this->assertSame('Domain expires within 7 days', $domain->alerts[0]->message);

        $renewal = [
            ['key' => 'tls.expiry_band', 'old' => '7', 'new' => 'ok'],
            ['key' => 'tls.fingerprint', 'old' => 'aa', 'new' => 'bb'],
        ];
        $renewed = $this->change($policy, $state, 0, $renewal);
        $this->assertSame([AlertKind::Renewed], $this->kinds($renewed->alerts));
        $this->assertSame('Certificate renewed', $renewed->alerts[0]->message);
        $this->assertSame($renewal, $renewed->alerts[0]->changes);

        // One renewal per improved band, each with its own changes; other keys keep their own alerts.
        $mixed = $this->change($policy, $state, 0, [
            ['key' => 'tls.expiry_band', 'old' => '7', 'new' => 'ok'],
            ['key' => 'tls.fingerprint', 'old' => 'aa', 'new' => 'bb'],
            ['key' => 'dns.records', 'old' => '1.1.1.1', 'new' => '2.2.2.2'],
            ['key' => 'domain.expiry_band', 'old' => '14', 'new' => 'ok'],
        ]);
        $this->assertSame([AlertKind::Renewed, AlertKind::Changed, AlertKind::Renewed], $this->kinds($mixed->alerts));
        $this->assertSame(['tls.expiry_band', 'tls.fingerprint'], array_column($mixed->alerts[0]->changes, 'key'));
        $this->assertSame('DNS records changed', $mixed->alerts[1]->message);
        $this->assertSame(['dns.records'], array_column($mixed->alerts[1]->changes, 'key'));
        $this->assertSame('Domain renewed', $mixed->alerts[2]->message);
        $this->assertSame(['domain.expiry_band'], array_column($mixed->alerts[2]->changes, 'key'));

        $renewedWithNewIssuer = $this->change($policy, $state, 0, [
            ['key' => 'tls.expiry_band', 'old' => '7', 'new' => 'ok'],
            ['key' => 'tls.issuer', 'old' => 'CA One', 'new' => 'CA Two'],
            ['key' => 'tls.fingerprint', 'old' => 'aa', 'new' => 'bb'],
        ]);
        $this->assertSame([AlertKind::Renewed, AlertKind::Changed], $this->kinds($renewedWithNewIssuer->alerts));
        $this->assertSame(['tls.expiry_band', 'tls.fingerprint'], array_column($renewedWithNewIssuer->alerts[0]->changes, 'key'));
        $this->assertSame('Certificate issuer changed', $renewedWithNewIssuer->alerts[1]->message);

        // A domain renewal does not absorb a certificate change.
        $domainOnly = $this->change($policy, $state, 0, [
            ['key' => 'domain.expiry_band', 'old' => '7', 'new' => 'ok'],
            ['key' => 'tls.fingerprint', 'old' => 'aa', 'new' => 'bb'],
        ]);
        $this->assertSame([AlertKind::Renewed, AlertKind::Changed], $this->kinds($domainOnly->alerts));
        $this->assertSame('Certificate changed', $domainOnly->alerts[1]->message);

        $issuer = $this->change($policy, $state, 0, [
            ['key' => 'tls.issuer', 'old' => 'CA One', 'new' => 'CA Two'],
            ['key' => 'tls.fingerprint', 'old' => 'aa', 'new' => 'bb'],
        ]);
        $this->assertSame([AlertKind::Changed], $this->kinds($issuer->alerts));
        $this->assertSame('Certificate issuer changed', $issuer->alerts[0]->message);
        $this->assertCount(2, $issuer->alerts[0]->changes);

        $fingerprint = $this->change($policy, $state, 0, [['key' => 'tls.fingerprint', 'old' => 'aa', 'new' => 'bb']]);
        $this->assertSame('Certificate changed', $fingerprint->alerts[0]->message);

        $dns = $this->change($policy, $state, 0, [['key' => 'dns.records', 'old' => '1.1.1.1', 'new' => '2.2.2.2']]);
        $this->assertSame('DNS records changed', $dns->alerts[0]->message);
        $other = $this->change($policy, $state, 0, [['key' => 'http.server', 'old' => 'nginx', 'new' => 'caddy']]);
        $this->assertSame('http.server changed', $other->alerts[0]->message);
        $this->assertNull($other->alerts[0]->status);

        $this->assertSame([], $this->change($this->policy(notifyChanges: false), $state, 0, $renewal)->alerts);
        $muted = $this->statusChange($policy, $state, MonitorStatus::Up, MonitorStatus::Maintenance, 0);
        $this->assertSame([], $this->change($policy, $muted->state, 10, $renewal)->alerts);

        // Change alerts are neither suppressed nor flap-damped.
        $suppressed = $this->change($policy, $state, 0, $renewal, suppressed: true);
        $this->assertFalse($suppressed->alerts[0]->suppressed);
        $flapping = new AlertSnapshot(AlertClass::Ok, AlertClass::Ok, MonitorStatus::Up, null, null, null, true, $this->at(0), [$this->at(0)->getTimestamp(), $this->at(180)->getTimestamp()], null, 0, false, $this->at(180), null);
        $this->assertSame([AlertKind::Renewed], $this->kinds($this->change($policy, $flapping, 200, $renewal)->alerts));
    }

    public function test_expiry_band_rank(): void
    {
        $this->assertSame(-1, ExpiryBand::rank('expired'));
        $this->assertSame(14, ExpiryBand::rank('14'));
        $this->assertSame(PHP_INT_MAX, ExpiryBand::rank('ok'));
        $this->assertSame('Certificate expires within 14 days', ExpiryBand::message('tls.expiry_band', '14'));
        $this->assertSame('Domain expired', ExpiryBand::message('domain.expiry_band', 'expired'));
        $this->assertSame('Certificate expires within 1 day', ExpiryBand::message('tls.expiry_band', '1'));

        $this->expectException(InvalidArgumentException::class);
        ExpiryBand::message('tls.expiry_band', 'ok');
    }

    public function test_due_at_is_the_earliest_pending_decision(): void
    {
        $policy = $this->policy(resend: 30);
        $up = $this->upMonitor($policy);
        $this->assertNull($up->dueAt);

        $degraded = $this->statusChange($policy, $up, MonitorStatus::Up, MonitorStatus::Degraded, 0);
        $this->assertEquals($this->at(300), $degraded->state->dueAt, 'degraded hold end');

        $down = $this->statusChange($policy, $up, MonitorStatus::Up, MonitorStatus::Down, 0);
        $this->assertEquals($this->at(1800), $down->state->dueAt, 'next reminder');

        $suppressed = $this->statusChange($policy, $up, MonitorStatus::Up, MonitorStatus::Down, 0, suppressed: true);
        $this->assertEquals($this->at(60), $suppressed->state->dueAt, 'suppression recheck');

        // Flapping while suppressed: the recheck comes before the flap end.
        $state = $up;
        $current = MonitorStatus::Up;
        for ($i = 1; $i <= 4; $i++) {
            $next = $i % 2 === 1 ? MonitorStatus::Down : MonitorStatus::Up;
            $state = $this->statusChange($policy, $state, $current, $next, $i * 60, suppressed: true)->state;
            $current = $next;
        }
        $this->assertTrue($state->flapping);
        $this->assertTrue($state->suppressed);
        $this->assertEquals($this->at(240 + 60), $state->dueAt);
        $unsuppressed = $this->tick($policy, $state, 300);
        $this->assertSame([], $unsuppressed->alerts, 'no catch-up while flapping');
        $this->assertEquals($this->at(240 + 600), $unsuppressed->state->dueAt, 'flap end');

        // A withheld down is not caught up inside the episode; the stable alert reports it.
        $downWhileFlapping = $this->statusChange($policy, $state, MonitorStatus::Up, MonitorStatus::Down, 270, suppressed: true);
        $this->assertSame([], $downWhileFlapping->alerts);
        $released = $this->tick($policy, $downWhileFlapping->state, 330);
        $this->assertSame([], $released->alerts);
        $this->assertFalse($released->state->suppressed);
        $stable = $this->tick($policy, $released->state, 270 + 600);
        $this->assertSame([AlertKind::Stable], $this->kinds($stable->alerts));
        $this->assertSame(AlertClass::Down, $stable->state->notified);
    }

    public function test_suppression_catch_up_waits_for_the_end_of_maintenance(): void
    {
        $policy = $this->policy();
        $down = $this->statusChange($policy, $this->upMonitor($policy), MonitorStatus::Up, MonitorStatus::Down, 0, suppressed: true);
        $this->assertTrue($down->alerts[0]->suppressed);
        $maintenance = $this->statusChange($policy, $down->state, MonitorStatus::Down, MonitorStatus::Maintenance, 30);

        $released = $this->tick($policy, $maintenance->state, 90);
        $this->assertSame([], $released->alerts, 'no down while in maintenance');
        $this->assertFalse($released->state->suppressed);
        $this->assertNull($released->state->dueAt);

        $back = $this->statusChange($policy, $released->state, MonitorStatus::Maintenance, MonitorStatus::Down, 600);
        $this->assertSame([AlertKind::Down], $this->kinds($back->alerts));
        $this->assertFalse($back->alerts[0]->suppressed);
        $this->assertSame(AlertClass::Down, $back->state->notified);
        $this->assertSame([], $this->tick($policy, $back->state, 660)->alerts);
    }

    public function test_flap_end_is_deferred_while_muted(): void
    {
        $policy = $this->policy();
        $state = $this->upMonitor($policy);
        $current = MonitorStatus::Up;
        for ($i = 1; $i <= 4; $i++) {
            $next = $i % 2 === 1 ? MonitorStatus::Down : MonitorStatus::Up;
            $state = $this->statusChange($policy, $state, $current, $next, $i * 60)->state;
            $current = $next;
        }
        $this->assertTrue($state->flapping);

        $muted = $this->statusChange($policy, $state, MonitorStatus::Up, MonitorStatus::Maintenance, 300);
        $this->assertNull($muted->state->dueAt, 'nothing to evaluate while muted');
        $inside = $this->tick($policy, $muted->state, 2000);
        $this->assertSame([], $inside->alerts);
        $this->assertTrue($inside->state->flapping);

        // The status that ends maintenance makes the overdue flap end due at once.
        $back = $this->statusChange($policy, $inside->state, MonitorStatus::Maintenance, MonitorStatus::Up, 3000);
        $this->assertSame([], $back->alerts);
        $this->assertNotNull($back->state->dueAt);
        $this->assertTrue($back->state->dueAt->lessThanOrEqualTo($this->at(3000)));
        $stable = $this->tick($policy, $back->state, 3000);
        $this->assertSame([AlertKind::Stable], $this->kinds($stable->alerts));
        $this->assertSame('No status change for 10 min', $stable->alerts[0]->message);
        $this->assertFalse($stable->state->flapping);
        $this->assertNull($stable->state->dueAt);
    }

    public function test_flapping_and_stable_are_recorded_but_not_sent_while_suppressed(): void
    {
        $policy = $this->policy();
        $state = $this->upMonitor($policy);
        $alerts = [];
        $current = MonitorStatus::Up;
        for ($i = 1; $i <= 5; $i++) {
            $next = $i % 2 === 1 ? MonitorStatus::Down : MonitorStatus::Up;
            $decision = $this->statusChange($policy, $state, $current, $next, $i * 60, suppressed: true);
            $alerts = [...$alerts, ...$decision->alerts];
            $state = $decision->state;
            $current = $next;
        }

        $flapping = array_values(array_filter($alerts, static fn (PlannedAlert $a): bool => $a->kind === AlertKind::Flapping));
        $this->assertCount(1, $flapping);
        $this->assertTrue($flapping[0]->suppressed);
        $this->assertTrue($state->suppressed);

        $stable = $this->tick($policy, $state, 1000, suppressed: true);
        $this->assertSame([AlertKind::Stable], $this->kinds($stable->alerts));
        $this->assertTrue($stable->alerts[0]->suppressed);
        $this->assertSame(AlertClass::Ok, $stable->state->notified, 'the withheld down is not marked as notified');

        $caughtUp = $this->tick($policy, $stable->state, 1060);
        $this->assertSame([AlertKind::Down], $this->kinds($caughtUp->alerts));
        $this->assertFalse($caughtUp->alerts[0]->suppressed);

        // A flapping episode that started after a delivered down and settles up sends the recovery at once.
        $delivered = $this->statusChange($policy, $this->upMonitor($policy), MonitorStatus::Up, MonitorStatus::Down, 0)->state;
        $current = MonitorStatus::Down;
        for ($i = 1; $i <= 4; $i++) {
            $next = $i % 2 === 1 ? MonitorStatus::Up : MonitorStatus::Down;
            $delivered = $this->statusChange($policy, $delivered, $current, $next, $i * 60)->state;
            $current = $next;
        }
        $settledUp = $this->statusChange($policy, $delivered, MonitorStatus::Down, MonitorStatus::Up, 300)->state;
        $this->assertTrue($settledUp->flapping);
        $this->assertSame(AlertClass::Down, $settledUp->notified);
        $end = $this->tick($policy, $settledUp, 1000, suppressed: true);
        $this->assertSame([AlertKind::Stable, AlertKind::Up], $this->kinds($end->alerts));
        $this->assertTrue($end->alerts[0]->suppressed);
        $this->assertFalse($end->alerts[1]->suppressed);
        $this->assertSame(AlertClass::Ok, $end->state->notified);
    }

    public function test_slow_flapping_ends_by_rate_and_alerts_resume(): void
    {
        $policy = $this->policy();
        $state = $this->upMonitor($policy);
        $current = MonitorStatus::Up;
        for ($i = 1; $i <= 4; $i++) {
            $next = $i % 2 === 1 ? MonitorStatus::Down : MonitorStatus::Up;
            $state = $this->statusChange($policy, $state, $current, $next, $i * 60)->state;
            $current = $next;
        }
        $this->assertTrue($state->flapping);
        $this->assertEquals($this->at(240), $state->flappingSince);

        // From here the class changes every 9 minutes: never 600 s of quiet, but well below the flap rate.
        $alerts = [];
        $stableAt = null;
        for ($t = 241; $t <= 240 + 6 * 540; $t++) {
            if (($t - 240) % 540 === 0) {
                $next = $current === MonitorStatus::Up ? MonitorStatus::Down : MonitorStatus::Up;
                $decision = $this->statusChange($policy, $state, $current, $next, $t);
                $current = $next;
            } elseif ($state->dueAt !== null && $this->at($t)->greaterThanOrEqualTo($state->dueAt)) {
                $decision = $this->tick($policy, $state, $t);
            } else {
                continue;
            }
            foreach ($decision->alerts as $alert) {
                $alerts[] = $alert->kind;
                if ($alert->kind === AlertKind::Stable) {
                    $stableAt = $t;
                }
            }
            $state = $decision->state;
        }

        // Changes at 240 and 780: the window holds fewer than two once 240 has left it, at 841.
        $this->assertSame(841, $stableAt);
        $this->assertSame([AlertKind::Stable, AlertKind::Up, AlertKind::Down, AlertKind::Up, AlertKind::Down, AlertKind::Up], $alerts);
        $this->assertFalse($state->flapping);
    }

    public function test_degraded_hold_respects_notify_degraded_turned_off(): void
    {
        $stored = new AlertSnapshot(AlertClass::Ok, AlertClass::Degraded, MonitorStatus::Degraded, null, null, $this->at(0), false, null, [], null, 0, false, $this->at(0), $this->at(300));
        $off = $this->policy(notifyDegraded: false);
        $decision = $this->tick($off, $stored, 600);
        $this->assertSame([], $decision->alerts);
        $this->assertNull($decision->state->dueAt);
        $this->assertSame([AlertKind::Degraded], $this->kinds($this->tick($this->policy(), $stored, 600)->alerts));
    }

    public function test_withheld_degraded_is_recorded_after_a_withheld_down(): void
    {
        $policy = $this->policy();
        $down = $this->statusChange($policy, $this->upMonitor($policy), MonitorStatus::Up, MonitorStatus::Down, 0, suppressed: true);
        $degraded = $this->statusChange($policy, $down->state, MonitorStatus::Down, MonitorStatus::Degraded, 100, suppressed: true);
        $this->assertSame([], $degraded->alerts, 'the hold has not ended');
        $this->assertEquals($this->at(160), $degraded->state->dueAt, 'suppression recheck before the hold end');

        $held = $this->tick($policy, $degraded->state, 400, suppressed: true);
        $this->assertSame([AlertKind::Degraded], $this->kinds($held->alerts));
        $this->assertTrue($held->alerts[0]->suppressed);
        $this->assertSame(AlertClass::Ok, $held->state->notified);
        $this->assertSame([], $this->tick($policy, $held->state, 460, suppressed: true)->alerts, 'recorded once');

        $released = $this->tick($policy, $held->state, 520);
        $this->assertSame([AlertKind::Degraded], $this->kinds($released->alerts));
        $this->assertFalse($released->alerts[0]->suppressed);
        $this->assertSame(AlertClass::Degraded, $released->state->notified);
        $this->assertEquals($this->at(100), $released->state->problemSince);
    }
}
