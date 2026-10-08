<?php

namespace Database\Factories;

use App\Enums\TipoDeVisual;
use App\Models\PainelWidget;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PainelWidget>
 */
class PainelWidgetFactory extends Factory
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
            'tipo' => TipoDeVisual::Destaque,
            'indicador' => 'cobertura_esf',
            'posicao' => 0,
            'largura' => 1,
        ];
    }
}
