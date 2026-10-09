<?php

namespace App\Integrations\Datasus;

use App\Integrations\ConfiguracaoInvalida;
use App\Integrations\Datasus\Contratos\BaixadorDeArquivos;
use ErrorException;
use FTP\Connection;
use RuntimeException;

/**
 * Acesso anônimo ao FTP público do DATASUS. O servidor vem só da configuração e os caminhos são
 * montados pelos conectores a partir de padrões fixos; qualquer outro formato é recusado.
 */
class FtpDatasus implements BaixadorDeArquivos
{
    private const PADRAO_DE_CAMINHO = '#^/dissemin/publicos/[A-Za-z0-9_/]+/[A-Za-z0-9_]+\.dbc$#';

    private ?Connection $conexao = null;

    public function assinatura(string $caminhoRemoto): ?string
    {
        $this->validar($caminhoRemoto);

        return $this->comNovaTentativa(function (Connection $conexao) use ($caminhoRemoto): ?string {
            $tamanho = ftp_size($conexao, $caminhoRemoto);

            if ($tamanho < 0) {
                return null;
            }

            return $tamanho.'|'.ftp_mdtm($conexao, $caminhoRemoto);
        });
    }

    public function baixar(string $caminhoRemoto, string $destino): void
    {
        $this->validar($caminhoRemoto);

        $baixou = $this->comNovaTentativa(fn (Connection $conexao): bool => ftp_get($conexao, $destino, $caminhoRemoto, FTP_BINARY));

        if (! $baixou || ! is_file($destino) || filesize($destino) === 0) {
            @unlink($destino);

            throw new RuntimeException('Não foi possível baixar o arquivo '.basename($caminhoRemoto).' do FTP do DATASUS.');
        }
    }

    public function __destruct()
    {
        if ($this->conexao !== null) {
            @ftp_close($this->conexao);
        }
    }

    private function validar(string $caminho): void
    {
        if (preg_match(self::PADRAO_DE_CAMINHO, $caminho) !== 1) {
            throw new RuntimeException('Caminho de arquivo do DATASUS fora do padrão permitido.');
        }
    }

    /**
     * Executa a operação; se a conexão tiver caído (o servidor encerra conexões ociosas), reconecta e tenta uma vez mais.
     *
     * @template T
     *
     * @param  callable(Connection): T  $operacao
     * @return T
     */
    private function comNovaTentativa(callable $operacao): mixed
    {
        try {
            return $operacao($this->conexao());
        } catch (ErrorException) {
            $this->desconectar();
        }

        try {
            return $operacao($this->conexao());
        } catch (ErrorException $e) {
            $this->desconectar();

            throw new RuntimeException('Falha na comunicação com o FTP do DATASUS: '.$e->getMessage());
        }
    }

    private function conexao(): Connection
    {
        if ($this->conexao !== null) {
            return $this->conexao;
        }

        if (! function_exists('ftp_connect')) {
            throw new ConfiguracaoInvalida('A extensão "ftp" do PHP não está habilitada. Ative-a no php.ini (extension=ftp) para ler os dados do DATASUS.');
        }

        $conexao = $this->conectarComTentativas();

        ftp_pasv($conexao, true);
        ftp_set_option($conexao, FTP_TIMEOUT_SEC, (int) config('aps.datasus.ftp_timeout'));

        return $this->conexao = $conexao;
    }

    /**
     * O FTP do DATASUS recusa (ou deixa pendurada) boa parte das conexões, mas atende na hora as que aceita. Por isso cada
     * tentativa tem um prazo curto e o conector tenta várias vezes antes de desistir.
     */
    private function conectarComTentativas(): Connection
    {
        $tentativas = max(1, (int) config('aps.datasus.ftp_tentativas_conexao'));

        for ($tentativa = 1; $tentativa <= $tentativas; $tentativa++) {
            $conexao = @ftp_connect((string) config('aps.datasus.ftp_host'), 21, (int) config('aps.datasus.ftp_timeout_conexao'));

            if ($conexao !== false && @ftp_login($conexao, 'anonymous', 'anonymous@example.org')) {
                return $conexao;
            }

            if ($conexao !== false) {
                @ftp_close($conexao);
            }

            if ($tentativa < $tentativas) {
                sleep((int) config('aps.datasus.ftp_espera_entre_tentativas_s'));
            }
        }

        throw new RuntimeException("Não foi possível conectar ao FTP do DATASUS (ftp.datasus.gov.br) após {$tentativas} tentativas. O serviço pode estar fora do ar ou a rede bloqueia conexões FTP.");
    }

    private function desconectar(): void
    {
        if ($this->conexao !== null) {
            @ftp_close($this->conexao);
            $this->conexao = null;
        }
    }
}
