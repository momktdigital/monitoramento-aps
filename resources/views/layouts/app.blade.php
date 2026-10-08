<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? config('app.name') }} · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen lg:flex">
    <aside class="border-b border-linha bg-white lg:flex lg:min-h-screen lg:w-60 lg:shrink-0 lg:flex-col lg:border-r lg:border-b-0">
        <div class="px-5 py-4">
            <p class="text-xs font-semibold uppercase tracking-widest text-marca-700">APS</p>
            <p class="text-base font-semibold">{{ config('app.name') }}</p>
        </div>

        <nav class="flex gap-1 overflow-x-auto px-3 pb-3 lg:flex-col lg:pb-0" aria-label="Navegação principal">
            @php
                $itens = [
                    ['painel', 'Meu painel'],
                    ['matriz', 'Matriz de prioridade'],
                    ['comparar', 'Comparar municípios'],
                    ['mapa', 'Mapa'],
                    ['metodologia', 'Metodologia'],
                    ['conta.seguranca', 'Segurança da conta'],
                ];

                if (auth()->user()->can('gerenciar-integracoes')) {
                    array_splice($itens, 5, 0, [['integracoes', 'Integrações']]);
                }
            @endphp
            @foreach ($itens as [$rota, $rotulo])
                <a href="{{ route($rota) }}" wire:navigate @class([
                    'whitespace-nowrap rounded-lg px-3 py-2 text-sm font-medium',
                    'bg-marca-50 text-marca-900' => request()->routeIs($rota),
                    'text-tinta-suave hover:bg-fundo hover:text-tinta' => ! request()->routeIs($rota),
                ]) @if (request()->routeIs($rota)) aria-current="page" @endif>{{ $rotulo }}</a>
            @endforeach
        </nav>

        <div class="hidden border-t border-linha px-5 py-4 text-sm lg:mt-auto lg:block">
            <p class="truncate font-medium">{{ auth()->user()->name }}</p>
            <p class="text-xs text-tinta-suave">{{ auth()->user()->perfil->rotulo() }}</p>
            <form method="POST" action="{{ route('logout') }}" class="mt-3">
                @csrf
                <button type="submit" class="text-sm font-medium text-marca-700 underline">Sair</button>
            </form>
        </div>
    </aside>

    <main class="min-w-0 flex-1 px-4 py-6 sm:px-8 sm:py-8">
        <div class="mb-4 flex justify-end lg:hidden">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="text-sm font-medium text-marca-700 underline">Sair</button>
            </form>
        </div>
        <div class="mx-auto max-w-6xl">
            {{ $slot }}
        </div>
    </main>
</body>
</html>
