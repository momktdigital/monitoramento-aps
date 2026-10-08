<?php

namespace App\Integrations\Datasus;

/**
 * Consulta à Lista Brasileira de ICSAP (config/icsap.php). A comparação é por prefixo da CID-10:
 * códigos de 3 caracteres valem para a categoria inteira e os de 4, só para a subcategoria.
 */
class ListaIcsap
{
    /** @var array<string, int> prefixo de 3 caracteres => grupo */
    private array $categorias = [];

    /** @var array<string, int> prefixo de 4 caracteres => grupo */
    private array $subcategorias = [];

    /**
     * @param  array<int, array{nome: string, cids: list<string>}>|null  $grupos
     */
    public function __construct(?array $grupos = null)
    {
        foreach ($grupos ?? config('icsap') as $numero => $grupo) {
            foreach ($grupo['cids'] as $cid) {
                if (strlen($cid) === 3) {
                    $this->categorias[$cid] = $numero;
                } else {
                    $this->subcategorias[$cid] = $numero;
                }
            }
        }
    }

    /**
     * Grupo (1 a 19) do diagnóstico, ou null se não for condição sensível. Aceita "J15.3", "j153" ou "J15".
     */
    public function grupoDe(string $cid): ?int
    {
        $codigo = strtoupper(str_replace('.', '', trim($cid)));

        if (strlen($codigo) < 3) {
            return null;
        }

        return $this->subcategorias[substr($codigo, 0, 4)] ?? $this->categorias[substr($codigo, 0, 3)] ?? null;
    }

    public function contem(string $cid): bool
    {
        return $this->grupoDe($cid) !== null;
    }
}
