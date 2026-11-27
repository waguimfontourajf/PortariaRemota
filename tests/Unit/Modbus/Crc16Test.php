<?php

namespace Tests\Unit\Modbus;

use App\Dispositivos\Modbus\Crc16;
use PHPUnit\Framework\TestCase;

class Crc16Test extends TestCase
{
    public function test_crc_igual_ao_exemplo_publicado(): void
    {
        // "01 05 00 00 FF 00 8C 3A" = ligar o relé 1 (exemplo da documentação Waveshare).
        $this->assertSame('01050000ff008c3a', bin2hex(Crc16::anexar(hex2bin('01050000ff00'))));
    }

    public function test_detecta_quadro_corrompido(): void
    {
        $this->assertTrue(Crc16::valido(hex2bin('01050000ff008c3a')));
        $this->assertFalse(Crc16::valido(hex2bin('01050000ff008c3b')));
    }
}
