<?php

/**
 * Módulo de relé Modbus falso para testes:
 *   php modbus_falso.php <porta> <formato tcp|rtu> [segundos] [slave id]
 *
 * Imita um eletechsup de 2 canais: registradores 1 e 2 (função 06 com
 * 0x01xx liga, 0x02xx desliga, 0x06ss liga e desliga após ss segundos),
 * bobinas 0 e 1 (funções 01/05) e entradas 0 e 1 (função 02; IN1 ativa).
 */

require __DIR__.'/../../vendor/autoload.php';

use App\Dispositivos\Modbus\Crc16;

[, $porta, $formato] = $argv;
$duracao = (int) ($argv[3] ?? 15);
$slave = (int) ($argv[4] ?? 1);
$reles = [0 => false, 1 => false];
$desligarEm = [];
$servidor = stream_socket_server("tcp://127.0.0.1:{$porta}", $e, $t);
echo "pronto\n";
$limite = time() + $duracao;

$ler = function ($c, int $n): string {
    $b = '';
    while (strlen($b) < $n) {
        $p = fread($c, $n - strlen($b));
        if ($p === '' || $p === false) {
            throw new RuntimeException('fim');
        }
        $b .= $p;
    }

    return $b;
};

$processar = function (int $funcao, string $dados) use (&$reles, &$desligarEm): string {
    foreach ($desligarEm as $i => $quando) {
        if (microtime(true) >= $quando) {
            $reles[$i] = false;
            unset($desligarEm[$i]);
        }
    }

    ['a' => $end, 'b' => $val] = unpack('na/nb', $dados);
    $erro = fn (int $codigo) => chr($funcao | 0x80).chr($codigo);

    switch ($funcao) {
        case 0x03:
            $i = $end - 1;
            if (! array_key_exists($i, $reles) || $val !== 1) {
                return $erro(2);
            }

            return chr(3).chr(2).pack('n', $reles[$i] ? 1 : 0);
        case 0x06:
            $i = $end - 1;
            if (! array_key_exists($i, $reles)) {
                return $erro(2);
            }
            $cmd = $val >> 8;
            match ($cmd) {
                0x01 => $reles[$i] = true,
                0x02 => $reles[$i] = false,
                0x06 => [$reles[$i] = true, $desligarEm[$i] = microtime(true) + ($val & 0xFF)],
                default => null,
            };

            return $cmd >= 1 && $cmd <= 6 ? chr(6).$dados : $erro(3);
        case 0x01:
            if (! array_key_exists($end, $reles)) {
                return $erro(2);
            }

            return chr(1).chr(1).chr($reles[$end] ? 1 : 0);
        case 0x05:
            if (! array_key_exists($end, $reles)) {
                return $erro(2);
            }
            $reles[$end] = $val === 0xFF00;

            return chr(5).$dados;
        case 0x02:
            return chr(2).chr(1).chr(0b01);
        default:
            return $erro(1);
    }
};

while (time() < $limite && ($conexao = @stream_socket_accept($servidor, $duracao))) {
    try {
        while (true) {
            if ($formato === 'tcp') {
                $cab = $ler($conexao, 7);
                ['id' => $id, 'tam' => $tam] = unpack('nid/nprot/ntam', $cab);
                $unidade = ord($cab[6]);
                $pdu = $ler($conexao, $tam - 1);
                if ($unidade !== $slave) {
                    continue; // outro escravo: não responde
                }
                $resp = $processar(ord($pdu[0]), substr($pdu, 1));
                fwrite($conexao, pack('nnn', $id, 0, strlen($resp) + 1).chr($unidade).$resp);
            } else {
                $quadro = $ler($conexao, 8); // todas as funções usadas têm 8 bytes no pedido
                if (! Crc16::valido($quadro) || ord($quadro[0]) !== $slave) {
                    continue;
                }
                $resp = $processar(ord($quadro[1]), substr($quadro, 2, 4));
                fwrite($conexao, Crc16::anexar(chr($slave).$resp));
            }
        }
    } catch (Throwable) {
        fclose($conexao);
    }
}
