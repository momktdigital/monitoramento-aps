<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A chave primária é o código IBGE de 7 dígitos: dispensa uma tabela de correspondência
     * e mantém as tabelas de valores estreitas.
     */
    public function up(): void
    {
        Schema::create('municipios', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedMediumInteger('codigo6')->unique();
            $table->string('nome', 80);
            $table->string('nome_busca', 80);
            $table->char('uf', 2);
            $table->unsignedTinyInteger('codigo_uf');
            $table->unsignedInteger('regiao_imediata_codigo')->nullable();
            $table->string('regiao_imediata_nome', 80)->nullable();
            $table->unsignedInteger('regiao_saude_codigo')->nullable();
            $table->string('regiao_saude_nome', 80)->nullable();
            $table->unsignedInteger('macrorregiao_saude_codigo')->nullable();
            $table->string('macrorregiao_saude_nome', 80)->nullable();
            $table->boolean('piloto')->default(false);
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->index('nome_busca');
            $table->index(['codigo_uf', 'regiao_saude_codigo']);
            $table->index('piloto');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('municipios');
    }
};
