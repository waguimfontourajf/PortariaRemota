<?php

namespace App\Support;

/**
 * Validação e formatação de CPF, CNPJ, telefone e CEP.
 */
final class Documento
{
    public static function digitos(?string $valor): string
    {
        return preg_replace('/\D/', '', (string) $valor) ?? '';
    }

    public static function cpfValido(?string $cpf): bool
    {
        $cpf = self::digitos($cpf);

        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            $soma = 0;
            for ($i = 0; $i < $t; $i++) {
                $soma += (int) $cpf[$i] * (($t + 1) - $i);
            }
            $digito = ((10 * $soma) % 11) % 10;
            if ((int) $cpf[$t] !== $digito) {
                return false;
            }
        }

        return true;
    }

    public static function cnpjValido(?string $cnpj): bool
    {
        $cnpj = self::digitos($cnpj);

        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        foreach ([12, 13] as $t) {
            $pesos = $t === 12 ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
            $soma = 0;
            for ($i = 0; $i < $t; $i++) {
                $soma += (int) $cnpj[$i] * $pesos[$i];
            }
            $resto = $soma % 11;
            $digito = $resto < 2 ? 0 : 11 - $resto;
            if ((int) $cnpj[$t] !== $digito) {
                return false;
            }
        }

        return true;
    }

    public static function formatarCpf(?string $cpf): ?string
    {
        $d = self::digitos($cpf);

        return strlen($d) === 11
            ? substr($d, 0, 3).'.'.substr($d, 3, 3).'.'.substr($d, 6, 3).'-'.substr($d, 9, 2)
            : (blank($cpf) ? null : $cpf);
    }

    public static function formatarCnpj(?string $cnpj): ?string
    {
        $d = self::digitos($cnpj);

        return strlen($d) === 14
            ? substr($d, 0, 2).'.'.substr($d, 2, 3).'.'.substr($d, 5, 3).'/'.substr($d, 8, 4).'-'.substr($d, 12, 2)
            : (blank($cnpj) ? null : $cnpj);
    }

    public static function formatarTelefone(?string $telefone): ?string
    {
        $d = self::digitos($telefone);

        return match (strlen($d)) {
            11 => '('.substr($d, 0, 2).') '.substr($d, 2, 5).'-'.substr($d, 7),
            10 => '('.substr($d, 0, 2).') '.substr($d, 2, 4).'-'.substr($d, 6),
            default => blank($telefone) ? null : $telefone,
        };
    }

    public static function formatarCep(?string $cep): ?string
    {
        $d = self::digitos($cep);

        return strlen($d) === 8 ? substr($d, 0, 5).'-'.substr($d, 5) : (blank($cep) ? null : $cep);
    }
}
