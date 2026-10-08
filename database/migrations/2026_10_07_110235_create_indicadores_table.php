<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('indicadores', function (Blueprint $table) {
            $table->unsignedSmallInteger('id')->autoIncrement();
            $table->string('codigo', 60)->unique();
            $table->string('nome', 120);
            $table->string('dimensao', 12);
            $table->string('fonte', 40);
            $table->string('unidade', 30);
            $table->string('polaridade', 14);
            $table->string('periodicidade', 14);
            $table->unsignedTinyInteger('casas_decimais')->default(1);
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->boolean('ativo')->default(true);
            $table->text('o_que_e');
            $table->text('como_calcula');
            $table->text('para_que_serve');
            $table->text('como_interpretar');
            $table->timestamps();

            $table->index(['dimensao', 'ordem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indicadores');
    }
};
