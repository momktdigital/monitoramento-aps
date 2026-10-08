<?php

namespace App\Integrations\Conectores;

use App\Enums\Frequencia;
use App\Integrations\ContextoDeIngestao;
use App\Integrations\Datasus\Contratos\BaixadorDeArquivos;
use App\Integrations\Datasus\Contratos\LeitorDeRegistros;
use App\Integrations\ResultadoDoTeste;
use App\Models\Integracao;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * Base dos conectores que leem arquivos .dbc do FTP do DATASUS: baixa para uma pasta temporária,
 * lê, agrega e APAGA o arquivo (só os valores agregados ficam no banco). Arquivos que não mudaram
 * desde a última leitura são pulados nas execuções de rotina.
 */
abstract class ConectorDatasusBase extends ConectorBase
{
    private const HORAS_ATE_LIMPAR_TEMPORARIOS = 24;

    protected Frequencia $frequenciaPadrao = Frequencia::Semanal;

    public function __construct(
        protected readonly BaixadorDeArquivos $baixador,
        protected readonly LeitorDeRegistros $leitor,
    ) {}

    /**
     * Caminho remoto de um arquivo que sempre existe, usado só no "Testar conexão".
     */
    abstract protected function arquivoDeTeste(): string;

    public function testarConexao(Integracao $integracao): ResultadoDoTeste
    {
        if (($problema = $this->leitor->disponivel()) !== null) {
            return ResultadoDoTeste::falha($problema);
        }

        try {
            $assinatura = $this->baixador->assinatura($this->arquivoDeTeste());
        } catch (Throwable $e) {
            return ResultadoDoTeste::falha($e->getMessage());
        }

        return $assinatura === null
            ? ResultadoDoTeste::falha('O FTP do DATASUS respondeu, mas o arquivo de teste não foi encontrado. A organização das pastas pode ter mudado.')
            : ResultadoDoTeste::sucesso('FTP do DATASUS acessível e Python pronto para ler os arquivos.');
    }

    protected function jaProcessado(string $chave, ?string $assinatura, ContextoDeIngestao $contexto): bool
    {
        return ! $contexto->ehReprocessamento() && $assinatura !== null && Cache::get($chave) === $assinatura;
    }

    protected function lembrar(string $chave, string $assinatura): void
    {
        Cache::forever($chave, $assinatura);
    }

    /**
     * Baixa o arquivo, entrega os registros ao consumidor e apaga o arquivo, aconteça o que acontecer.
     *
     * @param  list<string>  $campos
     * @param  Closure(\Generator<int, array<string, string>>): mixed  $consumidor
     */
    protected function lerArquivo(string $caminhoRemoto, array $campos, Closure $consumidor): mixed
    {
        $pasta = (string) config('aps.datasus.diretorio_temporario');
        File::ensureDirectoryExists($pasta);
        $destino = $pasta.DIRECTORY_SEPARATOR.Str::uuid()->toString().'.dbc';

        try {
            $this->baixador->baixar($caminhoRemoto, $destino);

            return $consumidor($this->leitor->ler($destino, $campos));
        } finally {
            if (is_file($destino)) {
                @unlink($destino);
            }
        }
    }

    /**
     * Remove arquivos temporários esquecidos por execuções interrompidas.
     */
    protected function limparTemporariosAntigos(): void
    {
        $pasta = (string) config('aps.datasus.diretorio_temporario');

        if (! is_dir($pasta)) {
            return;
        }

        foreach (File::files($pasta) as $arquivo) {
            if ($arquivo->getExtension() === 'dbc' && $arquivo->getMTime() < time() - self::HORAS_ATE_LIMPAR_TEMPORARIOS * 3600) {
                @unlink($arquivo->getPathname());
            }
        }
    }
}
