@php
    $campoClasses = 'block w-full rounded-lg border border-linha bg-white px-3 py-2.5 text-sm focus:border-marca-600 focus:outline-2 focus:outline-marca-600/30';
    $rotuloDaCompetencia = fn (?int $competencia) => $competencia ? substr((string) $competencia, 4, 2).'/'.substr((string) $competencia, 0, 4) : '—';
@endphp

<div class="space-y-6" @if ($emAndamento) wire:poll.5s @endif>
    <x-admin.cabecalho titulo="Índices e metodologia" descricao="Como o INA (necessidade) e o IDAPS (desempenho) são compostos, com que pesos, e quando foram calculados pela última vez. Mudar um peso cria uma nova versão e nada é perdido.">
        <x-slot:acoes>
            <x-ui.botao type="button" wire:click="recalcular" wire:loading.attr="disabled" wire:target="recalcular" :disabled="$emAndamento">{{ $emAndamento ? 'Calculando…' : 'Recalcular agora' }}</x-ui.botao>
        </x-slot:acoes>
    </x-admin.cabecalho>

    @if ($andamento && $andamento['estado'] === 'erro')
        <div role="alert" class="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900"><strong>O último recálculo falhou.</strong> {{ $andamento['detalhe'] }}</div>
    @elseif ($emAndamento)
        <div role="status" class="rounded-lg border border-sky-300 bg-sky-50 px-4 py-3 text-sm text-sky-900">{{ $andamento['estado'] === 'na_fila' ? 'Recálculo na fila, aguardando o processador de filas…' : 'Calculando os índices de todos os municípios…' }}</div>
    @elseif ($andamento && $andamento['estado'] === 'concluido')
        <div role="status" class="rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">Último recálculo concluído em {{ \Illuminate\Support\Carbon::createFromTimestamp($andamento['em'])->format('d/m/Y H:i') }}: {{ $andamento['linhas'] }} linhas (município × mês). {{ $andamento['detalhe'] }}</div>
    @endif

    @if ($desatualizado)
        <div role="alert" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">Municípios ou indicadores foram ligados/desligados depois do último cálculo. Use <strong>Recalcular agora</strong> para os índices refletirem a mudança.</div>
    @endif

    <dl class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-admin.numero rotulo="Versão ativa" :valor="'v'.$ativa->versao" :detalhe="$ativa->veioDoPainel() ? 'Ajustada no painel' : 'Do arquivo de configuração'" />
        <x-admin.numero rotulo="Mês mais recente" :valor="$rotuloDaCompetencia($ultimoMes)" detalhe="Último cálculo disponível" />
        <x-admin.numero rotulo="Municípios calculados" :valor="$municipiosCalculados" detalhe="No mês mais recente" />
        <x-admin.numero rotulo="Confiança mínima" :valor="number_format($configuracao['confianca_minima'] * 100, 0).'%'" detalhe="Abaixo disso o índice não sai" />
    </dl>

    <div role="note" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">Os pesos iniciais são <strong>provisórios</strong> e devem ser validados com os gestores antes do uso oficial. A página pública <a href="{{ route('metodologia') }}" class="font-medium underline">Metodologia</a> sempre mostra a versão ativa.</div>

    <section class="rounded-2xl border border-linha bg-white p-5 shadow-sm sm:p-6" aria-labelledby="titulo-pesos">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 id="titulo-pesos" class="text-lg font-semibold">Pesos da versão ativa</h2>
                <p class="mt-1 max-w-3xl text-sm text-tinta-suave">Cada índice é a média ponderada dos pilares; cada pilar é a média ponderada das notas dos seus indicadores (0 a 100, pela posição do município entre os do mesmo estado). Dado ausente não vira zero: o peso é redistribuído e a confiança diminui.</p>
            </div>
            <div class="flex gap-2">
                @if ($ativa->veioDoPainel())
                    <x-ui.botao type="button" tipo="secundario" wire:click="pedirVoltaAoArquivo">Voltar ao arquivo</x-ui.botao>
                @endif
                <x-ui.botao type="button" tipo="secundario" wire:click="editarPesos">Ajustar pesos</x-ui.botao>
            </div>
        </div>

        <div class="mt-5 grid gap-6 lg:grid-cols-2">
            @foreach ($indices as $chave => $titulo)
                @php $totalDosPilares = collect($configuracao[$chave]['pilares'])->sum('peso'); @endphp
                <div>
                    <h3 class="text-sm font-semibold">{{ $titulo }}</h3>
                    <ul class="mt-3 space-y-3">
                        @foreach ($configuracao[$chave]['pilares'] as $pilar)
                            @php
                                $parte = $totalDosPilares > 0 ? $pilar['peso'] / $totalDosPilares * 100 : 0;
                                $totalDosComponentes = collect($pilar['componentes'])->sum('peso');
                            @endphp
                            <li>
                                <div class="flex items-center justify-between gap-3 text-sm">
                                    <span class="font-medium">{{ $pilar['nome'] }}@if (! empty($pilar['obrigatorio'])) <span class="font-normal text-tinta-suave">· obrigatório</span>@endif</span>
                                    <span class="tabular-nums text-tinta-suave">{{ number_format($parte, 0) }}%</span>
                                </div>
                                <div class="mt-1 h-2 overflow-hidden rounded-full bg-fundo" role="img" aria-label="{{ $pilar['nome'] }}: {{ number_format($parte, 0) }}% do índice"><div class="h-full rounded-full bg-marca-600" style="width: {{ $parte }}%"></div></div>
                                <details class="mt-1.5 text-xs text-tinta-suave">
                                    <summary class="cursor-pointer select-none hover:text-tinta">{{ count($pilar['componentes']) }} indicador(es)</summary>
                                    <ul class="mt-1 space-y-0.5 pl-1">
                                        @foreach ($pilar['componentes'] as $codigo => $componente)
                                            <li class="flex justify-between gap-3"><code>{{ $codigo }}</code><span class="tabular-nums">{{ $totalDosComponentes > 0 ? number_format($componente['peso'] / $totalDosComponentes * 100, 0) : 0 }}% do pilar · {{ $componente['sentido'] === 'alto' ? 'quanto maior, maior a nota' : 'quanto menor, maior a nota' }}</span></li>
                                        @endforeach
                                    </ul>
                                </details>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>

        <dl class="mt-6 grid gap-3 border-t border-linha pt-4 text-sm sm:grid-cols-3">
            <div><dt class="text-xs text-tinta-suave">Matriz IPF: corte entre alto e baixo</dt><dd class="font-medium">{{ $configuracao['ipf']['corte'] === 'mediana' ? 'Mediana dos municípios' : $configuracao['ipf']['corte'] }}</dd></div>
            <div><dt class="text-xs text-tinta-suave">Alerta de efetividade</dt><dd class="font-medium">Estrutura ≥ {{ $configuracao['efetividade']['estrutura_minima'] }} e resultado ≤ {{ $configuracao['efetividade']['resultado_maximo'] }}</dd></div>
            <div><dt class="text-xs text-tinta-suave">Histórico calculado</dt><dd class="font-medium">{{ $configuracao['janela_meses'] }} meses</dd></div>
        </dl>
        <p class="mt-3 text-xs text-tinta-suave">Limiares, validade dos dados e indicador-âncora ficam em <code>config/indices.php</code>; aqui só os pesos podem ser ajustados.</p>
    </section>

    <section class="rounded-2xl border border-linha bg-white shadow-sm" aria-labelledby="titulo-versoes">
        <h2 id="titulo-versoes" class="border-b border-linha px-5 py-4 text-lg font-semibold">Histórico de versões</h2>
        <ul class="divide-y divide-linha text-sm">
            @foreach ($versoes as $versao)
                <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-3" wire:key="versao-{{ $versao->id }}">
                    <span class="font-medium">Versão {{ $versao->versao }} <span class="font-normal text-tinta-suave">· {{ $versao->veioDoPainel() ? 'ajustada no painel' : 'do arquivo de configuração' }} · {{ $versao->created_at->format('d/m/Y H:i') }}</span></span>
                    @if ($versao->ativa) <x-admin.selo cor="verde">Ativa</x-admin.selo> @endif
                </li>
            @endforeach
        </ul>
    </section>

    @if ($janela === 'pesos')
        <x-admin.modal id="pesos" titulo="Ajustar pesos" fechar="fechar" largura="max-w-3xl" descricao="Só importa a proporção: dobrar todos os pesos de um pilar não muda nada. Zere o peso para tirar um pilar ou indicador do cálculo.">
            <form wire:submit="salvarPesos" class="space-y-6">
                @foreach ($indices as $chave => $titulo)
                    <fieldset class="space-y-3">
                        <legend class="text-sm font-semibold">{{ $titulo }}</legend>
                        @error("pilares.{$chave}") <p class="text-sm text-red-700">{{ $message }}</p> @enderror
                        @foreach ($configuracao[$chave]['pilares'] as $codigoDoPilar => $pilar)
                            <div class="rounded-lg border border-linha p-3" wire:key="pilar-{{ $chave }}-{{ $codigoDoPilar }}">
                                <div class="flex items-center justify-between gap-3">
                                    <label for="pilar-{{ $chave }}-{{ $codigoDoPilar }}" class="text-sm font-medium">{{ $pilar['nome'] }}</label>
                                    <input id="pilar-{{ $chave }}-{{ $codigoDoPilar }}" type="number" min="0" max="100" step="any" inputmode="decimal" wire:model="pilares.{{ $chave }}.{{ $codigoDoPilar }}" class="w-24 rounded-lg border border-linha px-2 py-1.5 text-right text-sm tabular-nums" aria-label="Peso do pilar {{ $pilar['nome'] }}">
                                </div>
                                @error("pilares.{$chave}.{$codigoDoPilar}") <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                                <div class="mt-2 grid gap-x-6 gap-y-1.5 sm:grid-cols-2">
                                    @foreach ($pilar['componentes'] as $codigo => $componente)
                                        <div class="flex items-center justify-between gap-2 text-xs">
                                            <label for="comp-{{ $chave }}-{{ $codigoDoPilar }}-{{ $codigo }}" class="truncate text-tinta-suave" title="{{ $codigo }}">{{ $codigo }}</label>
                                            <input id="comp-{{ $chave }}-{{ $codigoDoPilar }}-{{ $codigo }}" type="number" min="0" max="100" step="any" inputmode="decimal" wire:model="componentes.{{ $chave }}.{{ $codigoDoPilar }}.{{ $codigo }}" class="w-20 rounded-md border border-linha px-2 py-1 text-right tabular-nums">
                                        </div>
                                        @error("componentes.{$chave}.{$codigoDoPilar}.{$codigo}") <p class="col-span-full text-xs text-red-700">{{ $message }}</p> @enderror
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </fieldset>
                @endforeach

                <div>
                    <label for="senhaAtual" class="block text-sm font-medium">Confirme a sua senha para salvar</label>
                    <input id="senhaAtual" type="password" wire:model="senhaAtual" autocomplete="current-password" class="mt-1.5 {{ $campoClasses }}">
                    @error('senhaAtual') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div class="flex justify-end gap-2">
                    <x-ui.botao type="button" tipo="secundario" wire:click="fechar">Cancelar</x-ui.botao>
                    <x-ui.botao wire:loading.attr="disabled" wire:target="salvarPesos">Salvar nova versão</x-ui.botao>
                </div>
            </form>
        </x-admin.modal>
    @endif

    @if ($janela === 'arquivo')
        <x-admin.modal id="arquivo" titulo="Voltar à metodologia do arquivo" fechar="fechar" descricao="Os ajustes de peso feitos no painel deixam de valer e os índices passam a seguir config/indices.php. As versões anteriores continuam no histórico.">
            <form wire:submit="voltarAoArquivo" class="space-y-4">
                <div>
                    <label for="senhaAtual" class="block text-sm font-medium">Digite a sua senha para confirmar</label>
                    <input id="senhaAtual" type="password" x-ref="campo" wire:model="senhaAtual" autocomplete="current-password" class="mt-1.5 {{ $campoClasses }}">
                    @error('senhaAtual') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <div class="flex justify-end gap-2">
                    <x-ui.botao type="button" tipo="secundario" wire:click="fechar">Cancelar</x-ui.botao>
                    <x-ui.botao>Voltar ao arquivo</x-ui.botao>
                </div>
            </form>
        </x-admin.modal>
    @endif
</div>
