<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $titulo ?? 'Entrar' }} · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen">
    <main class="mx-auto flex min-h-screen w-full max-w-md flex-col justify-center px-4 py-10">
        <div class="mb-8 text-center">
            <p class="text-xs font-semibold uppercase tracking-widest text-marca-700">Atenção Primária à Saúde</p>
            <h1 class="mt-2 text-2xl font-semibold">{{ config('app.name') }}</h1>
        </div>

        <div class="rounded-2xl border border-linha bg-white p-6 shadow-sm sm:p-8">
            {{ $slot }}
        </div>

        <p class="mt-6 text-center text-xs text-tinta-suave">Acesso restrito a usuários autorizados.</p>
    </main>
</body>
</html>
