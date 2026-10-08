<?php

namespace App\Integrations;

use RuntimeException;

/**
 * Falha esperada por configuração ausente ou recusada pela fonte (ex.: chave de API). A mensagem é
 * escrita para o administrador e pode ser exibida na tela.
 */
class ConfiguracaoInvalida extends RuntimeException {}
