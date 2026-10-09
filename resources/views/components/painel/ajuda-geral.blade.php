@props(['titulo', 'secoes', 'rodape' => null])

{{-- Botão "?" para telas de análise (matriz, mapa, comparação): mesmo balão do painel, com textos informados diretamente. --}}
<div x-data="ajuda" x-on:keydown.escape.window="fechar">
    <button type="button" x-on:click="alternar" :aria-expanded="aberto" aria-haspopup="dialog"
        aria-label="Como ler este visual: {{ $titulo }}"
        class="flex size-7 items-center justify-center rounded-full border border-linha bg-white text-sm font-semibold text-marca-700 hover:bg-marca-50 focus:outline-2 focus:outline-offset-2 focus:outline-marca-600">?</button>

    <div hidden x-bind:hidden="oculto" x-on:click.outside="fecharFora" role="dialog" aria-label="Explicação: {{ $titulo }}"
        class="absolute inset-x-3 top-14 z-30 max-h-[28rem] overflow-y-auto rounded-xl border border-linha bg-white p-4 text-left shadow-xl">
        <div class="flex items-start justify-between gap-3">
            <p class="text-sm font-semibold">{{ $titulo }}</p>
            <button type="button" x-on:click="fechar" class="rounded p-0.5 text-tinta-suave hover:bg-fundo" aria-label="Fechar explicação">✕</button>
        </div>

        <dl class="mt-3 space-y-3 text-sm">
            @foreach ($secoes as $titulo_da_secao => $texto)
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-marca-700">{{ $titulo_da_secao }}</dt>
                    <dd class="mt-0.5 leading-relaxed text-tinta">{{ $texto }}</dd>
                </div>
            @endforeach
        </dl>

        @if ($rodape)
            <p class="mt-3 border-t border-linha pt-3 text-xs text-tinta-suave">Fonte: {{ $rodape }}</p>
        @endif
    </div>
</div>
