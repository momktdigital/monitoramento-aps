<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Resultado pré-calculado dos índices: uma linha por município e mês de referência. As notas vão de 0 a 100.
     * `detalhes` (JSON) guarda, por indicador, o valor usado, a competência dele e a nota, para explicar o número;
     * nunca entra em consultas de ranking.
     */
    public function up(): void
    {
        Schema::create('indices_municipio', function (Blueprint $table) {
            $table->unsignedInteger('municipio_id');
            $table->unsignedMediumInteger('competencia');
            $table->unsignedSmallInteger('metodologia_id');
            $table->decimal('ina', 5, 2)->nullable();
            $table->decimal('idaps', 5, 2)->nullable();
            $table->decimal('idaps_estrutura', 5, 2)->nullable();
            $table->decimal('idaps_resultado', 5, 2)->nullable();
            $table->unsignedTinyInteger('ipf_quadrante')->nullable();
            $table->decimal('ipf_pontuacao', 5, 2)->nullable();
            $table->boolean('estrutura_sem_resultado')->default(false);
            $table->decimal('confianca_ina', 4, 3)->nullable();
            $table->decimal('confianca_idaps', 4, 3)->nullable();
            $table->json('detalhes')->nullable();

            $table->primary(['municipio_id', 'competencia']);
            $table->index(['competencia', 'municipio_id'], 'indices_competencia_idx');

            $table->foreign('municipio_id')->references('id')->on('municipios')->cascadeOnDelete();
            $table->foreign('metodologia_id')->references('id')->on('metodologias')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indices_municipio');
    }
};
