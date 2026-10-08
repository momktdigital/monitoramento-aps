<?php

namespace App\Integrations\Datasus\Contratos;

interface BaixadorDeArquivos
{
    /**
     * Identifica a versão atual de um arquivo remoto (tamanho e data de modificação), ou null se ele
     * ainda não foi publicado. Serve para não baixar de novo o que não mudou.
     */
    public function assinatura(string $caminhoRemoto): ?string;

    /**
     * Baixa o arquivo remoto para o caminho local informado.
     */
    public function baixar(string $caminhoRemoto, string $destino): void;
}
