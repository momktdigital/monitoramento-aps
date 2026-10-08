<?php

namespace Database\Factories;

use App\Enums\OrigemExecucao;
use App\Enums\StatusIngestao;
use App\Models\Ingestao;
use App\Models\Integracao;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ingestao>
 */
class IngestaoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'integracao_id' => Integracao::factory(),
            'user_id' => null,
            'origem' => OrigemExecucao::Agendada,
            'status' => StatusIngestao::Sucesso,
            'meses' => null,
            'iniciada_em' => now()->subMinutes(5),
            'finalizada_em' => now()->subMinutes(4),
            'linhas' => 100,
            'competencia_mais_recente' => 202607,
            'mensagem' => null,
            'avisos' => null,
        ];
    }
}
