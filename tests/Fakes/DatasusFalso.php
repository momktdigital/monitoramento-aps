<?php

namespace Tests\Fakes;

use App\Integrations\Datasus\Contratos\BaixadorDeArquivos;
use App\Integrations\Datasus\Contratos\LeitorDeRegistros;
use Generator;
use RuntimeException;

/**
 * Substitui o FTP e o Python nos testes: "publica" arquivos com registros prontos e anota o que foi baixado.
 */
class DatasusFalso implements BaixadorDeArquivos, LeitorDeRegistros
{
    /** @var array<string, list<array<string, string>>> */
    private array $arquivos = [];

    /** @var list<string> */
    public array $baixados = [];

    /** @var array<string, string> */
    public array $destinos = [];

    public ?string $problemaDoAmbiente = null;

    public ?string $falhaAoLer = null;

    /** @var list<string> caminhos remotos cujo download falha (simula o FTP caindo no meio da carga) */
    public array $falhaAoBaixar = [];

    /**
     * @param  list<array<string, string>>  $registros
     */
    public function publicar(string $caminhoRemoto, array $registros): void
    {
        $this->arquivos[$caminhoRemoto] = $registros;
    }

    public function remover(string $caminhoRemoto): void
    {
        unset($this->arquivos[$caminhoRemoto]);
    }

    public function assinatura(string $caminhoRemoto): ?string
    {
        return isset($this->arquivos[$caminhoRemoto]) ? 'v1|'.count($this->arquivos[$caminhoRemoto]) : null;
    }

    public function baixar(string $caminhoRemoto, string $destino): void
    {
        if (! isset($this->arquivos[$caminhoRemoto])) {
            throw new RuntimeException("Arquivo inexistente: {$caminhoRemoto}");
        }

        if (in_array($caminhoRemoto, $this->falhaAoBaixar, true)) {
            throw new RuntimeException("FTP indisponível: {$caminhoRemoto}");
        }

        file_put_contents($destino, $caminhoRemoto);
        $this->baixados[] = $caminhoRemoto;
        $this->destinos[$caminhoRemoto] = $destino;
    }

    public function disponivel(): ?string
    {
        return $this->problemaDoAmbiente;
    }

    public function ler(string $arquivo, array $campos): Generator
    {
        $remoto = (string) file_get_contents($arquivo);

        if ($this->falhaAoLer !== null) {
            throw new RuntimeException($this->falhaAoLer);
        }

        foreach ($this->arquivos[$remoto] as $registro) {
            yield array_intersect_key($registro + array_fill_keys($campos, ''), array_flip($campos));
        }
    }
}
