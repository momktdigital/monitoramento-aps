<div class="space-y-8">
    <header>
        <h1 class="text-2xl font-semibold">Segurança da conta</h1>
        <p class="mt-1 text-sm text-tinta-suave">Proteja seu acesso com verificação em duas etapas e uma senha forte.</p>
    </header>

    @if (session('aviso'))
        <div role="alert" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ session('aviso') }}</div>
    @endif
    @if (session('sucesso'))
        <div role="status" class="rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ session('sucesso') }}</div>
    @endif

    <section class="rounded-2xl border border-linha bg-white p-6 shadow-sm" aria-labelledby="titulo-2fa">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 id="titulo-2fa" class="text-lg font-semibold">Verificação em duas etapas</h2>
                <p class="mt-1 text-sm text-tinta-suave">
                    Além da senha, você informa um código gerado por um aplicativo autenticador (Google Authenticator, Microsoft Authenticator, Authy…).
                    @if ($obrigatorio) <strong>Obrigatória para o seu perfil.</strong> @endif
                </p>
            </div>
            <span @class([
                'shrink-0 rounded-full px-3 py-1 text-xs font-semibold',
                'bg-emerald-100 text-emerald-800' => $doisFatoresAtivo,
                'bg-amber-100 text-amber-800' => ! $doisFatoresAtivo,
            ])>{{ $doisFatoresAtivo ? 'Ativada' : 'Desativada' }}</span>
        </div>

        @if (! $doisFatoresAtivo && ! $doisFatoresPendente)
            <x-ui.botao type="button" wire:click="iniciarDoisFatores" class="mt-5">Ativar verificação em duas etapas</x-ui.botao>
        @endif

        @if ($doisFatoresPendente)
            <div class="mt-6 grid gap-6 sm:grid-cols-[auto_1fr]">
                <div class="w-44 rounded-lg border border-linha bg-white p-2" aria-label="QR Code para o aplicativo autenticador">{!! $qrCodeSvg !!}</div>
                <div class="space-y-4">
                    <ol class="list-decimal space-y-1 pl-5 text-sm">
                        <li>Abra o aplicativo autenticador e escaneie o QR Code.</li>
                        <li>Se não puder escanear, digite esta chave: <code class="rounded bg-fundo px-1.5 py-0.5 text-xs font-semibold">{{ $chaveManual }}</code></li>
                        <li>Digite abaixo o código de 6 dígitos que o aplicativo mostrar.</li>
                    </ol>
                    <form wire:submit="confirmarDoisFatores" class="max-w-xs space-y-3">
                        <div>
                            <label for="codigoConfirmacao" class="block text-sm font-medium">Código</label>
                            <input id="codigoConfirmacao" type="text" inputmode="numeric" autocomplete="one-time-code" wire:model="codigoConfirmacao" class="mt-1.5 block w-full rounded-lg border border-linha px-3 py-2.5 text-sm focus:border-marca-600 focus:outline-2 focus:outline-marca-600/30">
                            @error('codigoConfirmacao') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                        </div>
                        <x-ui.botao>Confirmar e ativar</x-ui.botao>
                    </form>
                </div>
            </div>
        @endif

        @if ($doisFatoresAtivo)
            @if ($exibirCodigosRecuperacao)
                <div class="mt-6 rounded-lg border border-amber-300 bg-amber-50 p-4">
                    <p class="text-sm font-semibold text-amber-900">Guarde estes códigos de recuperação em um lugar seguro.</p>
                    <p class="mt-1 text-sm text-amber-900">Cada um pode ser usado uma única vez caso você perca o acesso ao aplicativo. Eles não serão mostrados novamente.</p>
                    <ul class="mt-3 grid grid-cols-2 gap-2 font-mono text-sm">
                        @foreach ($codigosRecuperacao as $codigo)
                            <li class="rounded bg-white px-2 py-1">{{ $codigo }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="mt-6 max-w-sm space-y-3">
                <div>
                    <label for="senhaParaAlterarDoisFatores" class="block text-sm font-medium">Sua senha atual</label>
                    <input id="senhaParaAlterarDoisFatores" type="password" autocomplete="current-password" wire:model="senhaParaAlterarDoisFatores" class="mt-1.5 block w-full rounded-lg border border-linha px-3 py-2.5 text-sm focus:border-marca-600 focus:outline-2 focus:outline-marca-600/30">
                    @error('senhaParaAlterarDoisFatores') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <div class="flex flex-wrap gap-3">
                    <x-ui.botao type="button" tipo="secundario" wire:click="gerarNovosCodigosRecuperacao">Gerar novos códigos</x-ui.botao>
                    @unless ($obrigatorio)
                        <x-ui.botao type="button" tipo="perigo" wire:click="desativarDoisFatores" wire:confirm="Desativar a verificação em duas etapas reduz a segurança da conta. Continuar?">Desativar</x-ui.botao>
                    @endunless
                </div>
            </div>
        @endif
    </section>

    <section class="rounded-2xl border border-linha bg-white p-6 shadow-sm" aria-labelledby="titulo-senha">
        <h2 id="titulo-senha" class="text-lg font-semibold">Alterar senha</h2>
        <p class="mt-1 text-sm text-tinta-suave">Mínimo de 12 caracteres, com letras maiúsculas e minúsculas, números e símbolos. Ao alterar, as outras sessões abertas serão encerradas.</p>

        <form wire:submit="alterarSenha" class="mt-5 max-w-sm space-y-4">
            @foreach ([['current_password', 'Senha atual', 'current-password'], ['password', 'Nova senha', 'new-password'], ['password_confirmation', 'Confirmar nova senha', 'new-password']] as [$campo, $rotulo, $autocomplete])
                <div>
                    <label for="{{ $campo }}" class="block text-sm font-medium">{{ $rotulo }}</label>
                    <input id="{{ $campo }}" type="password" autocomplete="{{ $autocomplete }}" wire:model="{{ $campo }}" class="mt-1.5 block w-full rounded-lg border border-linha px-3 py-2.5 text-sm focus:border-marca-600 focus:outline-2 focus:outline-marca-600/30">
                    @error($campo) <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
            @endforeach
            <x-ui.botao>Alterar senha</x-ui.botao>
        </form>
    </section>
</div>
