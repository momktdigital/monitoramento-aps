<?php

namespace Tests\Feature;

use Tests\TestCase;

class CabecalhosDeSegurancaTest extends TestCase
{
    public function test_respostas_trazem_os_cabecalhos_de_seguranca(): void
    {
        $resposta = $this->get(route('login'));

        $resposta->assertHeader('X-Frame-Options', 'DENY');
        $resposta->assertHeader('X-Content-Type-Options', 'nosniff');
        $resposta->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $resposta->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
        $resposta->assertHeaderMissing('X-Powered-By');
    }

    public function test_a_politica_de_conteudo_usa_nonce_e_nao_permite_scripts_inline_ou_eval(): void
    {
        $politica = $this->get(route('login'))->headers->get('Content-Security-Policy');

        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9+\/=]{20,}'/", $politica);
        $this->assertStringContainsString("frame-ancestors 'none'", $politica);
        $this->assertStringContainsString("object-src 'none'", $politica);
        $this->assertStringNotContainsString("'unsafe-eval'", $politica);
        $this->assertStringNotContainsString('script-src \'self\' \'unsafe-inline\'', $politica);
    }

    public function test_hsts_so_e_enviado_em_https(): void
    {
        $this->get(route('login'))->assertHeaderMissing('Strict-Transport-Security');

        $this->get(route('login'), ['HTTPS' => 'on'])->assertHeaderMissing('Strict-Transport-Security');

        $this->withServerVariables(['HTTPS' => 'on'])
            ->get('https://localhost/login')
            ->assertHeader('Strict-Transport-Security');
    }

    public function test_arquivos_sensiveis_nao_sao_rotas_da_aplicacao(): void
    {
        $this->get('/.env')->assertNotFound();
        $this->get('/composer.json')->assertNotFound();
    }
}
