<div class="item">
<div class="row">
<h3>{{ $item->name }}</h3>
<span class="status {{ $item->status->value }}">{{ __('status.component.'.$item->status->value) }}</span>
</div>
@if ($item->description !== null && $item->description !== '')
<div class="muted">{{ $item->description }}</div>
@endif
@if ($item->bars !== [])
{!! \App\Status\Pages\Svg::bars($item->bars, $barTitle, __('status.bars_summary', ['name' => $item->name, 'days' => count($item->bars)]).($item->uptimePercent !== null ? ' · '.__('status.uptime').': '.$percent($item->uptimePercent) : '')) !!}
<div class="scale"><span>{{ __('status.days_ago', ['days' => count($item->bars)]) }}</span>@if ($item->uptimePercent !== null)<span>{{ __('status.uptime') }}: {{ $percent($item->uptimePercent) }}</span>@endif<span>{{ __('status.today') }}</span></div>
@elseif ($item->uptimePercent !== null)
<div class="scale"><span>{{ __('status.uptime') }}: {{ $percent($item->uptimePercent) }}</span></div>
@endif
@if ($item->latencyHourly !== null)
{!! \App\Status\Pages\Svg::sparkline($item->latencyHourly, __('status.latency')) !!}
@endif
@if ($item->children !== [])
<details>
<summary>{{ __('status.components') }} ({{ count($item->children) }})</summary>
@foreach ($item->children as $child)
@include('status.partials.item', ['item' => $child])
@endforeach
</details>
@endif
</div>
