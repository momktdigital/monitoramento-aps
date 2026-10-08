<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Patamar a partir do qual não há ganho adicional (ex.: cobertura de 100% = todos têm equipe).
     * Nas comparações e nas análises em texto, valores acima do teto valem como o próprio teto.
     */
    public function up(): void
    {
        Schema::table('indicadores', function (Blueprint $table) {
            $table->decimal('teto', 16, 4)->nullable()->after('casas_decimais');
        });
    }

    public function down(): void
    {
        Schema::table('indicadores', function (Blueprint $table) {
            $table->dropColumn('teto');
        });
    }
};
