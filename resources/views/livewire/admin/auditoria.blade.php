@php
    $campoClasses = 'block w-full rounded-lg border border-linha bg-white px-3 py-2.5 text-sm focus:border-marca-600 focus:outline-2 focus:outline-marca-600/30';
    $ehFalha = fn (string $evento) => in_array($evento, ['login_falhou', 'login_bloqueado', 'dois_fatores_falhou'], true);
@endphp

<div class="space-y-6">
    <x-admin.cabecalho titulo="Auditoria" descricao="O registro de quem fez o quê e quando: acessos, mudanças em contas, integrações e dados. Os registros não podem ser editados e nunca guardam senhas ou chaves.">
        <x-slot:acoes>
            <a href="{{ route('admin.auditoria.exportar', $filtros) }}" class="inline-flex items-center rounded-lg border border-linha bg-white px-4 py-2.5 text-sm font-semibold hover:bg-fundo focus:outline-2 focus:outline-offset-2 focus:outline-marca-600"><x-ui.icone nome="baixar" class="mr-1.5 size-4" /> Baixar CSV</a>
        </x-slot:acoes>
    </x-admin.cabecalho>

    <section class="rounded-2xl border border-linha bg-white shadow-sm" aria-label="Registros de auditoria">
        <div class="grid gap-3 border-b border-linha p-4 sm:grid-cols-2 lg:grid-cols-[1.4fr_1fr_1.2fr_auto_auto]">
            <div>
                <label for="busca" class="sr-only">Buscar por pessoa, e-mail, IP ou detalhe</label>
                <input id="busca" type="search" wire:model.live.debounce.400ms="busca" placeholder="Pessoa, e-mail, IP ou detalhe" class="{{ $campoClasses }}">
            </div>
            <div>
                <label for="grupo" class="sr-only">Tipo</label>
                <select id="grupo" wire:model.live="grupo" class="{{ $campoClasses }}">
                    <option value="">Todos os tipos</option>
                    @foreach ($grupos as $codigo => $rotulo)
                        <option value="{{ $codigo }}">{{ $rotulo }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="evento" class="sr-only">Evento</label>
                <select id="evento" wire:model.live="evento" class="{{ $campoClasses }}">
                    <option value="">Todos os eventos</option>
                    @foreach ($eventos as $codigo => $rotulo)
                        <option value="{{ $codigo }}">{{ $rotulo }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="de" class="sr-only">De</label>
                <input id="de" type="date" wire:model.live="de" class="{{ $campoClasses }}" title="A partir de">
            </div>
            <div>
                <label for="ate" class="sr-only">Até</label>
                <input id="ate" type="date" wire:model.live="ate" class="{{ $campoClasses }}" title="Até">
            </div>
        </div>

        @if ($filtros !== [])
            <div class="flex items-center justify-between border-b border-linha bg-fundo px-4 py-2 text-xs text-tinta-suave">
                <span>{{ $registros->total() }} registro(s) com os filtros aplicados.</span>
                <button type="button" wire:click="limparFiltros" class="font-medium text-marca-800 underline">Limpar filtros</button>
            </div>
        @endif

        @if ($registros->isEmpty())
            <p class="p-8 text-center text-sm text-tinta-suave">Nenhum registro encontrado.</p>
        @else
            <ul class="divide-y divide-linha">
                @foreach ($registros as $registro)
                    @php
                        $grupoDoEvento = \App\Administracao\RotulosDeAuditoria::todos()[$registro->evento]['grupo'] ?? null;
                        $resumo = \App\Administracao\RotulosDeAuditoria::resumo($registro);
                    @endphp
                    <li wire:key="registro-{{ $registro->id }}">
                        <details class="group">
                            <summary class="flex cursor-pointer list-none flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 text-sm hover:bg-fundo focus:outline-2 focus:-outline-offset-2 focus:outline-marca-600">
                                <time class="w-36 shrink-0 text-xs tabular-nums text-tinta-suave" datetime="{{ $registro->created_at->toIso8601String() }}">{{ $registro->created_at->format('d/m/Y H:i:s') }}</time>
                                <span class="min-w-0 flex-1">
                                    <span class="font-medium">{{ \App\Administracao\RotulosDeAuditoria::rotulo($registro->evento) }}</span>
                                    @if ($resumo !== '') <span class="text-tinta-suave">· {{ $resumo }}</span> @endif
                                </span>
                                @if ($ehFalha($registro->evento)) <x-admin.selo cor="vermelho">Falha</x-admin.selo> @endif
                                <span class="text-xs text-tinta-suave">{{ $registro->user?->name ?? 'Sistema ou visitante' }}</span>
                            </summary>
                            <dl class="grid gap-x-6 gap-y-2 bg-fundo/60 px-4 py-3 text-xs sm:grid-cols-2">
                                <div><dt class="text-tinta-suave">Quem</dt><dd class="font-medium">{{ $registro->user ? $registro->user->name.' · '.$registro->user->email : 'Sem usuário identificado' }}</dd></div>
                                <div><dt class="text-tinta-suave">Evento (código)</dt><dd class="font-mono">{{ $registro->evento }}</dd></div>
                                <div><dt class="text-tinta-suave">Endereço IP</dt><dd class="font-mono">{{ $registro->ip ?? '—' }}</dd></div>
                                <div><dt class="text-tinta-suave">Navegador</dt><dd class="break-words">{{ $registro->user_agent ?: '—' }}</dd></div>
                                @if ($registro->dados)
                                    <div class="sm:col-span-2"><dt class="text-tinta-suave">Detalhes</dt><dd><pre class="mt-1 overflow-x-auto rounded-lg border border-linha bg-white p-3 font-mono text-xs">{{ json_encode($registro->dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></dd></div>
                                @endif
                            </dl>
                        </details>
                    </li>
                @endforeach
            </ul>

            @if ($registros->hasPages())
                <div class="border-t border-linha p-4">{{ $registros->links() }}</div>
            @endif
        @endif
    </section>

    <p class="text-xs text-tinta-suave">
        {{ number_format($total, 0, ',', '.') }} registro(s) guardado(s) @if ($maisAntigo)(o mais antigo é de {{ \Illuminate\Support\Carbon::parse($maisAntigo)->format('d/m/Y') }})@endif.
        Registros com mais de {{ $retencaoEmDias }} dias são apagados automaticamente todo dia (ajustável em <code>AUDITORIA_RETENCAO_DIAS</code>). A exportação traz até {{ number_format(\App\Http\Controllers\Admin\ExportarAuditoriaController::LIMITE_DE_LINHAS, 0, ',', '.') }} linhas dos filtros atuais.
    </p>
</div>
