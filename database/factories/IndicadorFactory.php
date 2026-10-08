<?php

namespace Database\Factories;

use App\Enums\Dimensao;
use App\Enums\Periodicidade;
use App\Enums\Polaridade;
use App\Models\Indicador;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Indicador>
 */
class IndicadorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'codigo' => fake()->unique()->slug(3),
            'nome' => fake()->sentence(3),
            'dimensao' => Dimensao::Desempenho,
            'fonte' => 'egestor',
            'unidade' => '%',
            'polaridade' => Polaridade::MaiorMelhor,
            'periodicidade' => Periodicidade::Mensal,
            'casas_decimais' => 1,
            'teto' => null,
            'ordem' => 0,
            'ativo' => true,
            'visivel' => true,
            'o_que_e' => fake()->sentence(),
            'como_calcula' => fake()->sentence(),
            'para_que_serve' => fake()->sentence(),
            'como_interpretar' => fake()->sentence(),
            'texto_versao' => 1,
        ];
    }

    public function inativo(): static
    {
        return $this->state(fn (array $attributes) => ['ativo' => false]);
    }
}
