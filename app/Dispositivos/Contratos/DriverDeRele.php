<?php

namespace App\Dispositivos\Contratos;

use App\Dispositivos\Campo;
use App\Dispositivos\Resultado;
use App\Models\Dispositivo;

/**
 * Contrato de todo driver de relé. Canais começam em 1.
 *
 * Para criar um driver novo: implemente esta interface (ou estenda
 * App\Dispositivos\Drivers\DriverBase) e registre a classe em
 * config/portaria.php > drivers.
 */
interface DriverDeRele
{
    /** Identificador gravado no banco (ex.: "shelly"). Nunca mude depois de usado. */
    public static function chave(): string;

    /** Nome exibido na caixa de seleção. */
    public static function nome(): string;

    public static function descricao(): string;

    /** O dispositivo é acessado por IP/host? */
    public static function usaHost(): bool;

    public static function portaPadrao(): ?int;

    public static function maximoDeCanais(): int;

    /**
     * Campos extras de configuração deste driver.
     *
     * @return list<Campo>
     */
    public static function campos(): array;

    /** Liga o canal e desliga sozinho depois de $ms milissegundos. */
    public function pulso(Dispositivo $dispositivo, int $canal, int $ms): Resultado;

    public function ligar(Dispositivo $dispositivo, int $canal): Resultado;

    public function desligar(Dispositivo $dispositivo, int $canal): Resultado;

    /** Consulta se o canal está ligado. */
    public function estado(Dispositivo $dispositivo, int $canal): Resultado;

    /** Verifica se o dispositivo responde (não aciona nada). */
    public function testar(Dispositivo $dispositivo): Resultado;
}
