@props(['analise'])

@php
    $estilos = [
        'favoravel' => ['bg-emerald-50 text-emerald-900 ring-emerald-200', '✓'],
        'atencao' => ['bg-amber-50 text-amber-900 ring-amber-200', '!'],
        'intermediario' => ['bg-sky-50 text-sky-900 ring-sky-200', '◐'],
        'informativo' => ['bg-slate-100 text-slate-800 ring-slate-200', 'i'],
        'sem_dado' => ['bg-slate-100 text-slate-700 ring-slate-200', '∅'],
    ];
    [$classes, $icone] = $estilos[$analise->tom] ?? $estilos['informativo'];
@endphp

<section class="mt-5 border-t border-linha pt-4" aria-label="Análise do cenário atual">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-xs font-semibold uppercase tracking-wide text-tinta-suave">Análise do cenário atual</h3>
        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset {{ $classes }}">
            <span aria-hidden="true" class="font-bold">{{ $icone }}</span>{{ $analise->rotuloDoTom() }}
        </span>
    </div>

    <div class="mt-2 space-y-2 text-sm leading-relaxed text-tinta">
        @foreach ($analise->paragrafos as $paragrafo)
            <p>{{ $paragrafo }}</p>
        @endforeach
    </div>
</section>
