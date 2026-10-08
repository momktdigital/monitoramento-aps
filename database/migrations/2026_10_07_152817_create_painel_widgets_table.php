<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Visuais do painel pessoal de cada usuário. Um visual é "tipo + indicador" (ex.: evolução da
     * cobertura da ESF); a ordem e a largura (1 a 3 colunas) são escolhas do usuário.
     */
    public function up(): void
    {
        Schema::create('painel_widgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tipo', 20);
            $table->string('indicador', 60);
            $table->unsignedSmallInteger('posicao')->default(0);
            $table->unsignedTinyInteger('largura')->default(1);
            $table->timestamps();

            $table->index(['user_id', 'posicao']);
            $table->unique(['user_id', 'tipo', 'indicador']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('painel_widgets');
    }
};
