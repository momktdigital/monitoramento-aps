@props(['valores'])

@php
    $quantidade = count($valores);
    $largura = 140;
    $altura = 36;
    $margem = 5;
    $minimo = $quantidade ? min($valores) : 0;
    $maximo = $quantidade ? max($valores) : 0;
    $faixa = $maximo - $minimo;
    $pontos = [];

    foreach (array_values($valores) as $indice => $valor) {
        $x = $quantidade > 1 ? $margem + ($largura - 2 * $margem) * $indice / ($quantidade - 1) : $largura / 2;
        $y = $faixa > 0 ? $altura - $margem - ($altura - 2 * $margem) * ($valor - $minimo) / $faixa : $altura / 2;
        $pontos[] = [round($x, 1), round($y, 1)];
    }

    $linha = implode(' ', array_map(fn ($ponto) => $ponto[0].','.$ponto[1], $pontos));
    $ultimo = $pontos[$quantidade - 1] ?? null;
@endphp

@if ($quantidade >= 2)
    <svg viewBox="0 0 {{ $largura }} {{ $altura }}" class="h-9 w-36" role="img" aria-label="Tendência dos últimos {{ $quantidade }} valores">
        <polyline points="{{ $linha }}" fill="none" stroke="#9a9a93" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
        <circle cx="{{ $ultimo[0] }}" cy="{{ $ultimo[1] }}" r="4" fill="#2a78d6" stroke="#ffffff" stroke-width="2" />
    </svg>
@endif
