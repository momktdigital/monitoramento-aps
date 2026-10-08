<?php

namespace Database\Seeders;

use App\Models\Indicador;
use Illuminate\Database\Seeder;

class IndicadorSeeder extends Seeder
{
    private const COLUNAS_DE_TEXTO = ['o_que_e', 'como_calcula', 'para_que_serve', 'como_interpretar'];

    /**
     * Cria os indicadores que faltam e atualiza a estrutura dos existentes.
     * Os textos de ajuda de indicadores já cadastrados NÃO são sobrescritos (ficam livres para edição).
     */
    public function run(): void
    {
        $catalogo = require database_path('seeders/data/indicadores.php');

        foreach ($catalogo as $posicao => $definicao) {
            $definicao['ordem'] = $posicao + 1;

            $indicador = Indicador::firstOrNew(['codigo' => $definicao['codigo']]);

            $definicao += ['texto_versao' => 1];

            // Textos já cadastrados só são substituídos quando o catálogo traz uma versão de texto maior.
            if ($indicador->exists && $definicao['texto_versao'] <= $indicador->texto_versao) {
                $definicao = array_diff_key($definicao, array_flip([...self::COLUNAS_DE_TEXTO, 'texto_versao']));
            }

            $indicador->fill($definicao)->save();
        }
    }
}
