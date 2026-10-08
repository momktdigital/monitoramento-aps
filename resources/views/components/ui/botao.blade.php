@props(['tipo' => 'primario'])

@php
    $classes = match ($tipo) {
        'secundario' => 'border border-linha bg-white text-tinta hover:bg-fundo',
        'perigo' => 'bg-red-700 text-white hover:bg-red-800',
        default => 'bg-marca-600 text-white hover:bg-marca-700',
    };
@endphp

<button {{ $attributes->merge(['type' => 'submit', 'class' => "inline-flex items-center justify-center rounded-lg px-4 py-2.5 text-sm font-semibold shadow-xs transition focus:outline-2 focus:outline-offset-2 focus:outline-marca-600 disabled:opacity-60 {$classes}"]) }}>
    {{ $slot }}
</button>
