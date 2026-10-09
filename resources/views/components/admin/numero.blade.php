@props(['rotulo', 'valor', 'detalhe' => null, 'href' => null, 'tom' => 'normal'])

{{-- Cartão com um número grande e o que ele significa; vira link quando há para onde ir. --}}
@php
    $classes = 'block rounded-2xl border bg-white p-5 shadow-sm '.($tom === 'alerta' ? 'border-amber-300' : ($tom === 'erro' ? 'border-red-300' : 'border-linha'));
    $interativo = $href ? 'hover:bg-fundo focus:outline-2 focus:outline-offset-2 focus:outline-marca-600' : '';
@endphp

<{{ $href ? 'a' : 'div' }} @if ($href) href="{{ $href }}" wire:navigate @endif class="{{ $classes }} {{ $interativo }}">
    <p class="text-xs font-medium uppercase tracking-wide text-tinta-suave">{{ $rotulo }}</p>
    <p class="mt-1 text-3xl font-semibold tracking-tight">{{ $valor }}</p>
    @if ($detalhe)
        <p class="mt-1 text-xs text-tinta-suave">{{ $detalhe }}</p>
    @endif
</{{ $href ? 'a' : 'div' }}>
