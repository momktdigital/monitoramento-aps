@php
    $colunas = [1 => '', 2 => 'md:col-span-2', 3 => 'md:col-span-2 lg:col-span-3'];
    $campo = 'mt-1.5 block w-full rounded-lg border border-linha bg-white px-3 py-2.5 text-sm focus:border-marca-600 focus:outline-2 focus:outline-marca-600/30';
@endphp

<div class="space-y-5">
    <header>
        <h1 class="text-2xl font-semibold">Meu painel</h1>
        <p class="mt-1 text-sm text-tinta-suave">Os indicadores mais importantes para você, com explicação e análise em linguagem simples.</p>
    </header>

    {{-- Filtros: uma única linha acima dos visuais; valem para todos os cartões --}}
    <div class="flex flex-wrap items-end gap-4 rounded-2xl border border-linha bg-white p-4 shadow-sm">
        <div class="min-w-56 grow sm:grow-0">
            <label for="municipio" class="block text-xs font-medium uppercase tracking-wide text-tinta-suave">Município</label>
            <select id="municipio" wire:model.live="municipioId" class="{{ $campo }}">
                @foreach ($municipios as $regiao => $doGrupo)
                    <optgroup label="{{ $regiao ?: 'Sem região de saúde' }}">
                        @foreach ($doGrupo as $item)
                            <option value="{{ $item->id }}">{{ $item->nome }}{{ $item->piloto ? ' ★' : '' }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </div>

        <div class="min-w-48">
            <label for="meses" class="block text-xs font-medium uppercase tracking-wide text-tinta-suave">Período dos gráficos</label>
            <select id="meses" wire:model.live="meses" class="{{ $campo }}">
                @foreach ($janelas as $valor => $rotulo)
                    <option value="{{ $valor }}">{{ $rotulo }}</option>
                @endforeach
            </select>
        </div>

        <div class="ml-auto flex flex-wrap items-center gap-2">
            @if ($editando)
                <x-ui.botao type="button" wire:click="abrirGaleria">+ Adicionar visual</x-ui.botao>
                <x-ui.botao type="button" tipo="secundario" wire:click="restaurarPadrao" wire:confirm="Voltar ao painel padrão? Seus visuais atuais serão substituídos.">Restaurar padrão</x-ui.botao>
            @endif
            <x-ui.botao type="button" :tipo="$editando ? 'primario' : 'secundario'" wire:click="alternarEdicao">{{ $editando ? 'Concluir edição' : 'Personalizar painel' }}</x-ui.botao>
        </div>
    </div>

    @if ($municipio)
        <p class="text-sm text-tinta-suave">
            Exibindo <strong class="text-tinta">{{ $municipio->nome }}</strong>@if ($municipio->regiao_saude_nome) · região de saúde {{ \Illuminate\Support\Str::title(mb_strtolower($municipio->regiao_saude_nome)) }}@endif
            · {{ $municipio->uf }}
        </p>
    @endif

    @if ($editando)
        <div role="status" class="rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
            <strong>Modo de edição.</strong> Arraste pelo ícone <span aria-hidden="true">⠿</span> ou use as setas para reordenar, escolha a largura de cada visual e remova o que não precisar. Clique em <em>Concluir edição</em> ao terminar.
        </div>
    @endif

    {{-- A grade aceita arrastar e soltar sempre, mas só há "alça" para arrastar no modo de edição --}}
    <div x-data="reordenavel" class="grid items-stretch gap-5 md:grid-cols-2 lg:grid-cols-3">
        @forelse ($widgets as $widget)
            <div wire:key="item-{{ $widget->id }}" data-id="{{ $widget->id }}" class="flex min-w-0 flex-col gap-2 {{ $colunas[$widget->largura] ?? '' }}">
                @if ($editando)
                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-dashed border-marca-600/50 bg-marca-50 px-3 py-2 text-xs" role="group" aria-label="Editar visual {{ $widget->tipo->rotulo() }}">
                        <div class="flex items-center gap-1">
                            <button type="button" data-arrastar title="Arraste para reordenar" aria-label="Arrastar para reordenar"
                                class="flex size-7 cursor-grab items-center justify-center rounded-lg border border-linha bg-white text-tinta-suave hover:bg-white active:cursor-grabbing">⠿</button>
                            <button type="button" wire:click="mover({{ $widget->id }}, 'antes')" aria-label="Mover para antes" title="Mover para antes"
                                class="flex size-7 items-center justify-center rounded-lg border border-linha bg-white text-tinta-suave hover:bg-marca-100">←</button>
                            <button type="button" wire:click="mover({{ $widget->id }}, 'depois')" aria-label="Mover para depois" title="Mover para depois"
                                class="flex size-7 items-center justify-center rounded-lg border border-linha bg-white text-tinta-suave hover:bg-marca-100">→</button>
                        </div>

                        <div class="flex items-center gap-1" role="group" aria-label="Largura do visual">
                            @foreach ([1 => 'Estreito', 2 => 'Médio', 3 => 'Largo'] as $largura => $rotulo)
                                <button type="button" wire:click="alterarLargura({{ $widget->id }}, {{ $largura }})" aria-pressed="{{ $widget->largura === $largura ? 'true' : 'false' }}"
                                    @class([
                                        'rounded-md px-2 py-1 font-medium',
                                        'bg-marca-600 text-white' => $widget->largura === $largura,
                                        'border border-linha bg-white text-tinta hover:bg-marca-100' => $widget->largura !== $largura,
                                    ])>{{ $rotulo }}</button>
                            @endforeach
                        </div>

                        <button type="button" wire:click="remover({{ $widget->id }})" wire:confirm="Remover este visual do seu painel?"
                            class="rounded-md px-2 py-1 font-medium text-red-700 hover:bg-red-50">Remover</button>
                    </div>
                @endif

                <div class="min-w-0 flex-1">
                    <livewire:painel.visual
                        :widget-id="$widget->id"
                        :municipio-id="$municipioId"
                        :meses="$meses"
                        :key="'visual-'.$widget->id"
                    />
                </div>
            </div>
        @empty
            <div class="rounded-2xl border border-dashed border-linha bg-white p-10 text-center md:col-span-2 lg:col-span-3">
                <p class="font-medium">Seu painel está vazio.</p>
                <p class="mt-1 text-sm text-tinta-suave">Clique em <strong>Personalizar painel</strong> e depois em <strong>Adicionar visual</strong> para escolher os indicadores que quer acompanhar.</p>
            </div>
        @endforelse
    </div>

    @if ($galeriaAberta)
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto p-4 sm:p-8">
            <div class="fixed inset-0 bg-slate-900/50" wire:click="fecharGaleria" aria-hidden="true"></div>

            <div role="dialog" aria-modal="true" aria-labelledby="titulo-galeria" wire:keydown.escape="fecharGaleria" class="relative w-full max-w-3xl rounded-2xl bg-white p-6 shadow-xl sm:p-8">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 id="titulo-galeria" class="text-lg font-semibold">Adicionar visual ao painel</h2>
                        <p class="mt-1 text-sm text-tinta-suave">Escolha o indicador e como quer vê-lo. Você pode adicionar vários.</p>
                    </div>
                    <button type="button" wire:click="fecharGaleria" class="rounded-lg p-1 text-tinta-suave hover:bg-fundo" aria-label="Fechar">✕</button>
                </div>

                <div class="mt-5">
                    <label for="busca" class="block text-xs font-medium uppercase tracking-wide text-tinta-suave">Buscar indicador</label>
                    <input id="busca" type="search" wire:model.live.debounce.300ms="busca" placeholder="Ex.: cobertura, internações, população…" autofocus class="{{ $campo }}">
                </div>

                <div class="mt-5 space-y-6">
                    @forelse ($grupos as $rotuloDoGrupo => $indicadores)
                        <section aria-labelledby="grupo-{{ \Illuminate\Support\Str::slug($rotuloDoGrupo) }}">
                            <h3 id="grupo-{{ \Illuminate\Support\Str::slug($rotuloDoGrupo) }}" class="text-xs font-semibold uppercase tracking-wide text-marca-700">{{ $rotuloDoGrupo }}</h3>
                            <ul class="mt-2 divide-y divide-linha rounded-xl border border-linha">
                                @foreach ($indicadores as $indicador)
                                    <li wire:key="galeria-{{ $indicador->codigo }}" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                        <span class="min-w-0 text-sm font-medium">{{ $indicador->nome }}</span>
                                        <span class="flex flex-wrap gap-2">
                                            @foreach ($tipos as $tipo)
                                                @php $jaAdicionado = in_array($tipo->value.'|'.$indicador->codigo, $noPainel, true); @endphp
                                                <button type="button" wire:click="adicionar('{{ $tipo->value }}', '{{ $indicador->codigo }}')" title="{{ $tipo->descricao() }}" @disabled($jaAdicionado)
                                                    @class([
                                                        'rounded-lg border px-3 py-1.5 text-xs font-medium',
                                                        'border-emerald-200 bg-emerald-50 text-emerald-900' => $jaAdicionado,
                                                        'border-linha bg-white text-tinta hover:bg-marca-50' => ! $jaAdicionado,
                                                    ])>{{ $jaAdicionado ? '✓ ' : '+ ' }}{{ $tipo->rotulo() }}</button>
                                            @endforeach
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @empty
                        <p class="text-sm text-tinta-suave">Nenhum indicador encontrado para essa busca.</p>
                    @endforelse
                </div>

                <div class="mt-6 flex justify-end border-t border-linha pt-5">
                    <x-ui.botao type="button" wire:click="fecharGaleria">Concluir</x-ui.botao>
                </div>
            </div>
        </div>
    @endif
</div>
