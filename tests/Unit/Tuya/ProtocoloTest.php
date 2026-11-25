<?php

namespace Tests\Unit\Tuya;

use App\Dispositivos\Tuya\Protocolo;
use PHPUnit\Framework\TestCase;

/**
 * Gabaritos gerados com a biblioteca de referência tinytuya 1.21.1
 * (Python), para garantir que os pacotes são idênticos aos dela.
 */
class ProtocoloTest extends TestCase
{
    private const CHAVE = '0123456789abcdef';

    private const SESSAO = 'fedcba9876543210';

    private const CONTROLE = '{"devId":"bf01","uid":"bf01","t":"1700000000","dps":{"1":true}}';

    private const CONTROLE_NOVO = '{"protocol":5,"t":1700000000,"data":{"dps":{"1":true}}}';

    private const CONSULTA = '{"gwId":"bf01","devId":"bf01","uid":"bf01","t":"1700000000"}';

    private const RESPOSTA = '{"dps":{"1":true,"9":0},"t":1700000001}';

    private const ENC_CTRL_31 = '000055aa000000070000000700000073332e316530613136373164386137383734663143752f536b437756444245704b2f4538693233714d7552484c446338334e715a5945616e4e754838555830554b4a795151764b71434142586871713262387874584a473554555855356d35534c7050506a4571782f673d3d874b7b4c0000aa55';

    private const ENC_QUERY_31 = '000055aa000000080000000a000000447b2267774964223a2262663031222c226465764964223a2262663031222c22756964223a2262663031222c2274223a2231373030303030303030227d4674b28d0000aa55';

    private const ENC_CTRL_33 = '000055aa000000070000000700000057332e330000000000000000000000000aefd2902c150c11292bf13c8b6dea32e4472c373cdcda996046a736e1fc517d14289c9042f2aa08005786aab66fcc6d5c91b94d45d4e66e522e93cf8c4ab1fe11fbb7ba0000aa55';

    private const ENC_QUERY_33 = '000055aa000000080000000a000000487ab13cff013b99cfa305500c209e486fba95c41df4bf70e3c1a5e30f2f6dc3b074d582708547fe7e322c4e19a8edf5c4be23f34440aefb47c4fa7fbe7027af01121bd41f0000aa55';

    private const ENC_CTRL_34 = '000055aa000000090000000d000000746a2f4275da435008b5b5d1f2e19bf95d9fabc506b104548101bafb6638e0886b1e4c9dd4fafe30e6932f5acf9b6f4646472563a0fe8ddb6914321529740d6ae08e7ce1dafdf623cb20c0a8df8172887a04e4f3a253fd5c220800508a775a3676f03f8aeee397add94b067fb7a63f0a5d0000aa55';

    private const ENC_CTRL_35 = '000066990000000000090000000d000000626162636465666768696a6b6c7af66b616d15ce53dc219e9d444236b2f60c05c0847cedc8f8f512728da60bb7301f4d88cef8b0639c0918c58e165c575a1736c8eeaf4ad45f06f1a1c7eefebb3a3f8eff1f45dec29f07a9c74f4ee85eefdd3a8bab8600009966';

    private const DEC_33 = '000055aa000000030000000a0000003c00000000c27ee03f8be481e63320de7dc6eb429656870c28ebd04a67afd4078edbcfffaa3ed0f11afce322098b728261328fae5fcb5c0c820000aa55';

    private const DEC_33_STATUS = '000055aa000000040000000800000047332e33000000000000000000000000c27ee03f8be481e63320de7dc6eb429656870c28ebd04a67afd4078edbcfffaa3ed0f11afce322098b728261328fae5f4e7aab170000aa55';

    private const DEC_34 = '000055aa0000000300000010000000580000000040a7ab53750b6c0fb3d777d952c916c6269a09864bdcf82d14721f6b438eba6d516da6e05ce092f8615ef27839dbb78f824786cf84b53fbe4fcf33cdb6fe2d4206342f00fbca8fdffa676d88e45d24d40000aa55';

    private const DEC_35 = '0000669900000000000300000010000000476162636465666768696a6b6c49d85e611637aa23af03a4e6667314f3a00e02cadc31b785aee7556b83f05daf3b194a88cef8b0639c0855d7d49a362d546d0abeee5ecc0647e0b100009966';

    private const SESS_34 = '8cfba42de6a66d943a3463c439e8641d';

    private const SESS_35 = '13317c8c5f135cf672705dcaaaf089c5';

    public function test_codifica_controle_igual_a_tinytuya(): void
    {
        $this->assertSame(self::ENC_CTRL_31, bin2hex(Protocolo::codificar('3.1', 7, Protocolo::CONTROLE, self::CONTROLE, self::CHAVE)));
        $this->assertSame(self::ENC_CTRL_33, bin2hex(Protocolo::codificar('3.3', 7, Protocolo::CONTROLE, self::CONTROLE, self::CHAVE)));
        $this->assertSame(self::ENC_CTRL_34, bin2hex(Protocolo::codificar('3.4', 9, Protocolo::CONTROLE_NOVO, self::CONTROLE_NOVO, self::SESSAO)));
        $this->assertSame(self::ENC_CTRL_35, bin2hex(Protocolo::codificar('3.5', 9, Protocolo::CONTROLE_NOVO, self::CONTROLE_NOVO, self::SESSAO, 'abcdefghijkl')));
    }

    public function test_codifica_consulta_igual_a_tinytuya(): void
    {
        $this->assertSame(self::ENC_QUERY_31, bin2hex(Protocolo::codificar('3.1', 8, Protocolo::CONSULTA, self::CONSULTA, self::CHAVE)));
        $this->assertSame(self::ENC_QUERY_33, bin2hex(Protocolo::codificar('3.3', 8, Protocolo::CONSULTA, self::CONSULTA, self::CHAVE)));
    }

    public function test_decodifica_respostas_geradas_pela_tinytuya(): void
    {
        foreach ([
            ['3.3', self::DEC_33, self::CHAVE, Protocolo::CONSULTA, 0],
            ['3.3', self::DEC_33_STATUS, self::CHAVE, Protocolo::STATUS, null],
            ['3.4', self::DEC_34, self::SESSAO, Protocolo::CONSULTA_NOVA, 0],
            ['3.5', self::DEC_35, self::SESSAO, Protocolo::CONSULTA_NOVA, 0],
        ] as [$versao, $hex, $chave, $comando, $retorno]) {
            $mensagem = Protocolo::decodificar($versao, hex2bin($hex), $chave);
            $this->assertSame($comando, $mensagem->comando, "versão {$versao}");
            $this->assertSame($retorno, $mensagem->retorno, "versão {$versao}");
            $this->assertSame(self::RESPOSTA, $mensagem->payload, "versão {$versao}");
            $this->assertSame(['1' => true, '9' => 0], $mensagem->json()['dps']);
        }
    }

    public function test_chave_de_sessao_igual_a_tinytuya(): void
    {
        $this->assertSame(self::SESS_34, bin2hex(Protocolo::chaveDeSessao('3.4', self::CHAVE, '0123456789abcdef', 'ABCDEFGHIJKLMNOP')));
        $this->assertSame(self::SESS_35, bin2hex(Protocolo::chaveDeSessao('3.5', self::CHAVE, '0123456789abcdef', 'ABCDEFGHIJKLMNOP')));
    }

    public function test_chave_errada_e_detectada(): void
    {
        $this->expectExceptionMessageMatches('/chave local/');
        Protocolo::decodificar('3.4', hex2bin(self::DEC_34), 'XXXXXXXXXXXXXXXX');
    }
}
