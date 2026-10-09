@props(['nome', 'classe' => 'size-4'])

{{-- Ícones de traço (24×24), sempre decorativos: o botão que os contém carrega o texto acessível. --}}
<svg {{ $attributes->merge(['class' => $classe.' shrink-0']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    @switch($nome)
        @case('expandir')
            <path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7" />
            @break
        @case('reduzir')
            <path d="M4 14h6v6M20 10h-6V4M14 10l7-7M3 21l7-7" />
            @break
        @case('tabela')
            <rect x="3" y="4" width="18" height="16" rx="2" /><path d="M3 10h18M9 4v16" />
            @break
        @case('grafico')
            <path d="M3 3v18h18" /><path d="m7 15 4-5 3 3 5-7" />
            @break
        @case('imagem')
            <rect x="3" y="3" width="18" height="18" rx="2" /><circle cx="9" cy="9" r="1.5" /><path d="m21 15-4-4L6 21" />
            @break
        @case('baixar')
            <path d="M12 3v12M7 10l5 5 5-5M5 21h14" />
            @break
        @case('mais')
            <path d="M12 5v14M5 12h14" />
            @break
        @case('estrela')
            <path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z" />
            @break
        @case('lapis')
            <path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z" />
            @break
        @case('lixeira')
            <path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14M10 11v6M14 11v6" />
            @break
        @case('restaurar')
            <path d="M3 12a9 9 0 1 0 3-6.7L3 8M3 3v5h5" />
            @break
        @case('seta-esquerda')
            <path d="m15 18-6-6 6-6" />
            @break
        @case('seta-direita')
            <path d="m9 18 6-6-6-6" />
            @break
        @case('fechar')
            <path d="M18 6 6 18M6 6l12 12" />
            @break
        @case('check')
            <path d="m5 12 5 5L20 7" />
            @break
    @endswitch
</svg>
