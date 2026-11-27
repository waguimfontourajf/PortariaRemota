<?php

namespace App\Dispositivos\Modbus;

/**
 * CRC16 do Modbus RTU (polinômio 0xA001, valor inicial 0xFFFF, byte baixo primeiro).
 */
final class Crc16
{
    public static function calcular(string $dados): int
    {
        $crc = 0xFFFF;

        for ($i = 0, $n = strlen($dados); $i < $n; $i++) {
            $crc ^= ord($dados[$i]);

            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 1) ? (($crc >> 1) ^ 0xA001) : ($crc >> 1);
            }
        }

        return $crc;
    }

    public static function anexar(string $dados): string
    {
        return $dados.pack('v', self::calcular($dados));
    }

    public static function valido(string $quadro): bool
    {
        if (strlen($quadro) < 4) {
            return false;
        }

        return unpack('v', substr($quadro, -2))[1] === self::calcular(substr($quadro, 0, -2));
    }
}
