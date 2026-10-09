<?php

namespace App\Administracao;

use DomainException;

/**
 * Regra de proteção da administração (ex.: não remover o último administrador). A mensagem é escrita para quem usa a tela.
 */
class OperacaoNaoPermitida extends DomainException {}
