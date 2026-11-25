<?php

namespace App\Dispositivos\Tuya;

use App\Dispositivos\ExcecaoDeComunicacao;

/**
 * Codificação/decodificação de pacotes do protocolo LAN da Tuya
 * (versões 3.1, 3.3, 3.4 e 3.5). Funções puras: não abre conexões.
 *
 * Formatos:
 *  - 55AA (até 3.4): prefixo, seq, comando, tamanho | [retorno] payload | CRC32 ou HMAC-SHA256 | sufixo
 *  - 6699 (3.5):     prefixo, 0, seq, comando, tamanho | IV(12) + AES-GCM + tag(16) | sufixo
 */
final class Protocolo
{
    public const PREFIXO_55AA = 0x000055AA;

    public const SUFIXO_55AA = 0x0000AA55;

    public const PREFIXO_6699 = 0x00006699;

    public const SUFIXO_6699 = 0x00009966;

    public const SESSAO_INICIO = 0x03;

    public const SESSAO_RESPOSTA = 0x04;

    public const SESSAO_FIM = 0x05;

    public const CONTROLE = 0x07;

    public const STATUS = 0x08;

    public const BATIMENTO = 0x09;

    public const CONSULTA = 0x0A;

    public const CONTROLE_NOVO = 0x0D;

    public const CONSULTA_NOVA = 0x10;

    public const ATUALIZAR_DPS = 0x12;

    /** Chave fixa usada pela Tuya para criptografar os anúncios UDP. */
    public const CHAVE_UDP = 'yGAdlopoPVldABfn';

    /** Comandos que NÃO levam o cabeçalho "3.x" + 12 bytes zerados. */
    private const SEM_CABECALHO_DE_VERSAO = [
        self::CONSULTA, self::CONSULTA_NOVA, self::ATUALIZAR_DPS, self::BATIMENTO,
        self::SESSAO_INICIO, self::SESSAO_RESPOSTA, self::SESSAO_FIM,
    ];

    public static function cabecalhoDeVersao(string $versao): string
    {
        return $versao.str_repeat("\0", 12);
    }

    public static function levaCabecalhoDeVersao(int $comando): bool
    {
        return ! in_array($comando, self::SEM_CABECALHO_DE_VERSAO, true);
    }

    /**
     * Monta o pacote completo pronto para enviar.
     *
     * @param  string  $chave  local_key (ou chave de sessão em 3.4/3.5)
     */
    public static function codificar(string $versao, int $seq, int $comando, string $json, string $chave, ?string $iv = null): string
    {
        $comCabecalho = self::levaCabecalhoDeVersao($comando);

        return match ($versao) {
            '3.5' => self::empacotar6699(
                $seq, $comando,
                ($comCabecalho ? self::cabecalhoDeVersao($versao) : '').$json,
                $chave, $iv ?? random_bytes(12),
            ),
            '3.4' => self::empacotar55AA(
                $seq, $comando,
                self::cifrarEcb(($comCabecalho ? self::cabecalhoDeVersao($versao) : '').$json, $chave),
                $chave,
            ),
            '3.2', '3.3' => self::empacotar55AA(
                $seq, $comando,
                ($comCabecalho ? self::cabecalhoDeVersao($versao) : '').self::cifrarEcb($json, $chave),
            ),
            '3.1' => self::empacotar55AA($seq, $comando, $comando === self::CONTROLE ? self::assinar31($json, $chave) : $json),
            default => throw new ExcecaoDeComunicacao("Versão de protocolo Tuya não suportada: {$versao}"),
        };
    }

    public static function empacotar55AA(int $seq, int $comando, string $payload, ?string $chaveHmac = null): string
    {
        $tamanho = strlen($payload) + ($chaveHmac !== null ? 36 : 8);
        $buffer = pack('NNNN', self::PREFIXO_55AA, $seq, $comando, $tamanho).$payload;
        $verificacao = $chaveHmac !== null
            ? hash_hmac('sha256', $buffer, $chaveHmac, true)
            : pack('N', crc32($buffer));

        return $buffer.$verificacao.pack('N', self::SUFIXO_55AA);
    }

