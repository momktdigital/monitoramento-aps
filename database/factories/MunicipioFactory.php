<?php

namespace Database\Factories;

use App\Models\Municipio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Municipio>
 */
class MunicipioFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $codigo = fake()->unique()->numberBetween(1100015, 5300108);
        $nome = fake()->unique()->city();

        return [
            'id' => $codigo,
            'codigo6' => intdiv($codigo, 10),
            'nome' => $nome,
            'nome_busca' => Municipio::normalizarNome($nome),
            'uf' => 'RJ',
            'codigo_uf' => 33,
            'regiao_imediata_codigo' => 330004,
            'regiao_imediata_nome' => 'Volta Redonda - Barra Mansa',
            'regiao_saude_codigo' => 33004,
            'regiao_saude_nome' => 'MEDIO PARAIBA',
            'macrorregiao_saude_codigo' => 3312,
            'macrorregiao_saude_nome' => 'MACRORREGIAO I',
            'piloto' => false,
            'ativo' => true,
        ];
    }

    public function piloto(): static
    {
        return $this->state(fn (array $attributes) => ['piloto' => true]);
    }
}
