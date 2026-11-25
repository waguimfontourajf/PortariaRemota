<?php

namespace App\Dispositivos\Tuya;

/**
 * Mensagem do protocolo local Tuya já decodificada.
 */
final readonly class Mensagem
{
    public function __construct(
        public int $seq,
        public int $comando,
        public string $payload,
        public ?int $retorno = null,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function json(): ?array
    {
        $inicio = strpos($this->payload, '{');

        if ($inicio === false) {
            return null;
        }

        $dados = json_decode(substr($this->payload, $inicio), true);

        return is_array($dados) ? $dados : null;
    }
}
