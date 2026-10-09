@php
    $selos = ['ok' => 'verde', 'atencao' => 'ambar', 'erro' => 'vermelho'];
    $atalho = 'flex flex-col rounded-2xl border border-linha bg-white p-5 shadow-sm hover:bg-fundo focus:outline-2 focus:outline-offset-2 focus:outline-marca-600';
@endphp

<div class="space-y-6">
    <x-admin.cabecalho titulo="Visão geral" descricao="O que precisa da sua atenção agora e um resumo da plataforma. Se a lista de pendências estiver vazia, está tudo em ordem." />

    <dl class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-admin.numero rotulo="Usuários ativos" :valor="$numeros['usuarios']" :href="route('admin.usuarios')" />
        <x-admin.numero rotulo="Municípios na comparação" :valor="$numeros['municipios']" :href="route('admin.municipios')" />
        <x-admin.numero rotulo="Indicadores ativos" :valor="$numeros['indicadores']" :href="route('admin.indicadores')" />
        <x-admin.numero rotulo="Índices calculados" :valor="$numeros['indices']" :detalhe="$numeros['ultimoMes'] ? 'Mês de referência '.$numeros['ultimoMes'] : 'Ainda não calculados'" :href="route('admin.indices')" />
    </dl>

    <section class="rounded-2xl border bg-white shadow-sm {{ $resumo['erro'] > 0 ? 'border-red-300' : ($resumo['atencao'] > 0 ? 'border-amber-300' : 'border-linha') }}" aria-labelledby="titulo-pendencias">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-linha px-5 py-4">
            <h2 id="titulo-pendencias" class="text-lg font-semibold">Pendências</h2>
            <p class="text-sm text-tinta-suave">{{ $resumo['erro'] }} problema(s) · {{ $resumo['atencao'] }} ponto(s) de atenção · {{ $resumo['ok'] }} item(ns) em ordem</p>
        </div>

        @if ($pendencias->isEmpty())
            <p class="px-5 py-6 text-sm"><x-admin.selo cor="verde"><span aria-hidden="true">✓</span> Tudo certo</x-admin.selo> <span class="ml-2 text-tinta-suave">Nenhuma pendência: ambiente, fila, agendador, integrações e contas estão em ordem.</span></p>
        @else
            <ul class="divide-y divide-linha">
                @foreach ($pendencias as $item)
                    @php $verificacao = $item['verificacao']; @endphp
                    <li class="flex flex-wrap items-start justify-between gap-3 px-5 py-3 text-sm">
                        <div class="flex min-w-0 gap-3">
                            <x-admin.selo :cor="$selos[$verificacao->estado]" class="h-6 shrink-0 self-start">{{ $verificacao->rotuloDoEstado() }}</x-admin.selo>
                            <div class="min-w-0">
                                <p class="font-medium">{{ $verificacao->nome }} <span class="font-normal text-tinta-suave">· {{ $verificacao->grupo }}</span></p>
                                <p class="text-tinta-suave">{{ $verificacao->detalhe }}</p>
                                @if ($verificacao->dica) <p class="mt-0.5 text-xs">→ {{ $verificacao->dica }}</p> @endif
                            </div>
                        </div>
                        <a href="{{ route($item['rota']) }}" wire:navigate class="shrink-0 rounded-lg border border-linha px-3 py-1.5 text-xs font-medium hover:bg-fundo focus:outline-2 focus:outline-marca-600">Abrir</a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section aria-labelledby="titulo-atalhos">
        <h2 id="titulo-atalhos" class="sr-only">Atalhos</h2>
        <div class="grid gap-3 sm:grid-cols-3">
            <a href="{{ route('admin.usuarios') }}" wire:navigate class="{{ $atalho }}"><span class="font-semibold">Gerenciar usuários</span><span class="mt-1 text-sm text-tinta-suave">Criar contas, trocar perfis, redefinir senhas e encerrar acessos.</span></a>
            <a href="{{ route('admin.integracoes') }}" wire:navigate class="{{ $atalho }}"><span class="font-semibold">Atualizar integrações</span><span class="mt-1 text-sm text-tinta-suave">Ver a última carga de cada fonte, configurar chaves e forçar uma atualização.</span></a>
            <a href="{{ route('admin.indices') }}" wire:navigate class="{{ $atalho }}"><span class="font-semibold">Recalcular índices</span><span class="mt-1 text-sm text-tinta-suave">Ajustar pesos do INA e do IDAPS e atualizar os números.</span></a>
        </div>
    </section>

    <section class="rounded-2xl border border-linha bg-white shadow-sm" aria-labelledby="titulo-atividade">
        <div class="flex items-center justify-between border-b border-linha px-5 py-4">
            <h2 id="titulo-atividade" class="text-lg font-semibold">Atividade recente</h2>
            <a href="{{ route('admin.auditoria') }}" wire:navigate class="text-sm font-medium text-marca-800 underline">Ver toda a auditoria</a>
        </div>
        @if ($atividade->isEmpty())
            <p class="px-5 py-6 text-sm text-tinta-suave">Nenhum registro ainda.</p>
        @else
            <ul class="divide-y divide-linha text-sm">
                @foreach ($atividade as $registro)
                    @php $resumoDoRegistro = \App\Administracao\RotulosDeAuditoria::resumo($registro); @endphp
                    <li class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5 px-5 py-2.5">
                        <span><span class="font-medium">{{ \App\Administracao\RotulosDeAuditoria::rotulo($registro->evento) }}</span> <span class="text-tinta-suave">· {{ $registro->user?->name ?? 'Sistema ou visitante' }}@if ($resumoDoRegistro !== '') · {{ $resumoDoRegistro }}@endif</span></span>
                        <time class="text-xs tabular-nums text-tinta-suave" datetime="{{ $registro->created_at->toIso8601String() }}">{{ $registro->created_at->format('d/m/Y H:i') }}</time>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
