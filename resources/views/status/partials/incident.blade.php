<article class="card incident" id="incident-{{ $incident->id }}">
<div class="row">
<h3>{{ $incident->title }}</h3>
<span class="status {{ $incident->status->isOpen() ? $incident->impact->displayStatus()->value : 'operational' }}">{{ __('status.incident_status.'.$incident->status->value) }}</span>
</div>
<div class="muted">
{{ __('status.started', ['date' => $date($incident->startedAt)]) }}@if ($incident->resolvedAt !== null) · {{ __('status.resolved_at', ['date' => $date($incident->resolvedAt)]) }}@endif
@if ($incident->status->isOpen()) · {{ __('status.impact.'.$incident->impact->value) }}@endif
</div>
@if ($incident->affectedComponents !== [])
<div class="muted">{{ __('status.affected', ['components' => implode(', ', $incident->affectedComponents)]) }}</div>
@endif
@foreach ($incident->updates as $update)
<div class="update">
<div><strong>{{ __('status.incident_status.'.$update->status->value) }}</strong> <span class="muted">{{ $date($update->createdAt) }}</span></div>
<div class="prose">{!! $update->messageHtml !!}</div>
</div>
@endforeach
</article>
