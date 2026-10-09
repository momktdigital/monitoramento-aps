@props(['pontos'])

{{-- Tendência em miniatura. Cada ponto tem uma área de passar o mouse maior que o desenho, com o período e o valor. --}}
@php
    $valores = array_column($pontos, 'valor');
    $quantidade = count($valores);
    $largura = 200;
    $altura = 40;
    $margem = 6;
    $minimo = $quantidade ? min($valores) : 0;
    $maximo = $quantidade ? max($valores) : 0;
    $faixa = $maximo - $minimo;
    $coordenadas = [];

    foreach (array_values($valores) as $indice => $valor) {
        $x = $quantidade > 1 ? $margem + ($largura - 2 * $margem) * $indice / ($quantidade - 1) : $largura / 2;
        $y = $faixa > 0 ? $altura - $margem - ($altura - 2 * $margem) * ($valor - $minimo) / $faixa : $altura / 2;
        $coordenadas[] = [round($x, 1), round($y, 1)];
    }

    $linha = implode(' ', array_map(fn ($ponto) => $ponto[0].','.$ponto[1], $coordenadas));
    $ultimo = $coordenadas[$quantidade - 1] ?? null;
@endphp

@if ($quantidade >= 2)
    <svg viewBox="0 0 {{ $largura }} {{ $altura }}" class="h-10 w-full max-w-[200px]" role="img" aria-label="Tendência dos últimos {{ $quantidade }} valores: de {{ $pontos[0]['texto'] }} ({{ $pontos[0]['rotulo'] }}) a {{ $pontos[$quantidade - 1]['texto'] }} ({{ $pontos[$quantidade - 1]['rotulo'] }})">
        <polyline points="{{ $linha }}" fill="none" stroke="#9a9a93" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
        <circle cx="{{ $ultimo[0] }}" cy="{{ $ultimo[1] }}" r="4" fill="#2a78d6" stroke="#ffffff" stroke-width="2" />
        @foreach ($coordenadas as $indice => [$x, $y])
            <circle cx="{{ $x }}" cy="{{ $y }}" r="7" fill="transparent" class="hover:fill-[#2a78d6]/20"><title>{{ $pontos[$indice]['rotulo'] }}: {{ $pontos[$indice]['texto'] }}</title></circle>
        @endforeach
    </svg>
@endif
