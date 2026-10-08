<?php

namespace Tests\Feature;

use App\Integrations\Ibge\MalhaMunicipal;
use App\Models\Municipio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MalhaMunicipalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['aps.http.tentativas' => 1, 'aps.http.espera_ms' => 0]);
    }

    /**
     * @return array<string, mixed>
     */
    private function geojson(string ...$codigos): array
    {
        return [
            'type' => 'FeatureCollection',
            'features' => array_map(fn (string $codigo): array => [
                'type' => 'Feature',
                'properties' => ['codarea' => $codigo],
                'geometry' => ['type' => 'MultiPolygon', 'coordinates' => [[[[-44.5, -23.0], [-44.4, -23.0], [-44.4, -22.9], [-44.5, -23.0]]]]],
            ], $codigos ?: ['3300100']),
        ];
    }

    public function test_baixa_a_malha_do_ibge_valida_e_guarda_em_disco(): void
    {
        Http::fake(['servicodados.ibge.gov.br/api/v3/malhas/*' => Http::response($this->geojson('3300100', '3306107'))]);

        $texto = app(MalhaMunicipal::class)->daUf(33);

        $this->assertNotNull($texto);
        $this->assertCount(2, json_decode($texto, true)['features']);
        Storage::disk('local')->assertExists('malhas/uf-33.geojson');
        Http::assertSent(fn ($requisicao): bool => str_contains($requisicao->url(), 'estados/33') && str_contains($requisicao->url(), 'intrarregiao=municipio') && str_contains($requisicao->url(), 'qualidade=minima'));
    }

    public function test_usa_o_arquivo_em_disco_enquanto_ele_estiver_dentro_da_validade(): void
    {
        Http::fake(['*' => Http::response($this->geojson())]);

        $malha = app(MalhaMunicipal::class);
        $malha->daUf(33);
        $malha->daUf(33);

        Http::assertSentCount(1);
    }

    public function test_arquivo_vencido_e_baixado_de_novo(): void
    {
        Http::fake(['*' => Http::response($this->geojson('3300100'))]);

        $malha = app(MalhaMunicipal::class);
        $malha->daUf(33);

        Storage::disk('local')->put('malhas/uf-33.geojson', json_encode($this->geojson('3300100')));
        touch(Storage::disk('local')->path('malhas/uf-33.geojson'), now()->subDays(120)->getTimestamp());

        $malha->daUf(33);

        Http::assertSentCount(2);
    }

    public function test_se_o_ibge_falhar_na_atualizacao_o_arquivo_antigo_continua_servindo(): void
    {
        $antigo = json_encode($this->geojson('3300100'));
        Storage::disk('local')->put('malhas/uf-33.geojson', $antigo);
        touch(Storage::disk('local')->path('malhas/uf-33.geojson'), now()->subDays(120)->getTimestamp());
        Http::fake(['*' => Http::response('erro', 500)]);

        $this->assertSame($antigo, app(MalhaMunicipal::class)->daUf(33));
    }

    public function test_sem_arquivo_e_com_o_ibge_fora_do_ar_devolve_nulo(): void
    {
        Http::fake(['*' => Http::response('erro', 500)]);

        $this->assertNull(app(MalhaMunicipal::class)->daUf(33));
        Storage::disk('local')->assertMissing('malhas/uf-33.geojson');
    }

    public function test_recusa_resposta_que_nao_e_uma_malha_de_municipios(): void
    {
        foreach ([
            ['type' => 'FeatureCollection', 'features' => []],
            ['type' => 'Feature'],
            ['type' => 'FeatureCollection', 'features' => [['type' => 'Feature', 'properties' => ['codarea' => 'abc'], 'geometry' => ['type' => 'Polygon', 'coordinates' => []]]]],
            ['type' => 'FeatureCollection', 'features' => [['type' => 'Feature', 'properties' => ['codarea' => '3300100'], 'geometry' => ['type' => 'Point', 'coordinates' => [0, 0]]]]],
        ] as $invalida) {
            Http::fake(['*' => Http::response($invalida)]);

            $this->assertNull(app(MalhaMunicipal::class)->daUf(33), json_encode($invalida));
        }

        Storage::disk('local')->assertMissing('malhas/uf-33.geojson');
    }

    public function test_codigo_de_uf_fora_do_intervalo_nem_chega_a_consultar_o_ibge(): void
    {
        Http::fake();

        $this->assertNull(app(MalhaMunicipal::class)->daUf(0));
        $this->assertNull(app(MalhaMunicipal::class)->daUf(99));

        Http::assertNothingSent();
    }

    public function test_a_rota_exige_login(): void
    {
        $this->get('/dados/malha/33')->assertRedirect('/login');
    }

    public function test_a_rota_entrega_o_geojson_com_cache_privado_e_responde_304_quando_nada_mudou(): void
    {
        Municipio::factory()->create();
        Http::fake(['*' => Http::response($this->geojson('3300100'))]);
        $usuario = User::factory()->gestor()->create();

        $resposta = $this->actingAs($usuario)->get('/dados/malha/33')->assertOk();

        $this->assertStringContainsString('geo+json', $resposta->headers->get('Content-Type'));
        $this->assertStringContainsString('private', $resposta->headers->get('Cache-Control'));
        $this->assertNotEmpty($resposta->headers->get('ETag'));

        $this->actingAs($usuario)->get('/dados/malha/33', ['If-None-Match' => $resposta->headers->get('ETag')])->assertStatus(304);
    }

    public function test_a_rota_so_serve_ufs_que_o_sistema_acompanha(): void
    {
        Municipio::factory()->create();
        Http::fake();

        $this->actingAs(User::factory()->gestor()->create())->get('/dados/malha/35')->assertNotFound();
        $this->actingAs(User::factory()->gestor()->create())->get('/dados/malha/abc')->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_a_rota_informa_indisponibilidade_quando_nao_ha_malha(): void
    {
        Municipio::factory()->create();
        Http::fake(['*' => Http::response('erro', 500)]);

        $this->actingAs(User::factory()->gestor()->create())->get('/dados/malha/33')->assertStatus(503);
    }
}
