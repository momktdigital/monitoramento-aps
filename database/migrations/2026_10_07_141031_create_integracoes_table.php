<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integracoes', function (Blueprint $table) {
            $table->id();
            $table->string('fonte', 40)->unique();
            $table->boolean('ativa')->default(true);
            $table->string('frequencia', 12)->default('diaria');
            $table->time('horario')->default('03:00:00');
            $table->text('config')->nullable();
            $table->string('status', 12)->default('nunca');
            $table->timestamp('ultima_execucao_em')->nullable();
            $table->timestamp('ultimo_sucesso_em')->nullable();
            $table->unsignedMediumInteger('ultima_competencia')->nullable();
            $table->unsignedInteger('ultimas_linhas')->nullable();
            $table->text('ultimo_erro')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integracoes');
    }
};
