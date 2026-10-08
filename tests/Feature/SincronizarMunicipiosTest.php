<?php

namespace Tests\Feature;

use App\Integrations\ClienteHttp;
use App\Models\Municipio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SincronizarMunicipiosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'aps.ufs' => ['RJ'],
            'aps.municipios_piloto' => [3306107],
            'aps.http.tentativas' => 1,
            'aps.http.espera_ms' => 0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function respostasDasFontes(): array
    {
        return [
            'servicodados.ibge.gov.br/*' => Http::response([
                ['id' => 3306107, 'nome' => 'Valença', 'regiao-imediata' => ['id' => 330006, 'nome' => 'Valença']],
                ['id' => 3304201, 'nome' => 'Resende', 'regiao-imediata' => ['id' => 330003, 'nome' => 'Resende']],
            ]),
            'apidadosabertos.saude.gov.br/*' => Http::response(['macrorregiao_regiao_saude_municipios' => [
                ['codigo_municipio' => '330610', 'codigo_regiao_saude' => '33004', 'regiao_saude' => 'MEDIO PARAIBA', 'codigo_macrorregiao_saude' => '3312', 'macrorregiao_saude' => 'MACRORREGIAO I'],
                ['codigo_municipio' => '330420', 'codigo_regiao_saude' => '33004', 'regiao_saude' => 'MEDIO PARAIBA', 'codigo_macrorregiao_saude' => '3312', 'macrorregiao_saude' => 'MACRORREGIAO I'],
            ]]),
        ];
    }

    public function test_carrega_municipios_com_regiao_de_saude_e_marca_o_piloto(): void
    {
        Http::fake($this->respostasDasFontes());

        $this->artisan('aps:sincronizar-municipios')->assertSuccessful();

        $valenca = Municipio::findOrFail(3306107);

        $this->assertSame(330610, $valenca->codigo6);
        $this->assertSame('valenca', $valenca->nome_busca);
        $this->assertSame('RJ', $valenca->uf);
        $this->assertSame(33, $valenca->codigo_uf);
        $this->assertSame('MEDIO PARAIBA', $valenca->regiao_saude_nome);
        $this->assertSame(3312, $valenca->macrorregiao_saude_codigo);
        $this->assertTrue($valenca->piloto);
        $this->assertFalse(Municipio::findOrFail(3304201)->piloto);
    }

    public function test_e_idempotente_e_nao_desfaz_a_escolha_manual_de_piloto(): void
    {
        Http::fake($this->respostasDasFontes());

        $this->artisan('aps:sincronizar-municipios')->assertSuccessful();

        Municipio::whereKey(3306107)->update(['piloto' => false]);
        Municipio::whereKey(3304201)->update(['piloto' => true]);

        $this->artisan('aps:sincronizar-municipios')->assertSuccessful();

        $this->assertSame(2, Municipio::count());
        $this->assertFalse(Municipio::findOrFail(3306107)->piloto);
        $this->assertTrue(Municipio::findOrFail(3304201)->piloto);
    }

    public function test_falha_nas_regioes_de_saude_nao_impede_carga_nem_apaga_regioes_existentes(): void
    {
        Http::fake($this->respostasDasFontes());
        $this->artisan('aps:sincronizar-municipios')->assertSuccessful();

        Http::fake([
            'servicodados.ibge.gov.br/*' => Http::response([
                ['id' => 3306107, 'nome' => 'Valença', 'regiao-imediata' => ['id' => 330006, 'nome' => 'Valença']],
            ]),
            'apidadosabertos.saude.gov.br/*' => Http::response('erro', 500),
        ]);

        $this->artisan('aps:sincronizar-municipios')->assertSuccessful();

        $this->assertSame('MEDIO PARAIBA', Municipio::findOrFail(3306107)->regiao_saude_nome);
    }

    public function test_falha_no_ibge_encerra_com_erro_sem_gravar_nada(): void
    {
        Http::fake(['servicodados.ibge.gov.br/*' => Http::response('erro', 503)]);

        $this->artisan('aps:sincronizar-municipios')->assertFailed();

        $this->assertSame(0, Municipio::count());
    }

    public function test_so_conversa_com_hosts_declarados_na_configuracao(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ClienteHttp::fonte('https://site-qualquer.example');
    }
}
