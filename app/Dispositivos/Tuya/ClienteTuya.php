<?php

namespace App\Dispositivos\Tuya;

use App\Dispositivos\ExcecaoDeComunicacao;
use Closure;

/**
 * Conexão TCP com um aparelho Tuya na rede local (porta 6668).
 *
 * Atenção: o aparelho aceita poucas conexões ao mesmo tempo. Se o app do
 * celular estiver conectado a ele pela rede local, a conexão pode falhar.
 */
class ClienteTuya
{
    /** @var resource|null */
    private $socket = null;

    private int $seq = 1;

    private string $chaveAtual;

    /** @var (Closure(string): void)|null */
    private ?Closure $diagnostico = null;

    public function __construct(
        private readonly string $host,
        private readonly string $idDispositivo,
        private readonly string $chaveLocal,
        private readonly string $versao = '3.3',
        private readonly int $porta = 6668,
        private readonly float $timeout = 3.0,
    ) {
        if (strlen($chaveLocal) !== 16) {
            throw new ExcecaoDeComunicacao('A chave local (local_key) deve ter exatamente 16 caracteres.');
        }

        $this->chaveAtual = $chaveLocal;
    }

    /**
     * Recebe mensagens técnicas (usado pelo comando de diagnóstico).
     *
     * @param  Closure(string): void  $callback
     */
    public function aoDiagnosticar(Closure $callback): static
    {
        $this->diagnostico = $callback;

        return $this;
    }

    public function conectar(): void
    {
        if ($this->socket) {
            return;
        }

        $this->log("Conectando em {$this->host}:{$this->porta} (protocolo {$this->versao})...");

        $erroNumero = 0;
        $erroTexto = '';
        $socket = @stream_socket_client("tcp://{$this->host}:{$this->porta}", $erroNumero, $erroTexto, $this->timeout);

        if (! $socket) {
            throw new ExcecaoDeComunicacao("Não foi possível conectar ao aparelho em {$this->host}:{$this->porta} ({$erroTexto}). Confira o IP e se ele está na mesma rede.");
        }

        stream_set_blocking($socket, true);
        $this->socket = $socket;

        if (in_array($this->versao, ['3.4', '3.5'], true)) {
            $this->negociarSessao();
        }
    }

    public function fechar(): void
    {
        if ($this->socket) {
            @fclose($this->socket);
        }

        $this->socket = null;
        $this->chaveAtual = $this->chaveLocal;
    }

    public function __destruct()
    {
        $this->fechar();
    }

    /**
     * Lê os "data points" (dps) do aparelho. Ex.: ["1" => true, "9" => 0].
     *
     * @return array<string, mixed>
     */
    public function consultar(): array
    {
        $this->conectar();

        if ($this->protocoloNovo()) {
            $comando = Protocolo::CONSULTA_NOVA;
            $json = '{}';
        } else {
            $comando = Protocolo::CONSULTA;
            $json = $this->json(['gwId' => $this->idDispositivo, 'devId' => $this->idDispositivo, 'uid' => $this->idDispositivo, 't' => (string) time()]);
        }

        $this->enviar($comando, $json);
        // Avisos de status (0x08) que chegarem antes são guardados como plano B.
        $resposta = $this->receber([$comando], reserva: [Protocolo::STATUS]);
        $dados = $resposta->json();

        // Alguns aparelhos 3.3 ("device22") recusam a consulta padrão com
        // "data unvalid": nesses, a consulta é feita pelo comando 0x0D.
        if (! $this->protocoloNovo() && str_contains($resposta->payload, 'data unvalid')) {
            $this->log('Aparelho do tipo "device22": repetindo a consulta pelo comando 0x0D.');
            $this->enviar(Protocolo::CONTROLE_NOVO, $this->json([
                'devId' => $this->idDispositivo, 'uid' => $this->idDispositivo, 't' => (string) time(), 'dps' => ['1' => null],
            ]));
            $resposta = $this->receber([Protocolo::CONTROLE_NOVO], reserva: [Protocolo::STATUS]);
            $dados = $resposta->json();
        }

        if ($dados === null) {
            $texto = trim($resposta->payload);
            throw new ExcecaoDeComunicacao('O aparelho não devolveu o estado'.($texto !== '' ? " (respondeu: \"{$texto}\")" : '').'. Confira a versão do protocolo e a chave local.');
        }

        return (array) ($dados['dps'] ?? $dados['data']['dps'] ?? []);
    }

    /**
     * Altera "data points". Ex.: ["1" => true].
     *
     * @param  array<string, mixed>  $dps
     */
    public function definir(array $dps): Mensagem
    {
        $this->conectar();

        if ($this->protocoloNovo()) {
            $comando = Protocolo::CONTROLE_NOVO;
            $json = $this->json(['protocol' => 5, 't' => time(), 'data' => ['dps' => $dps]]);
        } else {
            $comando = Protocolo::CONTROLE;
            $json = $this->json(['devId' => $this->idDispositivo, 'uid' => $this->idDispositivo, 't' => (string) time(), 'dps' => $dps]);
        }

        $this->enviar($comando, $json);
        $resposta = $this->receber([$comando, Protocolo::STATUS]);

        if ($resposta->retorno !== null && $resposta->retorno !== 0) {
            throw new ExcecaoDeComunicacao("O aparelho recusou o comando (código {$resposta->retorno}).");
        }

        return $resposta;
    }

