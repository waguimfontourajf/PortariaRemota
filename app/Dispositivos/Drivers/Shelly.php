<?php

namespace App\Dispositivos\Drivers;

use App\Dispositivos\Campo;
use App\Dispositivos\ExcecaoDeComunicacao;
use App\Dispositivos\Resultado;
use App\Models\Dispositivo;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Shelly Gen1 (Shelly 1, 2.5, 4Pro...) e Gen2/Gen3/Gen4 (Plus 1, Pro 1, 1 Gen3...).
 *
 * O pulso usa o temporizador do próprio Shelly ("timer"/"toggle_after"):
 * mesmo que a rede caia logo depois do comando, o relé desliga sozinho.
 */
class Shelly extends DriverBase
{
    public static function chave(): string
    {
        return 'shelly';
    }

    public static function nome(): string
    {
        return 'Shelly (Gen1, Plus, Pro, Gen3, Gen4)';
    }

    public static function descricao(): string
    {
        return 'Comunicação local pela API do Shelly. Para portões, use modelos de contato seco (Shelly 1, Plus 1, 1 Gen3).';
    }

    public static function portaPadrao(): ?int
    {
        return 80;
    }

    public static function campos(): array
    {
        return [
            new Campo('geracao', 'Geração', 'selecao', padrao: 'auto', opcoes: [
                'auto' => 'Detectar automaticamente',
                'gen1' => 'Gen1 (Shelly 1, 1PM, 2.5...)',
                'gen2' => 'Gen2 ou mais nova (Plus, Pro, Gen3, Gen4)',
            ]),
            new Campo('usuario', 'Usuário', placeholder: 'admin', ajuda: 'Somente se a autenticação estiver ativada no Shelly. Nos Gen2+ o usuário é sempre “admin”.'),
            new Campo('senha', 'Senha', 'senha'),
            new Campo('timeout', 'Tempo limite (segundos)', 'numero', padrao: 3, regras: ['min:1', 'max:30']),
        ];
    }

    public function pulso(Dispositivo $dispositivo, int $canal, int $ms): Resultado
    {
        return $this->executar(function () use ($dispositivo, $canal, $ms) {
            $id = $canal - 1;
            $segundos = max(0.1, round($ms / 1000, 1));

            if ($this->geracao($dispositivo) === 'gen1') {
                // Gen1 aceita apenas segundos inteiros no timer.
                $this->get($dispositivo, "/relay/{$id}", ['turn' => 'on', 'timer' => max(1, (int) ceil($ms / 1000))]);
            } else {
                $this->get($dispositivo, '/rpc/Switch.Set', ['id' => $id, 'on' => 'true', 'toggle_after' => $segundos]);
            }

            return Resultado::ok('Pulso enviado (o Shelly desliga sozinho).', true);
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
            $id = $canal - 1;

            if ($this->geracao($dispositivo) === 'gen1') {
                $dados = $this->get($dispositivo, "/relay/{$id}")->json();

                return Resultado::ok('Shelly respondeu.', (bool) ($dados['ison'] ?? false), ['geracao' => 'gen1']);
            }

            $dados = $this->get($dispositivo, '/rpc/Switch.GetStatus', ['id' => $id])->json();

            return Resultado::ok('Shelly respondeu.', (bool) ($dados['output'] ?? false), ['geracao' => 'gen2+']);
        });
    }

    public function testar(Dispositivo $dispositivo): Resultado
    {
        return $this->executar(function () use ($dispositivo) {
            Cache::forget($this->chaveGeracao($dispositivo));
            $info = $this->get($dispositivo, '/shelly', autenticar: false)->json() ?? [];
            $modelo = $info['model'] ?? $info['type'] ?? 'desconhecido';
            $estado = $this->estado($dispositivo, 1);

            if (! $estado->sucesso) {
                return $estado;
            }

            return Resultado::ok("Shelly {$modelo} respondeu (firmware ".($info['fw_id'] ?? $info['fw'] ?? '?').').', $estado->ligado, $info);
        });
    }

    private function definir(Dispositivo $dispositivo, int $canal, bool $ligar): Resultado
    {
        $id = $canal - 1;

        if ($this->geracao($dispositivo) === 'gen1') {
            $this->get($dispositivo, "/relay/{$id}", ['turn' => $ligar ? 'on' : 'off']);
        } else {
            $this->get($dispositivo, '/rpc/Switch.Set', ['id' => $id, 'on' => $ligar ? 'true' : 'false']);
        }

        return Resultado::ok($ligar ? 'Ligado.' : 'Desligado.', $ligar);
    }

    private function geracao(Dispositivo $dispositivo): string
    {
        $configurada = $dispositivo->opcao('geracao', 'auto');

        if ($configurada !== 'auto') {
            return $configurada;
        }

        return Cache::remember($this->chaveGeracao($dispositivo), now()->addDay(), function () use ($dispositivo) {
            $info = $this->get($dispositivo, '/shelly', autenticar: false)->json() ?? [];

            return isset($info['gen']) && (int) $info['gen'] >= 2 ? 'gen2' : 'gen1';
        });
    }

    /**
     * @param  array<string, mixed>  $parametros
     */
    private function get(Dispositivo $dispositivo, string $caminho, array $parametros = [], bool $autenticar = true): Response
    {
        $resposta = $this->requisicao($dispositivo, $autenticar)->get($this->urlBase($dispositivo).$caminho, $parametros);

        if ($resposta->status() === 401) {
            throw new ExcecaoDeComunicacao('O Shelly pediu senha: confira usuário e senha no cadastro.');
        }

        if ($resposta->failed()) {
            $erro = $resposta->json('message') ?? $resposta->json('error.message') ?? $resposta->body();
            throw new ExcecaoDeComunicacao('O Shelly respondeu com erro: '.mb_substr((string) $erro, 0, 200));
        }

        return $resposta;
    }

    private function requisicao(Dispositivo $dispositivo, bool $autenticar): PendingRequest
    {
        $requisicao = Http::timeout($this->timeout($dispositivo))->connectTimeout(min(3, $this->timeout($dispositivo)))->acceptJson();
        $senha = (string) $dispositivo->opcao('senha', '');

        if (! $autenticar || $senha === '') {
            return $requisicao;
        }

        $usuario = (string) $dispositivo->opcao('usuario', 'admin');

        // Gen1 usa Basic; Gen2+ usa Digest (sempre com usuário "admin").
        return $this->geracaoSemConsulta($dispositivo) === 'gen1'
            ? $requisicao->withBasicAuth($usuario, $senha)
            : $requisicao->withDigestAuth('admin', $senha);
    }

    private function geracaoSemConsulta(Dispositivo $dispositivo): string
    {
        $configurada = $dispositivo->opcao('geracao', 'auto');

        return $configurada !== 'auto' ? $configurada : (Cache::get($this->chaveGeracao($dispositivo)) ?? 'gen2');
    }

    private function chaveGeracao(Dispositivo $dispositivo): string
    {
        return 'shelly:geracao:'.$dispositivo->host.':'.$dispositivo->porta;
    }
}
