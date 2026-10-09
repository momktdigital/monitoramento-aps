<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Campos usados pela administração de usuários: senha temporária (troca obrigatória no próximo acesso),
     * último acesso e quem criou a conta.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('deve_alterar_senha')->default(false)->after('ativo');
            $table->timestamp('ultimo_acesso_em')->nullable()->after('deve_alterar_senha');
            $table->string('ultimo_acesso_ip', 45)->nullable()->after('ultimo_acesso_em');
            $table->foreignId('criado_por_id')->nullable()->after('ultimo_acesso_ip')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('criado_por_id');
            $table->dropColumn(['deve_alterar_senha', 'ultimo_acesso_em', 'ultimo_acesso_ip']);
        });
    }
};
