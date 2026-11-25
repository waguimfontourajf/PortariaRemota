<?php

namespace App\Dispositivos\Drivers;

use App\Dispositivos\Campo;
use App\Dispositivos\ExcecaoDeComunicacao;
use App\Dispositivos\Resultado;
use App\Dispositivos\Tuya\ClienteTuya;
use App\Models\Dispositivo;

/**
 * Aparelhos Tuya / Smart Life (e marcas que usam a plataforma, como JWCOM
 * SMART, Positivo Casa Inteligente, Nova Digital...) controlados pela REDE
 * LOCAL, sem depender da nuvem.
 *
 * Requer o ID do aparelho e a chave local (local_key). Veja docs/TUYA.md.
 */
class TuyaLocal extends DriverBase
{
    public static function chave(): string
    {
        return 'tuya_local';
    }

    public static function nome(): string
    {
        return 'Tuya / Smart Life (rede local)';
    }

    public static function descricao(): string
    {
        return 'Funciona sem internet. Precisa do ID e da chave local do aparelho (comando “php artisan portaria:tuya-chaves”) e da versão do protocolo (“php artisan portaria:tuya-procurar”).';
    }

    public static function portaPadrao(): ?int
    {
        return 6668;
    }

    public static function maximoDeCanais(): int
    {
        return 8;
    }

    public static function campos(): array
    {
        return [
            new Campo('device_id', 'ID do aparelho (device id)', obrigatorio: true, placeholder: 'ebxxxxxxxxxxxxxxxxxxxx', regras: ['min:10', 'max:40']),
            new Campo('local_key', 'Chave local (local_key)', 'senha', obrigatorio: true, ajuda: '16 caracteres. Muda se o aparelho for removido e pareado de novo no app.', regras: ['size:16']),
            new Campo('versao', 'Versão do protocolo', 'selecao', obrigatorio: true, padrao: '3.3', opcoes: [
                '3.1' => '3.1 (aparelhos antigos)',
                '3.3' => '3.3 (mais comum)',
                '3.4' => '3.4',
                '3.5' => '3.5 (aparelhos novos)',
            ], ajuda: 'O comando “php artisan portaria:tuya-procurar” mostra a versão de cada aparelho.'),
            new Campo('dps', 'Data points dos canais', padrao: '1', placeholder: '1,2,3,4', ajuda: 'Número do “dp” de cada canal, na ordem. Tomadas e bocais costumam usar 1; lâmpadas, 20.'),
            new Campo('dp_contagem', 'Data point do temporizador (opcional)', placeholder: '9', ajuda: 'Se o aparelho tem “contagem regressiva”, o próprio aparelho desliga mesmo se a rede cair. Comum: 9.'),
            new Campo('timeout', 'Tempo limite (segundos)', 'numero', padrao: 3, regras: ['min:1', 'max:30']),
        ];
    }

    public function pulso(Dispositivo $dispositivo, int $canal, int $ms): Resultado
    {
        return $this->executar(function () use ($dispositivo, $canal, $ms) {
            $cliente = $this->cliente($dispositivo);
            $dp = $this->dp($dispositivo, $canal);

            try {
                $ligar = [$dp => true];
                if ($contagem = $dispositivo->opcao('dp_contagem')) {
                    $ligar[(string) $contagem] = max(1, (int) ceil($ms / 1000));
                }

                $cliente->definir($ligar);
                usleep($ms * 1000);

                // Desliga (com nova tentativa): relé preso = portão aberto.
                try {
                    $cliente->definir([$dp => false]);
                } catch (ExcecaoDeComunicacao) {
                    $cliente->fechar();
                    $cliente->definir([$dp => false]);
                }
            } finally {
                $cliente->fechar();
            }

            return Resultado::ok('Pulso enviado.', false);
        });
    }

    public function ligar(Dispositivo $dispositivo, int $canal): Resultado
    {
        return $this->executar(fn () => $this->definir($dispositivo, $canal, true));
    }

    public function desligar(Dispositivo $dispositivo, int $canal): Resultado
    {
        return $this->executar(fn () => $this->definir($dispositivo, $canal, false));
    }

    public function estado(Dispositivo $dispositivo, int $canal): Resultado
    {
        return $this->executar(function () use ($dispositivo, $canal) {
            $cliente = $this->cliente($dispositivo);

            try {
                $dps = $cliente->consultar();
            } finally {
                $cliente->fechar();
            }

            $dp = $this->dp($dispositivo, $canal);

            return Resultado::ok('Aparelho respondeu.', array_key_exists($dp, $dps) ? (bool) $dps[$dp] : null, ['dps' => $dps]);
        });
    }

    public function testar(Dispositivo $dispositivo): Resultado
    {
        $resultado = $this->estado($dispositivo, 1);

        if (! $resultado->sucesso) {
            return $resultado;
        }

        $dps = json_encode($resultado->dados['dps'] ?? [], JSON_UNESCAPED_SLASHES);

        return Resultado::ok("Aparelho respondeu. Data points: {$dps}", $resultado->ligado, $resultado->dados)->comDuracao($resultado->duracaoMs);
    }

    private function definir(Dispositivo $dispositivo, int $canal, bool $ligado): Resultado
    {
        $cliente = $this->cliente($dispositivo);

        try {
            $cliente->definir([$this->dp($dispositivo, $canal) => $ligado]);
        } finally {
            $cliente->fechar();
        }

        return Resultado::ok($ligado ? 'Ligado.' : 'Desligado.', $ligado);
    }

    /**
     * Cliente para o dispositivo. Público para o comando de diagnóstico.
     */
    public function cliente(Dispositivo $dispositivo): ClienteTuya
    {
        if (blank($dispositivo->host)) {
            throw new ExcecaoDeComunicacao('Endereço (IP) do aparelho não configurado.');
        }

        return new ClienteTuya(
            host: $dispositivo->host,
            idDispositivo: (string) $dispositivo->opcao('device_id', ''),
            chaveLocal: (string) $dispositivo->opcao('local_key', ''),
            versao: (string) $dispositivo->opcao('versao', '3.3'),
            porta: $dispositivo->porta ?: 6668,
            timeout: $this->timeout($dispositivo),
        );
    }

    private function dp(Dispositivo $dispositivo, int $canal): string
    {
        $lista = array_values(array_filter(array_map('trim', explode(',', (string) $dispositivo->opcao('dps', '1')))));
        $dp = $lista[$canal - 1] ?? null;

        if ($dp === null || ! ctype_digit($dp)) {
            throw new ExcecaoDeComunicacao("Canal {$canal} sem data point configurado (campo \"Data points dos canais\").");
        }

        return $dp;
    }
}
