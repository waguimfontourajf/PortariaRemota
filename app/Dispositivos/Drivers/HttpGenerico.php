<?php

namespace App\Dispositivos\Drivers;

use App\Dispositivos\Campo;
use App\Dispositivos\ExcecaoDeComunicacao;
use App\Dispositivos\Resultado;
use App\Models\Dispositivo;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Qualquer placa que aceite comandos por URL (HTTP ou HTTPS).
 *
 * Nas URLs podem ser usados: {canal} (1, 2...), {canal0} (0, 1...),
 * {ms} (tempo do pulso em milissegundos) e {segundos}.
 * URLs começando com "/" são completadas com o IP/porta do dispositivo.
 */
class HttpGenerico extends DriverBase
{
    public static function chave(): string
    {
        return 'http';
    }

    public static function nome(): string
    {
        return 'HTTP genérico (URL configurável)';
    }

    public static function descricao(): string
    {
        return 'Para placas que abrem/fecham o relé ao acessar um endereço web. Use {canal}, {canal0}, {ms} e {segundos} nas URLs.';
    }

    public static function maximoDeCanais(): int
    {
        return 16;
    }

    public static function campos(): array
    {
        return [
            new Campo('url_pulso', 'URL de pulso (opcional)', placeholder: '/relay/{canal0}?turn=on&timer={segundos}', ajuda: 'Se a placa tem temporizador próprio. Senão, o sistema liga, espera e desliga.'),
            new Campo('url_ligar', 'URL para ligar', obrigatorio: true, placeholder: '/relay/{canal0}?turn=on'),
            new Campo('url_desligar', 'URL para desligar', obrigatorio: true, placeholder: '/relay/{canal0}?turn=off'),
            new Campo('url_estado', 'URL de consulta (opcional)', placeholder: '/status', ajuda: 'Usada no teste de comunicação.'),
            new Campo('metodo', 'Método HTTP', 'selecao', padrao: 'GET', opcoes: ['GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT']),
            new Campo('corpo', 'Corpo da requisição (POST/PUT)', placeholder: '{"canal": {canal}, "acao": "ligar"}'),
            new Campo('tipo_corpo', 'Formato do corpo', 'selecao', padrao: 'json', opcoes: ['json' => 'JSON', 'form' => 'Formulário', 'texto' => 'Texto']),
            new Campo('autenticacao', 'Autenticação', 'selecao', padrao: 'nenhuma', opcoes: ['nenhuma' => 'Nenhuma', 'basic' => 'Basic', 'digest' => 'Digest', 'bearer' => 'Token (Bearer)']),
            new Campo('usuario', 'Usuário'),
            new Campo('senha', 'Senha / token', 'senha'),
            new Campo('texto_sucesso', 'Texto esperado na resposta (opcional)', ajuda: 'Se preenchido, o comando só é considerado bem-sucedido se a resposta contiver este texto.'),
            new Campo('estado_ligado', 'Texto que indica "ligado" na consulta (opcional)', placeholder: '"ison":true'),
            new Campo('timeout', 'Tempo limite (segundos)', 'numero', padrao: 3, regras: ['min:1', 'max:30']),
        ];
    }

    public function pulso(Dispositivo $dispositivo, int $canal, int $ms): Resultado
    {
        if (blank($dispositivo->opcao('url_pulso'))) {
            return parent::pulso($dispositivo, $canal, $ms);
        }

        return $this->executar(fn () => $this->chamar($dispositivo, 'url_pulso', $canal, $ms, 'Pulso enviado.', false));
    }

    public function ligar(Dispositivo $dispositivo, int $canal): Resultado
    {
        return $this->executar(fn () => $this->chamar($dispositivo, 'url_ligar', $canal, 0, 'Ligado.', true));
    }

    public function desligar(Dispositivo $dispositivo, int $canal): Resultado
    {
        return $this->executar(fn () => $this->chamar($dispositivo, 'url_desligar', $canal, 0, 'Desligado.', false));
    }

