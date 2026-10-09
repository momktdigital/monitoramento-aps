@props(['cor' => 'cinza'])

@php
    $classes = match ($cor) {
        'verde' => 'bg-emerald-50 text-emerald-900 ring-emerald-200',
        'ambar' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'vermelho' => 'bg-red-50 text-red-900 ring-red-200',
        'azul' => 'bg-sky-50 text-sky-900 ring-sky-200',
        default => 'bg-slate-100 text-slate-700 ring-slate-200',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset {$classes}"]) }}>{{ $slot }}</span>
