@php
    $campoClasses = 'block w-full rounded-lg border border-linha bg-white px-3 py-2.5 text-sm focus:border-marca-600 focus:outline-2 focus:outline-marca-600/30';
@endphp

<div class="space-y-6">
    <x-admin.cabecalho titulo="Municípios" descricao="Quais municípios entram na comparação e nos índices (ativos) e quais são acompanhados de perto (piloto). Municípios desativados deixam de contar como pares na comparação.">
        <x-slot:acoes>
            <x-ui.botao type="button" tipo="secundario" wire:click="sincronizar" wire:loading.attr="disabled" wire:target="sincronizar" title="Atualiza nomes e regiões de saúde com o IBGE e o Ministério da Saúde">Sincronizar com o IBGE</x-ui.botao>
        </x-slot:acoes>
    </x-admin.cabecalho>

    <dl class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-admin.numero rotulo="Municípios cadastrados" :valor="$totais['todos']" :detalhe="$totais['ufs'].' estado(s)'" />
        <x-admin.numero rotulo="Ativos na comparação" :valor="$totais['ativos']" :detalhe="($totais['todos'] - $totais['ativos']).' desativado(s)'" />
        <x-admin.numero rotulo="Piloto" :valor="$totais['piloto']" detalhe="Acompanhados de perto" />
        <x-admin.numero rotulo="Regiões de saúde" :valor="$regioes->count()" />
    </dl>

    <section class="rounded-2xl border border-linha bg-white shadow-sm" aria-label="Lista de municípios">
        <div class="grid gap-3 border-b border-linha p-4 sm:grid-cols-[1fr_auto_auto]">
            <div>
                <label for="busca" class="sr-only">Buscar município</label>
                <input id="busca" type="search" wire:model.live.debounce.300ms="busca" placeholder="Buscar município" class="{{ $campoClasses }}">
            </div>
            <div>
                <label for="filtro-regiao" class="sr-only">Região de saúde</label>
                <select id="filtro-regiao" wire:model.live="regiao" class="{{ $campoClasses }}">
                    <option value="">Todas as regiões de saúde</option>
                    @foreach ($regioes as $nome)
                        <option value="{{ $nome }}">{{ $nome }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="filtro-situacao" class="sr-only">Situação</label>
                <select id="filtro-situacao" wire:model.live="situacao" class="{{ $campoClasses }}">
                    <option value="">Todos</option>
                    <option value="ativos">Ativos</option>
                    <option value="inativos">Desativados</option>
                    <option value="piloto">Piloto</option>
                </select>
            </div>
        </div>

        @if ($regiao !== '')
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-linha bg-fundo px-4 py-3 text-sm">
                <p>Região <strong>{{ $regiao }}</strong>: {{ $regiaoEscolhida }} município(s).</p>
                <div class="flex gap-2">
                    <button type="button" wire:click="definirPilotoDaRegiao(true)" wire:confirm="Marcar todos os {{ $regiaoEscolhida }} municípios desta região como piloto?" class="rounded-lg border border-linha bg-white px-3 py-1.5 text-xs font-medium hover:bg-fundo">Marcar toda a região como piloto</button>
                    <button type="button" wire:click="definirPilotoDaRegiao(false)" wire:confirm="Remover a marcação de piloto de todos os municípios desta região?" class="rounded-lg border border-linha bg-white px-3 py-1.5 text-xs font-medium hover:bg-fundo">Remover piloto da região</button>
                </div>
            </div>
        @endif

        @if ($municipios->isEmpty())
            <p class="p-8 text-center text-sm text-tinta-suave">Nenhum município encontrado.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <caption class="sr-only">Municípios cadastrados</caption>
                    <thead class="border-b border-linha text-xs uppercase tracking-wide text-tinta-suave">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">Município</th>
                            <th scope="col" class="px-4 py-3 font-medium">Região de saúde</th>
                            <th scope="col" class="px-4 py-3 font-medium">Ativo na comparação</th>
                            <th scope="col" class="px-4 py-3 font-medium">Piloto</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-linha">
                        @foreach ($municipios as $municipio)
                            <tr wire:key="municipio-{{ $municipio->id }}" @class(['bg-fundo/60 text-tinta-suave' => ! $municipio->ativo])>
                                <td class="px-4 py-3 font-medium">{{ $municipio->nome }} <span class="font-normal text-tinta-suave">{{ $municipio->uf }}</span></td>
                                <td class="px-4 py-3 text-xs text-tinta-suave">{{ $municipio->regiao_saude_nome ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    <button type="button" role="switch" aria-checked="{{ $municipio->ativo ? 'true' : 'false' }}" wire:click="alternarAtivo({{ $municipio->id }})" aria-label="Ativo: {{ $municipio->nome }}"
                                        @class(['relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition focus:outline-2 focus:outline-offset-2 focus:outline-marca-600', 'bg-marca-600' => $municipio->ativo, 'bg-slate-300' => ! $municipio->ativo])>
                                        <span @class(['inline-block size-5 rounded-full bg-white shadow transition', 'translate-x-5' => $municipio->ativo, 'translate-x-0.5' => ! $municipio->ativo])></span>
                                    </button>
                                    <span class="ml-2 text-xs">{{ $municipio->ativo ? 'Ativo' : 'Desativado' }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <button type="button" role="switch" aria-checked="{{ $municipio->piloto ? 'true' : 'false' }}" wire:click="alternarPiloto({{ $municipio->id }})" aria-label="Piloto: {{ $municipio->nome }}"
                                        @class(['relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition focus:outline-2 focus:outline-offset-2 focus:outline-marca-600', 'bg-marca-600' => $municipio->piloto, 'bg-slate-300' => ! $municipio->piloto])>
                                        <span @class(['inline-block size-5 rounded-full bg-white shadow transition', 'translate-x-5' => $municipio->piloto, 'translate-x-0.5' => ! $municipio->piloto])></span>
                                    </button>
                                    <span class="ml-2 text-xs">{{ $municipio->piloto ? 'Piloto' : '—' }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($municipios->hasPages())
                <div class="border-t border-linha p-4">{{ $municipios->links() }}</div>
            @endif
        @endif
    </section>
</div>
