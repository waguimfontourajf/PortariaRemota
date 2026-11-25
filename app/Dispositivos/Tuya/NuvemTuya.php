<?php

namespace App\Dispositivos\Tuya;

use App\Dispositivos\ExcecaoDeComunicacao;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Acesso à API de nuvem da Tuya (iot.tuya.com), usado UMA VEZ para obter
 * o ID e a chave local (local_key) dos aparelhos. O controle do dia a dia
 * é feito pela rede local (driver TuyaLocal), sem nuvem.
 */
class NuvemTuya
{
    public const REGIOES = [
        'us' => 'https://openapi.tuyaus.com',     // Américas (Brasil)
        'eu' => 'https://openapi.tuyaeu.com',     // Europa
        'cn' => 'https://openapi.tuyacn.com',     // China
        'in' => 'https://openapi.tuyain.com',     // Índia
        'ueaz' => 'https://openapi-ueaz.tuyaus.com',
        'weaz' => 'https://openapi-weaz.tuyaeu.com',
    ];

    private ?string $token = null;

    public function __construct(
        private readonly string $accessId,
        private readonly string $accessSecret,
        private readonly string $regiao = 'us',
    ) {
        if (! isset(self::REGIOES[$regiao])) {
            throw new ExcecaoDeComunicacao('Região inválida. Use: '.implode(', ', array_keys(self::REGIOES)));
        }
    }

    /**
     * Lista os aparelhos ligados ao projeto (contas de app vinculadas).
     *
     * @return list<array{id: string, nome: string, chave_local: string, produto: ?string, online: bool, ip: ?string}>
     */
    public function aparelhos(): array
    {
        $aparelhos = [];
        $ultimo = null;

        do {
            $consulta = ['size' => 50] + ($ultimo ? ['last_row_key' => $ultimo] : []);
            $resultado = $this->get('/v1.0/iot-01/associated-users/devices', $consulta);

            foreach ($resultado['devices'] ?? [] as $d) {
                $aparelhos[] = [
                    'id' => (string) ($d['id'] ?? ''),
                    'nome' => (string) ($d['name'] ?? ''),
                    'chave_local' => (string) ($d['local_key'] ?? ''),
                    'produto' => $d['product_name'] ?? null,
                    'online' => (bool) ($d['online'] ?? false),
                    'ip' => $d['ip'] ?? null,
                ];
            }

            $ultimo = $resultado['last_row_key'] ?? null;
        } while (($resultado['has_more'] ?? false) && $ultimo);

        return $aparelhos;
    }

    /**
     * Dados de um aparelho específico pelo ID.
     *
     * @return array{id: string, nome: string, chave_local: string, produto: ?string, online: bool, ip: ?string}
     */
    public function aparelho(string $id): array
    {
        $d = $this->get('/v1.0/devices/'.rawurlencode($id));

        return [
            'id' => (string) ($d['id'] ?? $id),
            'nome' => (string) ($d['name'] ?? ''),
            'chave_local' => (string) ($d['local_key'] ?? ''),
            'produto' => $d['product_name'] ?? null,
            'online' => (bool) ($d['online'] ?? false),
            'ip' => $d['ip'] ?? null,
        ];
    }

    /**
     * @param  array<string, scalar>  $consulta
     * @return array<string, mixed>
     */
    private function get(string $caminho, array $consulta = []): array
    {
        $this->token ??= $this->obterToken();

        return $this->requisitar($caminho, $consulta, $this->token);
    }

    private function obterToken(): string
    {
        $resultado = $this->requisitar('/v1.0/token', ['grant_type' => 1], null);

        return (string) ($resultado['access_token'] ?? throw new ExcecaoDeComunicacao('A Tuya não devolveu o token de acesso.'));
    }

    /**
     * Requisição assinada (HMAC-SHA256) conforme a documentação da Tuya.
     *
     * @param  array<string, scalar>  $consulta
     * @return array<string, mixed>
     */
    private function requisitar(string $caminho, array $consulta, ?string $token): array
    {
        ksort($consulta);
        $url = $caminho.($consulta ? '?'.http_build_query($consulta) : '');
        $tempo = (string) (int) (microtime(true) * 1000);
        $nonce = Str::uuid()->toString();
        $textoAssinado = "GET\n".hash('sha256', '')."\n\n".$url;
        $assinatura = strtoupper(hash_hmac('sha256', $this->accessId.($token ?? '').$tempo.$nonce.$textoAssinado, $this->accessSecret));

        $resposta = Http::timeout(15)->withHeaders(array_filter([
            'client_id' => $this->accessId,
            'access_token' => $token,
            't' => $tempo,
            'nonce' => $nonce,
            'sign' => $assinatura,
            'sign_method' => 'HMAC-SHA256',
        ]))->get(self::REGIOES[$this->regiao].$url);

        $dados = $resposta->json() ?? [];

        if (! ($dados['success'] ?? false)) {
            $codigo = $dados['code'] ?? $resposta->status();
            $mensagem = $dados['msg'] ?? 'erro desconhecido';
            $dica = match ((int) $codigo) {
                1004 => ' (assinatura inválida: confira o Access Secret)',
                1010, 1011 => ' (token inválido)',
                1106 => ' (sem permissão: o app foi vinculado ao projeto? os serviços de API estão autorizados?)',
                2009 => ' (região/data center errado: tente outra região)',
                28841105 => ' (região/data center errado: tente outra região)',
                default => '',
            };

            throw new ExcecaoDeComunicacao("A Tuya respondeu com erro {$codigo}: {$mensagem}{$dica}");
        }

        return (array) ($dados['result'] ?? []);
    }
}
