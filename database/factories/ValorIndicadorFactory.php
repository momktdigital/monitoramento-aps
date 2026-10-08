<?php

namespace Database\Factories;

use App\Models\Indicador;
use App\Models\Municipio;
use App\Models\ValorIndicador;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ValorIndicador>
 */
class ValorIndicadorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'municipio_id' => Municipio::factory(),
            'indicador_id' => Indicador::factory(),
            'competencia' => 202601,
            'valor' => fake()->randomFloat(4, 0, 100),
            'numerador' => null,
            'denominador' => null,
        ];
    }
}
