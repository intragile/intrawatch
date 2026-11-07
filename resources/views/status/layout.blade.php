<!DOCTYPE html>
<html lang="{{ $view->locale }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="60">
<title>{{ $view->title }}</title>
<link rel="canonical" href="{{ $url }}">
<link rel="alternate" type="application/atom+xml" title="{{ $view->title }}" href="{{ $url }}/feed.atom">
@if ($page->favicon_url)
<link rel="icon" href="{{ $page->favicon_url }}">
@endif
<style>
:root{--accent: {{ $accent }};--bg:#f6f7f9;--card:#fff;--text:#1d2330;--muted:#5f6b7a;--line:#e3e6eb;
--operational:{{ \App\Status\Pages\Svg::color(\App\Enums\DisplayStatus::Operational) }};--degraded:{{ \App\Status\Pages\Svg::color(\App\Enums\DisplayStatus::Degraded) }};
--partial_outage:{{ \App\Status\Pages\Svg::color(\App\Enums\DisplayStatus::PartialOutage) }};--major_outage:{{ \App\Status\Pages\Svg::color(\App\Enums\DisplayStatus::MajorOutage) }};
--maintenance:{{ \App\Status\Pages\Svg::color(\App\Enums\DisplayStatus::Maintenance) }};--unknown:{{ \App\Status\Pages\Svg::color(\App\Enums\DisplayStatus::Unknown) }}}
@media (prefers-color-scheme:dark){:root{--bg:#12151b;--card:#1b2029;--text:#e6e9ef;--muted:#9aa4b2;--line:#2b323d}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
main{max-width:880px;margin:0 auto;padding:24px 16px 48px}
a{color:var(--accent)}
header.top{display:flex;align-items:center;gap:12px;margin-bottom:20px}
header.top img{max-height:48px;max-width:200px}
h1{font-size:1.6em;margin:0}
h2{font-size:1.15em;margin:32px 0 12px}
h3{font-size:1em;margin:0}
.card{background:var(--card);border:1px solid var(--line);border-radius:8px;padding:16px;margin-bottom:12px}
.banner{color:#fff;font-weight:600;font-size:1.1em;border:0}
.banner.operational{background:var(--operational)}.banner.degraded{background:var(--degraded)}
.banner.partial_outage{background:var(--partial_outage)}.banner.major_outage{background:var(--major_outage)}
.banner.maintenance{background:var(--maintenance)}.banner.unknown{background:var(--unknown);color:var(--text)}
.muted{color:var(--muted);font-size:.9em}
.prose p{margin:.4em 0}
.item{padding:12px 0;border-top:1px solid var(--line)}
.item:first-of-type{border-top:0}
.row{display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap}
.status{font-size:.9em;font-weight:600}
.status.operational{color:var(--operational)}.status.degraded{color:var(--degraded)}
.status.partial_outage{color:var(--partial_outage)}.status.major_outage{color:var(--major_outage)}
.status.maintenance{color:var(--maintenance)}.status.unknown{color:var(--muted)}
svg.bars{display:block;width:100%;height:30px;margin-top:8px}
svg.spark{display:block;width:100%;height:30px;margin-top:6px;fill:none;stroke:var(--accent);stroke-width:1.5}
svg.spark circle{fill:var(--accent);stroke:none}
.scale{display:flex;justify-content:space-between;font-size:.8em;color:var(--muted)}
details{margin-top:8px}
details .item{margin-left:16px}
summary{cursor:pointer;color:var(--muted);font-size:.9em}
.update{margin-top:10px;padding-left:12px;border-left:3px solid var(--line)}
.incident h3,.maintenance h3{color:var(--accent)}
footer{margin-top:40px;text-align:center}
{!! $customCss !!}
</style>
</head>
<body>
<main>
@yield('content')
</main>
</body>
</html>
