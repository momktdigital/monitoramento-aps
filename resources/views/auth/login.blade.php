<x-layouts.guest titulo="Entrar">
    <h2 class="text-lg font-semibold">Acesse sua conta</h2>

    <form method="POST" action="{{ route('login.store') }}" class="mt-6 space-y-5">
        @csrf

        <x-ui.campo nome="email" rotulo="E-mail" tipo="email" :value="old('email')" required autofocus autocomplete="username" />
        <x-ui.campo nome="password" rotulo="Senha" tipo="password" required autocomplete="current-password" />

        <x-ui.botao class="w-full">Entrar</x-ui.botao>
    </form>
</x-layouts.guest>
