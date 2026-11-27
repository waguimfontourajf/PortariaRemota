<?php

namespace Tests\Feature\Portaria;

use App\Dispositivos\ExcecaoDeComunicacao;
use App\Dispositivos\Modbus\ClienteModbus;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Conversa com um módulo Modbus falso (tests/Fixtures/modbus_falso.php),
 * que imita um relé eletechsup de 2 canais.
 */
class ClienteModbusTest extends TestCase
{
    /** @var resource|null */
    private $processo = null;

    private int $porta;

    protected function tearDown(): void
    {
        if ($this->processo) {
            proc_terminate($this->processo);
            proc_close($this->processo);
        }

        parent::tearDown();
    }

    private function iniciarModulo(string $formato): void
    {
        $this->porta = random_int(20000, 40000);
        $this->processo = proc_open(
            [PHP_BINARY, base_path('tests/Fixtures/modbus_falso.php'), (string) $this->porta, $formato],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertSame("pronto\n", fgets($pipes[1]));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function formatos(): array
    {
        return ['Modbus TCP' => ['tcp'], 'RTU sobre TCP' => ['rtu']];
    }

    #[DataProvider('formatos')]
    public function test_comandos_eletechsup(string $formato): void
    {
        $this->iniciarModulo($formato);
        $cliente = new ClienteModbus('127.0.0.1', $this->porta, 1, $formato, 2);

        $this->assertSame([0], $cliente->lerRegistradores(1));
        $cliente->escreverRegistrador(1, 0x0100);
        $this->assertSame([1], $cliente->lerRegistradores(1));
        $cliente->escreverRegistrador(1, 0x0200);
        $this->assertSame([0], $cliente->lerRegistradores(1));

        // Pulso controlado pelo módulo: liga e desliga sozinho após 1 s.
        $cliente->escreverRegistrador(2, 0x0601);
        $this->assertSame([1], $cliente->lerRegistradores(2));
        usleep(1_200_000);
        $this->assertSame([0], $cliente->lerRegistradores(2));

        $this->assertSame([true, false], $cliente->lerEntradas(0, 2));
        $cliente->fechar();
    }

    #[DataProvider('formatos')]
    public function test_bobinas_padrao(string $formato): void
    {
        $this->iniciarModulo($formato);
        $cliente = new ClienteModbus('127.0.0.1', $this->porta, 1, $formato, 2);

        $cliente->escreverBobina(0, true);
        $this->assertSame([true], $cliente->lerBobinas(0));
        $cliente->escreverBobina(0, false);
        $this->assertSame([false], $cliente->lerBobinas(0));
        $cliente->fechar();
    }

    public function test_erro_do_modulo_vira_mensagem_em_portugues(): void
    {
        $this->iniciarModulo('tcp');
        $cliente = new ClienteModbus('127.0.0.1', $this->porta, 1, 'tcp', 2);

        $this->expectException(ExcecaoDeComunicacao::class);
        $this->expectExceptionMessage('não existe no módulo');
        $cliente->lerRegistradores(9);
    }

    public function test_sem_modulo_na_porta(): void
    {
        $this->expectException(ExcecaoDeComunicacao::class);
        $this->expectExceptionMessage('Não foi possível conectar');
        (new ClienteModbus('127.0.0.1', 1, 1, 'tcp', 1))->lerRegistradores(1);
    }
}
