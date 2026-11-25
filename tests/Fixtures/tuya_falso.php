<?php

/**
 * Aparelho Tuya falso para testes: php tuya_falso.php <porta> <chave> <versao>
 * Atende conexões por até 15 s. Implementa consulta, controle e (3.4/3.5)
 * a negociação de sessão, respondendo como um aparelho real.
 */

require __DIR__.'/../../vendor/autoload.php';

use App\Dispositivos\Tuya\Protocolo as P;

[, $porta, $chave, $versao] = $argv;
$dps = ['1' => false];
$servidor = stream_socket_server("tcp://127.0.0.1:{$porta}", $e, $t);
echo "pronto\n";
$limite = time() + (int) ($argv[4] ?? 15);

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

// Resposta do aparelho (inclui código de retorno 0).
$responder = function ($c, int $seq, int $cmd, string $claro, string $k, bool $cabecalho = false) use ($versao) {
    $claro = ($cabecalho ? P::cabecalhoDeVersao($versao) : '').$claro;
    $pacote = match ($versao) {
        '3.5' => P::empacotar6699($seq, $cmd, "\0\0\0\0".$claro, $k, random_bytes(12)),
        '3.4' => P::empacotar55AA($seq, $cmd, "\0\0\0\0".($claro === '' ? '' : P::cifrarEcb($claro, $k)), $k),
        default => P::empacotar55AA($seq, $cmd, "\0\0\0\0".($claro === '' ? '' : P::cifrarEcb($claro, $k))),
    };
    fwrite($c, $pacote);
};

while (time() < $limite && ($conexao = @stream_socket_accept($servidor, (int) ($argv[4] ?? 15)))) {
    $k = $chave;
    try {
        while (true) {
            $pre = $ler($conexao, 4);
            $cab = $pre.$ler($conexao, P::tamanhoDoCabecalho($pre) - 4);
            $pacote = $cab.$ler($conexao, P::tamanhoTotal($cab) - strlen($cab));
            // Mensagens do cliente não têm código de retorno: decodifica pelo mesmo formato.
            $m = P::decodificar($versao, $pacote, $k);
            $claro = $m->payload;

            if ($m->comando === P::SESSAO_INICIO) {
                $remoto = random_bytes(16);
                $responder($conexao, $m->seq, P::SESSAO_RESPOSTA, $remoto.hash_hmac('sha256', $claro, $chave, true), $k);
                $pre = $ler($conexao, 4);
                $cab = $pre.$ler($conexao, P::tamanhoDoCabecalho($pre) - 4);
                $fim = P::decodificar($versao, $cab.$ler($conexao, P::tamanhoTotal($cab) - strlen($cab)), $k);
                if (! hash_equals(hash_hmac('sha256', $remoto, $chave, true), substr($fim->payload, 0, 32))) {
                    throw new RuntimeException('hmac');
                }
                $k = P::chaveDeSessao($versao, $chave, $claro, $remoto);
            } elseif (in_array($m->comando, [P::CONSULTA, P::CONSULTA_NOVA], true)) {
                $responder($conexao, $m->seq, $m->comando, json_encode(['dps' => $dps]), $k);
            } elseif (in_array($m->comando, [P::CONTROLE, P::CONTROLE_NOVO], true)) {
                $d = json_decode($claro, true);
                foreach ($d['dps'] ?? $d['data']['dps'] ?? [] as $dp => $valor) {
                    $dps[$dp] = $valor;
                }
                $responder($conexao, $m->seq, $m->comando, '', $k);
                $responder($conexao, $m->seq + 1, P::STATUS, json_encode(['dps' => $dps]), $k, true);
            }
        }
    } catch (Throwable) {
        fclose($conexao);
    }
}
