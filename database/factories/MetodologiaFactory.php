<?php

namespace Database\Factories;

use App\Models\Metodologia;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Metodologia>
 */
class MetodologiaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $configuracao = (array) config('indices');

        return [
            'versao' => fake()->unique()->numberBetween(1, 30000),
            'hash' => Metodologia::hashDe($configuracao),
            'ativa' => true,
            'configuracao' => $configuracao,
        ];
    }
}
