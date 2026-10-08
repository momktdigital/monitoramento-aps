@props(['quadrante', 'tamanho' => 12])

{{-- Marca de cada quadrante da matriz: a forma (e não só a cor) identifica o grupo. Prioridade máxima é o único grupo com cor de destaque. --}}
@php
    $cor = $quadrante === 1 ? '#eb6834' : '#8d8c86';
@endphp
<svg width="{{ $tamanho }}" height="{{ $tamanho }}" viewBox="0 0 12 12" aria-hidden="true" class="inline-block shrink-0 align-[-1px]">
    @switch($quadrante)
        @case(1)
            <circle cx="6" cy="6" r="5" fill="{{ $cor }}" />
            @break
        @case(2)
            <path d="M6 0.5 11.5 6 6 11.5 0.5 6Z" fill="{{ $cor }}" />
            @break
        @case(3)
            <path d="M6 1 11.2 10.5H0.8Z" fill="{{ $cor }}" />
            @break
        @default
            <rect x="1" y="1" width="10" height="10" rx="1" fill="{{ $cor }}" />
    @endswitch
</svg>