    private function negociarSessao(): void
    {
        $nonceLocal = random_bytes(16);
        $this->log('Negociando chave de sessão...');

        $this->enviar(Protocolo::SESSAO_INICIO, $nonceLocal);
        $resposta = $this->receber([Protocolo::SESSAO_RESPOSTA]);

        if (strlen($resposta->payload) < 48) {
            throw new ExcecaoDeComunicacao('Resposta inesperada na negociação de sessão. Confira a versão do protocolo (3.4/3.5).');
        }

        $nonceRemoto = substr($resposta->payload, 0, 16);
        $verificacao = substr($resposta->payload, 16, 32);

        if (! hash_equals(hash_hmac('sha256', $nonceLocal, $this->chaveLocal, true), $verificacao)) {
            throw new ExcecaoDeComunicacao('A chave local (local_key) está incorreta: o aparelho não confirmou a negociação.');
        }

        $this->enviar(Protocolo::SESSAO_FIM, hash_hmac('sha256', $nonceRemoto, $this->chaveLocal, true));
        $this->chaveAtual = Protocolo::chaveDeSessao($this->versao, $this->chaveLocal, $nonceLocal, $nonceRemoto);
        $this->log('Sessão estabelecida.');
    }

    private function enviar(int $comando, string $payload): void
    {
        $pacote = Protocolo::codificar($this->versao, $this->seq++, $comando, $payload, $this->chaveAtual);
        $this->log(sprintf('→ comando 0x%02X (%d bytes): %s', $comando, strlen($pacote), bin2hex($pacote)));

        if (@fwrite($this->socket, $pacote) !== strlen($pacote)) {
            $this->fechar();
            throw new ExcecaoDeComunicacao('A conexão com o aparelho caiu ao enviar o comando.');
        }
    }

    /**
     * Lê pacotes até chegar um dos comandos esperados (ignora batimentos e afins).
     * Se o tempo acabar e tiver chegado algum comando "reserva", devolve o último.
     *
     * @param  list<int>  $esperados
     * @param  list<int>  $reserva
     */
    private function receber(array $esperados, array $reserva = []): Mensagem
    {
        $limite = microtime(true) + $this->timeout;
        $guardada = null;

        while (microtime(true) < $limite) {
            try {
                $mensagem = $this->receberUma($limite);
            } catch (ExcecaoDeComunicacao $e) {
                if ($guardada && microtime(true) >= $limite) {
                    return $guardada;
                }

                throw $e;
            }

            if (in_array($mensagem->comando, $esperados, true)) {
                return $mensagem;
            }

            if (in_array($mensagem->comando, $reserva, true) && $mensagem->json() !== null) {
                $guardada = $mensagem;
            }
        }

        return $guardada ?? throw new ExcecaoDeComunicacao('O aparelho não respondeu a tempo.');
    }

    private function receberUma(float $limite): Mensagem
    {
        $prefixo = $this->lerBytes(4, $limite);
        $cabecalho = $prefixo.$this->lerBytes(Protocolo::tamanhoDoCabecalho($prefixo) - 4, $limite);
        $total = Protocolo::tamanhoTotal($cabecalho);

        if ($total > 65536) {
            throw new ExcecaoDeComunicacao('Resposta do aparelho com tamanho inválido.');
        }

        $pacote = $cabecalho.$this->lerBytes($total - strlen($cabecalho), $limite);
        $this->log(sprintf('← %d bytes: %s', strlen($pacote), bin2hex($pacote)));

        $mensagem = Protocolo::decodificar($this->versao, $pacote, $this->chaveAtual);
        $this->log(sprintf('← comando 0x%02X, retorno %s: %s', $mensagem->comando, $mensagem->retorno ?? '-', $mensagem->payload === '' ? '(vazio)' : (mb_check_encoding($mensagem->payload, 'UTF-8') ? $mensagem->payload : bin2hex($mensagem->payload))));

        return $mensagem;
    }

    private function lerBytes(int $quantidade, float $limite): string
    {
        $dados = '';

        while (strlen($dados) < $quantidade) {
            $restante = $limite - microtime(true);

            if ($restante <= 0) {
                throw new ExcecaoDeComunicacao($dados === '' && $this->seq <= 2
                    ? 'O aparelho aceitou a conexão mas não respondeu. Confira a versão do protocolo e a chave local.'
                    : 'O aparelho não respondeu a tempo.');
            }

            stream_set_timeout($this->socket, (int) $restante, (int) (fmod($restante, 1) * 1_000_000));
            $pedaco = fread($this->socket, $quantidade - strlen($dados));

            if ($pedaco === false || ($pedaco === '' && feof($this->socket))) {
                $this->fechar();
                throw new ExcecaoDeComunicacao('O aparelho encerrou a conexão. Possíveis causas: chave local ou versão incorretas, ou outro app conectado a ele.');
            }

            $dados .= $pedaco;
        }

        return $dados;
    }

    private function protocoloNovo(): bool
    {
        return in_array($this->versao, ['3.4', '3.5'], true);
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function json(array $dados): string
    {
        return json_encode($dados, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function log(string $mensagem): void
    {
        if ($this->diagnostico) {
            ($this->diagnostico)($mensagem);
        }
    }
}
