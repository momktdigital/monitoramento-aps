@php
    $kpi = $visual['kpi'];
    $ehDestaque = $visual['tipo'] === 'destaque';
@endphp

<article class="relative flex h-full flex-col rounded-2xl border border-linha bg-white p-5 shadow-sm" aria-labelledby="titulo-visual-{{ $widget->id }}">
    <header class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <h2 id="titulo-visual-{{ $widget->id }}" class="text-sm font-semibold leading-snug">{{ $visual['titulo'] }}</h2>
            @if ($visual['competencia'])
                <p class="mt-0.5 text-xs text-tinta-suave">Dado de {{ $visual['competencia'] }}</p>
            @endif
        </div>

        <div class="shrink-0">
            <x-painel.ajuda :visual="$visual" />
        </div>
    </header>

    @if ($visual['sem_dado'])
        <div class="mt-4 flex items-center gap-3 rounded-lg border border-dashed border-linha px-4 py-6 text-sm text-tinta-suave">
            <span aria-hidden="true" class="text-2xl">∅</span>
            <span>Sem dados para exibir.</span>
        </div>
    @elseif ($ehDestaque && $kpi)
        <div class="mt-4">
            <p class="flex flex-wrap items-baseline gap-x-2">
                <span class="text-4xl font-semibold tracking-tight">{{ $kpi['numero'] }}</span>
                @if ($kpi['unidade'] !== '')
                    <span class="text-sm text-tinta-suave">{{ $kpi['unidade'] }}</span>
                @endif
            </p>

            @if ($kpi['variacao'])
                @php
                    $variacao = $kpi['variacao'];
                    $icone = $variacao['direcao'] > 0 ? '▲' : ($variacao['direcao'] < 0 ? '▼' : '■');
                    $classes = $variacao['sentido'] > 0 ? 'bg-emerald-50 text-emerald-900 ring-emerald-200' : ($variacao['sentido'] < 0 ? 'bg-amber-50 text-amber-900 ring-amber-200' : 'bg-slate-100 text-slate-800 ring-slate-200');
                    $juizo = $variacao['sentido'] > 0 ? 'melhora' : ($variacao['sentido'] < 0 ? 'piora' : ($variacao['direcao'] === 0 ? 'estável' : null));
                @endphp
                <p class="mt-2">
                    <span class="inline-flex flex-wrap items-center gap-x-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset {{ $classes }}">
                        <span aria-hidden="true">{{ $icone }}</span>
                        <span class="font-semibold">{{ $variacao['direcao'] === 0 ? 'sem variação' : $variacao['texto'] }}</span>
                        <span>desde {{ $variacao['referencia'] }}</span>
                        @if ($juizo) <span>· {{ $juizo }}</span> @endif
                    </span>
                </p>
            @endif

            @if ($kpi['comparativos'])
                <dl class="mt-3 space-y-1 text-xs">
                    @foreach ($kpi['comparativos'] as $comparativo)
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-tinta-suave">{{ $comparativo['rotulo'] }}</dt>
                            <dd class="font-semibold tabular-nums">{{ $comparativo['valor'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif

            @if (count($kpi['sparkline']) >= 2)
                <div class="mt-3"><x-painel.sparkline :valores="$kpi['sparkline']" /></div>
            @endif
        </div>
    @elseif ($visual['grafico'])
        <x-painel.grafico :dados="$visual['grafico']" :titulo="$visual['titulo']" :tabela="$visual['tabela']" />
    @endif

    <div class="mt-auto"><x-painel.analise :analise="$analise" /></div>

    <p class="mt-4 text-xs text-tinta-suave">Fonte: {{ $visual['indicador']['fonte'] }}</p>
</article>
