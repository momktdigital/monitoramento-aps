@props(['dados', 'titulo', 'tabela' => null, 'clicavel' => false, 'altura' => 'h-64'])

{{-- O gráfico é desenhado no navegador (ECharts). Cada valor também está em tabela: o gráfico nunca é a única forma de ler o dado. --}}
<div x-data="grafico" data-dados="{{ json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) }}" @if ($clicavel) data-clicavel="1" @endif class="mt-4">
    <div x-ref="area" data-area-grafico wire:ignore role="img" aria-label="Gráfico: {{ $titulo }}.{{ $tabela ? ' Os valores estão na tabela logo abaixo.' : ' Os valores estão na tabela desta página.' }}" class="{{ $altura }} w-full"></div>
</div>

@if ($tabela)
    <details class="mt-3 text-sm">
        <summary class="cursor-pointer text-xs font-medium text-marca-700 underline">Ver os valores em tabela</summary>
        <div class="mt-2 max-h-56 overflow-auto rounded-lg border border-linha">
            <table class="w-full text-left text-xs">
                <thead class="sticky top-0 bg-fundo text-tinta-suave">
                    <tr>
                        @foreach ($tabela['colunas'] as $coluna)
                            <th scope="col" class="px-3 py-2 font-medium">{{ $coluna }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-linha">
                    @foreach ($tabela['linhas'] as $linha)
                        <tr>
                            @foreach ($linha as $indice => $celula)
                                @if ($indice === 0)
                                    <th scope="row" class="px-3 py-1.5 font-medium">{{ $celula }}</th>
                                @else
                                    <td class="px-3 py-1.5 tabular-nums">{{ $celula }}</td>
                                @endif
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>
@endif
