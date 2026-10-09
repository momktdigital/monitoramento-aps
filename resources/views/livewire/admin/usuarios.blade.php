@php
    $campoClasses = 'mt-1.5 block w-full rounded-lg border border-linha bg-white px-3 py-2.5 text-sm focus:border-marca-600 focus:outline-2 focus:outline-marca-600/30';
    $titulosDeAcao = [
        'senha' => ['Redefinir a senha', 'Uma nova senha temporária será gerada, as sessões abertas serão encerradas e a pessoa terá de trocá-la no próximo acesso.', 'Redefinir senha'],
        'dois-fatores' => ['Refazer a verificação em duas etapas', 'O aplicativo autenticador atual deixa de valer e as sessões abertas são encerradas. Administradores precisarão configurar tudo de novo ao entrar.', 'Remover verificação'],
        'sessoes' => ['Encerrar as sessões abertas', 'A pessoa será desconectada de todos os dispositivos e precisará entrar de novo.', 'Encerrar sessões'],
        'desativar' => ['Desativar a conta', 'A pessoa deixa de conseguir entrar e as sessões abertas são encerradas. Nada é apagado: você pode reativar depois.', 'Desativar conta'],
        'excluir' => ['Excluir a conta', 'A conta e o painel personalizado dela são apagados e isso não pode ser desfeito. O histórico de auditoria é mantido.', 'Excluir definitivamente'],
    ];
@endphp

