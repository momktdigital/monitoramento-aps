<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Versões da metodologia dos índices (pesos, indicadores, limiares). Uma só fica ativa; as anteriores
     * permanecem para auditoria. `hash` identifica o conteúdo e evita criar versão nova sem mudança.
     */
    public function up(): void
    {
        Schema::create('metodologias', function (Blueprint $table) {
            $table->unsignedSmallInteger('id')->autoIncrement();
            $table->unsignedSmallInteger('versao')->unique();
            $table->string('hash', 40);
            $table->boolean('ativa')->default(false);
            $table->json('configuracao');
            $table->timestamps();

            $table->index('ativa');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metodologias');
    }
};
