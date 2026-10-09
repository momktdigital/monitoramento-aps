@props(['id', 'titulo', 'fechar', 'largura' => 'max-w-lg', 'descricao' => null])

{{-- Janela de diálogo: fecha no Esc e no clique fora, leva o foco para o campo marcado com x-ref="campo" e descreve a si mesma para leitores de tela. --}}
<div wire:key="modal-{{ $id }}" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto p-4 sm:p-8">
    <div class="fixed inset-0 bg-slate-900/50" wire:click="{{ $fechar }}" aria-hidden="true"></div>

    <div x-data="focoInicial" role="dialog" aria-modal="true" aria-labelledby="titulo-{{ $id }}" @if ($descricao) aria-describedby="descricao-{{ $id }}" @endif wire:keydown.escape="{{ $fechar }}"
        class="relative w-full {{ $largura }} rounded-2xl bg-white p-6 shadow-xl sm:p-8">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 id="titulo-{{ $id }}" class="text-lg font-semibold">{{ $titulo }}</h2>
                @if ($descricao)
                    <p id="descricao-{{ $id }}" class="mt-1 text-sm text-tinta-suave">{{ $descricao }}</p>
                @endif
            </div>
            <button type="button" wire:click="{{ $fechar }}" class="rounded-lg p-1.5 text-tinta-suave hover:bg-fundo focus:outline-2 focus:outline-marca-600" aria-label="Fechar"><x-ui.icone nome="fechar" class="size-5" /></button>
        </div>

        <div class="mt-5">{{ $slot }}</div>
    </div>
</div>
