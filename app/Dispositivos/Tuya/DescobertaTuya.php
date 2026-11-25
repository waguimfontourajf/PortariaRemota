<?php

namespace App\Dispositivos\Tuya;

use Throwable;

/**
 * Escuta os anúncios que os aparelhos Tuya enviam na rede local (UDP 6666,
 * 6667 e 7000, a cada ~5 segundos) e devolve IP, ID e versão de cada um.
 */
class DescobertaTuya
{
    private const PORTAS = [6666, 6667, 7000];

    /**
     * @return array<string, array{ip: string, id: string, versao: string, produto: ?string}> indexado pelo ID
     */
    public function procurar(int $segundos = 8): array
    {
        $sockets = [];
        $erros = [];

        foreach (self::PORTAS as $porta) {
            $socket = @stream_socket_server("udp://0.0.0.0:{$porta}", $numero, $texto, STREAM_SERVER_BIND);
            if ($socket) {
                stream_set_blocking($socket, false);
                $sockets[$porta] = $socket;
            } else {
                $erros[] = "porta {$porta}: {$texto}";
            }
        }

        if ($sockets === []) {
            throw new \RuntimeException('Não foi possível escutar as portas UDP 6666/6667/7000 ('.implode('; ', $erros).'). Feche outros programas Tuya/Home Assistant e libere o firewall.');
        }

        $encontrados = [];
        $limite = microtime(true) + $segundos;
        $chaveUdp = md5(Protocolo::CHAVE_UDP, true);

        while (($restante = $limite - microtime(true)) > 0) {
            $leitura = array_values($sockets);
            $nada = null;

            if (! @stream_select($leitura, $nada, $nada, (int) $restante, (int) (fmod($restante, 1) * 1_000_000))) {
                continue;
            }

            foreach ($leitura as $socket) {
                $pacote = stream_socket_recvfrom($socket, 4096, 0, $origem);

                if (! $pacote) {
                    continue;
                }

                $dados = $this->decodificar($pacote, $chaveUdp);

                if (isset($dados['gwId'])) {
                    $encontrados[$dados['gwId']] = [
                        'ip' => (string) ($dados['ip'] ?? strtok((string) $origem, ':')),
                        'id' => (string) $dados['gwId'],
                        'versao' => (string) ($dados['version'] ?? '?'),
                        'produto' => $dados['productKey'] ?? null,
                    ];
                }
            }
        }

        foreach ($sockets as $socket) {
            fclose($socket);
        }

        return $encontrados;
    }

    /**
     * @return array<string, mixed>
     */
    public function decodificar(string $pacote, string $chaveUdp): array
    {
        foreach (['3.3', '3.1'] as $versao) {
            try {
                $mensagem = Protocolo::decodificar($versao === '3.1' ? '3.1' : '3.3', $pacote, $chaveUdp);
                $json = $mensagem->json();
                if ($json) {
                    return $json;
                }
            } catch (Throwable) {
                // tenta o próximo formato
            }
        }

        return [];
    }
}
