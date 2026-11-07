<?php

namespace Tests\Feature\Alerting\Senders;

use App\Alerting\Channels\Senders\OpsgenieSender;
use App\Alerting\Channels\Senders\PagerDutySender;
use App\Alerting\Transport\DeliveryFailure;
use App\Enums\AlertKind;
use App\Enums\ChannelType;
use GuzzleHttp\Psr7\Response;

class IncidentToolSenderTest extends SenderTestCase
{
    private const ROUTING_KEY = 'R0123456789abcdef0123456789abcde';

    /**
     * @return list<array<array-key, mixed>>
     */
    private function bodies(): array
    {
        $out = [];
        foreach ($this->history as $request) {
            $decoded = json_decode((string) $request->getBody(), true);
            $this->assertIsArray($decoded);
            $out[] = $decoded;
        }

        return $out;
    }

    public function test_pagerduty_and_opsgenie_trigger_and_resolve_share_a_key(): void
    {
        $pd = new PagerDutySender($this->http([new Response(202), new Response(202)]));
        $spec = $this->spec(ChannelType::PagerDuty, [], ['routing_key' => self::ROUTING_KEY]);
        $pd->send($spec, $this->notificationOf(AlertKind::Down), 'k1');
        $pd->send($spec, $this->notificationOf(AlertKind::Up, 'up'), 'k2');

        [$trigger, $resolve] = $this->bodies();
        $this->assertSame('https://events.pagerduty.com/v2/enqueue', (string) $this->history[0]->getUri());
        $this->assertSame('trigger', $trigger['event_action']);
        $this->assertSame('intrawatch-7-42', $trigger['dedup_key']);
        $this->assertSame(self::ROUTING_KEY, $trigger['routing_key']);
        $this->assertSame(['summary' => '[DOWN] Web', 'source' => 'https://x.test', 'severity' => 'critical'], $trigger['payload']);
        $this->assertSame('resolve', $resolve['event_action']);
        $this->assertSame('intrawatch-7-42', $resolve['dedup_key']);

        $og = new OpsgenieSender($this->http([new Response(202), new Response(202)]));
        $spec = $this->spec(ChannelType::Opsgenie, ['region' => 'eu'], ['api_key' => 'og-key-123456']);
        $og->send($spec, $this->notificationOf(AlertKind::Down), 'k1');
        $og->send($spec, $this->notificationOf(AlertKind::Up, 'up'), 'k2');

        $this->assertSame('https://api.eu.opsgenie.com/v2/alerts', (string) $this->history[0]->getUri());
        $this->assertSame('GenieKey og-key-123456', $this->history[0]->getHeaderLine('Authorization'));
        $this->assertSame('https://api.eu.opsgenie.com/v2/alerts/intrawatch-7-42/close?identifierType=alias', (string) $this->history[1]->getUri());
        $create = $this->bodies()[0];
        $this->assertSame('intrawatch-7-42', $create['alias']);
        $this->assertSame('P1', $create['priority']);
        $this->assertSame('[DOWN] Web', $create['message']);
        $this->assertSame('Web body', $create['description']);
    }

    public function test_pagerduty_kind_mapping(): void
    {
        $spec = $this->spec(ChannelType::PagerDuty, [], ['routing_key' => self::ROUTING_KEY]);
        $cases = [
            [AlertKind::StillDown, 'down', [], 'trigger', 'intrawatch-7-42', 'critical'],
            [AlertKind::Flapping, 'down', [], 'trigger', 'intrawatch-7-42', 'warning'],
            [AlertKind::Degraded, 'degraded', [], 'trigger', 'intrawatch-7-42', 'warning'],
            [AlertKind::Stable, 'up', [], 'resolve', 'intrawatch-7-42', null],
            [AlertKind::Stable, 'degraded', [], 'resolve', 'intrawatch-7-42', null],
            [AlertKind::Stable, 'down', [], 'trigger', 'intrawatch-7-42', 'critical'],
            [AlertKind::Expiring, 'up', [['key' => 'cert_expiry', 'old' => 1, 'new' => 2]], 'trigger', 'intrawatch-7-42-cert_expiry', 'warning'],
            [AlertKind::Renewed, 'up', [['key' => 'cert_expiry', 'old' => 1, 'new' => 2]], 'resolve', 'intrawatch-7-42-cert_expiry', null],
        ];
        foreach ($cases as [$kind, $status, $changes, $action, $key, $severity]) {
            (new PagerDutySender($this->http([new Response(202)])))->send($spec, $this->notificationOf($kind, $status, $changes), 'k');
            $body = $this->lastJson();
            $this->assertSame($action, $body['event_action'], $kind->value);
            $this->assertSame($key, $body['dedup_key'], $kind->value);
            $payload = $body['payload'] ?? [];
            $this->assertSame($severity, is_array($payload) ? ($payload['severity'] ?? null) : null, $kind->value);
        }
    }

