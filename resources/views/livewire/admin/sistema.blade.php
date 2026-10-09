@php
    $selos = ['ok' => 'verde', 'atencao' => 'ambar', 'erro' => 'vermelho'];
    $icones = ['ok' => '✓', 'atencao' => '!', 'erro' => '✕'];
    $botaoLeve = 'rounded-lg border border-linha px-3 py-2 text-sm font-medium hover:bg-fundo focus:outline-2 focus:outline-marca-600';
@endphp

<div class="space-y-6">
    <x-admin.cabecalho titulo="Sistema" descricao="Se tudo o que a plataforma precisa para funcionar está de pé: ambiente, banco, fila de trabalhos, agendador, integrações e contas. Cada item diz o que fazer quando algo foge do esperado.">
        <x-slot:acoes>
            <x-ui.botao type="button" tipo="secundario" wire:click="verificarDeNovo" wire:loading.attr="disabled" wire:target="verificarDeNovo">Verificar de novo</x-ui.botao>
        </x-slot:acoes>
    </x-admin.cabecalho>

    <dl class="grid grid-cols-3 gap-3">
        <x-admin.numero rotulo="Tudo certo" :valor="$resumo['ok']" />
        <x-admin.numero rotulo="Atenção" :valor="$resumo['atencao']" :tom="$resumo['atencao'] > 0 ? 'alerta' : 'normal'" />
        <x-admin.numero rotulo="Problemas" :valor="$resumo['erro']" :tom="$resumo['erro'] > 0 ? 'erro' : 'normal'" />
    </dl>

    <div class="grid gap-6 lg:grid-cols-2">
        @foreach ($grupos as $nomeDoGrupo => $verificacoes)
            <section class="rounded-2xl border border-linha bg-white shadow-sm" aria-labelledby="grupo-{{ $loop->index }}">
                <h2 id="grupo-{{ $loop->index }}" class="border-b border-linha px-5 py-3 text-sm font-semibold">{{ $nomeDoGrupo }}</h2>
                <ul class="divide-y divide-linha">
                    @foreach ($verificacoes as $verificacao)
                        <li class="flex gap-3 px-5 py-3 text-sm">
                            <x-admin.selo :cor="$selos[$verificacao->estado]" class="h-6 shrink-0 self-start" title="{{ $verificacao->rotuloDoEstado() }}"><span aria-hidden="true">{{ $icones[$verificacao->estado] }}</span> {{ $verificacao->rotuloDoEstado() }}</x-admin.selo>
                            <div class="min-w-0">
                                <p class="font-medium">{{ $verificacao->nome }}</p>
                                <p class="text-tinta-suave">{{ $verificacao->detalhe }}</p>
                                @if ($verificacao->dica)
                                    <p class="mt-1 text-xs text-tinta">→ {{ $verificacao->dica }}</p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>

    <section class="rounded-2xl border border-linha bg-white p-5 shadow-sm sm:p-6" aria-labelledby="titulo-fila">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 id="titulo-fila" class="text-lg font-semibold">Fila de trabalhos</h2>
                <p class="mt-1 text-sm text-tinta-suave">
                    @if ($fila['usaBanco'])
                        {{ $fila['pendentes'] }} esperando
                        @if ($fila['espera'] !== null) · o mais antigo há {{ \App\Support\Duracao::formatar($fila['espera']) }} @endif
                        · {{ $fila['totalDeFalhas'] }} com falha.
                    @else
                        A fila não usa o banco de dados; os números não estão disponíveis aqui.
                    @endif
                </p>
            </div>
            @if ($fila['totalDeFalhas'] > 0)
                <button type="button" wire:click="descartarTodasAsFalhas" wire:confirm="Descartar todos os {{ $fila['totalDeFalhas'] }} trabalhos que falharam? Eles não poderão mais ser reenviados." class="{{ $botaoLeve }} text-red-800">Descartar todos</button>
            @endif
        </div>

        @if ($fila['falhas'] !== [])
            <ul class="mt-4 divide-y divide-linha text-sm">
                @foreach ($fila['falhas'] as $falha)
                    <li class="flex flex-wrap items-start justify-between gap-3 py-3" wire:key="falha-{{ $falha['id'] }}">
                        <div class="min-w-0">
                            <p class="font-medium">{{ class_basename($falha['trabalho']) }} <span class="font-normal text-tinta-suave">· {{ $falha['falhou_em']->format('d/m/Y H:i') }} · fila {{ $falha['fila'] }}</span></p>
                            <p class="break-words text-xs text-tinta-suave">{{ $falha['motivo'] }}</p>
                        </div>
                        <div class="flex gap-2">
                            <button type="button" wire:click="reenviarFalha('{{ $falha['id'] }}')" class="{{ $botaoLeve }}">Tentar de novo</button>
                            <button type="button" wire:click="descartarFalha('{{ $falha['id'] }}')" wire:confirm="Descartar este trabalho?" class="{{ $botaoLeve }}">Descartar</button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @elseif ($fila['usaBanco'])
            <p class="mt-4 text-sm text-tinta-suave">Nenhum trabalho com falha.</p>
        @endif
    </section>

    <section class="rounded-2xl border border-linha bg-white p-5 shadow-sm sm:p-6" aria-labelledby="titulo-manutencao">
        <h2 id="titulo-manutencao" class="text-lg font-semibold">Manutenção</h2>
        <ul class="mt-3 divide-y divide-linha text-sm">
            <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                <div class="max-w-2xl">
                    <p class="font-medium">Renovar o cache dos visuais</p>
                    <p class="text-tinta-suave">Os gráficos guardam resultados prontos para abrir rápido. Use depois de corrigir dados à mão ou se algum visual parecer desatualizado.</p>
                </div>
                <button type="button" wire:click="renovarCache" class="{{ $botaoLeve }}">Renovar cache</button>
            </li>
            <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                <div class="max-w-2xl">
                    <p class="font-medium">Recalcular medianas de comparação</p>
                    <p class="text-tinta-suave">Refaz, a partir dos valores gravados, as medianas e quartis usados nas comparações e nos textos de análise. Acontece sozinho a cada carga de dados.</p>
                </div>
                <button type="button" wire:click="recalcularBenchmarks" wire:loading.attr="disabled" wire:target="recalcularBenchmarks" class="{{ $botaoLeve }}">Recalcular medianas</button>
            </li>
            <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                <div class="max-w-2xl">
                    <p class="font-medium">Recalcular os índices INA, IDAPS e IPF</p>
                    <p class="text-tinta-suave">Fica na tela Índices, junto dos pesos e do histórico de versões.</p>
                </div>
                <a href="{{ route('admin.indices') }}" wire:navigate class="{{ $botaoLeve }}">Ir para Índices</a>
            </li>
        </ul>
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="rounded-2xl border border-linha bg-white p-5 shadow-sm sm:p-6" aria-labelledby="titulo-info">
            <h2 id="titulo-info" class="text-lg font-semibold">Informações do ambiente</h2>
            <dl class="mt-3 divide-y divide-linha text-sm">
                @foreach ($informacoes as $rotulo => $valor)
                    <div class="flex justify-between gap-4 py-2"><dt class="text-tinta-suave">{{ $rotulo }}</dt><dd class="text-right font-medium">{{ $valor }}</dd></div>
                @endforeach
            </dl>
        </section>

        <section class="rounded-2xl border border-linha bg-white p-5 shadow-sm sm:p-6" aria-labelledby="titulo-erros">
            <h2 id="titulo-erros" class="text-lg font-semibold">Últimos erros registrados</h2>
            @if ($erros === [])
                <p class="mt-3 text-sm text-tinta-suave">Nenhum erro recente no registro do sistema.</p>
            @else
                <ul class="mt-3 space-y-2 text-xs">
                    @foreach ($erros as $erro)
                        <li class="break-words rounded-lg bg-fundo px-3 py-2 font-mono">{{ $erro }}</li>
                    @endforeach
                </ul>
                <p class="mt-3 text-xs text-tinta-suave">Só o início de cada erro. O detalhe completo está em <code>storage/logs/laravel.log</code>.</p>
            @endif
        </section>
    </div>
</div>