    public function estado(Dispositivo $dispositivo, int $canal): Resultado
    {
        return $this->executar(function () use ($dispositivo, $canal) {
            if (blank($dispositivo->opcao('url_estado'))) {
                return Resultado::ok('Sem URL de consulta configurada: comunicação não verificada.');
            }

            $resposta = $this->requisicao($dispositivo)->get($this->url($dispositivo, 'url_estado', $canal, 0));
            $this->exigirSucesso($resposta->status(), $resposta->body(), null);

            $ligadoTexto = $dispositivo->opcao('estado_ligado');

            return Resultado::ok('Dispositivo respondeu.', $ligadoTexto ? str_contains($resposta->body(), $ligadoTexto) : null, [
                'status' => $resposta->status(),
            ]);
        });
    }

    private function chamar(Dispositivo $dispositivo, string $campo, int $canal, int $ms, string $mensagem, ?bool $ligado): Resultado
    {
        $url = $this->url($dispositivo, $campo, $canal, $ms);
        $metodo = strtoupper((string) $dispositivo->opcao('metodo', 'GET'));
        $requisicao = $this->requisicao($dispositivo);
        $corpo = $this->substituir((string) $dispositivo->opcao('corpo', ''), $canal, $ms);

        if ($metodo !== 'GET' && $corpo !== '') {
            $requisicao = match ($dispositivo->opcao('tipo_corpo', 'json')) {
                'form' => $requisicao->withBody($corpo, 'application/x-www-form-urlencoded'),
                'texto' => $requisicao->withBody($corpo, 'text/plain'),
                default => $requisicao->withBody($corpo, 'application/json'),
            };
        }

        $resposta = $requisicao->send($metodo, $url);
        $this->exigirSucesso($resposta->status(), $resposta->body(), $dispositivo->opcao('texto_sucesso'));

        return Resultado::ok($mensagem, $ligado, ['status' => $resposta->status()]);
    }

    private function exigirSucesso(int $status, string $corpo, ?string $textoEsperado): void
    {
        if ($status === 401 || $status === 403) {
            throw new ExcecaoDeComunicacao('O dispositivo recusou o acesso (usuário/senha incorretos?).');
        }

        if ($status < 200 || $status >= 300) {
            throw new ExcecaoDeComunicacao("O dispositivo respondeu com erro HTTP {$status}.");
        }

        if ($textoEsperado && ! str_contains($corpo, $textoEsperado)) {
            throw new ExcecaoDeComunicacao('A resposta do dispositivo não contém o texto esperado.');
        }
    }

    private function requisicao(Dispositivo $dispositivo): PendingRequest
    {
        $requisicao = Http::timeout($this->timeout($dispositivo))->connectTimeout(min(3, $this->timeout($dispositivo)));
        $usuario = (string) $dispositivo->opcao('usuario', '');
        $senha = (string) $dispositivo->opcao('senha', '');

        return match ($dispositivo->opcao('autenticacao', 'nenhuma')) {
            'basic' => $requisicao->withBasicAuth($usuario, $senha),
            'digest' => $requisicao->withDigestAuth($usuario, $senha),
            'bearer' => $requisicao->withToken($senha),
            default => $requisicao,
        };
    }

    private function url(Dispositivo $dispositivo, string $campo, int $canal, int $ms): string
    {
        $modelo = (string) $dispositivo->opcao($campo, '');

        if ($modelo === '') {
            throw new ExcecaoDeComunicacao('URL não configurada.');
        }

        $url = $this->substituir($modelo, $canal, $ms);

        return str_starts_with($url, '/') ? $this->urlBase($dispositivo).$url : $url;
    }

    private function substituir(string $texto, int $canal, int $ms): string
    {
        return strtr($texto, [
            '{canal}' => (string) $canal,
            '{canal0}' => (string) ($canal - 1),
            '{ms}' => (string) $ms,
            '{segundos}' => (string) max(1, (int) ceil($ms / 1000)),
        ]);
    }
}
