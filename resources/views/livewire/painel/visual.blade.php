@php
    $kpi = $visual['kpi'];
    $tipo = \App\Enums\TipoDeVisual::from($visual['tipo']);
    $ehDestaque = $tipo === \App\Enums\TipoDeVisual::Destaque;
    $temGrafico = (bool) $visual['grafico'];
    $temTabela = (bool) $visual['tabela'];
    $clicavel = in_array($tipo, [\App\Enums\TipoDeVisual::Ranking, \App\Enums\TipoDeVisual::Mapa, \App\Enums\TipoDeVisual::Matriz], true);
    $altura = match ($tipo) {
        \App\Enums\TipoDeVisual::Mapa => 'h-72 group-data-[expandido=sim]/cartao:h-[62vh]',
        \App\Enums\TipoDeVisual::Matriz => 'h-80 group-data-[expandido=sim]/cartao:h-[62vh]',
        default => 'h-64 group-data-[expandido=sim]/cartao:h-[55vh]',
    };
    $ferramenta = 'inline-flex size-7 items-center justify-center rounded-lg border border-linha bg-white text-tinta-suave hover:bg-marca-50 hover:text-marca-700 focus:outline-2 focus:outline-offset-2 focus:outline-marca-600';
@endphp

{{-- A raiz do componente Livewire é um div simples; o Alpine do cartão fica numa <section> de dentro. Ao trocar o esqueleto
     "Carregando" pelo conteúdo, o Livewire reaproveita elementos de mesma tag: com <section> (e a chave) o escopo nasce
     como elemento novo, já completo, e os filhos encontram os dados do cartão. --}}
