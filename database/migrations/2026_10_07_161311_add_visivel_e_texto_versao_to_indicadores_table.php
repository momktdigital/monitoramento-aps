<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `visivel`: indicadores auxiliares (contagens mensais usadas para calcular taxas acumuladas) são
     * coletados, mas não aparecem na galeria nem no painel.
     * `texto_versao`: permite corrigir os textos de ajuda do catálogo nos bancos já existentes sem
     * sobrescrever edições a cada execução do seeder (só quando o catálogo traz uma versão maior).
     */
    public function up(): void
    {
        Schema::table('indicadores', function (Blueprint $table) {
            $table->boolean('visivel')->default(true)->after('ativo');
            $table->unsignedSmallInteger('texto_versao')->default(1)->after('como_interpretar');
        });
    }

    public function down(): void
    {
        Schema::table('indicadores', function (Blueprint $table) {
            $table->dropColumn(['visivel', 'texto_versao']);
        });
    }
};
