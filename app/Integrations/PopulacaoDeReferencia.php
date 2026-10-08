<?php

namespace App\Integrations;

use App\Models\Indicador;
use App\Models\ValorIndicador;

/**
 * População de cada município por ano (indicador `populacao_total`), usada como denominador
 * de percentuais e taxas. Para uma competência usa o ano mais próximo igual ou anterior.
 */
class PopulacaoDeReferencia
{
    /** @var array<int, array<int, float>>|null município => [ano => população] */
    private ?array $porMunicipio = null;

    public function para(int $municipioId, int $competencia): ?float
    {
        $anos = $this->carregar()[$municipioId] ?? [];

        if ($anos === []) {
            return null;
        }

        $ano = intdiv($competencia, 100);
        $anteriores = array_filter($anos, fn (int $anoDisponivel): bool => $anoDisponivel <= $ano, ARRAY_FILTER_USE_KEY);

        if ($anteriores !== []) {
            return $anteriores[max(array_keys($anteriores))];
        }

        return $anos[min(array_keys($anos))];
    }

    /**
     * @return array<int, array<int, float>>
     */
    private function carregar(): array
    {
        if ($this->porMunicipio !== null) {
            return $this->porMunicipio;
        }

        $indicadorId = Indicador::where('codigo', 'populacao_total')->value('id');
        $this->porMunicipio = [];

        if ($indicadorId === null) {
            return $this->porMunicipio;
        }

        ValorIndicador::where('indicador_id', $indicadorId)
            ->whereNotNull('valor')
            ->get(['municipio_id', 'competencia', 'valor'])
            ->each(function (ValorIndicador $linha): void {
                $this->porMunicipio[$linha->municipio_id][intdiv($linha->competencia, 100)] = $linha->valor;
            });

        return $this->porMunicipio;
    }
}
