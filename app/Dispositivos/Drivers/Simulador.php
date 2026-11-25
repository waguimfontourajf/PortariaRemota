<?php

namespace App\Dispositivos\Drivers;

use App\Dispositivos\Campo;
use App\Dispositivos\Resultado;
use App\Models\Dispositivo;
use Illuminate\Support\Facades\Cache;

/**
 * Relé de mentira: não precisa de hardware. Serve para demonstrações,
 * treinamento e testes automáticos.
 */
class Simulador extends DriverBase
{
    public static function chave(): string
    {
        return 'simulador';
    }

    public static function nome(): string
    {
        return 'Simulador (sem equipamento)';
    }

    public static function descricao(): string
    {
        return 'Finge ser um relé. Use para demonstração e treinamento.';
    }

    public static function usaHost(): bool
    {
        return false;
    }

    public static function maximoDeCanais(): int
    {
        return 16;
    }

    public static function campos(): array
    {
        return [
            new Campo('falhar', 'Simular falha de comunicação', 'booleano', padrao: false),
        ];
    }

    public function pulso(Dispositivo $dispositivo, int $canal, int $ms): Resultado
    {
        return $this->executar(fn () => $this->falhaSimulada($dispositivo)
            ?? Resultado::ok('Pulso simulado de '.$ms.' ms no canal '.$canal.'.', false));
    }

    public function ligar(Dispositivo $dispositivo, int $canal): Resultado
    {
        return $this->alterar($dispositivo, $canal, true);
    }

    public function desligar(Dispositivo $dispositivo, int $canal): Resultado
    {
        return $this->alterar($dispositivo, $canal, false);
    }

    public function estado(Dispositivo $dispositivo, int $canal): Resultado
    {
        return $this->executar(fn () => $this->falhaSimulada($dispositivo)
            ?? Resultado::ok('Estado simulado.', (bool) Cache::get($this->chaveCache($dispositivo, $canal), false)));
    }

    private function alterar(Dispositivo $dispositivo, int $canal, bool $ligado): Resultado
    {
        return $this->executar(function () use ($dispositivo, $canal, $ligado) {
            if ($falha = $this->falhaSimulada($dispositivo)) {
                return $falha;
            }

            Cache::put($this->chaveCache($dispositivo, $canal), $ligado, now()->addDay());

            return Resultado::ok($ligado ? 'Ligado (simulado).' : 'Desligado (simulado).', $ligado);
        });
    }

    private function falhaSimulada(Dispositivo $dispositivo): ?Resultado
    {
        return $dispositivo->opcao('falhar') ? Resultado::falha('Falha simulada de comunicação.') : null;
    }

    private function chaveCache(Dispositivo $dispositivo, int $canal): string
    {
        return "simulador:{$dispositivo->id}:{$canal}";
    }
}
