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
@else
<link rel="icon" href="/favicon.ico" sizes="48x48">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
@endif
<style>
@font-face{font-family:"Barlow";font-weight:400;font-display:swap;src:url(/fonts/barlow-latin-400-normal.woff2) format("woff2");unicode-range:U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD}
@font-face{font-family:"Barlow";font-weight:400;font-display:swap;src:url(/fonts/barlow-latin-ext-400-normal.woff2) format("woff2");unicode-range:U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+0304,U+0308,U+0329,U+1D00-1DBF,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF}
@font-face{font-family:"Barlow";font-weight:600;font-display:swap;src:url(/fonts/barlow-latin-600-normal.woff2) format("woff2");unicode-range:U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD}
@font-face{font-family:"Barlow";font-weight:600;font-display:swap;src:url(/fonts/barlow-latin-ext-600-normal.woff2) format("woff2");unicode-range:U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+0304,U+0308,U+0329,U+1D00-1DBF,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF}
@font-face{font-family:"Barlow Semi Condensed";font-weight:600;font-display:swap;src:url(/fonts/barlow-semi-condensed-latin-600-normal.woff2) format("woff2");unicode-range:U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD}
@font-face{font-family:"Barlow Semi Condensed";font-weight:600;font-display:swap;src:url(/fonts/barlow-semi-condensed-latin-ext-600-normal.woff2) format("woff2");unicode-range:U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+0304,U+0308,U+0329,U+1D00-1DBF,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF}

:root{--accent: {{ $accent }};--bg:#f2f4f7;--card:#fff;--text:#17202b;--muted:#526070;--line:#d3dae2;
@foreach (\App\Enums\DisplayStatus::cases() as $status)
@if ($status === \App\Enums\DisplayStatus::Unknown)
--unknown:#b5bdc8;--b-unknown:{{ \App\Status\Pages\Svg::color($status) }};
@else
--{{ $status->value }}:{{ \App\Status\Pages\Svg::color($status) }};--b-{{ $status->value }}:var(--{{ $status->value }});
@endif
@endforeach
--t-operational:#157f4d;--t-degraded:#8c5b00;--t-partial_outage:#a84b00;--t-major_outage:#bf2d35;--t-maintenance:#6448b8;--t-unknown:var(--muted)}
@media (prefers-color-scheme:dark){:root{--bg:#111821;--card:#18212c;--text:#e6eaf0;--muted:#9aa6b6;--line:#2a3544;
--operational:#2fa36a;--degraded:#d69a1c;--partial_outage:#df7a36;--major_outage:#e0464e;--maintenance:#8467d6;--unknown:#465163;--b-unknown:#243040;
--t-operational:#55c990;--t-degraded:#ebbb55;--t-partial_outage:#f2a35e;--t-major_outage:#f4878b;--t-maintenance:#b19ef3}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:400 15px/1.5 Barlow,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;font-variant-numeric:tabular-nums}
main{max-width:860px;margin:0 auto;padding:32px 16px 56px}
a{color:var(--accent)}
:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
h1,h2,.banner{font-family:"Barlow Semi Condensed",Barlow,system-ui,sans-serif;font-weight:600;letter-spacing:0}
header.top{display:flex;align-items:center;gap:14px;margin-bottom:24px}
header.top img{max-height:48px;max-width:200px}
h1{font-size:1.75rem;line-height:1.15;margin:0}
h2{font-size:1.25rem;line-height:1.25;margin:36px 0 12px}
h3{font-size:1rem;font-weight:600;margin:0}
.card{background:var(--card);border:1px solid var(--line);border-radius:8px;padding:16px 18px;margin-bottom:12px}
.banner{display:flex;align-items:center;gap:14px;font-size:1.75rem;line-height:1.2;padding:22px 20px;margin-top:20px;border-left:6px solid var(--unknown)}
.banner::before{content:"";flex:none;width:18px;height:18px;border-radius:4px;background:var(--unknown)}
.banner.operational{border-left-color:var(--operational)}.banner.operational::before{background:var(--operational)}
.banner.degraded{border-left-color:var(--degraded)}.banner.degraded::before{background:var(--degraded)}
.banner.partial_outage{border-left-color:var(--partial_outage)}.banner.partial_outage::before{background:var(--partial_outage)}
.banner.major_outage{border-left-color:var(--major_outage)}.banner.major_outage::before{background:repeating-linear-gradient(135deg,var(--major_outage) 0 3px,transparent 3px 6px),var(--major_outage);box-shadow:inset 0 0 0 2px var(--major_outage)}
.banner.maintenance{border-left-color:var(--maintenance)}.banner.maintenance::before{background:var(--maintenance)}
.muted{color:var(--muted);font-size:.9em}
.prose{max-width:72ch}
.prose p{margin:.4em 0}
.item{padding:14px 0;border-top:1px solid var(--line)}
.item:first-of-type{border-top:0;padding-top:4px}
.item:last-child{padding-bottom:4px}
.row{display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap}
.status{font-size:.9em;font-weight:600}
.status::before{content:"";display:inline-block;width:9px;height:9px;border-radius:2px;margin-right:7px;background:currentColor;vertical-align:1px}
.status.operational{color:var(--t-operational)}.status.degraded{color:var(--t-degraded)}
.status.partial_outage{color:var(--t-partial_outage)}.status.major_outage{color:var(--t-major_outage)}
.status.maintenance{color:var(--t-maintenance)}.status.unknown{color:var(--t-unknown)}
svg.bars{display:block;width:100%;height:30px;margin-top:10px}
svg.bars .b-operational{fill:var(--b-operational);stroke:var(--b-operational)}svg.bars .b-degraded{fill:var(--b-degraded);stroke:var(--b-degraded)}
svg.bars .b-partial_outage{fill:var(--b-partial_outage);stroke:var(--b-partial_outage)}svg.bars .b-major_outage{fill:var(--b-major_outage);stroke:var(--b-major_outage)}
svg.bars .b-maintenance{fill:var(--b-maintenance);stroke:var(--b-maintenance)}svg.bars .b-unknown{fill:var(--b-unknown);stroke:var(--b-unknown)}
svg.bars path[class]{fill:none}
svg.spark{display:block;width:100%;height:30px;margin-top:6px;fill:none;stroke:var(--accent);stroke-width:1.5}
svg.spark circle{fill:var(--accent);stroke:none}
.scale{display:flex;justify-content:space-between;gap:8px;margin-top:4px;font-size:.8125em;color:var(--muted)}
details{margin-top:10px}
details .item{margin-left:16px}
summary{cursor:pointer;color:var(--muted);font-size:.9em}
.update{margin-top:12px;padding-left:12px;border-left:3px solid var(--line)}
.incident h3,.maintenance h3{color:var(--text)}
footer{margin-top:48px;padding-top:16px;border-top:1px solid var(--line);text-align:center}
footer .muted{margin:4px 0}
@media (max-width:520px){h1{font-size:1.5rem}.banner{font-size:1.4rem;padding:18px 16px}}
{!! $customCss !!}
</style>
</head>
<body>
<main>
@yield('content')
</main>
</body>
</html>
