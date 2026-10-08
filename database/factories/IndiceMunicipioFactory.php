<?php

namespace Database\Factories;

use App\Enums\QuadranteIpf;
use App\Models\IndiceMunicipio;
use App\Models\Metodologia;
use App\Models\Municipio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IndiceMunicipio>
 */
class IndiceMunicipioFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $ina = fake()->randomFloat(2, 0, 100);
        $idaps = fake()->randomFloat(2, 0, 100);

        return [
            'municipio_id' => Municipio::factory(),
            'competencia' => 202607,
            'metodologia_id' => Metodologia::factory(),
            'ina' => $ina,
            'idaps' => $idaps,
            'idaps_estrutura' => fake()->randomFloat(2, 0, 100),
            'idaps_resultado' => fake()->randomFloat(2, 0, 100),
            'ipf_quadrante' => QuadranteIpf::PrioridadeMaxima,
            'ipf_pontuacao' => round(($ina + 100 - $idaps) / 2, 2),
            'estrutura_sem_resultado' => false,
            'confianca_ina' => 1.0,
            'confianca_idaps' => 1.0,
            'detalhes' => null,
        ];
    }
}
