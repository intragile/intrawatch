<article class="card maintenance" id="maintenance-{{ $maintenance->id }}">
<div class="row">
<h3>{{ $maintenance->title }}</h3>
@if ($active)
<span class="status maintenance">{{ __('status.in_progress') }}</span>
@endif
</div>
<div class="muted">
@if ($active)
{{ $maintenance->endsAt !== null ? __('status.until', ['date' => $date($maintenance->endsAt)]) : __('status.until_further_notice') }}
@else
{{ __('status.window', ['start' => $date($maintenance->startsAt), 'end' => $maintenance->endsAt !== null ? $date($maintenance->endsAt) : __('status.until_further_notice')]) }}
@endif
</div>
@if ($maintenance->affectedComponents !== [])
<div class="muted">{{ __('status.affected', ['components' => implode(', ', $maintenance->affectedComponents)]) }}</div>
@endif
@if ($maintenance->descriptionHtml !== null)
<div class="prose">{!! $maintenance->descriptionHtml !!}</div>
@endif
</article>
