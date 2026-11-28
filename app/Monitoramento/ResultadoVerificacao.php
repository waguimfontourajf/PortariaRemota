<?php

namespace App\Monitoramento;

final readonly class ResultadoVerificacao
{
    public function __construct(
        public bool $respondeu,
        public ?int $latenciaMs = null,
        public ?string $erro = null,
    ) {}
}
