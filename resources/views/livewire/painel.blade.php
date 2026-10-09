@php
    $colunas = [1 => '', 2 => 'md:col-span-2', 3 => 'md:col-span-2 lg:col-span-3'];
    $campo = 'mt-1.5 block w-full rounded-lg border border-linha bg-white px-3 py-2.5 text-sm focus:border-marca-600 focus:outline-2 focus:outline-marca-600/30';
    $botaoDeArea = 'inline-flex items-center gap-1.5 rounded-lg border border-linha bg-white px-3 py-2 text-xs font-semibold text-tinta hover:bg-fundo focus:outline-2 focus:outline-offset-2 focus:outline-marca-600 disabled:cursor-not-allowed disabled:opacity-50';
    $indiceDaArea = $areas->search(fn ($a) => $a->id === $area->id);
@endphp

<div class="space-y-5">
    <header>
        <h1 class="text-2xl font-semibold">Meu painel</h1>
        <p class="mt-1 text-sm text-tinta-suave">Os indicadores organizados por assunto, cada um com explicação e análise em linguagem simples.</p>
    </header>

    {{-- Filtros: uma única linha acima dos visuais; valem para todas as áreas --}}
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
                <x-ui.botao type="button" tipo="secundario" wire:click="restaurarPadrao" wire:confirm="Voltar ao painel padrão? Todas as suas áreas e visuais serão substituídas pelas áreas prontas.">
                    <x-ui.icone nome="restaurar" class="mr-1.5 size-4" />Restaurar painel padrão
                </x-ui.botao>
            @endif
            <x-ui.botao type="button" :tipo="$editando ? 'primario' : 'secundario'" wire:click="alternarEdicao">
                @if ($editando)
                    <x-ui.icone nome="check" class="mr-1.5 size-4" />Concluir edição
                @else
                    <x-ui.icone nome="lapis" class="mr-1.5 size-4" />Personalizar painel
                @endif
            </x-ui.botao>
        </div>
    </div>

    @if ($municipio)
        <p class="text-sm text-tinta-suave">
            Exibindo <strong class="text-tinta">{{ $municipio->nome }}</strong>@if ($municipio->regiao_saude_nome) · região de saúde {{ \Illuminate\Support\Str::title(mb_strtolower($municipio->regiao_saude_nome)) }}@endif
            · {{ $municipio->uf }}
        </p>
    @endif

    {{-- Abas: uma por área. Setas do teclado percorrem as abas. --}}
    <div class="sticky top-0 z-20 -mx-4 bg-fundo/95 px-4 pt-1 backdrop-blur sm:-mx-8 sm:px-8">
        <div role="tablist" aria-label="Áreas do painel" wire:keydown.arrow-right.prevent="navegarArea('proxima')" wire:keydown.arrow-left.prevent="navegarArea('anterior')"
            class="flex items-end gap-1 overflow-x-auto border-b border-linha [scrollbar-width:thin]">
            @foreach ($areas as $aba)
                @php $ativa = $aba->id === $area->id; @endphp
                <button type="button" role="tab" id="aba-{{ $aba->id }}" wire:key="aba-{{ $aba->id }}" wire:click="abrirArea({{ $aba->id }})"
                    aria-selected="{{ $ativa ? 'true' : 'false' }}" aria-controls="painel-da-area" tabindex="{{ $ativa ? '0' : '-1' }}"
                    @class([
                        '-mb-px inline-flex shrink-0 items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-medium focus:outline-2 focus:-outline-offset-2 focus:outline-marca-600',
                        'border-marca-600 text-marca-900' => $ativa,
                        'border-transparent text-tinta-suave hover:border-linha hover:text-tinta' => ! $ativa,
                    ])>
                    @if ($aba->padrao)
                        <x-ui.icone nome="estrela" class="size-3.5 text-amber-500" /><span class="sr-only">Área inicial: </span>
                    @endif
                    {{ $aba->nome }}
                    <span @class(['rounded-full px-1.5 py-0.5 text-[0.7rem] font-semibold tabular-nums ring-1 ring-inset', 'bg-marca-50 text-marca-900 ring-marca-600/20' => $ativa, 'bg-white text-tinta-suave ring-linha' => ! $ativa])><span class="sr-only">, </span>{{ $aba->widgets_count }}<span class="sr-only"> visuais</span></span>
                </button>
            @endforeach

            @if ($editando)
                <button type="button" wire:click="novaArea" @disabled(! $podeCriarArea)
                    title="{{ $podeCriarArea ? 'Criar uma nova área' : 'Limite de '.$maximoDeAreas.' áreas atingido' }}"
                    class="-mb-px ml-1 inline-flex shrink-0 items-center gap-1.5 rounded-t-lg border-b-2 border-transparent px-3 py-2.5 text-sm font-semibold text-marca-700 hover:bg-marca-50 focus:outline-2 focus:-outline-offset-2 focus:outline-marca-600 disabled:cursor-not-allowed disabled:opacity-50">
                    <x-ui.icone nome="mais" />Nova área
                </button>
            @endif
        </div>
    </div>

    <div role="tabpanel" id="painel-da-area" aria-labelledby="aba-{{ $area->id }}" class="space-y-5">
        @if ($editando)
            <section class="rounded-2xl border border-marca-600/30 bg-marca-50/60 p-4" aria-label="Gerenciar a área {{ $area->nome }}">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold">Editando a área “{{ $area->nome }}”</p>
                        <p class="mt-0.5 max-w-2xl text-xs leading-relaxed text-tinta-suave">Arraste os visuais pelo ícone <span aria-hidden="true">⠿</span> ou use as setas para reordenar, escolha a largura de cada um e remova o que não precisar. Tudo isso vale só para esta área.</p>
                    </div>
                    <x-ui.botao type="button" wire:click="abrirGaleria"><x-ui.icone nome="mais" class="mr-1.5 size-4" />Adicionar visuais</x-ui.botao>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-marca-600/15 pt-3">
                    <button type="button" wire:click="renomearArea({{ $area->id }})" class="{{ $botaoDeArea }}"><x-ui.icone nome="lapis" class="size-3.5" />Renomear</button>

                    @if ($area->padrao)
                        <span class="inline-flex items-center gap-1.5 rounded-lg bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-900 ring-1 ring-inset ring-amber-200"><x-ui.icone nome="estrela" class="size-3.5 text-amber-500" />Esta é a área inicial</span>
                    @else
                        <button type="button" wire:click="definirComoInicial({{ $area->id }})" class="{{ $botaoDeArea }}" title="A área inicial é a primeira que abre quando você entra no painel"><x-ui.icone nome="estrela" class="size-3.5" />Definir como inicial</button>
                    @endif

                    <span class="inline-flex gap-1" role="group" aria-label="Posição da aba">
                        <button type="button" wire:click="moverArea({{ $area->id }}, 'antes')" @disabled($indiceDaArea === 0) class="{{ $botaoDeArea }} px-2" aria-label="Mover a aba para a esquerda" title="Mover a aba para a esquerda"><x-ui.icone nome="seta-esquerda" class="size-3.5" /></button>
                        <button type="button" wire:click="moverArea({{ $area->id }}, 'depois')" @disabled($indiceDaArea === $areas->count() - 1) class="{{ $botaoDeArea }} px-2" aria-label="Mover a aba para a direita" title="Mover a aba para a direita"><x-ui.icone nome="seta-direita" class="size-3.5" /></button>
                    </span>

                    <span class="mx-1 hidden h-5 w-px bg-marca-600/20 sm:block" aria-hidden="true"></span>

                    @if ($podeRestaurarArea)
                        <button type="button" wire:click="restaurarArea({{ $area->id }})" wire:confirm="Restaurar esta área? Os visuais dela voltam ao conjunto original, e as mudanças que você fez nela são descartadas." class="{{ $botaoDeArea }}"><x-ui.icone nome="restaurar" class="size-3.5" />Restaurar esta área</button>
                    @endif

                    <button type="button" wire:click="excluirArea({{ $area->id }})" @disabled($areas->count() <= 1)
                        wire:confirm="Excluir a área “{{ $area->nome }}”{{ $area->widgets_count > 0 ? ' e os '.$area->widgets_count.' visuais dela' : '' }}? Isso não pode ser desfeito (mas você pode restaurar o painel padrão)."
                        title="{{ $areas->count() <= 1 ? 'O painel precisa ter pelo menos uma área' : 'Excluir esta área' }}"
                        class="{{ $botaoDeArea }} text-red-700 hover:bg-red-50"><x-ui.icone nome="lixeira" class="size-3.5" />Excluir área</button>
                </div>
            </section>
        @endif

        {{-- A grade aceita arrastar e soltar sempre, mas só há "alça" para arrastar no modo de edição --}}
        <div x-data="reordenavel" wire:key="grade-{{ $area->id }}" class="grid items-stretch gap-5 md:grid-cols-2 lg:grid-cols-3">
            @forelse ($widgets as $widget)
                <div wire:key="item-{{ $widget->id }}" data-id="{{ $widget->id }}" class="flex min-w-0 flex-col gap-2 {{ $colunas[$widget->largura] ?? '' }}">
                    @if ($editando)
                        <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-dashed border-marca-600/50 bg-marca-50 px-3 py-2 text-xs" role="group" aria-label="Editar visual {{ $widget->tipo->rotulo() }}: {{ $widget->indicador }}">
                            <div class="flex items-center gap-1">
                                <button type="button" data-arrastar title="Arraste para reordenar" aria-label="Arrastar para reordenar"
                                    class="flex size-7 cursor-grab items-center justify-center rounded-lg border border-linha bg-white text-tinta-suave hover:bg-white active:cursor-grabbing">⠿</button>
                                <button type="button" wire:click="mover({{ $widget->id }}, 'antes')" aria-label="Mover para antes" title="Mover para antes"
                                    class="flex size-7 items-center justify-center rounded-lg border border-linha bg-white text-tinta-suave hover:bg-marca-100"><x-ui.icone nome="seta-esquerda" class="size-3.5" /></button>
                                <button type="button" wire:click="mover({{ $widget->id }}, 'depois')" aria-label="Mover para depois" title="Mover para depois"
                                    class="flex size-7 items-center justify-center rounded-lg border border-linha bg-white text-tinta-suave hover:bg-marca-100"><x-ui.icone nome="seta-direita" class="size-3.5" /></button>
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

                            <button type="button" wire:click="remover({{ $widget->id }})" wire:confirm="Remover este visual da área “{{ $area->nome }}”? Você pode adicioná-lo de novo em Adicionar visuais."
                                class="inline-flex items-center gap-1 rounded-md px-2 py-1 font-medium text-red-700 hover:bg-red-50"><x-ui.icone nome="lixeira" class="size-3.5" />Remover</button>
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
                    <p class="font-medium">A área “{{ $area->nome }}” está vazia.</p>
                    <p class="mx-auto mt-1 max-w-md text-sm text-tinta-suave">
                        @if ($editando)
                            Clique em <strong>Adicionar visuais</strong> para escolher o que acompanhar nesta área.
                        @else
                            Clique em <strong>Personalizar painel</strong> e depois em <strong>Adicionar visuais</strong> para escolher o que acompanhar aqui.
                        @endif
                    </p>
                    @if ($editando)
                        <x-ui.botao type="button" class="mt-4" wire:click="abrirGaleria"><x-ui.icone nome="mais" class="mr-1.5 size-4" />Adicionar visuais</x-ui.botao>
                    @endif
                </div>
            @endforelse
        </div>
    </div>

    {{-- Criar ou renomear uma área --}}
    @if ($formularioDeAreaAberto)
        <div wire:key="modal-area" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto p-4 sm:p-8">
            <div class="fixed inset-0 bg-slate-900/50" wire:click="cancelarArea" aria-hidden="true"></div>

            <form x-data="focoInicial" wire:submit="salvarArea" role="dialog" aria-modal="true" aria-labelledby="titulo-area" wire:keydown.escape="cancelarArea" class="relative w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl sm:p-8">
                <h2 id="titulo-area" class="text-lg font-semibold">{{ $areaEmEdicao ? 'Renomear área' : 'Nova área' }}</h2>
                <p class="mt-1 text-sm text-tinta-suave">{{ $areaEmEdicao ? 'O nome aparece na aba.' : 'Uma área é uma aba do painel para reunir os visuais de um assunto.' }}</p>

                <div class="mt-5">
                    <label for="area-nome" class="block text-xs font-medium uppercase tracking-wide text-tinta-suave">Nome da área</label>
                    <input id="area-nome" x-ref="campo" type="text" wire:model="areaNome" maxlength="{{ \App\Models\PainelArea::NOME_MAXIMO }}" autocomplete="off" placeholder="Ex.: Reunião de segunda"
                        aria-describedby="area-nome-ajuda" @error('areaNome') aria-invalid="true" @enderror class="{{ $campo }}">
                    @error('areaNome')
                        <p class="mt-1.5 text-sm font-medium text-red-700" role="alert">{{ $message }}</p>
                    @enderror
                    <p id="area-nome-ajuda" class="mt-1.5 text-xs text-tinta-suave">Até {{ \App\Models\PainelArea::NOME_MAXIMO }} caracteres.</p>
                </div>

                @if (! $areaEmEdicao)
                    <fieldset class="mt-5">
                        <legend class="text-xs font-medium uppercase tracking-wide text-tinta-suave">Começar com</legend>
                        <div class="mt-2 max-h-64 space-y-2 overflow-y-auto pr-1">
                            <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-linha p-3 has-[:checked]:border-marca-600 has-[:checked]:bg-marca-50">
                                <input type="radio" wire:model="areaModelo" value="" class="mt-1">
                                <span><span class="block text-sm font-medium">Área vazia</span><span class="block text-xs text-tinta-suave">Você escolhe os visuais depois.</span></span>
                            </label>
                            @foreach ($modelos as $modelo)
                                <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-linha p-3 has-[:checked]:border-marca-600 has-[:checked]:bg-marca-50">
                                    <input type="radio" wire:model="areaModelo" value="{{ $modelo['chave'] }}" class="mt-1">
                                    <span><span class="block text-sm font-medium">{{ $modelo['nome'] }} <span class="font-normal text-tinta-suave">· {{ count($modelo['visuais']) }} visuais</span></span><span class="block text-xs text-tinta-suave">{{ $modelo['descricao'] }}</span></span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endif

                <div class="mt-6 flex justify-end gap-2 border-t border-linha pt-5">
                    <x-ui.botao type="button" tipo="secundario" wire:click="cancelarArea">Cancelar</x-ui.botao>
                    <x-ui.botao type="submit">{{ $areaEmEdicao ? 'Salvar nome' : 'Criar área' }}</x-ui.botao>
                </div>
            </form>
        </div>
    @endif

    {{-- Galeria: cada botão liga ou desliga o visual na área aberta --}}
    @if ($galeriaAberta)
        <div wire:key="modal-galeria" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto p-4 sm:p-8">
            <div class="fixed inset-0 bg-slate-900/50" wire:click="fecharGaleria" aria-hidden="true"></div>

            <div x-data="focoInicial" role="dialog" aria-modal="true" aria-labelledby="titulo-galeria" wire:keydown.escape="fecharGaleria" class="relative w-full max-w-3xl rounded-2xl bg-white p-6 shadow-xl sm:p-8">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 id="titulo-galeria" class="text-lg font-semibold">Visuais da área “{{ $area->nome }}”</h2>
                        <p class="mt-1 text-sm text-tinta-suave">Clique para <strong class="font-semibold text-tinta">adicionar</strong> ou, se já estiver na área, para <strong class="font-semibold text-tinta">remover</strong>. As mudanças valem na hora.</p>
                    </div>
                    <button type="button" wire:click="fecharGaleria" class="rounded-lg p-1.5 text-tinta-suave hover:bg-fundo focus:outline-2 focus:outline-marca-600" aria-label="Fechar"><x-ui.icone nome="fechar" class="size-5" /></button>
                </div>

                <div class="mt-5 flex flex-wrap items-end justify-between gap-3">
                    <div class="min-w-64 grow">
                        <label for="busca" class="block text-xs font-medium uppercase tracking-wide text-tinta-suave">Buscar indicador</label>
                        <input id="busca" x-ref="campo" type="search" wire:model.live.debounce.300ms="busca" placeholder="Ex.: cobertura, internações, população…" class="{{ $campo }}">
                    </div>
                    <p class="pb-2.5 text-sm text-tinta-suave" aria-live="polite"><strong class="text-tinta">{{ count($noPainel) }}</strong> {{ count($noPainel) === 1 ? 'visual' : 'visuais' }} nesta área</p>
                </div>

                <div class="mt-5 space-y-6">
                    @forelse ($grupos as $rotuloDoGrupo => $indicadores)
                        <section aria-labelledby="grupo-{{ \Illuminate\Support\Str::slug($rotuloDoGrupo) }}">
                            <h3 id="grupo-{{ \Illuminate\Support\Str::slug($rotuloDoGrupo) }}" class="text-xs font-semibold uppercase tracking-wide text-marca-700">{{ $rotuloDoGrupo }}</h3>
                            <ul class="mt-2 divide-y divide-linha rounded-xl border border-linha">
                                @foreach ($indicadores as $indicador)
                                    @php
                                        $tiposDoIndicador = \App\Enums\TipoDeVisual::paraIndicador($indicador);
                                        $adicionados = collect($tiposDoIndicador)->filter(fn ($t) => in_array($t->value.'|'.$indicador->codigo, $noPainel, true))->count();
                                        $todos = $adicionados === count($tiposDoIndicador);
                                    @endphp
                                    <li wire:key="galeria-{{ $indicador->codigo }}" class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-3">
                                        <span class="min-w-0 text-sm font-medium">{{ $indicador->nome }}</span>
                                        <span class="flex flex-wrap items-center gap-2" role="group" aria-label="Visuais de {{ $indicador->nome }}">
                                            @foreach ($tiposDoIndicador as $tipo)
                                                @php $jaAdicionado = in_array($tipo->value.'|'.$indicador->codigo, $noPainel, true); @endphp
                                                <button type="button" wire:click="alternarVisual('{{ $tipo->value }}', '{{ $indicador->codigo }}')" aria-pressed="{{ $jaAdicionado ? 'true' : 'false' }}"
                                                    title="{{ $jaAdicionado ? 'Remover da área: ' : 'Adicionar à área: ' }}{{ $tipo->descricao() }}"
                                                    @class([
                                                        'inline-flex items-center gap-1 rounded-lg border px-3 py-1.5 text-xs font-medium focus:outline-2 focus:outline-offset-2 focus:outline-marca-600',
                                                        'border-marca-600 bg-marca-600 text-white hover:bg-marca-700' => $jaAdicionado,
                                                        'border-linha bg-white text-tinta hover:bg-marca-50' => ! $jaAdicionado,
                                                    ])>
                                                    <x-ui.icone :nome="$jaAdicionado ? 'check' : 'mais'" class="size-3" />{{ $tipo->rotulo() }}<span class="sr-only">{{ $jaAdicionado ? ' (na área; clique para remover)' : ' (clique para adicionar)' }}</span>
                                                </button>
                                            @endforeach
                                            @if (count($tiposDoIndicador) > 1)
                                                <button type="button" wire:click="alternarIndicador('{{ $indicador->codigo }}')" class="rounded-lg px-2 py-1.5 text-xs font-medium text-marca-700 underline decoration-marca-600/40 underline-offset-2 hover:bg-marca-50 focus:outline-2 focus:outline-marca-600">{{ $todos ? 'Remover todos' : 'Adicionar todos' }}<span class="sr-only"> os visuais de {{ $indicador->nome }}</span></button>
                                            @endif
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
