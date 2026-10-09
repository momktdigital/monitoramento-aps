<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * De onde veio a versão: "arquivo" (config/indices.php) ou "painel" (pesos ajustados por um administrador).
     * Uma versão do painel não é substituída automaticamente pelo conteúdo do arquivo.
     */
    public function up(): void
    {
        Schema::table('metodologias', function (Blueprint $table) {
            $table->string('origem', 10)->default('arquivo')->after('hash');
        });
    }

    public function down(): void
    {
        Schema::table('metodologias', function (Blueprint $table) {
            $table->dropColumn('origem');
        });
    }
};
