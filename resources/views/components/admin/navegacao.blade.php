@php
    // [rota, rótulo, para que serve]
    $secoes = [
        ['admin.inicio', 'Visão geral', 'Pendências e saúde do sistema'],
        ['admin.usuarios', 'Usuários', 'Contas, perfis e acessos'],
        ['admin.integracoes', 'Integrações', 'Fontes de dados, chaves e atualizações'],
        ['admin.municipios', 'Municípios', 'Quais entram na análise e quais são piloto'],
        ['admin.indicadores', 'Indicadores', 'Catálogo, textos e o que aparece para os usuários'],
        ['admin.indices', 'Índices', 'Pesos do INA e do IDAPS, e recálculo'],
        ['admin.auditoria', 'Auditoria', 'Quem fez o quê, e quando'],
        ['admin.sistema', 'Sistema', 'Saúde, filas e manutenção'],
    ];
@endphp

<nav aria-label="Seções da administração" class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
    <ul class="flex gap-1 border-b border-linha">
        @foreach ($secoes as [$rota, $rotulo, $dica])
            @php $ativa = request()->routeIs($rota); @endphp
            <li class="shrink-0">
                <a href="{{ route($rota) }}" wire:navigate title="{{ $dica }}" @if ($ativa) aria-current="page" @endif
                    @class([
                        '-mb-px inline-block border-b-2 px-3.5 py-2.5 text-sm font-medium focus:outline-2 focus:-outline-offset-2 focus:outline-marca-600',
                        'border-marca-600 text-marca-900' => $ativa,
                        'border-transparent text-tinta-suave hover:border-linha hover:text-tinta' => ! $ativa,
                    ])>{{ $rotulo }}</a>
            </li>
        @endforeach
    </ul>
</nav>
