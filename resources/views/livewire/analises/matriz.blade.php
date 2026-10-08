@php
    $campo = 'mt-1.5 block w-full rounded-lg border border-linha bg-white px-3 py-2.5 text-sm focus:border-marca-600 focus:outline-2 focus:outline-marca-600/30';
    $titulo = 'Matriz de prioridade: necessidade × desempenho';
@endphp

<div class="space-y-5">
    <header>
        <h1 class="text-2xl font-semibold">Matriz de prioridade (IPF)</h1>
        <p class="mt-1 text-sm text-tinta-suave">Cruza o quanto a população precisa da Atenção Primária (INA) com o quanto ela está funcionando (IDAPS) para mostrar onde o apoio tem mais potencial.</p>
    </header>

    <div class="flex flex-wrap items-end gap-4 rounded-2xl border border-linha bg-white p-4 shadow-sm">
        <div class="min-w-56 grow sm:grow-0">
            <label for="municipio" class="block text-xs font-medium uppercase tracking-wide text-tinta-suave">Município em foco</label>
            <select id="municipio" wire:model.live="municipioId" class="{{ $campo }}">
                @foreach ($municipios as $regiao => $doGrupo)
                    <optgroup label="{{ $regiao ?: 'Sem região de saúde' }}">
                        @foreach ($doGrupo as $item)
                            <option value="{{ $item->id }}">{{ $item->nome }}{{ $item->piloto ? ' ★' : '' }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </div>

        @unless ($semIndices)
            <div class="min-w-44">
                <label for="competencia" class="block text-xs font-medium uppercase tracking-wide text-tinta-suave">Mês de referência</label>
                <select id="competencia" wire:model.live="competencia" class="{{ $campo }}">
                    @foreach ($competencias as $valor => $rotulo)
                        <option value="{{ $valor }}" @selected($valor === $competenciaAtual)>{{ $rotulo }}</option>
                    @endforeach
                </select>
            </div>
        @endunless
    </div>

    @if ($semIndices)
        <div class="rounded-2xl border border-dashed border-linha bg-white p-10 text-center">
            <p class="font-medium">Os índices ainda não foram calculados.</p>
            <p class="mt-1 text-sm text-tinta-suave">Eles são calculados automaticamente depois que as integrações carregam os dados. Veja o andamento na tela de Integrações ou leia como o cálculo funciona na página Metodologia.</p>
            <p class="mt-4"><a href="{{ route('metodologia') }}" class="text-sm font-medium text-marca-700 underline">Ler a metodologia</a></p>
        </div>
    @else
        @if ($naMatriz < $totalDeMunicipios)
            <div role="status" class="rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
                <strong>{{ $naMatriz }} de {{ $totalDeMunicipios }} municípios</strong> estão na matriz neste mês.
                @if ($naMatriz === 0)
                    A necessidade (INA) ainda não pôde ser calculada: faltam dados de vulnerabilidade social (como Bolsa Família e BPC) para os municípios do estado. Enquanto isso, veja o ranking de desempenho (IDAPS) abaixo.
                @else
                    Os demais ainda não têm os dois índices calculados.
                @endif
            </div>
        @endif

        @if ($naMatriz > 0)
            <div class="grid items-start gap-5 lg:grid-cols-3">
                <div class="min-w-0 lg:col-span-2">
                    <x-analise.cartao id="titulo-matriz" :titulo="$titulo" :subtitulo="'Mês de referência: '.$competenciaRotulo" :analise="$analise" fonte="Cálculo da plataforma a partir de e-Gestor APS, CNES, IBGE, SIH, SIM, SINASC e Portal da Transparência"
                        :secoes="[
                            'O que é' => 'Um gráfico em que cada ponto é um município. Para a direita, quanto maior a necessidade da população (INA); para cima, quanto melhor o desempenho da Atenção Primária (IDAPS).',
                            'Como é calculado' => 'As duas notas vão de 0 a 100 e comparam o município com os demais do estado. As linhas tracejadas marcam a mediana de cada índice no mês e dividem o gráfico em quatro quadrantes.',
                            'Para que serve' => 'Mostrar onde a necessidade é alta e o desempenho é baixo (prioridade máxima) e onde o investimento já mostra resultado (grande potencial), para orientar o apoio e o financiamento.',
                            'Como interpretar' => 'A posição é relativa: ficar em um quadrante não é uma sentença, e sim uma comparação com os outros municípios do estado. Leia junto com os indicadores que compõem cada índice.',
                        ]">
                        <div class="mt-4 flex flex-wrap gap-x-5 gap-y-1.5 text-xs" aria-label="Legenda dos quadrantes">
                            @foreach ($quadrantes as $quadranteDaLegenda)
                                <span class="inline-flex items-center gap-1.5"><x-analise.forma :quadrante="$quadranteDaLegenda->value" />{{ $quadranteDaLegenda->rotulo() }} <span class="text-tinta-suave">({{ $resumo[$quadranteDaLegenda->value] }})</span></span>
                            @endforeach
                            <span class="inline-flex items-center gap-1.5"><span aria-hidden="true" class="inline-block size-3 rounded-full border-2 border-[#2a78d6] bg-white"></span>Município em foco</span>
                        </div>

                        <x-painel.grafico :dados="$grafico" :titulo="$titulo" altura="h-[26rem]" :clicavel="true" />
                    </x-analise.cartao>
                </div>

                <div class="min-w-0 space-y-5">
                    <section class="rounded-2xl border border-linha bg-white p-5 shadow-sm" aria-labelledby="titulo-foco">
                        <h2 id="titulo-foco" class="text-sm font-semibold">{{ $municipio->nome }}</h2>
                        <p class="text-xs text-tinta-suave">{{ \Illuminate\Support\Str::title(mb_strtolower((string) $municipio->regiao_saude_nome)) }} · {{ $competenciaRotulo }}</p>

                        @if ($selecionada && $selecionada->ipf_quadrante)
                            <p class="mt-3 inline-flex items-center gap-2 rounded-full bg-fundo px-3 py-1 text-xs font-semibold ring-1 ring-inset ring-linha"><x-analise.forma :quadrante="$selecionada->ipf_quadrante->value" />{{ $selecionada->ipf_quadrante->rotulo() }}</p>
                        @endif

                        <div class="mt-4 space-y-3">
                            <x-analise.nota rotulo="Necessidade (INA)" :valor="$selecionada?->ina" />
                            <x-analise.nota rotulo="Desempenho (IDAPS)" :valor="$selecionada?->idaps" />
                            @foreach (($selecionada?->detalhes['idaps']['pilares'] ?? []) as $chave => $pilar)
                                <x-analise.nota :rotulo="config('indices.idaps.pilares.'.$chave.'.nome', $chave)" :valor="$pilar['nota']" />
                            @endforeach
                        </div>

                        @if ($selecionada?->estrutura_sem_resultado)
                            <p class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900"><span aria-hidden="true" class="font-bold">!</span> Estrutura forte e resultado fraco: a cobertura está entre as mais altas, mas os resultados em saúde ficam abaixo.</p>
                        @endif

                        @if ($posicaoNoRanking)
                            <p class="mt-4 text-xs text-tinta-suave">{{ $posicaoNoRanking }}ª em prioridade de apoio, entre {{ $naMatriz }} municípios.</p>
                        @endif
                    </section>

                    <section class="rounded-2xl border border-linha bg-white p-5 shadow-sm" aria-labelledby="titulo-quadrantes">
                        <h2 id="titulo-quadrantes" class="text-sm font-semibold">O que significa cada quadrante</h2>
                        <dl class="mt-3 space-y-3 text-sm">
                            @foreach ($quadrantes as $quadranteDaLegenda)
                                <div>
                                    <dt class="flex items-center gap-1.5 font-medium"><x-analise.forma :quadrante="$quadranteDaLegenda->value" />{{ $quadranteDaLegenda->rotulo() }}</dt>
                                    <dd class="mt-0.5 text-xs leading-relaxed text-tinta-suave">{{ $quadranteDaLegenda->descricao() }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </section>
                </div>
            </div>

            <section class="rounded-2xl border border-linha bg-white p-5 shadow-sm" aria-labelledby="titulo-ranking">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h2 id="titulo-ranking" class="text-sm font-semibold">Ranking de prioridade de apoio</h2>
                        <p class="mt-0.5 text-xs text-tinta-suave">Do município com maior necessidade e menor desempenho para o menor. Clique no nome para colocá-lo em foco.</p>
                    </div>

                    <div class="flex flex-wrap gap-3">
                        <div>
                            <label for="filtro-quadrante" class="block text-xs font-medium uppercase tracking-wide text-tinta-suave">Quadrante</label>
                            <select id="filtro-quadrante" wire:model.live="quadrante" class="{{ $campo }} min-w-48">
                                <option value="0">Todos</option>
                                @foreach ($quadrantes as $opcao)
                                    <option value="{{ $opcao->value }}">{{ $opcao->rotulo() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="filtro-regiao" class="block text-xs font-medium uppercase tracking-wide text-tinta-suave">Região de saúde</label>
                            <select id="filtro-regiao" wire:model.live="regiao" class="{{ $campo }} min-w-48">
                                <option value="">Todas</option>
                                @foreach ($regioes as $nomeDaRegiao)
                                    <option value="{{ $nomeDaRegiao }}">{{ \Illuminate\Support\Str::title(mb_strtolower($nomeDaRegiao)) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                <div class="mt-4 max-h-[32rem] overflow-auto rounded-lg border border-linha">
                    <table class="w-full min-w-[40rem] text-left text-sm">
                        <thead class="sticky top-0 bg-fundo text-xs text-tinta-suave">
                            <tr>
                                <th scope="col" class="px-3 py-2 font-medium">Posição</th>
                                <th scope="col" class="px-3 py-2 font-medium">Município</th>
                                <th scope="col" class="px-3 py-2 font-medium">Região de saúde</th>
                                <th scope="col" class="px-3 py-2 text-right font-medium">INA</th>
                                <th scope="col" class="px-3 py-2 text-right font-medium">IDAPS</th>
                                <th scope="col" class="px-3 py-2 font-medium">Quadrante</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-linha">
                            @forelse ($ranking as $linha)
                                <tr wire:key="ranking-{{ $linha->municipio_id }}" @class(['bg-marca-50' => $linha->municipio_id === $municipio->id])>
                                    <td class="px-3 py-2 tabular-nums text-tinta-suave">{{ $posicoes[$linha->municipio_id] }}ª</td>
                                    <th scope="row" class="px-3 py-2 font-medium">
                                        <button type="button" wire:click="selecionar({{ $linha->municipio_id }})" class="text-left underline decoration-linha underline-offset-2 hover:decoration-marca-600 focus:outline-2 focus:outline-offset-2 focus:outline-marca-600" @if ($linha->municipio_id === $municipio->id) aria-current="true" @endif>{{ $linha->municipio->nome }}</button>{{ $linha->municipio->piloto ? ' ★' : '' }}
                                        @if ($linha->estrutura_sem_resultado)
                                            <span class="ml-1 inline-flex items-center rounded-full bg-amber-50 px-1.5 py-0.5 text-[0.65rem] font-semibold text-amber-900 ring-1 ring-inset ring-amber-200" title="Estrutura forte e resultado fraco"><span aria-hidden="true" class="mr-0.5">!</span>estrutura sem resultado</span>
                                        @endif
                                    </th>
                                    <td class="px-3 py-2 text-xs text-tinta-suave">{{ \Illuminate\Support\Str::title(mb_strtolower((string) $linha->municipio->regiao_saude_nome)) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ \App\Support\Formatador::numero($linha->ina, 0) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ \App\Support\Formatador::numero($linha->idaps, 0) }}</td>
                                    <td class="px-3 py-2"><span class="inline-flex items-center gap-1.5 text-xs"><x-analise.forma :quadrante="$linha->ipf_quadrante->value" />{{ $linha->ipf_quadrante->rotulo() }}</span></td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-3 py-6 text-center text-sm text-tinta-suave">Nenhum município nesse filtro.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        @else
            <section class="rounded-2xl border border-linha bg-white p-5 shadow-sm" aria-labelledby="titulo-idaps">
                <h2 id="titulo-idaps" class="text-sm font-semibold">Ranking de desempenho da Atenção Primária (IDAPS)</h2>
                <p class="mt-0.5 text-xs text-tinta-suave">Mês de referência: {{ $competenciaRotulo }}. Nota de 0 a 100 comparando cada município com os demais do estado.</p>

                <div class="mt-4 max-h-[32rem] overflow-auto rounded-lg border border-linha">
                    <table class="w-full text-left text-sm">
                        <thead class="sticky top-0 bg-fundo text-xs text-tinta-suave">
                            <tr>
                                <th scope="col" class="px-3 py-2 font-medium">Posição</th>
                                <th scope="col" class="px-3 py-2 font-medium">Município</th>
                                <th scope="col" class="px-3 py-2 font-medium">Região de saúde</th>
                                <th scope="col" class="px-3 py-2 text-right font-medium">IDAPS</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-linha">
                            @foreach ($rankingDeDesempenho as $posicao => $linha)
                                <tr wire:key="idaps-{{ $linha->municipio_id }}" @class(['bg-marca-50' => $linha->municipio_id === $municipio->id])>
                                    <td class="px-3 py-2 tabular-nums text-tinta-suave">{{ $posicao + 1 }}ª</td>
                                    <th scope="row" class="px-3 py-2 font-medium">
                                        <button type="button" wire:click="selecionar({{ $linha->municipio_id }})" class="text-left underline decoration-linha underline-offset-2 hover:decoration-marca-600 focus:outline-2 focus:outline-offset-2 focus:outline-marca-600">{{ $linha->municipio->nome }}</button>{{ $linha->municipio->piloto ? ' ★' : '' }}
                                    </th>
                                    <td class="px-3 py-2 text-xs text-tinta-suave">{{ \Illuminate\Support\Str::title(mb_strtolower((string) $linha->municipio->regiao_saude_nome)) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ \App\Support\Formatador::numero($linha->idaps, 0) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-5"><x-painel.analise :analise="$analise" /></div>
            </section>
        @endif
    @endif
</div>
