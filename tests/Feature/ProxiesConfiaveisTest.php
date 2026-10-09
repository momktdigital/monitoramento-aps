<?php

namespace Tests\Feature;

use App\Support\ProxiesConfiaveis;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Tests\TestCase;

class ProxiesConfiaveisTest extends TestCase
{
    protected function tearDown(): void
    {
        TrustProxies::flushState();
        Request::setTrustedProxies([], 0);

        parent::tearDown();
    }

    public function test_sem_configuracao_o_cabecalho_do_proxy_e_ignorado(): void
    {
        ProxiesConfiaveis::aplicar(null);
        ProxiesConfiaveis::aplicar('   ');

        $this->get('/metodologia', ['X-Forwarded-Proto' => 'https'])->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_confiando_no_proxy_o_https_original_e_reconhecido(): void
    {
        ProxiesConfiaveis::aplicar('*');

        $this->get('/metodologia', ['X-Forwarded-Proto' => 'https'])->assertHeader('Strict-Transport-Security');
    }

    public function test_na_lista_de_ips_o_proxy_listado_e_obedecido(): void
    {
        ProxiesConfiaveis::aplicar('10.0.0.5, 127.0.0.1');

        $this->get('/metodologia', ['X-Forwarded-Proto' => 'https'])->assertHeader('Strict-Transport-Security');
    }

    public function test_cabecalho_vindo_de_um_ip_fora_da_lista_e_ignorado(): void
    {
        ProxiesConfiaveis::aplicar('10.0.0.5');

        $this->get('/metodologia', ['X-Forwarded-Proto' => 'https'])->assertHeaderMissing('Strict-Transport-Security');
    }
}