    public static function empacotar6699(int $seq, int $comando, string $claro, string $chave, string $iv): string
    {
        $cabecalho = pack('NnNNN', self::PREFIXO_6699, 0, $seq, $comando, 12 + strlen($claro) + 16);
        $tag = '';
        $cifrado = openssl_encrypt($claro, 'aes-128-gcm', $chave, OPENSSL_RAW_DATA, $iv, $tag, substr($cabecalho, 4), 16);

        if ($cifrado === false) {
            throw new ExcecaoDeComunicacao('Falha ao criptografar (AES-GCM).');
        }

        return $cabecalho.$iv.$cifrado.$tag.pack('N', self::SUFIXO_6699);
    }

    /**
     * Quantos bytes faltam ler depois dos primeiros 4 (prefixo) para ter o
     * cabeçalho completo.
     */
    public static function tamanhoDoCabecalho(string $prefixo): int
    {
        return match (unpack('N', $prefixo)[1]) {
            self::PREFIXO_55AA => 16,
            self::PREFIXO_6699 => 18,
            default => throw new ExcecaoDeComunicacao('Resposta inválida do dispositivo (prefixo desconhecido).'),
        };
    }

    /**
     * Quantos bytes o pacote inteiro tem, a partir do cabeçalho.
     */
    public static function tamanhoTotal(string $cabecalho): int
    {
        if (unpack('N', substr($cabecalho, 0, 4))[1] === self::PREFIXO_6699) {
            return 18 + unpack('N', substr($cabecalho, 14, 4))[1] + 4;
        }

        return 16 + unpack('N', substr($cabecalho, 12, 4))[1];
    }

    /**
     * Decodifica um pacote recebido.
     *
     * @param  string  $chave  local_key ou chave de sessão
     */
    public static function decodificar(string $versao, string $pacote, string $chave): Mensagem
    {
        $prefixo = unpack('N', substr($pacote, 0, 4))[1];

        if ($prefixo === self::PREFIXO_6699) {
            return self::decodificar6699($pacote, $chave);
        }

        if ($prefixo !== self::PREFIXO_55AA) {
            throw new ExcecaoDeComunicacao('Resposta inválida do dispositivo.');
        }

        ['seq' => $seq, 'cmd' => $comando, 'tam' => $tamanho] = unpack('Nprefixo/Nseq/Ncmd/Ntam', substr($pacote, 0, 16));
        $usaHmac = $versao === '3.4';
        $tamanhoFinal = $usaHmac ? 36 : 8;
        $dados = substr($pacote, 16, $tamanho - $tamanhoFinal);

        if ($usaHmac) {
            $esperado = hash_hmac('sha256', substr($pacote, 0, 16 + strlen($dados)), $chave, true);
            if (! hash_equals($esperado, substr($pacote, 16 + strlen($dados), 32))) {
                throw new ExcecaoDeComunicacao('Assinatura inválida na resposta: a chave local (local_key) provavelmente está errada.');
            }
        }

        [$retorno, $dados] = self::separarRetorno($dados, $versao !== '3.1');

        if ($dados === '') {
            return new Mensagem($seq, $comando, '', $retorno);
        }

        if ($versao === '3.1') {
            // 3.1: respostas em texto puro, exceto quando vierem com "3.1" + md5 + base64.
            if (str_starts_with($dados, '3.1')) {
                $dados = self::decifrarEcb(base64_decode(substr($dados, 19)), $chave);
            }

            return new Mensagem($seq, $comando, $dados, $retorno);
        }

        // 3.2/3.3: o cabeçalho "3.x" + 12 bytes vem fora da parte cifrada
        // (cifrado = múltiplo de 16; com cabeçalho sobra 15).
        if (strlen($dados) % 16 === 15) {
            $dados = substr($dados, 15);
        }

        $claro = self::decifrarEcb($dados, $chave);

        if (str_starts_with($claro, '3.')) {
            $claro = substr($claro, 15);
        }

        return new Mensagem($seq, $comando, $claro, $retorno);
    }

