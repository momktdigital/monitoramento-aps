<?php

namespace Database\Factories;

use App\Enums\Perfil;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'perfil' => Perfil::Leitura,
            'ativo' => true,
            'municipio_id' => null,
            'painel_personalizado' => false,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'remember_token' => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => ['perfil' => Perfil::Admin]);
    }

    public function gestor(): static
    {
        return $this->state(fn (array $attributes) => ['perfil' => Perfil::Gestor]);
    }

    public function inativo(): static
    {
        return $this->state(fn (array $attributes) => ['ativo' => false]);
    }

    /**
     * Usuário com a verificação em duas etapas já confirmada.
     */
    public function comDoisFatores(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('SEGREDOTESTE234567'),
            'two_factor_recovery_codes' => encrypt(json_encode(['codigo-um', 'codigo-dois'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
