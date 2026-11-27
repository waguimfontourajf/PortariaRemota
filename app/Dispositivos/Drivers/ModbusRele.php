<?php

namespace App\Dispositivos\Drivers;

use App\Dispositivos\Campo;
use App\Dispositivos\ExcecaoDeComunicacao;
use App\Dispositivos\Modbus\ClienteModbus;
use App\Dispositivos\Resultado;
use App\Models\Dispositivo;
use Closure;
use Throwable;

/**
 * Módulos de relé Modbus pela rede: eletechsup (ETR0A01, ETR0B02, ET48A08...)
 * e outros que seguem o padrão Modbus (Waveshare, genéricos).
 *
 * Dois conjuntos de comandos:
 *  - "eletechsup": função 06 no registrador do canal (1 = canal 1), byte alto
 *    = comando (01 liga, 02 desliga, 06 = liga e desliga sozinho após N s).
 *    O pulso é feito pelo próprio módulo: se a rede cair, ele desliga mesmo assim.
 *  - "bobinas": padrão Modbus (funções 01/05), canal 1 = bobina 0.
 */
class ModbusRele extends DriverBase
{
    private const LIGAR = 0x01;

    private const DESLIGAR = 0x02;

    private const ATRASO = 0x06;

    /** @var list<string> */
    private array $registro = [];

    public static function chave(): string
    {
        return 'modbus';
    }

    public static function nome(): string
    {
        return 'Relé Modbus TCP (eletechsup e similares)';
    }

    public static function descricao(): string
    {
        return 'Módulos de relé com porta de rede que usam Modbus (ex.: eletechsup ETR0A01/ETR0B02). Porta padrão 502. Use a chave SW2 do módulo DESLIGADA.';
    }

    public static function portaPadrao(): ?int
    {
        return 502;
    }

    public static function maximoDeCanais(): int
    {
        return 32;
    }

    public static function campos(): array
    {
        return [
            new Campo('comandos', 'Tipo de comando', 'selecao', obrigatorio: true, padrao: 'eletechsup', opcoes: [
                'eletechsup' => 'eletechsup (registradores, pulso feito pelo módulo)',
                'bobinas' => 'Padrão Modbus (bobinas / coils)',
            ], ajuda: 'Para eletechsup, use a primeira opção.'),
            new Campo('formato', 'Formato', 'selecao', obrigatorio: true, padrao: 'tcp', opcoes: [
                'tcp' => 'Modbus TCP (mais comum)',
                'rtu' => 'Modbus RTU sobre TCP (modo transparente)',
            ], ajuda: 'Se o teste der “resposta inválida” ou “não respondeu”, experimente o outro formato.'),
            new Campo('unidade', 'Slave ID (endereço)', 'numero', padrao: 1, ajuda: 'Igual às chaves A0–A5 do módulo. Na dúvida, 1.', regras: ['integer', 'min:0', 'max:247']),
            new Campo('endereco_inicial', 'Endereço do canal 1 (opcional)', 'numero', placeholder: 'automático', ajuda: 'Deixe vazio: eletechsup usa 1; padrão Modbus usa 0.', regras: ['integer', 'min:0', 'max:65535']),
            new Campo('timeout', 'Tempo limite (segundos)', 'numero', padrao: 3, regras: ['min:1', 'max:30']),
        ];
    }

    public function ligar(Dispositivo $dispositivo, int $canal): Resultado
    {
        return $this->executar(fn () => $this->definir($dispositivo, $canal, true));
    }

    public function desligar(Dispositivo $dispositivo, int $canal): Resultado
    {
        return $this->executar(fn () => $this->definir($dispositivo, $canal, false));
    }

    public function pulso(Dispositivo $dispositivo, int $canal, int $ms): Resultado
    {
        if ($this->tipo($dispositivo) !== 'eletechsup') {
            return parent::pulso($dispositivo, $canal, $ms);
        }

        return $this->executar(function () use ($dispositivo, $canal, $ms) {
            $segundos = min(255, max(1, (int) ceil($ms / 1000)));
            $cliente = $this->cliente($dispositivo);

            try {
                $cliente->escreverRegistrador($this->endereco($dispositivo, $canal), (self::ATRASO << 8) | $segundos);
            } finally {
                $cliente->fechar();
            }

            return Resultado::ok("Pulso enviado ({$segundos} s, controlado pelo módulo).", false);
        });
    }

    public function estado(Dispositivo $dispositivo, int $canal): Resultado
    {
        return $this->executar(function () use ($dispositivo, $canal) {
            $cliente = $this->cliente($dispositivo);

            try {
                $ligado = $this->ler($cliente, $dispositivo, $canal);
            } finally {
                $cliente->fechar();
            }

            return Resultado::ok('Módulo respondeu.', $ligado);
        });
    }

