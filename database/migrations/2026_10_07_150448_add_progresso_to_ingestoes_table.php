<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingestoes', function (Blueprint $table) {
            $table->string('etapa', 120)->nullable()->after('mensagem');
            $table->unsignedInteger('passos_total')->nullable()->after('etapa');
            $table->unsignedInteger('passos_concluidos')->default(0)->after('passos_total');
        });
    }

    public function down(): void
    {
        Schema::table('ingestoes', function (Blueprint $table) {
            $table->dropColumn(['etapa', 'passos_total', 'passos_concluidos']);
        });
    }
};
