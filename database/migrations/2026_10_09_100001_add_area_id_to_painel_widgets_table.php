<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Visuais do painel padrão antigo (antes das áreas). Quem ainda tem exatamente esses recebe o painel novo. */
    private const PADRAO_ANTIGO = [
        'destaque|cobertura_esf', 'destaque|cobertura_aps', 'destaque|populacao_total', 'evolucao|cobertura_esf',
        'destaque|equipes_esf', 'ranking|cobertura_esf', 'destaque|pct_60_anos_ou_mais',
    ];

    /**
     * Todo visual passa a pertencer a uma área, e a unicidade (um mesmo visual não se repete) passa a valer dentro
     * da área, não mais no painel inteiro. Painéis já personalizados viram a área "Meu painel", sem perder nada.
     */
    public function up(): void
    {
        Schema::table('painel_widgets', function (Blueprint $table) {
            $table->foreignId('area_id')->nullable()->after('user_id')->constrained('painel_areas')->cascadeOnDelete();
        });

        foreach (DB::table('painel_widgets')->distinct()->pluck('user_id') as $usuario) {
            $atuais = DB::table('painel_widgets')->where('user_id', $usuario)->get(['tipo', 'indicador'])->map(fn ($w): string => $w->tipo.'|'.$w->indicador)->sort()->values()->all();
            $antigos = collect(self::PADRAO_ANTIGO)->sort()->values()->all();

            if ($atuais === $antigos) {
                DB::table('painel_widgets')->where('user_id', $usuario)->delete();
                DB::table('users')->where('id', $usuario)->update(['painel_personalizado' => false]);

                continue;
            }

            $area = DB::table('painel_areas')->insertGetId([
                'user_id' => $usuario,
                'nome' => 'Meu painel',
                'posicao' => 0,
                'padrao' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('painel_widgets')->where('user_id', $usuario)->update(['area_id' => $area]);
        }

        // Em chamadas separadas: o Laravel descarta índices pedidos no mesmo bloco de um change().
        Schema::table('painel_widgets', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'tipo', 'indicador']);
        });

        Schema::table('painel_widgets', function (Blueprint $table) {
            $table->unsignedBigInteger('area_id')->nullable(false)->change();
        });

        Schema::table('painel_widgets', function (Blueprint $table) {
            $table->unique(['area_id', 'tipo', 'indicador']);
            $table->index(['area_id', 'posicao']);
        });
    }

    public function down(): void
    {
        // A chave estrangeira usa os índices da área: ela sai primeiro.
        Schema::table('painel_widgets', function (Blueprint $table) {
            $table->dropForeign(['area_id']);
        });

        // Volta ao painel único: mantém só a primeira ocorrência de cada visual.
        $vistos = [];

        foreach (DB::table('painel_widgets')->orderBy('user_id')->orderBy('area_id')->orderBy('posicao')->get() as $widget) {
            $chave = $widget->user_id.'|'.$widget->tipo.'|'.$widget->indicador;

            if (isset($vistos[$chave])) {
                DB::table('painel_widgets')->where('id', $widget->id)->delete();
            }

            $vistos[$chave] = true;
        }

        Schema::table('painel_widgets', function (Blueprint $table) {
            if (Schema::hasIndex('painel_widgets', ['area_id', 'posicao'])) {
                $table->dropIndex(['area_id', 'posicao']);
            }

            $table->dropUnique(['area_id', 'tipo', 'indicador']);
        });

        Schema::table('painel_widgets', function (Blueprint $table) {
            $table->dropColumn('area_id');
            $table->unique(['user_id', 'tipo', 'indicador']);
        });
    }
};
