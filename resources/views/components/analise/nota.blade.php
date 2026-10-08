@props(['rotulo', 'valor' => null, 'dica' => null])

{{-- Nota de 0 a 100 como um medidor fino: o número sempre aparece por escrito ao lado. --}}
<div>
    <div class="flex items-baseline justify-between gap-3 text-sm">
        <span class="text-tinta-suave">{{ $rotulo }}</span>
        <span class="font-semibold tabular-nums">{{ $valor === null ? '—' : \App\Support\Formatador::numero($valor, 0) }}</span>
    </div>
    <div class="mt-1 h-1.5 rounded-full bg-linha" @if ($valor !== null) role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ round($valor) }}" aria-label="{{ $rotulo }}" @endif>
        @if ($valor !== null)
            <div class="h-full rounded-full bg-[#2a78d6]" style="width: {{ max(2, min(100, $valor)) }}%"></div>
        @endif
    </div>
    @if ($dica)
        <p class="mt-0.5 text-xs text-tinta-suave">{{ $dica }}</p>
    @endif
</div>
