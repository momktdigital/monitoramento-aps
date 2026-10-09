<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Áreas (abas) do painel de cada usuário. Cada área agrupa os visuais de um assunto. Uma delas é a inicial
     * (`padrao`): a que abre primeiro. `modelo` guarda de qual área pronta ela nasceu, para poder restaurá-la.
     */
    public function up(): void
    {
        Schema::create('painel_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('nome', 40);
            $table->unsignedTinyInteger('posicao')->default(0);
            $table->boolean('padrao')->default(false);
            $table->string('modelo', 40)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'posicao']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('painel_areas');
    }
};
