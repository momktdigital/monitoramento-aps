<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name') }} · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen">
    <header class="border-b border-linha bg-white">
        <div class="mx-auto flex max-w-4xl items-center justify-between gap-4 px-4 py-4 sm:px-8">
            <div>
                <p class="text-xs font-semibold uppercase tracking-widest text-marca-700">Atenção Primária à Saúde</p>
                <p class="text-base font-semibold">{{ config('app.name') }}</p>
            </div>
            @if (Route::has('login'))
                <a href="{{ route('login') }}" class="rounded-lg border border-linha bg-white px-3 py-2 text-sm font-semibold hover:bg-fundo focus:outline-2 focus:outline-offset-2 focus:outline-marca-600">Entrar</a>
            @endif
        </div>
    </header>

    <main class="mx-auto max-w-4xl px-4 py-8 sm:px-8 sm:py-10">
        {{ $slot }}
    </main>

    <footer class="mx-auto max-w-4xl px-4 pb-10 text-xs text-tinta-suave sm:px-8">
        Esta página é pública e traz apenas informações gerais sobre a metodologia e as fontes de dados.
    </footer>
</body>
</html>
