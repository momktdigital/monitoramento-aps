<?php

namespace App\Integrations\Datasus;

use App\Integrations\ConfiguracaoInvalida;
use App\Integrations\Datasus\Contratos\LeitorDeRegistros;
use Generator;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Lê arquivos .dbc chamando `leitor_dbc.py` (Python 3, só biblioteca padrão). Os argumentos vão em array,
 * sem shell, e os nomes de campo são validados: nada vindo de fora é interpretado como comando.
 */
class LeitorDeDbc implements LeitorDeRegistros
{
    private const SAIDA_ARQUIVO_INCOMPLETO = 3;

    private const SAIDA_VERSAO_ANTIGA = 3;

    private ?string $problemaDoAmbiente = null;

    private bool $ambienteVerificado = false;

    /**
     * Tenta executar o Python de verdade. Procurar o arquivo não serve: no Windows, `python` costuma ser um
     * atalho da Microsoft Store que o PHP não enxerga como arquivo, mas que executa normalmente.
     */
    public function disponivel(): ?string
    {
        if ($this->ambienteVerificado) {
            return $this->problemaDoAmbiente;
        }

        $python = (string) config('aps.datasus.python');
        $this->ambienteVerificado = true;

        try {
            $teste = new Process([$python, '-c', 'import sys; sys.exit(0 if sys.version_info >= (3, 8) else '.self::SAIDA_VERSAO_ANTIGA.')']);
            $teste->setTimeout(30);
            $teste->run();
        } catch (Throwable) {
            return $this->problemaDoAmbiente = $this->mensagemDePythonAusente($python);
        }

        return $this->problemaDoAmbiente = match (true) {
            $teste->isSuccessful() => null,
            $teste->getExitCode() === self::SAIDA_VERSAO_ANTIGA => 'O Python encontrado é anterior à versão 3.8. Instale uma versão mais recente.',
            default => $this->mensagemDePythonAusente($python),
        };
    }

    private function mensagemDePythonAusente(string $python): string
    {
        return "Python 3 não encontrado (\"{$python}\"). Instale o Python 3 e, se ele não estiver no PATH, informe o caminho do executável em APS_PYTHON no arquivo .env.";
    }

    public function ler(string $arquivo, array $campos): Generator
    {
        foreach ($campos as $campo) {
            if (preg_match('/^[A-Z0-9_]+$/', $campo) !== 1) {
                throw new RuntimeException("Nome de campo inválido: {$campo}");
            }
        }

        if (! is_file($arquivo)) {
            throw new RuntimeException('Arquivo .dbc não encontrado para leitura.');
        }

        if (($problema = $this->disponivel()) !== null) {
            throw new ConfiguracaoInvalida($problema);
        }

        $processo = new Process([(string) config('aps.datasus.python'), __DIR__.'/leitor_dbc.py', $arquivo, implode(',', $campos)]);
        $processo->setTimeout((float) config('aps.datasus.python_timeout'));
        $processo->start();

        $pendente = '';
        $cabecalhoLido = false;
        $erros = '';

        foreach ($processo as $tipo => $dados) {
            if ($tipo === Process::ERR) {
                $erros .= $dados;

                continue;
            }

            $pendente .= $dados;

            while (($fim = strpos($pendente, "\n")) !== false) {
                $linha = substr($pendente, 0, $fim);
                $pendente = substr($pendente, $fim + 1);

                if (! $cabecalhoLido) {
                    $cabecalhoLido = true;

                    continue;
                }

                yield $this->registro($campos, $linha);
            }
        }

        if ($pendente !== '' && $cabecalhoLido) {
            yield $this->registro($campos, $pendente);
        }

        if (! $processo->isSuccessful()) {
            $detalhe = trim($erros) !== '' ? trim($erros) : 'código de saída '.$processo->getExitCode();

            throw new RuntimeException($processo->getExitCode() === self::SAIDA_ARQUIVO_INCOMPLETO
                ? "O arquivo do DATASUS veio incompleto ou corrompido ({$detalhe}). Tente de novo mais tarde."
                : "Falha ao ler o arquivo .dbc: {$detalhe}");
        }
    }

    /**
     * @param  list<string>  $campos
     * @return array<string, string>
     */
    private function registro(array $campos, string $linha): array
    {
        $valores = explode("\t", rtrim($linha, "\r"));

        return array_combine($campos, array_pad($valores, count($campos), ''));
    }
}
