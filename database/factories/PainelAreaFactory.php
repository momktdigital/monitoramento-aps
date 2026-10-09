<?php

namespace Database\Factories;

use App\Models\PainelArea;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PainelArea>
 */
class PainelAreaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'nome' => fake()->unique()->words(2, true),
            'posicao' => 0,
            'padrao' => false,
            'modelo' => null,
        ];
    }

    public function padrao(): static
    {
        return $this->state(fn (array $attributes) => ['padrao' => true]);
    }
}
