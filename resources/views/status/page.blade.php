@extends('status.layout')

@section('content')
<header class="top">
@if ($page->logo_url)
<img src="{{ $page->logo_url }}" alt="{{ $view->title }}">
@endif
<h1>{{ $view->title }}</h1>
</header>
@if ($view->descriptionHtml !== null)
<div class="prose">{!! $view->descriptionHtml !!}</div>
@endif

<div class="card banner {{ $view->overall->value }}">{{ __('status.overall.'.$view->overallLabelKey) }}</div>

@if ($view->activeMaintenance !== [])
<h2>{{ __('status.active_maintenance') }}</h2>
@foreach ($view->activeMaintenance as $maintenance)
@include('status.partials.maintenance', ['maintenance' => $maintenance, 'active' => true])
@endforeach
@endif

@if ($view->openIncidents !== [])
<h2>{{ __('status.open_incidents') }}</h2>
@foreach ($view->openIncidents as $incident)
@include('status.partials.incident', ['incident' => $incident])
@endforeach
@endif

@foreach ($view->sections as $section)
<section>
<h2>{{ $section->name }}</h2>
@if ($section->description !== null && $section->description !== '')
<p class="muted">{{ $section->description }}</p>
@endif
<div class="card">
@foreach ($section->items as $item)
@include('status.partials.item', ['item' => $item])
@endforeach
</div>
</section>
@endforeach

@if ($view->upcomingMaintenance !== [])
<h2>{{ __('status.scheduled_maintenance') }}</h2>
@foreach ($view->upcomingMaintenance as $maintenance)
@include('status.partials.maintenance', ['maintenance' => $maintenance, 'active' => false])
@endforeach
@endif

@if ($page->incident_history_days > 0)
<h2>{{ __('status.past_incidents') }}</h2>
@forelse ($view->pastIncidents as $incident)
@include('status.partials.incident', ['incident' => $incident])
@empty
<p class="muted">{{ __('status.no_incidents', ['days' => $page->incident_history_days]) }}</p>
@endforelse
@endif

<footer>
@if ($footerHtml !== null)
<div class="prose">{!! $footerHtml !!}</div>
@endif
<!--volatile--><p class="muted">{{ __('status.last_updated', ['date' => $date($view->generatedAt), 'timezone' => $view->timezone]) }}</p><!--/volatile-->
<p class="muted">{{ __('status.powered_by') }}</p>
</footer>
@endsection
