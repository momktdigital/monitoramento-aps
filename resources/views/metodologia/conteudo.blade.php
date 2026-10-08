@php
    $secoes = [
        'indices' => 'Os três índices',
        'calculo' => 'Como cada nota é calculada',
        'composicao' => 'O que entra em cada índice',
        'matriz' => 'A matriz de prioridade',
        'efetividade' => 'Estrutura forte, resultado fraco',
        'faltas' => 'Quando faltam dados',
        'limites' => 'Limites e cuidados',
        'fontes' => 'De onde vêm os dados',
        'catalogo' => 'Catálogo de indicadores',
    ];
    $ipf = $configuracao['ipf'];
    $efetividade = $configuracao['efetividade'];
@endphp

<article class="space-y-10">
    <header>
        <h1 class="text-3xl font-semibold tracking-tight">Metodologia e fontes</h1>
        <p class="mt-3 max-w-3xl leading-relaxed text-tinta-suave">
            Esta página explica, sem atalhos, como a plataforma transforma dados públicos de saúde em três índices por município: o que é medido, com que peso, com que limites e de onde vem cada número.
            @if ($versao) <span class="whitespace-nowrap">Metodologia em uso: versão {{ $versao }}.</span> @endif
        </p>

        <nav aria-label="Nesta página" class="mt-5">
            <ul class="flex flex-wrap gap-2 text-sm">
                @foreach ($secoes as $ancora => $rotulo)
                    <li><a href="#{{ $ancora }}" class="inline-block rounded-full border border-linha bg-white px-3 py-1 hover:bg-marca-50 focus:outline-2 focus:outline-offset-2 focus:outline-marca-600">{{ $rotulo }}</a></li>
                @endforeach
            </ul>
        </nav>
    </header>

    <div role="note" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-relaxed text-amber-900">
        <strong>Pesos em validação.</strong> Os pesos abaixo são uma proposta inicial e ainda serão discutidos com gestores de saúde. Eles estão aqui para que qualquer pessoa possa conferir e questionar o cálculo; se mudarem, uma nova versão da metodologia é criada e todos os índices são recalculados.
    </div>

    <section id="indices" class="scroll-mt-6 space-y-4" aria-labelledby="h-indices">
        <h2 id="h-indices" class="text-xl font-semibold">Os três índices</h2>
        <dl class="space-y-4 leading-relaxed">
            <div>
                <dt class="font-semibold">INA · Índice de Necessidade da APS</dt>
                <dd class="text-tinta-suave">Resume o quanto a população do município precisa da Atenção Primária: perfil de idade, vulnerabilidade social e dependência do SUS. Não diz se o município faz bem ou mal, só o tamanho da demanda.</dd>
            </div>
            <div>
                <dt class="font-semibold">IDAPS · Índice de Desempenho da APS</dt>
                <dd class="text-tinta-suave">Resume como a Atenção Primária está funcionando: estrutura e cobertura, acompanhamento do cuidado e resultados em saúde. O valor repassado ao município nunca entra como medida de desempenho: dinheiro recebido não é resultado.</dd>
            </div>
            <div>
                <dt class="font-semibold">IPF · Índice de Potencial de Financiamento</dt>
                <dd class="text-tinta-suave">Cruza o INA com o IDAPS em uma matriz de quatro quadrantes, para indicar onde o apoio tem mais potencial de fazer diferença. Cada indicador é usado em um só índice, para que nada seja contado duas vezes.</dd>
            </div>
        </dl>
    </section>

    <section id="calculo" class="scroll-mt-6 space-y-4" aria-labelledby="h-calculo">
        <h2 id="h-calculo" class="text-xl font-semibold">Como cada nota é calculada</h2>
        <ol class="list-decimal space-y-3 pl-5 leading-relaxed marker:font-semibold">
            <li><strong>Cada indicador vira uma nota de 0 a 100.</strong> <span class="text-tinta-suave">A nota é a posição do município entre os municípios do mesmo estado: o menor valor recebe 0 e o maior recebe 100. Municípios empatados (por exemplo, vários com 100% de cobertura) dividem a mesma nota.</span></li>
            <li><strong>O sentido é respeitado.</strong> <span class="text-tinta-suave">Em indicadores em que valor baixo é o desejável (internações evitáveis, mortalidade infantil), a nota é invertida: menos é melhor nota. No INA, mais pobreza ou mais idosos significam mais necessidade, e portanto nota maior.</span></li>
            <li><strong>As notas se combinam em pilares.</strong> <span class="text-tinta-suave">Cada pilar é a média ponderada das notas dos seus indicadores.</span></li>
            <li><strong>Os pilares se combinam no índice.</strong> <span class="text-tinta-suave">O índice é a média ponderada dos pilares, também de 0 a 100.</span></li>
            <li><strong>Vale o último dado conhecido.</strong> <span class="text-tinta-suave">Para cada mês de referência, cada indicador usa o dado mais recente até aquele mês, desde que não esteja velho demais: {{ $configuracao['validade_meses']['mensal'] }} meses para indicadores mensais, {{ $configuracao['validade_meses']['quadrimestral'] }} para quadrimestrais e {{ $configuracao['validade_meses']['anual'] }} para anuais (dados do Censo valem por mais tempo).</span></li>
        </ol>
        <p class="rounded-lg bg-white p-4 text-sm leading-relaxed text-tinta-suave ring-1 ring-linha"><strong class="text-tinta">Exemplo.</strong> Se a cobertura da Estratégia Saúde da Família de um município está entre as 25% mais baixas do estado, a nota dela é cerca de 25. Se a taxa de internações evitáveis está entre as 25% mais altas, a nota desse indicador também é cerca de 25, porque nesse caso valor alto é ruim.</p>
    </section>

    <section id="composicao" class="scroll-mt-6 space-y-6" aria-labelledby="h-composicao">
        <h2 id="h-composicao" class="text-xl font-semibold">O que entra em cada índice</h2>
        <p class="leading-relaxed text-tinta-suave">Os percentuais mostram o peso de cada pilar no índice e de cada indicador dentro do pilar. Indicadores marcados como <em>aguardando dados</em> já estão previstos na metodologia, mas só passam a contar quando a fonte estiver disponível; enquanto isso, o peso deles é redistribuído entre os demais.</p>

        @foreach ($indices as $chave => $indice)
            <div class="space-y-3">
                <h3 class="font-semibold">{{ strtoupper($chave) }} · {{ $indice['nome'] }}</h3>

                @foreach ($indice['pilares'] as $pilar)
                    <div class="overflow-hidden rounded-xl border border-linha bg-white">
                        <div class="flex flex-wrap items-baseline justify-between gap-2 bg-fundo px-4 py-2.5">
                            <p class="text-sm font-semibold">{{ $pilar['nome'] }}@if ($pilar['obrigatorio']) <span class="ml-1 text-xs font-normal text-tinta-suave">· obrigatório: sem ele o índice não é calculado</span>@endif</p>
                            <p class="text-xs text-tinta-suave">{{ $pilar['peso'] }}% do {{ strtoupper($chave) }}</p>
                        </div>
                        <ul class="divide-y divide-linha text-sm">
                            @foreach ($pilar['componentes'] as $componente)
                                <li class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5 px-4 py-2">
                                    <span class="min-w-0">
                                        <span class="font-medium">{{ $componente['nome'] }}</span>
                                        <span class="block text-xs text-tinta-suave">{{ $componente['sentido'] === 'alto' ? 'Quanto maior o valor, maior a nota' : 'Quanto menor o valor, maior a nota' }}{{ $chave === 'ina' ? ' (maior nota = maior necessidade)' : '' }}</span>
                                    </span>
                                    <span class="flex items-center gap-3 text-xs">
                                        <span @class(['rounded-full px-2 py-0.5 font-medium ring-1 ring-inset', 'bg-emerald-50 text-emerald-900 ring-emerald-200' => $componente['em_uso'], 'bg-slate-100 text-slate-700 ring-slate-200' => ! $componente['em_uso']])>{{ $componente['em_uso'] ? 'com dados' : 'aguardando dados' }}</span>
                                        <span class="w-24 text-right tabular-nums text-tinta-suave">{{ $componente['peso'] }}% do pilar</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        @endforeach
    </section>

    <section id="matriz" class="scroll-mt-6 space-y-4" aria-labelledby="h-matriz">
        <h2 id="h-matriz" class="text-xl font-semibold">A matriz de prioridade</h2>
        <p class="leading-relaxed text-tinta-suave">
            Em cada mês, o INA e o IDAPS dos municípios são cruzados. O ponto que separa “alto” de “baixo” é
            @if (($ipf['corte'] ?? 'mediana') === 'mediana') a <strong class="text-tinta">mediana</strong> de cada índice entre os municípios do estado, o que garante metade acima e metade abaixo. @else o valor fixo {{ $ipf['corte'] }}. @endif
            Um município exatamente no corte conta como “alto”.
        </p>
        <dl class="grid gap-3 sm:grid-cols-2">
            @foreach ($quadrantes as $quadrante)
                <div class="rounded-xl border border-linha bg-white p-4">
                    <dt class="flex items-center gap-2 font-semibold"><x-analise.forma :quadrante="$quadrante->value" />{{ $quadrante->rotulo() }}</dt>
                    <dd class="mt-1 text-sm leading-relaxed text-tinta-suave">{{ $quadrante->descricao() }}</dd>
                </div>
            @endforeach
        </dl>
        <p class="text-sm leading-relaxed text-tinta-suave">A <strong class="text-tinta">pontuação de prioridade</strong> usada para ordenar os municípios é a média entre o INA e o complemento do IDAPS (100 menos o IDAPS): necessidade alta e desempenho baixo dão as maiores pontuações.</p>
    </section>

    <section id="efetividade" class="scroll-mt-6 space-y-3" aria-labelledby="h-efetividade">
        <h2 id="h-efetividade" class="text-xl font-semibold">Estrutura forte, resultado fraco</h2>
        <p class="leading-relaxed text-tinta-suave">
            Um alerta especial marca os municípios em que a estrutura e a cobertura estão entre as mais fortes do estado (nota a partir de {{ $efetividade['estrutura_minima'] }}), mas os resultados em saúde ficam entre os mais fracos (nota até {{ $efetividade['resultado_maximo'] }}).
            O alerta não aponta culpados: é um convite para investigar por que a estrutura ainda não se traduz em resultado.
        </p>
    </section>

    <section id="faltas" class="scroll-mt-6 space-y-3" aria-labelledby="h-faltas">
        <h2 id="h-faltas" class="text-xl font-semibold">Quando faltam dados</h2>
        <ul class="list-disc space-y-2 pl-5 leading-relaxed text-tinta-suave marker:text-tinta">
            <li>Dado ausente <strong class="text-tinta">nunca vira zero</strong>. O peso dele é redistribuído entre os indicadores que existem.</li>
            <li>Cada índice informa sua <strong class="text-tinta">confiança</strong>: a parcela da metodologia que foi de fato coberta. Abaixo de {{ round($configuracao['confianca_minima'] * 100) }}%, o índice não é calculado; a partir de {{ round($configuracao['confianca_alta'] * 100) }}%, a confiança é considerada alta.</li>
            <li>Um indicador só entra no cálculo se tiver valor para pelo menos {{ round($configuracao['cobertura_minima_dos_pares'] * 100) }}% dos municípios do estado. Assim, uma fonte carregada só para alguns municípios não distorce a comparação.</li>
            <li>Pilares marcados como obrigatórios precisam ter ao menos um indicador com dados; sem isso o índice não é calculado.</li>
            <li>O cálculo só compara municípios de um mesmo estado e exige pelo menos {{ $configuracao['minimo_de_pares'] }} municípios.</li>
        </ul>
    </section>

    <section id="limites" class="scroll-mt-6 space-y-3" aria-labelledby="h-limites">
        <h2 id="h-limites" class="text-xl font-semibold">Limites e cuidados</h2>
        <ul class="list-disc space-y-2 pl-5 leading-relaxed text-tinta-suave marker:text-tinta">
            <li><strong class="text-tinta">As notas são relativas.</strong> Elas comparam o município com os demais do estado. Um município pode melhorar e manter a nota se os outros melhorarem junto, ou piorar de posição sem piorar de fato.</li>
            <li><strong class="text-tinta">Internações (SIH/SUS).</strong> Contam as internações ocorridas em hospitais do próprio estado. Moradores que se internam em outro estado não aparecem.</li>
            <li><strong class="text-tinta">Mortalidade infantil, pré-natal e baixo peso (SIM e SINASC).</strong> Os dados finais saem com cerca de um ano e meio de atraso: o valor mostrado costuma ser o do último ano fechado.</li>
            <li><strong class="text-tinta">Municípios pequenos.</strong> Poucos casos fazem taxas como a mortalidade infantil oscilar muito de um ano para o outro.</li>
            <li><strong class="text-tinta">Dados administrativos.</strong> Vêm de sistemas oficiais e podem ser revisados pelas fontes; a plataforma atualiza os valores quando isso acontece.</li>
            <li><strong class="text-tinta">Apoio à decisão.</strong> Os índices orientam a conversa sobre prioridades. Não substituem a análise local, nem são um ranking oficial.</li>
        </ul>
    </section>

    <section id="fontes" class="scroll-mt-6 space-y-3" aria-labelledby="h-fontes">
        <h2 id="h-fontes" class="text-xl font-semibold">De onde vêm os dados</h2>
        <p class="leading-relaxed text-tinta-suave">Todos os dados são públicos e agregados por município: a plataforma não guarda registros individuais. As fontes são consultadas automaticamente, sem importação manual.</p>
        <ul class="divide-y divide-linha rounded-xl border border-linha bg-white">
            @foreach ($fontes as $fonte)
                <li class="px-4 py-3">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="font-medium">{{ $fonte['nome'] }}</p>
                        <p class="text-xs text-tinta-suave">{{ $fonte['atualizada_em'] ? 'Última atualização: '.$fonte['atualizada_em']->timezone(config('app.timezone'))->format('d/m/Y') : 'Ainda sem atualização' }}</p>
                    </div>
                    <p class="mt-1 text-sm leading-relaxed text-tinta-suave">{{ $fonte['descricao'] }}</p>
                </li>
            @endforeach
        </ul>
    </section>

    <section id="catalogo" class="scroll-mt-6 space-y-3" aria-labelledby="h-catalogo">
        <h2 id="h-catalogo" class="text-xl font-semibold">Catálogo de indicadores</h2>
        <p class="leading-relaxed text-tinta-suave">Para cada indicador: o que é, como é calculado, para que serve e como interpretar. Os mesmos textos aparecem no botão <span class="inline-flex size-5 items-center justify-center rounded-full border border-linha bg-white align-middle text-xs font-semibold text-marca-700">?</span> de cada visual.</p>

        @foreach ($catalogo as $rotuloDoGrupo => $indicadores)
            <details class="rounded-xl border border-linha bg-white">
                <summary class="cursor-pointer px-4 py-3 text-sm font-semibold focus:outline-2 focus:outline-offset-2 focus:outline-marca-600">{{ $rotuloDoGrupo }} <span class="font-normal text-tinta-suave">({{ $indicadores->count() }})</span></summary>
                <div class="divide-y divide-linha border-t border-linha">
                    @foreach ($indicadores as $indicador)
                        <div class="px-4 py-4">
                            <h3 class="flex flex-wrap items-baseline gap-x-2 font-semibold">{{ $indicador->nome }}@unless ($indicador->ativo)<span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700 ring-1 ring-inset ring-slate-200">planejado</span>@endunless</h3>
                            <p class="mt-0.5 text-xs text-tinta-suave">{{ $indicador->fonteRotulo() }} · {{ $indicador->polaridade->rotulo() }}</p>
                            <dl class="mt-3 space-y-2 text-sm leading-relaxed">
                                @foreach (['O que é' => $indicador->o_que_e, 'Como é calculado' => $indicador->como_calcula, 'Para que serve' => $indicador->para_que_serve, 'Como interpretar' => $indicador->como_interpretar] as $titulo => $texto)
                                    <div>
                                        <dt class="text-xs font-semibold uppercase tracking-wide text-marca-700">{{ $titulo }}</dt>
                                        <dd class="text-tinta">{{ $texto }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </div>
                    @endforeach
                </div>
            </details>
        @endforeach
    </section>
</article>
