@php
    $campoClasses = 'block w-full rounded-lg border border-linha bg-white px-3 py-2.5 text-sm focus:border-marca-600 focus:outline-2 focus:outline-marca-600/30';
@endphp

<div class="space-y-6">
    <x-admin.cabecalho titulo="Indicadores" descricao="O catálogo do que a plataforma mede. Aqui você liga e desliga indicadores, escolhe o que os usuários veem e ajusta os textos do botão “?” dos visuais e da página de Metodologia.">
    </x-admin.cabecalho>

    <dl class="grid grid-cols-3 gap-3">
        <x-admin.numero rotulo="Indicadores" :valor="$totais['todos']" />
        <x-admin.numero rotulo="Ativos" :valor="$totais['ativos']" detalhe="Coletados e calculados" />
        <x-admin.numero rotulo="Ocultos" :valor="$totais['ocultos']" detalhe="Auxiliares, fora das listas" />
    </dl>

    <div class="grid gap-3 sm:grid-cols-[1fr_auto]">
        <div>
            <label for="busca" class="sr-only">Buscar indicador</label>
            <input id="busca" type="search" wire:model.live.debounce.300ms="busca" placeholder="Buscar por nome ou código" class="{{ $campoClasses }}">
        </div>
        <div>
            <label for="filtro-dimensao" class="sr-only">Dimensão</label>
            <select id="filtro-dimensao" wire:model.live="dimensao" class="{{ $campoClasses }}">
                <option value="">Todas as dimensões</option>
                @foreach ($dimensoes as $opcao)
                    <option value="{{ $opcao->value }}">{{ $opcao->rotulo() }}</option>
                @endforeach
            </select>
        </div>
    </div>

    @forelse ($grupos as $dimensaoRotulo => $indicadores)
        <section class="rounded-2xl border border-linha bg-white shadow-sm" aria-labelledby="dim-{{ $loop->index }}">
            <h2 id="dim-{{ $loop->index }}" class="border-b border-linha px-4 py-3 text-sm font-semibold">{{ $dimensaoRotulo }} <span class="font-normal text-tinta-suave">· {{ $indicadores->count() }}</span></h2>
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <caption class="sr-only">Indicadores de {{ $dimensaoRotulo }}</caption>
                    <thead class="border-b border-linha text-xs uppercase tracking-wide text-tinta-suave">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">Indicador</th>
                            <th scope="col" class="px-4 py-3 font-medium">Dados</th>
                            <th scope="col" class="px-4 py-3 font-medium">Ativo</th>
                            <th scope="col" class="px-4 py-3 font-medium">Visível</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium"><span class="sr-only">Ações</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-linha">
                        @foreach ($indicadores as $indicador)
                            @php
                                $dados = $cobertura->get($indicador->id);
                                $indices = $usadosNosIndices->get($indicador->codigo, []);
                            @endphp
                            <tr wire:key="indicador-{{ $indicador->id }}" @class(['bg-fundo/60 text-tinta-suave' => ! $indicador->ativo])>
                                <td class="px-4 py-3">
                                    <p class="font-medium">{{ $indicador->nome }}</p>
                                    <p class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-tinta-suave">
                                        <code>{{ $indicador->codigo }}</code>
                                        <span>· {{ strtoupper((string) $indicador->fonte) }}</span>
                                        @foreach ($indices as $rotulo)
                                            <x-admin.selo cor="azul">Entra no {{ $rotulo }}</x-admin.selo>
                                        @endforeach
                                    </p>
                                </td>
                                <td class="px-4 py-3 text-xs text-tinta-suave">
                                    @if ($dados)
                                        {{ $dados->municipios }} município(s)<br>até {{ $indicador->rotuloDaCompetencia((int) $dados->ultima) }}
                                    @else
                                        Sem dados ainda
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <button type="button" role="switch" aria-checked="{{ $indicador->ativo ? 'true' : 'false' }}" wire:click="alternarAtivo({{ $indicador->id }})" aria-label="Ativo: {{ $indicador->nome }}"
                                        @class(['relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition focus:outline-2 focus:outline-offset-2 focus:outline-marca-600', 'bg-marca-600' => $indicador->ativo, 'bg-slate-300' => ! $indicador->ativo])>
                                        <span @class(['inline-block size-5 rounded-full bg-white shadow transition', 'translate-x-5' => $indicador->ativo, 'translate-x-0.5' => ! $indicador->ativo])></span>
                                    </button>
                                </td>
                                <td class="px-4 py-3">
                                    <button type="button" role="switch" aria-checked="{{ $indicador->visivel ? 'true' : 'false' }}" wire:click="alternarVisivel({{ $indicador->id }})" aria-label="Visível: {{ $indicador->nome }}"
                                        @class(['relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition focus:outline-2 focus:outline-offset-2 focus:outline-marca-600', 'bg-marca-600' => $indicador->visivel, 'bg-slate-300' => ! $indicador->visivel])>
                                        <span @class(['inline-block size-5 rounded-full bg-white shadow transition', 'translate-x-5' => $indicador->visivel, 'translate-x-0.5' => ! $indicador->visivel])></span>
                                    </button>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button type="button" wire:click="editar({{ $indicador->id }})" class="rounded-lg border border-linha px-3 py-1.5 text-xs font-medium hover:bg-fundo focus:outline-2 focus:outline-marca-600">Editar textos</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @empty
        <p class="rounded-2xl border border-linha bg-white p-8 text-center text-sm text-tinta-suave shadow-sm">Nenhum indicador encontrado.</p>
    @endforelse

    @if ($editando)
        <x-admin.modal id="editar-indicador" :titulo="$editando->nome" fechar="fechar" largura="max-w-2xl" descricao="Estes textos aparecem no botão “?” de cada visual e na página pública de Metodologia. Escreva para quem não é da área de dados.">
            <form wire:submit="salvar" class="space-y-4">
                <div>
                    <label for="nome" class="block text-sm font-medium">Nome</label>
                    <input id="nome" type="text" x-ref="campo" wire:model="nome" class="mt-1.5 {{ $campoClasses }}">
                    @error('nome') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                @foreach ([['oQueE', 'O que é'], ['comoCalcula', 'Como é calculado'], ['paraQueServe', 'Para que serve'], ['comoInterpretar', 'Como interpretar']] as [$campo, $rotulo])
                    <div>
                        <label for="{{ $campo }}" class="block text-sm font-medium">{{ $rotulo }}</label>
                        <textarea id="{{ $campo }}" rows="3" wire:model="{{ $campo }}" class="mt-1.5 {{ $campoClasses }}"></textarea>
                        @error($campo) <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                @endforeach
                <div class="flex flex-wrap items-center justify-between gap-2 pt-2">
                    <button type="button" wire:click="restaurarTextos" wire:confirm="Substituir o nome e os quatro textos pelo padrão do catálogo? O que você escreveu será perdido." class="rounded-lg px-3 py-2 text-sm font-medium text-tinta-suave hover:bg-fundo"><x-ui.icone nome="restaurar" class="mr-1 inline size-4" /> Restaurar padrão do catálogo</button>
                    <div class="flex gap-2">
                        <x-ui.botao type="button" tipo="secundario" wire:click="fechar">Cancelar</x-ui.botao>
                        <x-ui.botao wire:loading.attr="disabled" wire:target="salvar">Salvar</x-ui.botao>
                    </div>
                </div>
            </form>
        </x-admin.modal>
    @endif
</div>
