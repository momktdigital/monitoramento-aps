<?php

namespace Database\Factories;

use App\Enums\Frequencia;
use App\Enums\StatusIntegracao;
use App\Models\Integracao;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Integracao>
 */
class IntegracaoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fonte' => 'ibge',
            'ativa' => true,
            'frequencia' => Frequencia::Diaria,
            'horario' => '03:00:00',
            'config' => null,
            'status' => StatusIntegracao::Nunca,
            'ultima_execucao_em' => null,
            'ultimo_sucesso_em' => null,
            'ultima_competencia' => null,
            'ultimas_linhas' => null,
            'ultimo_erro' => null,
        ];
    }

    public function daFonte(string $fonte): static
    {
        return $this->state(fn (array $attributes) => ['fonte' => $fonte]);
    }
}
