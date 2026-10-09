@php
    $cores = [
        'verde' => 'bg-emerald-100 text-emerald-800',
        'ambar' => 'bg-amber-100 text-amber-900',
        'vermelho' => 'bg-red-100 text-red-800',
        'azul' => 'bg-sky-100 text-sky-800',
        'cinza' => 'bg-slate-100 text-slate-700',
    ];
    $campoClasses = 'mt-1.5 block w-full rounded-lg border border-linha bg-white px-3 py-2.5 text-sm focus:border-marca-600 focus:outline-2 focus:outline-marca-600/30';
@endphp

<div wire:poll.{{ $algumaEmAndamento ? '5s' : '30s' }} class="space-y-6">
    <x-admin.cabecalho titulo="Integrações" descricao="Fontes públicas de dados que alimentam a plataforma. Aqui você acompanha a última atualização, configura chaves e força uma nova carga." />

    @if ($filaParada)
        <div role="alert" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Há atualizações esperando na fila há alguns minutos. O processador de filas pode estar desligado: inicie-o com <code class="rounded bg-white px-1.5 py-0.5 text-xs font-semibold">php artisan queue:work</code> (ou <code class="rounded bg-white px-1.5 py-0.5 text-xs font-semibold">composer run dev</code>).
        </div>
    @endif

    <ul class="space-y-4">
        @foreach ($integracoes as $item)
            @php
                /** @var \App\Models\Integracao $integracao */
                $integracao = $item['integracao'];
                $conector = $item['conector'];
                $teste = $resultadosDeTeste[$integracao->id] ?? null;
            @endphp
            <li wire:key="integracao-{{ $integracao->id }}" class="rounded-2xl border border-linha bg-white p-5 shadow-sm sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold">{{ $conector->nome() }}</h2>
                        <p class="mt-1 max-w-2xl text-sm text-tinta-suave">{{ $conector->descricao() }}</p>
                    </div>
                    <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold {{ $cores[$item['estado']['cor']] }}">
                        <span aria-hidden="true">●</span>{{ $item['estado']['rotulo'] }}
                    </span>
                </div>

                <dl class="mt-5 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-tinta-suave">Última atualização</dt>
                        <dd class="mt-1">
                            @if ($integracao->ultimo_sucesso_em)
                                <span class="font-medium">{{ $integracao->ultimo_sucesso_em->diffForHumans() }}</span>
                                <span class="block text-xs text-tinta-suave">{{ $integracao->ultimo_sucesso_em->format('d/m/Y H:i') }}</span>
                            @else
                                <span class="text-tinta-suave">Ainda não atualizada</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-tinta-suave">Dado mais recente</dt>
                        <dd class="mt-1 font-medium">{{ $conector->rotuloDaCompetencia($integracao->ultima_competencia) }}
                            @if ($integracao->ultimas_linhas !== null)
                                <span class="block text-xs font-normal text-tinta-suave">{{ number_format($integracao->ultimas_linhas, 0, ',', '.') }} valores na última carga</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-tinta-suave">Frequência</dt>
                        <dd class="mt-1 font-medium">{{ $integracao->frequencia->rotulo() }}
                            @if ($integracao->frequencia->value !== 'manual')
                                <span class="font-normal text-tinta-suave">às {{ substr((string) $integracao->horario, 0, 5) }}</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-tinta-suave">Próxima execução</dt>
                        <dd class="mt-1 font-medium">{{ $item['proximo'] ?? '—' }}</dd>
                    </div>
                </dl>

                @php $execucao = $execucoes[$integracao->id] ?? null; @endphp
                @if ($integracao->status->value === 'na_fila')
                    <div role="status" class="mt-4 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">Aguardando o processador de filas iniciar a atualização…</div>
                @elseif ($execucao)
                    @php
                        $fracao = $execucao->fracaoConcluida();
                        $restante = $execucao->segundosRestantes($agora);
                        $percentual = $fracao === null ? null : (int) round($fracao * 100);
                    @endphp
                    <div role="status" aria-live="polite" class="mt-4 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-medium">{{ $execucao->etapa ?? 'Iniciando…' }}</span>
                            @if ($percentual !== null) <span class="font-semibold">{{ $percentual }}%</span> @endif
                        </div>
                        @if ($percentual !== null)
                            <div class="mt-2 h-2 overflow-hidden rounded-full bg-sky-200" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percentual }}" aria-label="Progresso da atualização">
                                <div class="h-full rounded-full bg-sky-600 transition-all" style="width: {{ $percentual }}%"></div>
                            </div>
                        @endif
                        <p class="mt-2 text-xs">
                            @if ($execucao->passos_total)
                                {{ number_format($execucao->passos_concluidos, 0, ',', '.') }} de {{ number_format($execucao->passos_total, 0, ',', '.') }} ·
                            @elseif ($execucao->passos_concluidos > 0)
                                {{ number_format($execucao->passos_concluidos, 0, ',', '.') }} passos concluídos ·
                            @endif
                            decorrido {{ \App\Support\Duracao::formatar((int) $execucao->iniciada_em->diffInSeconds($agora)) }}
                            @if ($restante !== null) · restam cerca de {{ \App\Support\Duracao::formatar($restante) }} @endif
                        </p>
                    </div>
                @endif

                @if ($integracao->status->value === 'erro' && $integracao->ultimo_erro)
                    <div role="alert" class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
                        <strong>Última execução falhou:</strong> {{ $integracao->ultimo_erro }}
                    </div>
                @endif

                @if (! $item['configurada'])
                    <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                        Esta fonte precisa de configuração (por exemplo, a chave da API) antes de funcionar. Use <strong>Configurar</strong>.
                    </div>
                @endif

                @if ($teste)
                    <div role="status" @class([
                        'mt-4 rounded-lg border px-4 py-3 text-sm',
                        'border-emerald-200 bg-emerald-50 text-emerald-900' => $teste['ok'],
                        'border-red-200 bg-red-50 text-red-900' => ! $teste['ok'],
                    ])>
                        <strong>{{ $teste['ok'] ? 'Teste ok:' : 'Teste falhou:' }}</strong> {{ $teste['texto'] }}
                    </div>
                @endif

                <div class="mt-5 flex flex-wrap gap-2">
                    <x-ui.botao type="button" wire:click="abrirAtualizacao({{ $integracao->id }})" :disabled="$integracao->status->emAndamento()">Atualizar agora</x-ui.botao>
                    <x-ui.botao type="button" tipo="secundario" wire:click="testar({{ $integracao->id }})" wire:loading.attr="disabled" wire:target="testar({{ $integracao->id }})">
                        <span wire:loading.remove wire:target="testar({{ $integracao->id }})">Testar conexão</span>
                        <span wire:loading wire:target="testar({{ $integracao->id }})">Testando…</span>
                    </x-ui.botao>
                    <x-ui.botao type="button" tipo="secundario" wire:click="abrirConfiguracao({{ $integracao->id }})">Configurar</x-ui.botao>
                    <x-ui.botao type="button" tipo="secundario" wire:click="abrirHistorico({{ $integracao->id }})">Histórico</x-ui.botao>
                    <x-ui.botao type="button" tipo="secundario" wire:click="alternarAtiva({{ $integracao->id }})">{{ $integracao->ativa ? 'Pausar' : 'Retomar' }}</x-ui.botao>
                </div>
            </li>
        @endforeach
    </ul>

    @if ($janela && $selecionada)
        @php
            /** @var \App\Models\Integracao $alvo */
            $alvo = $selecionada['integracao'];
            $conectorAlvo = $selecionada['conector'];
        @endphp

        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto p-4 sm:p-8">
            <div class="fixed inset-0 bg-slate-900/50" wire:click="fechar" aria-hidden="true"></div>

            <div role="dialog" aria-modal="true" aria-labelledby="titulo-janela" wire:keydown.escape="fechar" class="relative w-full max-w-2xl rounded-2xl bg-white p-6 shadow-xl sm:p-8">
                <div class="flex items-start justify-between gap-4">
                    <h2 id="titulo-janela" class="text-lg font-semibold">
                        @if ($janela === 'configurar') Configurar · {{ $conectorAlvo->nome() }}
                        @elseif ($janela === 'atualizar') Atualizar agora · {{ $conectorAlvo->nome() }}
                        @else Histórico · {{ $conectorAlvo->nome() }}
                        @endif
                    </h2>
                    <button type="button" wire:click="fechar" class="rounded-lg p-1 text-tinta-suave hover:bg-fundo" aria-label="Fechar">✕</button>
                </div>

                @if ($janela === 'configurar')
                    <form wire:submit="salvarConfiguracao" class="mt-6 space-y-5">
                        <div class="grid gap-5 sm:grid-cols-2">
                            <div>
                                <label for="frequencia" class="block text-sm font-medium">Frequência de atualização</label>
                                <select id="frequencia" wire:model="frequencia" class="{{ $campoClasses }}">
                                    @foreach (\App\Enums\Frequencia::cases() as $opcao)
                                        <option value="{{ $opcao->value }}">{{ $opcao->rotulo() }}</option>
                                    @endforeach
                                </select>
                                @error('frequencia') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="horario" class="block text-sm font-medium">Horário</label>
                                <input id="horario" type="time" wire:model="horario" class="{{ $campoClasses }}">
                                @error('horario') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        @foreach ($conectorAlvo->campos() as $campo)
                            <div>
                                <div class="flex items-center justify-between gap-3">
                                    <label for="campo-{{ $campo->nome }}" class="block text-sm font-medium">
                                        {{ $campo->secreto && $alvo->possuiConfig($campo->nome) ? 'Substituir '.mb_strtolower($campo->rotulo) : $campo->rotulo }}
                                        @if ($campo->obrigatorio) <span class="text-red-700" aria-hidden="true">*</span> @endif
                                    </label>
                                    @if ($campo->secreto)
                                        <span @class([
                                            'rounded-full px-2.5 py-0.5 text-xs font-semibold',
                                            'bg-emerald-100 text-emerald-800' => $alvo->possuiConfig($campo->nome),
                                            'bg-amber-100 text-amber-900' => ! $alvo->possuiConfig($campo->nome),
                                        ])>{{ $alvo->possuiConfig($campo->nome) ? 'Configurada' : 'Não configurada' }}</span>
                                    @endif
                                </div>

                                @if ($campo->secreto)
                                    <input id="campo-{{ $campo->nome }}" type="password" autocomplete="new-password" spellcheck="false" wire:model="valores.{{ $campo->nome }}"
                                        placeholder="{{ $alvo->possuiConfig($campo->nome) ? '•••••••••••• (deixe em branco para manter)' : 'Cole a chave aqui' }}" class="{{ $campoClasses }}">
                                @elseif ($campo->tipo === 'numero')
                                    <input id="campo-{{ $campo->nome }}" type="number" min="{{ $campo->minimo }}" max="{{ $campo->maximo }}" wire:model="valores.{{ $campo->nome }}" class="{{ $campoClasses }}">
                                @else
                                    <input id="campo-{{ $campo->nome }}" type="text" wire:model="valores.{{ $campo->nome }}" class="{{ $campoClasses }}">
                                @endif

                                @if ($campo->ajuda) <p class="mt-1.5 text-xs text-tinta-suave">{{ $campo->ajuda }}</p> @endif
                                @error('valores.'.$campo->nome) <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                            </div>
                        @endforeach

                        <label class="flex items-center gap-3 text-sm">
                            <input type="checkbox" wire:model="ativa" class="size-4 rounded border-linha text-marca-600">
                            Integração ativa (executa automaticamente conforme a frequência)
                        </label>

                        <div class="flex flex-wrap justify-end gap-3 border-t border-linha pt-5">
                            <x-ui.botao type="button" tipo="secundario" wire:click="fechar">Cancelar</x-ui.botao>
                            <x-ui.botao>Salvar configuração</x-ui.botao>
                        </div>
                    </form>
                @elseif ($janela === 'atualizar')
                    <form wire:submit="atualizarAgora" class="mt-6 space-y-5">
                        <fieldset>
                            <legend class="text-sm font-medium">Período a buscar</legend>
                            <div class="mt-3 space-y-3 text-sm">
                                <label class="flex items-start gap-3 rounded-lg border border-linha p-3">
                                    <input type="radio" wire:model.live="periodo" value="padrao" class="mt-1">
                                    <span><span class="font-medium">Atualização normal</span><span class="block text-xs text-tinta-suave">Busca apenas os meses recentes (ou o histórico inicial, se for a primeira vez).</span></span>
                                </label>
                                <label class="flex items-start gap-3 rounded-lg border border-linha p-3">
                                    <input type="radio" wire:model.live="periodo" value="personalizado" class="mt-1">
                                    <span class="w-full">
                                        <span class="font-medium">Período personalizado</span>
                                        <span class="block text-xs text-tinta-suave">Busca os últimos meses que você informar (de 1 a {{ \App\Integrations\ContextoDeIngestao::MAXIMO_DE_MESES }}). Períodos longos podem demorar bastante.</span>
                                        @if ($periodo === 'personalizado')
                                            <span class="mt-3 flex items-center gap-2">
                                                <input id="meses" type="number" min="1" max="{{ \App\Integrations\ContextoDeIngestao::MAXIMO_DE_MESES }}" wire:model="meses" aria-label="Quantidade de meses" class="block w-28 rounded-lg border border-linha bg-white px-3 py-2.5 text-sm focus:border-marca-600 focus:outline-2 focus:outline-marca-600/30">
                                                <span class="text-xs text-tinta-suave">meses, contando do mês atual para trás</span>
                                            </span>
                                        @endif
                                    </span>
                                </label>
                            </div>
                            @error('periodo') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
                            @error('meses') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
                        </fieldset>
                        <label class="flex items-start gap-3 text-sm">
                            <input type="checkbox" wire:model="reprocessar" class="mt-1 size-4 rounded border-linha text-marca-600">
                            <span><span class="font-medium">Reprocessar arquivos já lidos</span><span class="block text-xs text-tinta-suave">Desmarcado (recomendado), só entra o que é novo ou foi alterado na fonte, e o que já está salvo é mantido. Marque apenas se suspeitar que dados antigos estão errados.</span></span>
                        </label>
                        <p class="text-xs text-tinta-suave">A atualização roda em segundo plano. Reexecutar não duplica dados: valores existentes são apenas atualizados.</p>
                        <div class="flex flex-wrap justify-end gap-3 border-t border-linha pt-5">
                            <x-ui.botao type="button" tipo="secundario" wire:click="fechar">Cancelar</x-ui.botao>
                            <x-ui.botao>Iniciar atualização</x-ui.botao>
                        </div>
                    </form>
                @else
                    <div class="mt-6">
                        @forelse ($historico as $execucao)
                            <div class="border-b border-linha py-4 text-sm last:border-b-0">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="font-medium">{{ $execucao->iniciada_em->format('d/m/Y H:i') }}
                                        <span class="font-normal text-tinta-suave">· {{ $execucao->origem->rotulo() }}@if ($execucao->user) · {{ $execucao->user->name }}@endif</span>
                                    </p>
                                    <span @class([
                                        'rounded-full px-2.5 py-0.5 text-xs font-semibold',
                                        'bg-emerald-100 text-emerald-800' => $execucao->status->value === 'sucesso',
                                        'bg-amber-100 text-amber-900' => $execucao->status->value === 'parcial',
                                        'bg-red-100 text-red-800' => $execucao->status->value === 'erro',
                                        'bg-sky-100 text-sky-800' => $execucao->status->value === 'executando',
                                    ])>{{ $execucao->status->rotulo() }}</span>
                                </div>
                                <p class="mt-1 text-xs text-tinta-suave">
                                    {{ number_format($execucao->linhas, 0, ',', '.') }} valores
                                    @if ($execucao->duracaoEmSegundos() !== null) · {{ $execucao->duracaoEmSegundos() }} s @endif
                                    @if ($execucao->competencia_mais_recente) · até {{ $conectorAlvo->rotuloDaCompetencia($execucao->competencia_mais_recente) }} @endif
                                </p>
                                @if ($execucao->mensagem)
                                    <p class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-red-900">{{ $execucao->mensagem }}</p>
                                @endif
                                @if ($execucao->avisos)
                                    <details class="mt-2">
                                        <summary class="cursor-pointer text-xs font-medium text-amber-900">{{ count($execucao->avisos) }} aviso(s)</summary>
                                        <ul class="mt-2 list-disc space-y-1 pl-5 text-xs text-tinta-suave">
                                            @foreach ($execucao->avisos as $aviso) <li>{{ $aviso }}</li> @endforeach
                                        </ul>
                                    </details>
                                @endif
                            </div>
                        @empty
                            <p class="text-sm text-tinta-suave">Nenhuma execução registrada ainda.</p>
                        @endforelse
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
