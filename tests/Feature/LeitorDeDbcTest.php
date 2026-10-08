<?php

namespace Tests\Feature;

use App\Integrations\ConfiguracaoInvalida;
use App\Integrations\Datasus\LeitorDeDbc;
use RuntimeException;
use Tests\TestCase;

/**
 * Roda o Python de verdade sobre arquivos .dbc minúsculos (tests/Fixtures/dbc, gerados por gerar.py com a
 * mesma compressão dos arquivos do DATASUS). Sem Python 3.8+ na máquina, estes testes são pulados.
 */
class LeitorDeDbcTest extends TestCase
{
    private const MINI = __DIR__.'/../Fixtures/dbc/mini.dbc';

    private const TRUNCADO = __DIR__.'/../Fixtures/dbc/mini_truncado.dbc';

    private LeitorDeDbc $leitor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->leitor = new LeitorDeDbc;
    }

    private function exigirPython(): void
    {
        if ($this->leitor->disponivel() !== null) {
            $this->markTestSkipped('Python 3.8+ não está disponível neste ambiente.');
        }
    }

    public function test_le_so_os_campos_pedidos_sem_espacos_e_ignora_registros_excluidos(): void
    {
        $this->exigirPython();

        $registros = iterator_to_array($this->leitor->ler(self::MINI, ['MUNIC_RES', 'DIAG_PRINC', 'NOME']), false);

        $this->assertSame([
            ['MUNIC_RES' => '330610', 'DIAG_PRINC' => 'I64', 'NOME' => 'Valença'],
            ['MUNIC_RES' => '330610', 'DIAG_PRINC' => 'S628', 'NOME' => 'Valença'],
            ['MUNIC_RES' => '330420', 'DIAG_PRINC' => 'J153', 'NOME' => 'Resende'],
            ['MUNIC_RES' => '330420', 'DIAG_PRINC' => 'E109', 'NOME' => ''],
        ], $registros);
    }

    public function test_arquivo_incompleto_levanta_erro_com_mensagem_para_o_administrador(): void
    {
        $this->exigirPython();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('incompleto ou corrompido');

        iterator_to_array($this->leitor->ler(self::TRUNCADO, ['NOME']), false);
    }

    public function test_campo_inexistente_no_arquivo_levanta_erro(): void
    {
        $this->exigirPython();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Campos inexistentes');

        iterator_to_array($this->leitor->ler(self::MINI, ['NAO_EXISTE']), false);
    }

    public function test_nome_de_campo_com_caracteres_perigosos_e_recusado_antes_de_executar_qualquer_coisa(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nome de campo inválido');

        iterator_to_array($this->leitor->ler(self::MINI, ['NOME; rm -rf /']), false);
    }

    public function test_arquivo_inexistente_levanta_erro(): void
    {
        $this->expectException(RuntimeException::class);

        iterator_to_array($this->leitor->ler(__DIR__.'/nao-existe.dbc', ['NOME']), false);
    }

    public function test_python_ausente_gera_mensagem_que_orienta_a_configurar_aps_python(): void
    {
        config(['aps.datasus.python' => 'python-que-nao-existe-xyz']);

        $problema = (new LeitorDeDbc)->disponivel();

        $this->assertNotNull($problema);
        $this->assertStringContainsString('APS_PYTHON', $problema);

        $this->expectException(ConfiguracaoInvalida::class);

        iterator_to_array((new LeitorDeDbc)->ler(self::MINI, ['NOME']), false);
    }
}
