<?php

namespace Tests\Unit\Alerting;

use App\Alerting\Messages\DefaultTemplates;
use App\Alerting\Messages\MonitorTarget;
use App\Alerting\Messages\TemplateRenderer;
use App\Enums\AlertKind;
use App\Models\Monitor;
use Tests\TestCase;

class TemplateRendererTest extends TestCase
{
    public function test_render_replaces_known_variables_and_validate_reports_unknown(): void
    {
        $this->assertSame('[DOWN] Web', TemplateRenderer::render('[{{kind_label}}] {{ monitor.name }}', ['kind_label' => 'DOWN', 'monitor.name' => 'Web']));
        $this->assertSame(['monitor.secret'], TemplateRenderer::validate('{{ monitor.secret }} {{ status }}'));
        $this->assertSame([], TemplateRenderer::validate('{{ monitor.name }} {{status}}'));
    }

    public function test_render_does_not_escape_or_reevaluate_values(): void
    {
        $this->assertSame('<b>{{ status }}</b>', TemplateRenderer::render('{{ message }}', ['message' => '<b>{{ status }}</b>', 'status' => 'x']));
    }

    public function test_every_default_template_uses_only_supported_variables(): void
    {
        $this->assertSame([], TemplateRenderer::validate(DefaultTemplates::title()));
        foreach (AlertKind::cases() as $kind) {
            $this->assertSame([], TemplateRenderer::validate(DefaultTemplates::body($kind)));
            $this->assertStringEndsWith("\n{{ monitor.url }}", DefaultTemplates::body($kind));
        }
    }

    public function test_target_never_contains_credentials_or_query(): void
    {
        $this->assertSame('https://x.test/a', MonitorTarget::describe(Monitor::factory()->http('https://u:p@x.test/a?token=1')->make(['workspace_id' => 1])));
        $this->assertSame('https://x.test', MonitorTarget::describe(Monitor::factory()->http('https://x.test:443')->make(['workspace_id' => 1])));
        $this->assertSame('http://x.test:8080/p', MonitorTarget::describe(Monitor::factory()->http('http://x.test:8080/p#f')->make(['workspace_id' => 1])));
        $this->assertSame('db.test:5432', MonitorTarget::describe(Monitor::factory()->tcp('db.test', 5432)->make(['workspace_id' => 1])));
        $this->assertSame('db.test', MonitorTarget::describe(Monitor::factory()->ping('db.test')->make(['workspace_id' => 1])));
        $this->assertSame('a.test (MX)', MonitorTarget::describe(Monitor::factory()->dns('a.test', 'MX')->make(['workspace_id' => 1])));
        $this->assertSame('a.test', MonitorTarget::describe(Monitor::factory()->domain('a.test')->make(['workspace_id' => 1])));
        $this->assertSame('push', MonitorTarget::describe(Monitor::factory()->push()->make(['workspace_id' => 1])));
    }
}