    private static function decodificar6699(string $pacote, string $chave): Mensagem
    {
        ['seq' => $seq, 'cmd' => $comando, 'tam' => $tamanho] = unpack('Nprefixo/nzero/Nseq/Ncmd/Ntam', substr($pacote, 0, 18));
        $corpo = substr($pacote, 18, $tamanho);
        $iv = substr($corpo, 0, 12);
        $tag = substr($corpo, -16);
        $cifrado = substr($corpo, 12, -16);

        $claro = openssl_decrypt($cifrado, 'aes-128-gcm', $chave, OPENSSL_RAW_DATA, $iv, $tag, substr($pacote, 4, 14));

        if ($claro === false) {
            throw new ExcecaoDeComunicacao('Não foi possível decifrar a resposta: a chave local (local_key) provavelmente está errada.');
        }

        [$retorno, $claro] = self::separarRetorno($claro, false);

        if (str_starts_with($claro, '3.')) {
            $claro = substr($claro, 15);
        }

        return new Mensagem($seq, $comando, $claro, $retorno);
    }

    /**
     * As respostas do aparelho podem começar com um código de retorno de 4
     * bytes. Em dados cifrados (múltiplos de 16, com ou sem cabeçalho de 15
     * bytes), ele é identificado pelo tamanho.
     *
     * @return array{0: int|null, 1: string}
     */
    private static function separarRetorno(string $dados, bool $cifrado): array
    {
        $tamanho = strlen($dados);

        if ($tamanho < 4) {
            return [null, $dados];
        }

        $temRetorno = $cifrado
            ? in_array($tamanho % 16, [4, 3], true) || $tamanho === 4
            : str_starts_with($dados, "\0\0\0");

        if (! $temRetorno || ! str_starts_with($dados, "\0\0\0")) {
            return [null, $dados];
        }

        return [unpack('N', substr($dados, 0, 4))[1], substr($dados, 4)];
    }

    public static function cifrarEcb(string $claro, string $chave, bool $preencher = true): string
    {
        $resultado = openssl_encrypt($claro, 'aes-128-ecb', $chave, OPENSSL_RAW_DATA | ($preencher ? 0 : OPENSSL_ZERO_PADDING));

        if ($resultado === false) {
            throw new ExcecaoDeComunicacao('Falha ao criptografar (AES).');
        }

        return $resultado;
    }

    public static function decifrarEcb(string $cifrado, string $chave): string
    {
        $resultado = openssl_decrypt($cifrado, 'aes-128-ecb', $chave, OPENSSL_RAW_DATA);

        if ($resultado === false) {
            throw new ExcecaoDeComunicacao('Não foi possível decifrar a resposta: a chave local (local_key) provavelmente está errada.');
        }

        return $resultado;
    }

    /**
     * Versão 3.1: o comando de controle vai em base64 com uma assinatura MD5.
     */
    private static function assinar31(string $json, string $chave): string
    {
        $base64 = base64_encode(self::cifrarEcb($json, $chave));
        $md5 = md5('data='.$base64.'||lpv=3.1||'.$chave);

        return '3.1'.substr($md5, 8, 16).$base64;
    }

    /**
     * Chave de sessão das versões 3.4/3.5, a partir dos dois "nonces".
     */
    public static function chaveDeSessao(string $versao, string $chaveLocal, string $nonceLocal, string $nonceRemoto): string
    {
        $mistura = $nonceLocal ^ $nonceRemoto;

        if ($versao === '3.4') {
            return self::cifrarEcb($mistura, $chaveLocal, preencher: false);
        }

        $tag = '';
        $cifrado = openssl_encrypt($mistura, 'aes-128-gcm', $chaveLocal, OPENSSL_RAW_DATA, substr($nonceLocal, 0, 12), $tag, '', 16);

        return substr((string) $cifrado, 0, 16);
    }
}
