<x-layouts.guest titulo="Verificação em duas etapas">
    <h2 class="text-lg font-semibold">Verificação em duas etapas</h2>
    <p class="mt-2 text-sm text-tinta-suave">Digite o código de 6 dígitos do seu aplicativo autenticador.</p>

    <form method="POST" action="{{ route('two-factor.login.store') }}" class="mt-6 space-y-5">
        @csrf

        <x-ui.campo nome="code" rotulo="Código do aplicativo" inputmode="numeric" autocomplete="one-time-code" autofocus />

        <details class="text-sm" @if ($errors->has('recovery_code')) open @endif>
            <summary class="cursor-pointer text-marca-700 underline">Perdi acesso ao aplicativo</summary>
            <div class="mt-4">
                <x-ui.campo nome="recovery_code" rotulo="Código de recuperação" autocomplete="one-time-code" ajuda="Use um dos códigos de emergência salvos ao ativar a verificação." />
            </div>
        </details>

        <x-ui.botao class="w-full">Confirmar</x-ui.botao>
    </form>
</x-layouts.guest>
