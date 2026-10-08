<?php

namespace App\Integrations\Datasus\Contratos;

interface LeitorDeRegistros
{
    /**
     * Verifica se o ambiente consegue ler os arquivos (ex.: Python instalado). Retorna a mensagem do
     * problema, em linguagem para o administrador, ou null se estiver tudo certo.
     */
    public function disponivel(): ?string;

    /**
     * Lê um arquivo .dbc local e devolve, um a um, os registros com apenas os campos pedidos
     * (nome do campo => valor, sem espaços nas pontas). Registros excluídos no DBF são ignorados.
     *
     * @param  list<string>  $campos
     * @return \Generator<int, array<string, string>>
     */
    public function ler(string $arquivo, array $campos): \Generator;
}
