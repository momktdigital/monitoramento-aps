<?php

namespace App\Integrations;

use App\Integrations\Conectores\DadosAbertosConector;
use App\Integrations\Conectores\DatasusSihConector;
use App\Integrations\Conectores\DatasusSimSinascConector;
use App\Integrations\Conectores\EGestorConector;
use App\Integrations\Conectores\IbgeConector;
use App\Integrations\Conectores\TransparenciaConector;
use App\Integrations\Contracts\ConectorDeFonte;
use App\Models\Integracao;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Lista única dos conectores disponíveis. Para criar uma nova fonte: implemente
 * `ConectorDeFonte` (veja `ConectorBase`) e inclua a classe aqui.
 */
class RegistroDeConectores
{
    /**
     * Ordem de execução importa: o IBGE traz a população usada como denominador pelos demais.
     *
     * @var list<class-string<ConectorDeFonte>>
     */
    private const CONECTORES = [
        IbgeConector::class,
        EGestorConector::class,
        DadosAbertosConector::class,
        TransparenciaConector::class,
        DatasusSihConector::class,
        DatasusSimSinascConector::class,
    ];

    /**
     * @return array<string, ConectorDeFonte> conectores indexados pela fonte
     */
    public static function todos(): array
    {
        $conectores = [];

        foreach (self::CONECTORES as $classe) {
            $conector = app($classe);
            $conectores[$conector->fonte()] = $conector;
        }

        return $conectores;
    }

    public static function para(string $fonte): ConectorDeFonte
    {
        return self::todos()[$fonte] ?? throw new InvalidArgumentException("Fonte de dados desconhecida: {$fonte}");
    }

    /**
     * Garante uma linha em `integracoes` para cada conector. Fontes que exigem configuração
     * (chave de API) nascem pausadas e são ativadas ao salvar a configuração completa.
     *
     * @return Collection<int, Integracao>
     */
    public static function garantirIntegracoes(): Collection
    {
        $integracoes = collect();

        foreach (self::todos() as $fonte => $conector) {
            $integracoes->push(Integracao::firstOrCreate(
                ['fonte' => $fonte],
                ['ativa' => ! $conector->exigeConfiguracao(), 'frequencia' => $conector->frequenciaPadrao()],
            ));
        }

        return $integracoes;
    }
}
