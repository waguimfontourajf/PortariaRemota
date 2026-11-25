<?php

namespace Tests\Feature\Portaria;

use App\Dispositivos\ExcecaoDeComunicacao;
use App\Dispositivos\Tuya\ClienteTuya;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Conversa completa com um aparelho Tuya falso (tests/Fixtures/tuya_falso.php).
 * A compatibilidade byte a byte com a biblioteca de referência está em
 * tests/Unit/Tuya/ProtocoloTest.php.
 */
class ClienteTuyaTest extends TestCase
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

    private function iniciarAparelho(string $versao, string $chave = '0123456789abcdef'): void
    {
        $this->porta = random_int(20000, 40000);
        $this->processo = proc_open(
            [PHP_BINARY, base_path('tests/Fixtures/tuya_falso.php'), (string) $this->porta, $chave, $versao],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertSame("pronto\n", fgets($pipes[1]));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function versoes(): array
    {
        return ['3.3' => ['3.3'], '3.4' => ['3.4'], '3.5' => ['3.5']];
    }

    #[DataProvider('versoes')]
    public function test_consulta_liga_e_desliga(string $versao): void
    {
        $this->iniciarAparelho($versao);
        $cliente = new ClienteTuya('127.0.0.1', 'bf0123456789abcdef', '0123456789abcdef', $versao, $this->porta, 3);

        $this->assertSame(['1' => false], $cliente->consultar());
        $cliente->definir(['1' => true]);
        $this->assertSame(['1' => true], $cliente->consultar());
        $cliente->fechar();

        // nova conexão / nova sessão
        $cliente->definir(['1' => false]);
        $this->assertSame(['1' => false], $cliente->consultar());
        $cliente->fechar();
    }

    public function test_chave_errada_no_34_e_detectada_na_negociacao(): void
    {
        $this->iniciarAparelho('3.4');
        $cliente = new ClienteTuya('127.0.0.1', 'bf0123456789abcdef', 'XXXXXXXXXXXXXXXX', '3.4', $this->porta, 2);

        $this->expectException(ExcecaoDeComunicacao::class);
        $this->expectExceptionMessageMatches('/chave local/');
        $cliente->consultar();
    }

    public function test_aparelho_desligado_gera_mensagem_clara(): void
    {
        $cliente = new ClienteTuya('127.0.0.1', 'bf0123456789abcdef', '0123456789abcdef', '3.3', 1, 1);

        $this->expectException(ExcecaoDeComunicacao::class);
        $this->expectExceptionMessageMatches('/Não foi possível conectar/');
        $cliente->consultar();
    }
}
