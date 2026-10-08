@php
    $campo = 'mt-1.5 block w-full rounded-lg border border-linha bg-white px-3 py-2.5 text-sm focus:border-marca-600 focus:outline-2 focus:outline-marca-600/30';
    $paleta = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100'];
    $tituloDoGrafico = 'Notas dos índices dos municípios escolhidos';
@endphp

<div class="space-y-5">
    <header>
        <h1 class="text-2xl font-semibold">Comparar municípios</h1>
        <p class="mt-1 text-sm text-tinta-suave">Coloque até {{ $maximo }} municípios lado a lado: notas dos índices e último valor de cada indicador. O endereço desta página guarda a escolha, então dá para compartilhar o link.</p>
    </header>

    <div class="flex flex-wrap items-end gap-4 rounded-2xl border border-linha bg-white p-4 shadow-sm">
        <div class="min-w-0 grow">
            <p class="text-xs font-medium uppercase tracking-wide text-tinta-suave" id="rotulo-escolhidos">Municípios na comparação</p>
            <ul class="mt-1.5 flex flex-wrap gap-2" aria-labelledby="rotulo-escolhidos">
                @foreach ($escolhidos as $id => $item)
                    <li wire:key="chip-{{ $id }}" class="inline-flex items-center gap-2 rounded-full border border-linha bg-fundo py-1 pl-3 pr-1 text-sm">
                        <span aria-hidden="true" class="inline-block size-3 rounded-full" style="background: {{ $paleta[$cores[$id] ?? 0] }}"></span>
                        <span class="font-medium">{{ $item->nome }}</span>
                        <button type="button" wire:click="remover({{ $id }})" class="flex size-6 items-center justify-center rounded-full text-tinta-suave hover:bg-white focus:outline-2 focus:outline-marca-600" aria-label="Remover {{ $item->nome }} da comparação">✕</button>
                    </li>
                @endforeach
            </ul>
        </div>

        @if ($podeAdicionar)
            <div class="min-w-56">
                <label for="novo" class="block text-xs font-medium uppercase tracking-wide text-tinta-suave">Adicionar município</label>
                <select id="novo" wire:model.live="novo" class="{{ $campo }}">
                    <option value="0">Escolha…</option>
                    @foreach ($disponiveis as $regiao => $doGrupo)
                        <optgroup label="{{ $regiao ?: 'Sem região de saúde' }}">
                            @foreach ($doGrupo as $item)
                                <option value="{{ $item->id }}">{{ $item->nome }}{{ $item->piloto ? ' ★' : '' }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>
        @else
            <p class="text-xs text-tinta-suave">Limite de {{ $maximo }} municípios. Remova um para adicionar outro.</p>
        @endif
    </div>

    <div class="grid items-start gap-5 lg:grid-cols-5">
        <div class="min-w-0 lg:col-span-3">
            <x-analise.cartao id="titulo-comparacao" titulo="Notas dos índices" :subtitulo="$competenciaRotulo ? 'Mês de referência: '.$competenciaRotulo.' · notas de 0 a 100' : null" :analise="$analise" fonte="Cálculo da plataforma a partir dos indicadores públicos"
                :secoes="[
                    'O que é' => 'As notas de 0 a 100 de cada município nos índices de necessidade (INA), desempenho (IDAPS) e nos dois pilares que mais pesam no desempenho: estrutura e resultados.',
                    'Como é calculado' => 'Cada nota compara o município com todos os municípios do estado (não só com os escolhidos aqui). A nota 50 está no meio da comparação.',
                    'Para que serve' => 'Ver, lado a lado, em que pontos um município está à frente ou atrás de outro e se isso vem da estrutura ou dos resultados em saúde.',
                    'Como interpretar' => 'Nas notas de desempenho, quanto maior, melhor. Na necessidade (INA), quanto maior, mais a população precisa da Atenção Primária; não é uma nota de qualidade.',
                ]">
                @if ($temIndices)
                    <div class="mt-4 flex flex-wrap gap-x-4 gap-y-1 text-xs" aria-label="Legenda">
                        @foreach ($escolhidos as $id => $item)
                            <span class="inline-flex items-center gap-1.5"><span aria-hidden="true" class="inline-block h-0.5 w-4 rounded" style="background: {{ $paleta[$cores[$id] ?? 0] }}"></span>{{ $item->nome }}</span>
                        @endforeach
                    </div>

                    <x-painel.grafico :dados="$grafico" :titulo="$tituloDoGrafico" altura="h-80" />
                @else
                    <div class="mt-4 rounded-lg border border-dashed border-linha px-4 py-6 text-sm text-tinta-suave">Os índices ainda não foram calculados para os municípios escolhidos. A tabela ao lado traz os indicadores disponíveis.</div>
                @endif
            </x-analise.cartao>
        </div>

        <section class="min-w-0 rounded-2xl border border-linha bg-white p-5 shadow-sm lg:col-span-2" aria-labelledby="titulo-notas">
            <h2 id="titulo-notas" class="text-sm font-semibold">Notas em números</h2>
            <div class="relative mt-3 overflow-x-auto rounded-lg border border-linha">
                <table class="w-full text-left text-sm">
                    <thead class="bg-fundo text-xs text-tinta-suave">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium">Nota</th>
                            @foreach ($escolhidos as $id => $item)
                                <th scope="col" class="px-3 py-2 text-right font-medium">{{ $item->nome }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-linha">
                        @foreach ($notas as $rotuloDaNota => $coluna)
                            <tr>
                                <th scope="row" class="px-3 py-2 font-medium">{{ $rotuloDaNota }}</th>
                                @foreach ($escolhidos as $id => $item)
                                    @php $nota = ($indices[$id] ?? null)?->{$coluna}; @endphp
                                    <td class="px-3 py-2 text-right tabular-nums">{{ $nota === null ? '—' : \App\Support\Formatador::numero($nota, 0) }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                        <tr>
                            <th scope="row" class="px-3 py-2 font-medium">Quadrante</th>
                            @foreach ($escolhidos as $id => $item)
                                @php $quadrante = ($indices[$id] ?? null)?->ipf_quadrante; @endphp
                                <td class="px-3 py-2 text-right text-xs">
                                    @if ($quadrante)
                                        <span class="inline-flex items-center justify-end gap-1"><x-analise.forma :quadrante="$quadrante->value" />{{ $quadrante->rotulo() }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <section class="rounded-2xl border border-linha bg-white p-5 shadow-sm" aria-labelledby="titulo-indicadores">
        <h2 id="titulo-indicadores" class="text-sm font-semibold">Indicadores lado a lado</h2>
        <p class="mt-0.5 text-xs text-tinta-suave">Último valor disponível de cada município. O símbolo <span aria-hidden="true" class="font-bold text-emerald-700">✓</span><span class="sr-only">de visto</span> marca a melhor situação nos indicadores em que há sentido bom ou ruim. Indicadores informativos não recebem marca.</p>

        @forelse ($grupos as $rotuloDoGrupo => $indicadoresDoGrupo)
            <div class="relative mt-4 overflow-x-auto rounded-lg border border-linha">
                <table class="w-full min-w-[32rem] text-left text-sm">
                    <caption class="bg-fundo px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-marca-700">{{ $rotuloDoGrupo }}</caption>
                    <thead class="sr-only">
                        <tr>
                            <th scope="col">Indicador</th>
                            @foreach ($escolhidos as $id => $item)
                                <th scope="col">{{ $item->nome }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-linha">
                        @foreach ($indicadoresDoGrupo as $indicadorDaLinha)
                            <tr wire:key="indicador-{{ $indicadorDaLinha->id }}">
                                <th scope="row" class="px-3 py-2 font-medium">{{ $indicadorDaLinha->nome }}</th>
                                @foreach ($escolhidos as $id => $item)
                                    @php $valor = $valores[$id][$indicadorDaLinha->id] ?? null; $ehMelhor = ($melhores[$indicadorDaLinha->id] ?? null) === $id; @endphp
                                    <td class="px-3 py-2 text-right tabular-nums">
                                        @if ($valor)
                                            <span @class(['font-semibold' => $ehMelhor])>{{ \App\Support\Formatador::valor($valor['valor'], $indicadorDaLinha) }}@if ($ehMelhor) <span aria-hidden="true" class="font-bold text-emerald-700">✓</span><span class="sr-only">(melhor situação)</span>@endif</span>
                                            <span class="block text-xs font-normal text-tinta-suave">{{ $indicadorDaLinha->rotuloDaCompetencia($valor['competencia']) }}</span>
                                        @else
                                            <span class="text-tinta-suave">—</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @empty
            <p class="mt-4 text-sm text-tinta-suave">Nenhum dos municípios escolhidos tem dados de indicadores ainda.</p>
        @endforelse
    </section>
</div>