<div class="space-y-6">
    <x-admin.cabecalho titulo="Usuários" descricao="Quem acessa a plataforma e com qual perfil. Não há cadastro público: as contas são criadas aqui, e quem recebe uma senha temporária precisa trocá-la no primeiro acesso.">
        <x-slot:acoes>
            <x-ui.botao type="button" wire:click="novo"><x-ui.icone nome="mais" class="mr-1.5 size-4" /> Novo usuário</x-ui.botao>
        </x-slot:acoes>
    </x-admin.cabecalho>

    <dl class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-admin.numero rotulo="Contas" :valor="$totais['todos']" />
        <x-admin.numero rotulo="Ativas" :valor="$totais['ativos']" :detalhe="($totais['todos'] - $totais['ativos']).' desativada(s)'" />
        <x-admin.numero rotulo="Administradores ativos" :valor="$totais['administradores']" :tom="$totais['administradores'] < 2 ? 'alerta' : 'normal'" :detalhe="$totais['administradores'] < 2 ? 'Recomenda-se ter pelo menos dois.' : null" />
        <x-admin.numero rotulo="Aguardando troca de senha" :valor="$totais['pendentes']" :tom="$totais['pendentes'] > 0 ? 'alerta' : 'normal'" />
    </dl>

    <section class="rounded-2xl border border-linha bg-white shadow-sm" aria-label="Lista de usuários">
        <div class="grid gap-3 border-b border-linha p-4 sm:grid-cols-[1fr_auto_auto]">
            <div>
                <label for="busca" class="sr-only">Buscar por nome ou e-mail</label>
                <input id="busca" type="search" wire:model.live.debounce.300ms="busca" placeholder="Buscar por nome ou e-mail" class="block w-full rounded-lg border border-linha bg-white px-3 py-2.5 text-sm focus:border-marca-600 focus:outline-2 focus:outline-marca-600/30">
            </div>
            <div>
                <label for="filtro-perfil" class="sr-only">Perfil</label>
                <select id="filtro-perfil" wire:model.live="perfil" class="block w-full rounded-lg border border-linha bg-white px-3 py-2.5 text-sm">
                    <option value="">Todos os perfis</option>
                    @foreach ($perfis as $opcao)
                        <option value="{{ $opcao->value }}">{{ $opcao->rotulo() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="filtro-situacao" class="sr-only">Situação</label>
                <select id="filtro-situacao" wire:model.live="situacao" class="block w-full rounded-lg border border-linha bg-white px-3 py-2.5 text-sm">
                    <option value="">Todas as situações</option>
                    <option value="ativos">Ativos</option>
                    <option value="inativos">Desativados</option>
                    <option value="pendentes">Aguardando troca de senha</option>
                </select>
            </div>
        </div>

        @if ($usuarios->isEmpty())
            <p class="p-8 text-center text-sm text-tinta-suave">Nenhum usuário encontrado com esses filtros.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <caption class="sr-only">Usuários cadastrados</caption>
                    <thead class="border-b border-linha text-xs uppercase tracking-wide text-tinta-suave">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">Usuário</th>
                            <th scope="col" class="px-4 py-3 font-medium">Perfil</th>
                            <th scope="col" class="px-4 py-3 font-medium">Situação</th>
                            <th scope="col" class="px-4 py-3 font-medium">2 etapas</th>
                            <th scope="col" class="px-4 py-3 font-medium">Último acesso</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium"><span class="sr-only">Ações</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-linha">
                        @foreach ($usuarios as $usuario)
                            <tr wire:key="usuario-{{ $usuario->id }}">
                                <td class="px-4 py-3">
                                    <button type="button" wire:click="detalhes({{ $usuario->id }})" class="text-left font-medium text-marca-800 hover:underline focus:outline-2 focus:outline-marca-600">{{ $usuario->name }}@if ($usuario->is(auth()->user())) <span class="font-normal text-tinta-suave">(você)</span>@endif</button>
                                    <p class="text-xs text-tinta-suave">{{ $usuario->email }}</p>
                                </td>
                                <td class="px-4 py-3">{{ $usuario->perfil->rotulo() }}</td>
                                <td class="px-4 py-3">
                                    @if (! $usuario->ativo)
                                        <x-admin.selo cor="cinza">Desativado</x-admin.selo>
                                    @elseif ($usuario->deve_alterar_senha)
                                        <x-admin.selo cor="ambar">Aguardando troca de senha</x-admin.selo>
                                    @else
                                        <x-admin.selo cor="verde">Ativo</x-admin.selo>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @if ($usuario->hasEnabledTwoFactorAuthentication())
                                        <x-admin.selo cor="verde">Ativada</x-admin.selo>
                                    @elseif ($usuario->perfil->exigeDoisFatores())
                                        <x-admin.selo cor="ambar">Pendente</x-admin.selo>
                                    @else
                                        <span class="text-xs text-tinta-suave">Opcional</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs text-tinta-suave">{{ $usuario->ultimo_acesso_em?->diffForHumans() ?? 'Nunca entrou' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <div class="inline-flex gap-1">
                                        <button type="button" wire:click="editar({{ $usuario->id }})" class="rounded-lg p-2 text-tinta-suave hover:bg-fundo hover:text-tinta focus:outline-2 focus:outline-marca-600" aria-label="Editar {{ $usuario->name }}" title="Editar"><x-ui.icone nome="lapis" class="size-4" /></button>
                                        <button type="button" wire:click="detalhes({{ $usuario->id }})" class="rounded-lg border border-linha px-3 py-1.5 text-xs font-medium hover:bg-fundo focus:outline-2 focus:outline-marca-600">Gerenciar</button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($usuarios->hasPages())
                <div class="border-t border-linha p-4">{{ $usuarios->links() }}</div>
            @endif
        @endif
    </section>

    <section class="rounded-2xl border border-linha bg-white p-5 shadow-sm" aria-labelledby="titulo-perfis">
        <h2 id="titulo-perfis" class="text-base font-semibold">O que cada perfil pode fazer</h2>
        <dl class="mt-3 grid gap-4 text-sm sm:grid-cols-3">
            <div>
                <dt class="font-medium">Administrador</dt>
                <dd class="mt-1 text-tinta-suave">Acessa tudo, inclusive esta área de administração. Precisa ativar a verificação em duas etapas.</dd>
            </div>
            <div>
                <dt class="font-medium">Gestor</dt>
                <dd class="mt-1 text-tinta-suave">Usa o painel, as análises e monta o próprio painel. Não entra na administração.</dd>
            </div>
            <div>
                <dt class="font-medium">Leitura</dt>
                <dd class="mt-1 text-tinta-suave">Consulta o painel e as análises. Hoje tem o mesmo acesso do gestor; a diferença será usada nos alertas e relatórios.</dd>
            </div>
        </dl>
    </section>

    {{-- Formulário de criação e edição --}}
    @if ($janela === 'formulario')
        <x-admin.modal id="formulario" :titulo="$usuarioId ? 'Editar usuário' : 'Novo usuário'" fechar="fechar" :descricao="$usuarioId ? null : 'Informe quem é a pessoa e qual perfil ela terá.'">
            <form wire:submit="salvar" class="space-y-4">
                @error('formulario')
                    <div role="alert" class="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900">{{ $message }}</div>
                @enderror

                <div>
                    <label for="nome" class="block text-sm font-medium">Nome completo</label>
                    <input id="nome" type="text" x-ref="campo" wire:model="nome" autocomplete="off" class="{{ $campoClasses }}" @error('nome') aria-invalid="true" @enderror>
                    @error('nome') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium">E-mail</label>
                    <input id="email" type="email" wire:model="email" autocomplete="off" class="{{ $campoClasses }}" @error('email') aria-invalid="true" @enderror>
                    @error('email') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="perfilDoFormulario" class="block text-sm font-medium">Perfil</label>
                        <select id="perfilDoFormulario" wire:model.live="perfilDoFormulario" class="{{ $campoClasses }}">
                            @foreach ($perfis as $opcao)
                                <option value="{{ $opcao->value }}">{{ $opcao->rotulo() }}</option>
                            @endforeach
                        </select>
                        @error('perfilDoFormulario') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="municipioId" class="block text-sm font-medium">Município padrão <span class="font-normal text-tinta-suave">(opcional)</span></label>
                        <select id="municipioId" wire:model="municipioId" class="{{ $campoClasses }}">
                            <option value="">Nenhum</option>
                            @foreach ($municipios as $municipio)
                                <option value="{{ $municipio->id }}">{{ $municipio->nome }}/{{ $municipio->uf }}</option>
                            @endforeach
                        </select>
                        @error('municipioId') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                </div>

                @if ($perfilDoFormulario === 'admin')
                    <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900">Administradores acessam toda a administração e serão obrigados a ativar a verificação em duas etapas no primeiro acesso.</p>
                @endif

                @if (! $usuarioId)
                    <fieldset class="space-y-3 rounded-lg border border-linha p-4">
                        <legend class="px-1 text-sm font-medium">Senha de acesso</legend>
                        <label class="flex items-start gap-2 text-sm">
                            <input type="radio" wire:model.live="modoDeSenha" value="gerar" class="mt-0.5 accent-marca-600">
                            <span><strong>Gerar uma senha temporária</strong> <span class="text-tinta-suave">— ela aparece uma única vez, para você repassar à pessoa.</span></span>
                        </label>
                        <label class="flex items-start gap-2 text-sm">
                            <input type="radio" wire:model.live="modoDeSenha" value="definir" class="mt-0.5 accent-marca-600">
                            <span><strong>Definir a senha agora</strong></span>
                        </label>
                        @if ($modoDeSenha === 'definir')
                            <div>
                                <label for="senha" class="block text-sm font-medium">Senha</label>
                                <div class="mt-1.5 flex gap-2">
                                    <input id="senha" type="text" wire:model="senha" autocomplete="off" class="block w-full rounded-lg border border-linha bg-white px-3 py-2.5 font-mono text-sm focus:border-marca-600 focus:outline-2 focus:outline-marca-600/30">
                                    <button type="button" wire:click="gerarSenhaTemporaria" class="shrink-0 rounded-lg border border-linha px-3 text-sm font-medium hover:bg-fundo">Sugerir</button>
                                </div>
                                @error('senha') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                                <p class="mt-1.5 text-xs text-tinta-suave">Mínimo de 12 caracteres, com maiúsculas, minúsculas, números e símbolos.</p>
                            </div>
                        @endif
                        <p class="text-xs text-tinta-suave">Em qualquer caso, a pessoa será obrigada a trocar a senha no primeiro acesso.</p>
                    </fieldset>
                @endif

                @if ($editandoPerfil)
                    <div>
                        <label for="senhaAtual" class="block text-sm font-medium">Confirme a sua senha para mudar o perfil</label>
                        <input id="senhaAtual" type="password" wire:model="senhaAtual" autocomplete="current-password" class="{{ $campoClasses }}">
                        @error('senhaAtual') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                @endif

                <div class="flex justify-end gap-2 pt-2">
                    <x-ui.botao type="button" tipo="secundario" wire:click="fechar">Cancelar</x-ui.botao>
                    <x-ui.botao wire:loading.attr="disabled" wire:target="salvar">{{ $usuarioId ? 'Salvar alterações' : 'Criar usuário' }}</x-ui.botao>
                </div>
            </form>
        </x-admin.modal>
    @endif

    {{-- Detalhes e ações sobre uma conta --}}
    @if ($janela === 'detalhes' && $selecionado)
        @php $ehVoce = $selecionado->is(auth()->user()); @endphp
        <x-admin.modal id="detalhes" :titulo="$selecionado->name" fechar="fechar" largura="max-w-2xl" :descricao="$selecionado->email">
            <div class="space-y-6">
                <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-xs text-tinta-suave">Perfil</dt><dd class="font-medium">{{ $selecionado->perfil->rotulo() }}</dd></div>
                    <div>
                        <dt class="text-xs text-tinta-suave">Situação</dt>
                        <dd>
                            @if (! $selecionado->ativo) <x-admin.selo cor="cinza">Desativado</x-admin.selo>
                            @elseif ($selecionado->deve_alterar_senha) <x-admin.selo cor="ambar">Aguardando troca de senha</x-admin.selo>
                            @else <x-admin.selo cor="verde">Ativo</x-admin.selo> @endif
                        </dd>
                    </div>
                    <div><dt class="text-xs text-tinta-suave">Verificação em duas etapas</dt><dd class="font-medium">{{ $selecionado->hasEnabledTwoFactorAuthentication() ? 'Ativada' : 'Não configurada' }}</dd></div>
                    <div><dt class="text-xs text-tinta-suave">Sessões abertas agora</dt><dd class="font-medium">{{ $sessoesAbertas }}</dd></div>
                    <div><dt class="text-xs text-tinta-suave">Último acesso</dt><dd class="font-medium">{{ $selecionado->ultimo_acesso_em ? $selecionado->ultimo_acesso_em->format('d/m/Y H:i').' · IP '.$selecionado->ultimo_acesso_ip : 'Nunca entrou' }}</dd></div>
                    <div><dt class="text-xs text-tinta-suave">Município padrão</dt><dd class="font-medium">{{ $selecionado->municipio ? $selecionado->municipio->nome.'/'.$selecionado->municipio->uf : 'Nenhum' }}</dd></div>
                    <div><dt class="text-xs text-tinta-suave">Conta criada</dt><dd class="font-medium">{{ $selecionado->created_at->format('d/m/Y') }}@if ($selecionado->criadoPor) por {{ $selecionado->criadoPor->name }}@endif</dd></div>
                </dl>

                <div>
                    <h3 class="text-sm font-semibold">Ações</h3>
                    @if ($ehVoce)
                        <p class="mt-2 text-sm text-tinta-suave">Esta é a sua conta. Para trocar a senha ou refazer a verificação em duas etapas use <a href="{{ route('conta.seguranca') }}" wire:navigate class="font-medium text-marca-800 underline">Segurança da conta</a>. Por segurança, você não pode se desativar, excluir ou mudar o próprio perfil.</p>
                    @else
                        <p class="mt-1 text-xs text-tinta-suave">As ações abaixo pedem a sua senha para confirmar.</p>
                    @endif
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" wire:click="editar({{ $selecionado->id }})" class="rounded-lg border border-linha px-3 py-2 text-sm font-medium hover:bg-fundo">Editar dados e perfil</button>
                        @unless ($ehVoce)
                            <button type="button" wire:click="pedirConfirmacao('senha', {{ $selecionado->id }})" class="rounded-lg border border-linha px-3 py-2 text-sm font-medium hover:bg-fundo">Redefinir senha</button>
                            <button type="button" wire:click="pedirConfirmacao('dois-fatores', {{ $selecionado->id }})" class="rounded-lg border border-linha px-3 py-2 text-sm font-medium hover:bg-fundo" @disabled(! $selecionado->hasEnabledTwoFactorAuthentication())>Refazer 2 etapas</button>
                            <button type="button" wire:click="pedirConfirmacao('sessoes', {{ $selecionado->id }})" class="rounded-lg border border-linha px-3 py-2 text-sm font-medium hover:bg-fundo" @disabled($sessoesAbertas === 0)>Encerrar sessões</button>
                            @if ($selecionado->ativo)
                                <button type="button" wire:click="pedirConfirmacao('desativar', {{ $selecionado->id }})" class="rounded-lg border border-amber-300 px-3 py-2 text-sm font-medium text-amber-900 hover:bg-amber-50">Desativar conta</button>
                            @else
                                <button type="button" wire:click="alternarSituacao({{ $selecionado->id }})" class="rounded-lg border border-emerald-300 px-3 py-2 text-sm font-medium text-emerald-900 hover:bg-emerald-50">Reativar conta</button>
                            @endif
                            <button type="button" wire:click="pedirConfirmacao('excluir', {{ $selecionado->id }})" class="rounded-lg border border-red-300 px-3 py-2 text-sm font-medium text-red-800 hover:bg-red-50">Excluir</button>
                        @endunless
                    </div>
                </div>

                <div>
                    <h3 class="text-sm font-semibold">Atividade recente</h3>
                    @if ($historico->isEmpty())
                        <p class="mt-2 text-sm text-tinta-suave">Nenhum registro na auditoria.</p>
                    @else
                        <ul class="mt-2 divide-y divide-linha text-sm">
                            @foreach ($historico as $item)
                                <li class="flex justify-between gap-4 py-2"><span>{{ $item['texto'] }}</span><time class="shrink-0 text-xs text-tinta-suave" datetime="{{ $item['quando']->toIso8601String() }}">{{ $item['quando']->format('d/m/Y H:i') }}</time></li>
                            @endforeach
                        </ul>
                        <a href="{{ route('admin.auditoria', ['q' => $selecionado->email]) }}" wire:navigate class="mt-2 inline-block text-xs font-medium text-marca-800 underline">Ver na auditoria</a>
                    @endif
                </div>
            </div>
        </x-admin.modal>
    @endif

    {{-- Confirmação com senha para ações que mudam acessos --}}
    @if ($janela === 'confirmar' && $acao && isset($titulosDeAcao[$acao]))
        <x-admin.modal id="confirmar" :titulo="$titulosDeAcao[$acao][0]" fechar="fechar" :descricao="$titulosDeAcao[$acao][1]">
            <form wire:submit="confirmar" class="space-y-4">
                <div>
                    <label for="senhaAtual" class="block text-sm font-medium">Digite a sua senha para confirmar</label>
                    <input id="senhaAtual" type="password" x-ref="campo" wire:model="senhaAtual" autocomplete="current-password" class="{{ $campoClasses }}" @error('senhaAtual') aria-invalid="true" @enderror>
                    @error('senhaAtual') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <div class="flex justify-end gap-2">
                    <x-ui.botao type="button" tipo="secundario" wire:click="fechar">Cancelar</x-ui.botao>
                    <x-ui.botao :tipo="in_array($acao, ['excluir', 'desativar'], true) ? 'perigo' : 'primario'" wire:loading.attr="disabled" wire:target="confirmar">{{ $titulosDeAcao[$acao][2] }}</x-ui.botao>
                </div>
            </form>
        </x-admin.modal>
    @endif

    {{-- Senha temporária: aparece uma única vez --}}
    @if ($janela === 'senha-gerada' && $senhaGerada)
        <x-admin.modal id="senha-gerada" titulo="Senha temporária" fechar="fechar" :descricao="$contextoDaSenha">
            <div class="space-y-4" x-data="copiar">
                <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                    <strong>Anote agora: esta senha não será mostrada de novo.</strong> Envie-a à pessoa por um canal seguro. No primeiro acesso ela será obrigada a escolher uma senha nova.
                </div>
                <div class="flex gap-2">
                    <label for="senha-temporaria" class="sr-only">Senha temporária</label>
                    <input id="senha-temporaria" x-ref="valor" type="text" readonly value="{{ $senhaGerada }}" class="block w-full rounded-lg border border-linha bg-fundo px-3 py-2.5 font-mono text-base font-semibold tracking-wide">
                    <button type="button" x-on:click="copiar" x-text="rotulo" class="shrink-0 rounded-lg border border-linha px-4 text-sm font-semibold hover:bg-fundo focus:outline-2 focus:outline-marca-600"></button>
                </div>
                <div class="flex justify-end">
                    <x-ui.botao type="button" wire:click="fechar">Já anotei, fechar</x-ui.botao>
                </div>
            </div>
        </x-admin.modal>
    @endif
</div>
