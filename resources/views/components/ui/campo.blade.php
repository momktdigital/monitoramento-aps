@props(['nome', 'rotulo', 'tipo' => 'text', 'ajuda' => null])

<div>
    <label for="{{ $nome }}" class="block text-sm font-medium text-tinta">{{ $rotulo }}</label>
    <input
        id="{{ $nome }}"
        name="{{ $nome }}"
        type="{{ $tipo }}"
        {{ $attributes->merge(['class' => 'mt-1.5 block w-full rounded-lg border border-linha bg-white px-3 py-2.5 text-sm shadow-xs focus:border-marca-600 focus:outline-2 focus:outline-marca-600/30']) }}
        @error($nome) aria-invalid="true" aria-describedby="{{ $nome }}-erro" @enderror
    >
    @if ($ajuda)
        <p class="mt-1.5 text-xs text-tinta-suave">{{ $ajuda }}</p>
    @endif
    @error($nome)
        <p id="{{ $nome }}-erro" class="mt-1.5 text-sm text-red-700">{{ $message }}</p>
    @enderror
</div>
