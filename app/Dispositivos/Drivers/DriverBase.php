<?php

namespace App\Dispositivos\Drivers;

use App\Dispositivos\Contratos\DriverDeRele;
use App\Dispositivos\ExcecaoDeComunicacao;
use App\Dispositivos\Resultado;
use App\Models\Dispositivo;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Base com comportamento comum: mede o tempo, transforma erros em Resultado
 * e implementa o pulso como "ligar, esperar, desligar" (os drivers que
 * suportam temporizador no próprio aparelho sobrescrevem pulso()).
 */
abstract class DriverBase implements DriverDeRele
{
    public static function usaHost(): bool
    {
        return true;
    }

    public static function portaPadrao(): ?int
    {
        return null;
    }

    public static function maximoDeCanais(): int
    {
        return 4;
    }

    public function pulso(Dispositivo $dispositivo, int $canal, int $ms): Resultado
    {
        return $this->executar(function () use ($dispositivo, $canal, $ms) {
            $ligou = $this->ligar($dispositivo, $canal);

            if (! $ligou->sucesso) {
                return $ligou;
            }

            usleep($ms * 1000);

            // Tenta desligar mais de uma vez: um relé preso ligado deixa o portão aberto.
            $desligou = $this->desligar($dispositivo, $canal);
            for ($tentativa = 1; ! $desligou->sucesso && $tentativa < 3; $tentativa++) {
                usleep(200_000);
                $desligou = $this->desligar($dispositivo, $canal);
            }

            return $desligou->sucesso
                ? Resultado::ok('Pulso enviado.', false)
                : Resultado::falha('Ligou, mas NÃO conseguiu desligar: verifique o equipamento! '.$desligou->mensagem);
        });
    }

    public function testar(Dispositivo $dispositivo): Resultado
    {
        $resultado = $this->estado($dispositivo, 1);

        return $resultado->sucesso
            ? Resultado::ok('Dispositivo respondeu.', $resultado->ligado, $resultado->dados)->comDuracao($resultado->duracaoMs)
            : $resultado;
    }

    /**
     * Executa medindo o tempo e convertendo exceções em falha.
     *
     * @param  Closure(): Resultado  $acao
     */
    protected function executar(Closure $acao): Resultado
    {
        $inicio = hrtime(true);

        try {
            $resultado = $acao();
        } catch (ExcecaoDeComunicacao $e) {
            $resultado = Resultado::falha($e->getMessage());
        } catch (ConnectionException $e) {
            $resultado = Resultado::falha('Sem resposta do dispositivo (rede ou endereço incorreto).', ['erro' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::warning('Erro no driver '.static::chave(), ['erro' => $e->getMessage()]);
            $resultado = Resultado::falha('Erro inesperado: '.$e->getMessage());
        }

        return $resultado->comDuracao((int) ((hrtime(true) - $inicio) / 1_000_000));
    }

    protected function urlBase(Dispositivo $dispositivo, string $esquema = 'http'): string
    {
        if (blank($dispositivo->host)) {
            throw new ExcecaoDeComunicacao('Endereço (IP) do dispositivo não configurado.');
        }

        $porta = $dispositivo->porta ?: static::portaPadrao();

        return $esquema.'://'.$dispositivo->host.($porta ? ':'.$porta : '');
    }

    protected function timeout(Dispositivo $dispositivo): float
    {
        return (float) $dispositivo->opcao('timeout', 3);
    }
}
