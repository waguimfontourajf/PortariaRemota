<?php

namespace App\Dispositivos;

/**
 * Campo de configuração exibido no cadastro do dispositivo, conforme o driver.
 */
final readonly class Campo
{
    /**
     * @param  'texto'|'senha'|'numero'|'selecao'|'booleano'  $tipo
     * @param  array<string, string>  $opcoes  valor => rótulo (para "selecao")
     * @param  list<mixed>  $regras  Regras extras de validação do Laravel
     */
    public function __construct(
        public string $nome,
        public string $rotulo,
        public string $tipo = 'texto',
        public bool $obrigatorio = false,
        public mixed $padrao = null,
        public ?string $ajuda = null,
        public array $opcoes = [],
        public array $regras = [],
        public ?string $placeholder = null,
    ) {}

    /**
     * @return list<mixed>
     */
    public function regrasDeValidacao(): array
    {
        $regras = [$this->obrigatorio ? 'required' : 'nullable'];

        $regras[] = match ($this->tipo) {
            'numero' => 'numeric',
            'booleano' => 'boolean',
            'selecao' => 'in:'.implode(',', array_keys($this->opcoes)),
            default => 'string',
        };

        if (in_array($this->tipo, ['texto', 'senha'], true)) {
            $regras[] = 'max:1000';
        }

        return [...$regras, ...$this->regras];
    }
}
