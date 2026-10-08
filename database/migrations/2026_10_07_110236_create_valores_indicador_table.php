<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabela mais consultada do sistema: linhas estreitas, sem JSON e sem timestamps.
     * A chave primária composta (InnoDB agrupa por ela) atende "série de um município/indicador";
     * o índice secundário atende ranking e comparação ("todos os municípios de um indicador/competência").
     * A competência é um inteiro AAAAMM (anuais usam AAAA12; quadrimestrais, o mês final).
     */
    public function up(): void
    {
        Schema::create('valores_indicador', function (Blueprint $table) {
            $table->unsignedInteger('municipio_id');
            $table->unsignedSmallInteger('indicador_id');
            $table->unsignedMediumInteger('competencia');
            $table->decimal('valor', 16, 4)->nullable();
            $table->decimal('numerador', 18, 2)->nullable();
            $table->decimal('denominador', 18, 2)->nullable();

            $table->primary(['municipio_id', 'indicador_id', 'competencia']);
            $table->index(['indicador_id', 'competencia', 'municipio_id'], 'valores_ranking_idx');

            $table->foreign('municipio_id')->references('id')->on('municipios')->cascadeOnDelete();
            $table->foreign('indicador_id')->references('id')->on('indicadores')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('valores_indicador');
    }
};
