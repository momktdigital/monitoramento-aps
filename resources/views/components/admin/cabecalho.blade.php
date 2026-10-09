@props(['titulo', 'descricao' => null])

{{-- Topo de toda página da administração: título, texto de apoio, ações da página e a navegação entre as seções. --}}
<header class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-widest text-marca-700">Administração</p>
            <h1 class="mt-1 text-2xl font-semibold">{{ $titulo }}</h1>
            @if ($descricao)
                <p class="mt-1 max-w-3xl text-sm text-tinta-suave">{{ $descricao }}</p>
            @endif
        </div>

        @isset($acoes)
            <div class="flex flex-wrap items-center gap-2">{{ $acoes }}</div>
        @endisset
    </div>

    <x-admin.navegacao />
</header>

<x-admin.avisos />
