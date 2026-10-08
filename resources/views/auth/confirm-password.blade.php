<x-layouts.guest titulo="Confirmar senha">
    <h2 class="text-lg font-semibold">Confirme sua senha</h2>
    <p class="mt-2 text-sm text-tinta-suave">Esta é uma área protegida. Confirme sua senha para continuar.</p>

    <form method="POST" action="{{ route('password.confirm.store') }}" class="mt-6 space-y-5">
        @csrf

        <x-ui.campo nome="password" rotulo="Senha" tipo="password" required autofocus autocomplete="current-password" />

        <x-ui.botao class="w-full">Confirmar</x-ui.botao>
    </form>
</x-layouts.guest>