    public function testar(Dispositivo $dispositivo): Resultado
    {
        return $this->executar(function () use ($dispositivo) {
            $cliente = $this->cliente($dispositivo);
            $canais = max(1, min((int) $dispositivo->canais ?: 1, static::maximoDeCanais()));

            try {
                $estados = [];
                for ($canal = 1; $canal <= $canais; $canal++) {
                    $estados[$canal] = $this->ler($cliente, $dispositivo, $canal);
                }

                // Entradas digitais (IN1, IN2...): nem todo módulo responde; só informativo.
                $entradas = null;
                try {
                    $entradas = $cliente->lerEntradas(0, 2);
                } catch (ExcecaoDeComunicacao) {
                    $cliente->fechar();
                }
            } finally {
                $cliente->fechar();
            }

            $texto = implode(', ', array_map(fn ($c, $l) => "canal {$c}: ".($l ? 'LIGADO' : 'desligado'), array_keys($estados), $estados));
            if ($entradas !== null) {
                $texto .= '. Entradas: '.implode(', ', array_map(fn ($i, $v) => 'IN'.($i + 1).'='.($v ? 'ativa' : 'inativa'), array_keys($entradas), $entradas));
            }

            return Resultado::ok("Módulo respondeu. {$texto}.", $estados[1], ['canais' => $estados, 'entradas' => $entradas]);
        });
    }

    protected function executar(Closure $acao): Resultado
    {
        $this->registro = [];
        $resultado = parent::executar($acao);

        if ($this->registro === []) {
            return $resultado;
        }

        return new Resultado(
            $resultado->sucesso, $resultado->mensagem, $resultado->ligado,
            [...$resultado->dados, 'registro' => $this->registro], $resultado->duracaoMs,
        );
    }

    /**
     * Envia o comando e lê de volta para confirmar o estado real.
     */
    private function definir(Dispositivo $dispositivo, int $canal, bool $ligado): Resultado
    {
        $cliente = $this->cliente($dispositivo);
        $endereco = $this->endereco($dispositivo, $canal);

        try {
            if ($this->tipo($dispositivo) === 'eletechsup') {
                $cliente->escreverRegistrador($endereco, ($ligado ? self::LIGAR : self::DESLIGAR) << 8);
            } else {
                $cliente->escreverBobina($endereco, $ligado);
            }

            usleep(100_000);
            try {
                $real = $this->ler($cliente, $dispositivo, $canal);
            } catch (Throwable) {
                $real = null; // módulo aceitou o comando mas não permite leitura
            }
        } finally {
            $cliente->fechar();
        }

        if ($real !== null && $real !== $ligado) {
            return Resultado::falha('O módulo recebeu o comando, mas o canal continua '.($real ? 'LIGADO' : 'desligado').'. Confira o canal e o tipo de comando.');
        }

        return Resultado::ok(($ligado ? 'Ligado' : 'Desligado').($real === null ? '.' : ' (confirmado pelo módulo).'), $real ?? $ligado);
    }

    private function ler(ClienteModbus $cliente, Dispositivo $dispositivo, int $canal): bool
    {
        $endereco = $this->endereco($dispositivo, $canal);

        return $this->tipo($dispositivo) === 'eletechsup'
            ? ($cliente->lerRegistradores($endereco, 1)[0] & 0xFF) !== 0
            : $cliente->lerBobinas($endereco, 1)[0];
    }

    private function tipo(Dispositivo $dispositivo): string
    {
        return (string) $dispositivo->opcao('comandos', 'eletechsup');
    }

    private function endereco(Dispositivo $dispositivo, int $canal): int
    {
        $inicial = $dispositivo->opcao('endereco_inicial');
        $inicial = $inicial === null ? ($this->tipo($dispositivo) === 'eletechsup' ? 1 : 0) : (int) $inicial;

        return $inicial + $canal - 1;
    }

    private function cliente(Dispositivo $dispositivo): ClienteModbus
    {
        if (blank($dispositivo->host)) {
            throw new ExcecaoDeComunicacao('Endereço (IP) do módulo não configurado.');
        }

        return (new ClienteModbus(
            host: $dispositivo->host,
            porta: (int) ($dispositivo->porta ?: 502),
            unidade: (int) $dispositivo->opcao('unidade', 1),
            formato: (string) $dispositivo->opcao('formato', 'tcp'),
            timeout: $this->timeout($dispositivo),
        ))->aoDiagnosticar(function (string $linha) {
            $this->registro[] = '['.date('H:i:s').'] '.$linha;
        });
    }
}
