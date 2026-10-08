@props(['id', 'titulo', 'subtitulo' => null, 'secoes' => [], 'analise' => null, 'fonte' => null])

{{-- Cartão das telas de análise: título, "?" com a explicação, conteúdo, análise do cenário e fonte (mesma estrutura dos visuais do painel). --}}
<article class="relative flex h-full flex-col rounded-2xl border border-linha bg-white p-5 shadow-sm" aria-labelledby="{{ $id }}">
    <header class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <h2 id="{{ $id }}" class="text-sm font-semibold leading-snug">{{ $titulo }}</h2>
            @if ($subtitulo)
                <p class="mt-0.5 text-xs text-tinta-suave">{{ $subtitulo }}</p>
            @endif
        </div>

        @if ($secoes)
            <div class="shrink-0"><x-painel.ajuda-geral :titulo="$titulo" :secoes="$secoes" :rodape="$fonte" /></div>
        @endif
    </header>

    {{ $slot }}

    @if ($analise)
        <div class="mt-auto"><x-painel.analise :analise="$analise" /></div>
    @endif

    @if ($fonte)
        <p class="mt-4 text-xs text-tinta-suave">Fonte: {{ $fonte }}</p>
    @endif
</article>
