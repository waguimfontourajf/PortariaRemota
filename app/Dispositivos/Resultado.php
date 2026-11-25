<?php

namespace App\Dispositivos;

/**
 * Resposta de um comando enviado a um dispositivo.
 */
final readonly class Resultado
{
    /**
     * @param  array<string, mixed>  $dados  Informações técnicas (para diagnóstico)
     */
    public function __construct(
        public bool $sucesso,
        public string $mensagem,
        public ?bool $ligado = null,
        public array $dados = [],
        public int $duracaoMs = 0,
    ) {}

    /**
     * @param  array<string, mixed>  $dados
     */
    public static function ok(string $mensagem = 'Comando executado.', ?bool $ligado = null, array $dados = []): self
    {
        return new self(true, $mensagem, $ligado, $dados);
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    public static function falha(string $mensagem, array $dados = []): self
    {
        return new self(false, $mensagem, null, $dados);
    }

    public function comDuracao(int $ms): self
    {
        return new self($this->sucesso, $this->mensagem, $this->ligado, $this->dados, $ms);
    }
}
