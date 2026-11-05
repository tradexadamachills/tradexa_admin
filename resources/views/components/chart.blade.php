@props(['title', 'note' => null, 'days' => [], 'series' => [], 'money' => false])
@php
    $w = 520; $h = 170; $pad = 24;
    $n = max(count($days), 1);
    $all = collect($series)->flatMap(fn ($s) => $s['values'])->map(fn ($v) => (float) $v);
    $max = max($all->max() ?? 0, 1);
    $x = fn ($i) => $pad + ($n > 1 ? ($w - 2 * $pad) * $i / ($n - 1) : 0);
    $y = fn ($v) => $h - $pad - ($h - 2 * $pad) * ((float) $v) / $max;
    $hasData = $all->sum() > 0;
@endphp
<section class="panel chart-panel">
    <div class="panel-heading"><div><h2>{{ $title }}</h2>@if($note)<p>{{ $note }}</p>@endif</div>
        <span class="chart-legend">@foreach($series as $s)<i style="background:{{ $s['color'] }}"></i>{{ $s['label'] }}@endforeach</span></div>
    @if(! $hasData)
        <p class="chart-empty">No data in this period yet.</p>
    @else
        <svg viewBox="0 0 {{ $w }} {{ $h }}" role="img" aria-label="{{ $title }}" class="chart-svg">
            @foreach([0, .5, 1] as $g)
                <line x1="{{ $pad }}" x2="{{ $w - $pad }}" y1="{{ $h - $pad - ($h - 2 * $pad) * $g }}" y2="{{ $h - $pad - ($h - 2 * $pad) * $g }}" stroke="#dce4f2" stroke-width="1"/>
                <text x="0" y="{{ $h - $pad - ($h - 2 * $pad) * $g + 4 }}" font-size="10" fill="#8190ac">{{ $money ? number_format($max * $g) : number_format($max * $g, $max < 10 ? 1 : 0) }}</text>
            @endforeach
            @foreach($series as $s)
                @php
                    $pts = collect($s['values'])->map(fn ($v, $i) => round($x($i), 1).','.round($y($v), 1))->implode(' ');
                @endphp
                <polyline points="{{ $pts }}" fill="none" stroke="{{ $s['color'] }}" stroke-width="2.4" stroke-linejoin="round" stroke-linecap="round"/>
            @endforeach
            <text x="{{ $pad }}" y="{{ $h - 6 }}" font-size="10" fill="#8190ac">{{ $days[0] ?? '' }}</text>
            <text x="{{ $w - $pad }}" y="{{ $h - 6 }}" font-size="10" fill="#8190ac" text-anchor="end">{{ $days[$n - 1] ?? '' }}</text>
        </svg>
    @endif
</section>