<div class="h-full">
<section x-data="cartao" wire:key="cartao-{{ $widget->id }}" class="h-full" x-on:keydown.escape.window="fechar" data-nome="{{ \Illuminate\Support\Str::slug($visual['titulo']) }}">
    <div hidden x-bind:hidden="fechado" x-on:click="alternarExpansao" class="fixed inset-0 z-40 bg-slate-900/50" aria-hidden="true"></div>

    <article x-bind:class="classes" x-bind:data-expandido="estado" data-expandido="nao"
        class="group/cartao relative flex h-full flex-col rounded-2xl border border-linha bg-white p-5 shadow-sm" aria-labelledby="titulo-visual-{{ $widget->id }}">
        <header class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 id="titulo-visual-{{ $widget->id }}" class="text-sm font-semibold leading-snug">{{ $visual['titulo'] }}</h2>
                @if ($visual['competencia'])
                    <p class="mt-0.5 text-xs text-tinta-suave">Dado de {{ $visual['competencia'] }}</p>
                @endif
            </div>

            <div class="flex shrink-0 items-center gap-1">
                @if ($temGrafico && $temTabela && ! $visual['sem_dado'])
                    <button type="button" x-bind:hidden="mostraTabela" x-on:click="alternarVista" class="{{ $ferramenta }}" title="Ver os valores em tabela" aria-label="Ver os valores em tabela"><x-ui.icone nome="tabela" /></button>
                    <button type="button" hidden x-bind:hidden="mostraGrafico" x-on:click="alternarVista" class="{{ $ferramenta }}" title="Voltar ao gráfico" aria-label="Voltar ao gráfico"><x-ui.icone nome="grafico" /></button>
                    <button type="button" x-bind:hidden="mostraTabela" x-on:click="baixarImagem" class="{{ $ferramenta }}" title="Baixar o gráfico como imagem (PNG)" aria-label="Baixar o gráfico como imagem (PNG)"><x-ui.icone nome="imagem" /></button>
                    <button type="button" x-on:click="baixarCsv" class="{{ $ferramenta }}" title="Baixar os valores em planilha (CSV)" aria-label="Baixar os valores em planilha (CSV)"><x-ui.icone nome="baixar" /></button>
                    <button type="button" x-bind:hidden="expandido" x-on:click="alternarExpansao" class="{{ $ferramenta }}" title="Ampliar este visual" aria-label="Ampliar este visual"><x-ui.icone nome="expandir" /></button>
                    <button type="button" hidden x-bind:hidden="fechado" x-on:click="alternarExpansao" class="{{ $ferramenta }}" title="Fechar a ampliação (Esc)" aria-label="Fechar a ampliação"><x-ui.icone nome="reduzir" /></button>
                @endif
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

                @if ($kpi['faixa'])
                    @php $faixa = $kpi['faixa']; @endphp
                    <div class="mt-4" role="group" aria-label="Posição entre os municípios da região de saúde">
                        <p class="flex items-center justify-between gap-2 text-xs">
                            <span class="font-medium">Entre os municípios da região</span>
                            @if ($faixa['posicao'])
                                <span class="rounded-full bg-fundo px-2 py-0.5 font-semibold tabular-nums ring-1 ring-inset ring-linha">{{ $faixa['posicao'] }}ª de {{ $faixa['total'] }}</span>
                            @endif
                        </p>
                        <div class="relative mt-3 h-1.5 rounded-full bg-linha" role="img"
                            aria-label="{{ $kpi['valor'] }}, entre o menor valor da região ({{ $faixa['minimo'] }}) e o maior ({{ $faixa['maximo'] }}). A mediana da região é {{ $faixa['mediana'] }}.">
                            <span class="absolute -top-1 h-3.5 w-0.5 -translate-x-1/2 rounded bg-slate-500" style="left: {{ $faixa['x_mediana'] }}%" title="Mediana da região: {{ $faixa['mediana'] }}"></span>
                            <span class="absolute top-1/2 size-3.5 -translate-x-1/2 -translate-y-1/2 rounded-full bg-[#2a78d6] ring-2 ring-white" style="left: {{ $faixa['x_valor'] }}%" title="Este município: {{ $faixa['valor'] }}"></span>
                        </div>
                        <div class="mt-1.5 flex items-start justify-between gap-2 text-[0.7rem] leading-tight tabular-nums text-tinta-suave">
                            <span>menor<br><strong class="font-semibold text-tinta">{{ $faixa['minimo'] }}</strong></span>
                            <span class="text-center"><span aria-hidden="true">▏</span> mediana<br><strong class="font-semibold text-tinta">{{ $faixa['mediana'] }}</strong></span>
                            <span class="text-right">maior<br><strong class="font-semibold text-tinta">{{ $faixa['maximo'] }}</strong></span>
                        </div>
                        @if ($faixa['melhor_e_maior'] || $faixa['melhor_e_menor'])
                            <p class="mt-1.5 text-[0.7rem] text-tinta-suave">{{ $faixa['melhor_e_maior'] ? 'Quanto mais à direita, melhor.' : 'Quanto mais à esquerda, melhor.' }}</p>
                        @endif
                    </div>
                @endif

                @php $estado = collect($kpi['comparativos'])->firstWhere('rotulo', 'Mediana do estado'); @endphp
                @if ($estado)
                    <dl class="mt-3 text-xs">
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-tinta-suave">{{ $estado['rotulo'] }}</dt>
                            <dd class="font-semibold tabular-nums">{{ $estado['valor'] }}</dd>
                        </div>
                    </dl>
                @endif

                @if (count($kpi['sparkline']) >= 2)
                    <div class="mt-3">
                        <x-painel.sparkline :pontos="$kpi['sparkline']" />
                        <p class="mt-0.5 text-[0.7rem] text-tinta-suave">Últimos {{ count($kpi['sparkline']) }} valores · passe o mouse sobre a linha</p>
                    </div>
                @endif
            </div>
        @elseif ($temGrafico)
            {{-- Gráfico (vista padrão) --}}
            <div x-bind:hidden="mostraTabela">
                @if ($tipo === \App\Enums\TipoDeVisual::Ranking)
                    <div class="mt-3 inline-flex rounded-lg border border-linha bg-fundo p-0.5 text-xs" role="group" aria-label="Comparar com">
                        @foreach (['regiao' => 'Região de saúde', 'estado' => 'Estado'] as $valor => $rotulo)
                            <button type="button" wire:click="definirEscopo('{{ $valor }}')" aria-pressed="{{ $visual['escopo'] === $valor ? 'true' : 'false' }}"
                                @class([
                                    'rounded-md px-2.5 py-1 font-medium focus:outline-2 focus:outline-offset-1 focus:outline-marca-600',
                                    'bg-white text-marca-900 shadow-xs ring-1 ring-linha' => $visual['escopo'] === $valor,
                                    'text-tinta-suave hover:text-tinta' => $visual['escopo'] !== $valor,
                                ])>{{ $rotulo }}</button>
                        @endforeach
                    </div>
                @endif

                @if ($visual['legenda'])
                    @php $legenda = $visual['legenda']; @endphp
                    @if ($legenda['tipo'] === 'faixas')
                        <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs" aria-label="Legenda do mapa">
                            <span class="font-medium text-tinta-suave">Menor</span>
                            @foreach ($legenda['faixas'] as $faixaDoMapa)
                                <span class="inline-flex items-center gap-1.5"><span aria-hidden="true" class="inline-block size-3 rounded-sm" style="background: {{ $faixaDoMapa['cor'] }}"></span>{{ $faixaDoMapa['rotulo'] }}</span>
                            @endforeach
                            <span class="font-medium text-tinta-suave">Maior</span>
                            @if ($legenda['sem_dado'] > 0)
                                <span class="inline-flex items-center gap-1.5"><span aria-hidden="true" class="inline-block size-3 rounded-sm border border-linha" style="background: #e8e6df"></span>Sem dado</span>
                            @endif
                        </div>
                    @else
                        <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs" aria-label="Legenda dos quadrantes">
                            @foreach ($legenda['itens'] as $item)
                                <span class="inline-flex items-center gap-1.5"><x-analise.forma :quadrante="$item['quadrante']" />{{ $item['rotulo'] }} <span class="text-tinta-suave">({{ $item['quantidade'] }})</span></span>
                            @endforeach
                            <span class="inline-flex items-center gap-1.5"><span aria-hidden="true" class="inline-block size-3 rounded-full border-2 border-[#2a78d6] bg-white"></span>Município em foco</span>
                        </div>
                    @endif
                @elseif ($tipo === \App\Enums\TipoDeVisual::Ranking)
                    <p class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs">
                        <span class="inline-flex items-center gap-1.5"><span aria-hidden="true" class="inline-block size-3 rounded-sm bg-[#2a78d6]"></span>Município em foco</span>
                        <span class="inline-flex items-center gap-1.5"><span aria-hidden="true" class="inline-block size-3 rounded-sm bg-[#b9b8b0]"></span>Demais municípios</span>
                    </p>
                @endif

                <x-painel.grafico :dados="$visual['grafico']" :titulo="$visual['titulo']" :clicavel="$clicavel" :altura="$altura" />

                <p class="mt-2 text-xs text-tinta-suave">{{ $tipo->dica() }}</p>
            </div>

            {{-- Mesmos valores, em tabela --}}
            @if ($temTabela)
                <div hidden x-bind:hidden="mostraGrafico" class="mt-4 max-h-80 overflow-auto rounded-lg border border-linha group-data-[expandido=sim]/cartao:max-h-[62vh]">
                    <table class="w-full text-left text-xs" data-tabela>
                        <caption class="sr-only">{{ $visual['titulo'] }}</caption>
                        <thead class="sticky top-0 bg-fundo text-tinta-suave">
                            <tr>
                                @foreach ($visual['tabela']['colunas'] as $coluna)
                                    <th scope="col" class="px-3 py-2 font-medium">{{ $coluna }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-linha">
                            @foreach ($visual['tabela']['linhas'] as $linha)
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
            @endif
        @endif

        <div class="mt-auto"><x-painel.analise :analise="$analise" /></div>

        <p class="mt-4 text-xs text-tinta-suave">Fonte: {{ $visual['indicador']['fonte'] }}</p>
    </article>
</section>
</div>
