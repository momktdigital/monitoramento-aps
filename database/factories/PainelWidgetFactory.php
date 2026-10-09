<?php

namespace Database\Factories;

use App\Enums\TipoDeVisual;
use App\Models\PainelArea;
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
     * Sem área informada, o visual vai para a primeira área do usuário (que é criada se ainda não existir).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'area_id' => fn (array $atributos): int => PainelArea::query()->where('user_id', $atributos['user_id'])->orderBy('posicao')->value('id')
                ?? PainelArea::factory()->padrao()->create(['user_id' => $atributos['user_id']])->id,
            'tipo' => TipoDeVisual::Destaque,
            'indicador' => 'cobertura_esf',
            'posicao' => 0,
            'largura' => 1,
        ];
    }
}
