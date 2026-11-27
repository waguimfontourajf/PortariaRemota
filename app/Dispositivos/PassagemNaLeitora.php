<?php

namespace App\Dispositivos;

use Carbon\CarbonInterface;

/**
 * Uma tentativa de passagem registrada pela leitora facial.
 */
final readonly class PassagemNaLeitora
{
    public function __construct(
        public string $referencia,      // identificador único do evento na leitora
        public CarbonInterface $momento,
        public ?int $codigoPessoa,      // ID da pessoa no sistema (null = não reconhecida)
        public bool $liberada,
        public string $descricao,
    ) {}
}
