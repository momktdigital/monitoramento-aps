<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Distingue "ainda não montou o painel" (recebe o painel padrão) de "removeu tudo de propósito".
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('painel_personalizado')->default(false)->after('municipio_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('painel_personalizado');
        });
    }
};
