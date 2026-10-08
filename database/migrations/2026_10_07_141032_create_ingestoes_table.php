<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingestoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integracao_id')->constrained('integracoes')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('origem', 12);
            $table->string('status', 12);
            $table->unsignedSmallInteger('meses')->nullable();
            $table->timestamp('iniciada_em');
            $table->timestamp('finalizada_em')->nullable();
            $table->unsignedInteger('linhas')->default(0);
            $table->unsignedMediumInteger('competencia_mais_recente')->nullable();
            $table->text('mensagem')->nullable();
            $table->json('avisos')->nullable();

            $table->index(['integracao_id', 'iniciada_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingestoes');
    }
};
