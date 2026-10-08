<?php

namespace Database\Factories;

use App\Enums\EscopoBenchmark;
use App\Models\Benchmark;
use App\Models\Indicador;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Benchmark>
 */
class BenchmarkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'indicador_id' => Indicador::factory(),
            'competencia' => 202601,
            'escopo' => EscopoBenchmark::Uf,
            'escopo_id' => 33,
            'quantidade' => 92,
            'media' => 60.0,
            'mediana' => 62.0,
            'p25' => 45.0,
            'p75' => 80.0,
            'minimo' => 10.0,
            'maximo' => 100.0,
        ];
    }
}
