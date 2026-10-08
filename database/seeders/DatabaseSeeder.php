<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Carrega apenas dados de referência. Nunca cria usuários com senha padrão:
     * use `php artisan aps:criar-usuario`.
     */
    public function run(): void
    {
        $this->call(IndicadorSeeder::class);
    }
}
