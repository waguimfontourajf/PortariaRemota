<?php

namespace App\Dispositivos;

use App\Models\HorarioAcesso;
use App\Models\Pessoa;
use Carbon\CarbonInterface;

/**
 * Quando a pessoa pode entrar pela leitora: validade (datas) e horário semanal.
 */
final readonly class RegrasDeAcesso
{
    public function __construct(
        public ?CarbonInterface $de = null,
        public ?CarbonInterface $ate = null,
        public ?HorarioAcesso $horario = null,
    ) {}

    public static function daPessoa(Pessoa $pessoa): self
    {
        return new self($pessoa->acesso_de?->copy()->startOfDay(), $pessoa->acesso_ate?->copy()->endOfDay(), $pessoa->horarioAcesso);
    }

    /**
     * Muda quando qualquer regra muda (inclusive a edição do horário).
     */
    public function assinatura(): string
    {
        return implode('|', [
            $this->de?->format('Y-m-d') ?? '-',
            $this->ate?->format('Y-m-d') ?? '-',
            $this->horario ? self::assinaturaDoHorario($this->horario) : '-',
        ]);
    }

    public static function assinaturaDoHorario(HorarioAcesso $horario): string
    {
        return $horario->numero.':'.implode(',', $horario->dias).':'.$horario->inicio.'-'.$horario->fim.':'.$horario->nome;
    }
}
