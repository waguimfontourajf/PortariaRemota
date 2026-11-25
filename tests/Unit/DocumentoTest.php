<?php

namespace Tests\Unit;

use App\Support\Documento;
use PHPUnit\Framework\TestCase;

class DocumentoTest extends TestCase
{
    public function test_valida_cpf(): void
    {
        $this->assertTrue(Documento::cpfValido('529.982.247-25'));
        $this->assertFalse(Documento::cpfValido('529.982.247-24'));
        $this->assertFalse(Documento::cpfValido('000.000.000-00'));
        $this->assertFalse(Documento::cpfValido('123'));
    }

    public function test_valida_cnpj(): void
    {
        $this->assertTrue(Documento::cnpjValido('11.222.333/0001-81'));
        $this->assertFalse(Documento::cnpjValido('11.222.333/0001-80'));
        $this->assertFalse(Documento::cnpjValido('11111111111111'));
    }

    public function test_formata(): void
    {
        $this->assertSame('529.982.247-25', Documento::formatarCpf('52998224725'));
        $this->assertSame('11.222.333/0001-81', Documento::formatarCnpj('11222333000181'));
        $this->assertSame('(11) 91234-5678', Documento::formatarTelefone('11912345678'));
        $this->assertSame('(11) 3123-4567', Documento::formatarTelefone('1131234567'));
        $this->assertSame('01001-000', Documento::formatarCep('01001000'));
        $this->assertNull(Documento::formatarCpf(''));
    }
}
