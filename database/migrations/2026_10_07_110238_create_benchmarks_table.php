<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Estatísticas pré-calculadas ao fim de cada carga, para o painel nunca agregar em tempo de requisição.
     * `escopo_id`: código da região de saúde, código da UF, ou 0 para o Brasil.
     */
    public function up(): void
    {
        Schema::create('benchmarks', function (Blueprint $table) {
            $table->unsignedSmallInteger('indicador_id');
            $table->unsignedMediumInteger('competencia');
            $table->unsignedTinyInteger('escopo');
            $table->unsignedInteger('escopo_id');
            $table->unsignedSmallInteger('quantidade');
            $table->decimal('media', 16, 4);
            $table->decimal('mediana', 16, 4);
            $table->decimal('p25', 16, 4);
            $table->decimal('p75', 16, 4);
            $table->decimal('minimo', 16, 4);
            $table->decimal('maximo', 16, 4);

            $table->primary(['indicador_id', 'competencia', 'escopo', 'escopo_id']);

            $table->foreign('indicador_id')->references('id')->on('indicadores')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benchmarks');
    }
};
