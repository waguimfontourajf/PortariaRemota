<?php

namespace App\Dispositivos;

use RuntimeException;

/**
 * Falha ao conversar com um dispositivo (rede, autenticação, resposta inválida).
 * A mensagem é exibida ao usuário, então deve estar em português.
 */
class ExcecaoDeComunicacao extends RuntimeException {}