    public function test_test_alerts_trigger_then_resolve_with_one_key(): void
    {
        (new PagerDutySender($this->http([new Response(202), new Response(202)])))
            ->send($this->spec(ChannelType::PagerDuty, [], ['routing_key' => self::ROUTING_KEY]), $this->notificationOf(AlertKind::Test, 'up'), 'abc-1');
        [$trigger, $resolve] = $this->bodies();
        $this->assertSame('trigger', $trigger['event_action']);
        $this->assertSame('resolve', $resolve['event_action']);
        $this->assertSame('iw-test-abc-1', $trigger['dedup_key']);
        $this->assertSame('iw-test-abc-1', $resolve['dedup_key']);

        (new OpsgenieSender($this->http([new Response(202), new Response(202)])))
            ->send($this->spec(ChannelType::Opsgenie, ['region' => 'us', 'priority' => 'P2'], ['api_key' => 'og-key-123456']), $this->notificationOf(AlertKind::Test, 'up'), 'abc-1');
        $this->assertCount(2, $this->history);
        $this->assertSame('P2', $this->bodies()[0]['priority']);
        $this->assertSame('https://api.opsgenie.com/v2/alerts/iw-test-abc-1/close?identifierType=alias', (string) $this->history[1]->getUri());
    }

    public function test_opsgenie_default_priority_and_close_of_unknown_alert(): void
    {
        $spec = $this->spec(ChannelType::Opsgenie, ['region' => 'us'], ['api_key' => 'og-key-123456']);
        (new OpsgenieSender($this->http([new Response(202)])))->send($spec, $this->notificationOf(AlertKind::Degraded, 'degraded'), 'k');
        $this->assertSame('P3', $this->lastJson()['priority']);

        // A 404 on close means there is nothing left to close.
        (new OpsgenieSender($this->http([new Response(404)])))->send($spec, $this->notificationOf(AlertKind::Up, 'up'), 'k');

        try {
            (new OpsgenieSender($this->http([new Response(403)])))->send($spec, $this->notificationOf(AlertKind::Up, 'up'), 'k');
            $this->fail('Expected DeliveryFailure');
        } catch (DeliveryFailure $e) {
            $this->assertTrue($e->permanent);
        }
    }

    public function test_changed_alerts_use_the_change_events_api(): void
    {
        $spec = $this->spec(ChannelType::PagerDuty, [], ['routing_key' => self::ROUTING_KEY]);
        $changes = [['key' => 'dns', 'old' => 'a', 'new' => 'b']];

        (new PagerDutySender($this->http([new Response(202)])))->send($spec, $this->notificationOf(AlertKind::Changed, 'up', $changes), 'k');

        $this->assertCount(1, $this->history);
        $this->assertSame('https://events.pagerduty.com/v2/change/enqueue', (string) $this->lastRequest()->getUri());
        $this->assertSame([
            'routing_key' => self::ROUTING_KEY,
            'payload' => [
                'summary' => '[CHANGED] Web',
                'source' => 'https://x.test',
                'timestamp' => '2026-07-01T10:30:00Z',
                'custom_details' => ['changes' => $changes],
            ],
            'links' => [['href' => 'https://iw.test/monitors/42', 'text' => 'IntraWatch']],
        ], $this->lastJson());
    }
}
