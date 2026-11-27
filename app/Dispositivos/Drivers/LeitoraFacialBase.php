<?php

namespace App\Dispositivos\Drivers;

use App\Dispositivos\Campo;
use App\Dispositivos\Contratos\LeitoraFacial;
use App\Dispositivos\ExcecaoDeComunicacao;
use App\Dispositivos\RegrasDeAcesso;
use App\Dispositivos\Resultado;
use App\Models\HorarioAcesso;
use App\Models\Dispositivo;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Base das leitoras faciais pela rede (HTTP).
 *
 * Como relé: "Ligar" e "Pulso" abrem a porta pela própria leitora (o tempo
 * de abertura é o configurado nela); "Desligar" não faz nada, porque a
 * leitora fecha sozinha.
 */
abstract class LeitoraFacialBase extends DriverBase implements LeitoraFacial
{
    /** @var list<string> */
    protected array $registro = [];

    public static function usaHost(): bool
    {
        return true;
    }

    public static function portaPadrao(): ?int
    {
        return 80;
    }

    public static function maximoDeCanais(): int
    {
        return 4;
    }

    /**
     * Campos comuns (usuário, senha, HTTPS, tempo limite) + os da marca.
     *
     * @param  list<Campo>  $especificos
     * @return list<Campo>
     */
    protected static function camposComuns(string $usuarioPadrao, array $especificos = []): array
    {
        return [
            new Campo('usuario', 'Usuário', obrigatorio: true, padrao: $usuarioPadrao, placeholder: $usuarioPadrao),
            new Campo('senha', 'Senha', 'senha', obrigatorio: true, ajuda: 'A mesma usada para entrar na página da leitora pelo navegador.'),
            ...$especificos,
            new Campo('publico', 'Quem vai para esta leitora', 'selecao', obrigatorio: true, padrao: 'todos', opcoes: [
                'todos' => 'Todas as pessoas liberadas',
                'moradores' => 'Só moradores',
                'funcionarios' => 'Só funcionários e prestadores',
            ], ajuda: 'Liberadas = ativas, com foto e com autorização de biometria.'),
            new Campo('blocos', 'Só destes blocos (opcional)', placeholder: 'Ex.: Bloco A, Bloco B', ajuda: 'Nomes dos blocos separados por vírgula, como no cadastro. Funcionários do condomínio entram sempre; moradores e prestadores, só se tiverem unidade nesses blocos.', regras: ['max:255']),
            new Campo('https', 'Usar HTTPS', 'booleano', padrao: false, ajuda: 'Só se a leitora estiver configurada para HTTPS.'),
            new Campo('timeout', 'Tempo limite (segundos)', 'numero', padrao: 8, regras: ['min:1', 'max:60']),
        ];
    }

    /**
     * Abre a porta (canal = número da porta na leitora).
     */
    abstract protected function abrirPorta(Dispositivo $dispositivo, int $porta): void;

    /**
     * Descrição curta do equipamento (modelo, firmware), para o teste.
     */
    abstract protected function informacoes(Dispositivo $dispositivo): string;

    public function ligar(Dispositivo $dispositivo, int $canal): Resultado
    {
        return $this->executar(function () use ($dispositivo, $canal) {
            $this->abrirPorta($dispositivo, $this->numeroDaPorta($dispositivo, $canal));

            return Resultado::ok('Porta aberta pela leitora (ela fecha sozinha).', null);
        });
    }

    public function desligar(Dispositivo $dispositivo, int $canal): Resultado
    {
        return Resultado::ok('A leitora fecha a porta sozinha; nada a fazer.', false);
    }

    public function pulso(Dispositivo $dispositivo, int $canal, int $ms): Resultado
    {
        return $this->executar(function () use ($dispositivo, $canal) {
            $this->abrirPorta($dispositivo, $this->numeroDaPorta($dispositivo, $canal));

            return Resultado::ok('Porta aberta pela leitora (o tempo de abertura é o configurado nela).', false);
        });
    }

    public function estado(Dispositivo $dispositivo, int $canal): Resultado
    {
        return $this->executar(fn () => Resultado::ok($this->informacoes($dispositivo), null));
    }

    public function testar(Dispositivo $dispositivo): Resultado
    {
        return $this->executar(fn () => Resultado::ok('Leitora respondeu: '.$this->informacoes($dispositivo), null));
    }

    protected function executar(Closure $acao): Resultado
    {
        $this->registro = [];
        $resultado = parent::executar($acao);

        if ($this->registro === []) {
            return $resultado;
        }

        return new Resultado(
            $resultado->sucesso, $resultado->mensagem, $resultado->ligado,
            [...$resultado->dados, 'registro' => $this->registro], $resultado->duracaoMs,
        );
    }

    /**
     * Requisição com tempo limite e (opcional) autenticação Digest.
     */
    protected function http(Dispositivo $dispositivo, bool $digest = true): PendingRequest
    {
        $timeout = $this->timeout($dispositivo);
        $requisicao = Http::timeout($timeout)->connectTimeout(min(5, $timeout))->withoutVerifying();

        return $digest
            ? $requisicao->withDigestAuth((string) $dispositivo->opcao('usuario', 'admin'), (string) $dispositivo->opcao('senha', ''))
            : $requisicao;
    }

    protected function base(Dispositivo $dispositivo): string
    {
        return $this->urlBase($dispositivo, $dispositivo->opcao('https') ? 'https' : 'http');
    }

    protected function timeout(Dispositivo $dispositivo): float
    {
        return (float) $dispositivo->opcao('timeout', 8);
    }

    /**
     * Anota a requisição e a resposta nos "Detalhes técnicos" e trata 401.
     */
    protected function registrar(string $metodo, string $caminho, Response $resposta): Response
    {
        // Nunca registra a sessão (Control iD) nem dados grandes (fotos).
        $caminho = preg_replace('/session=[^&]+/', 'session=***', $caminho);
        $corpo = trim(preg_replace('/\s+/', ' ', mb_substr($resposta->body(), 0, 300)));
        $this->registro[] = '['.date('H:i:s')."] {$metodo} {$caminho} → HTTP {$resposta->status()} {$corpo}";

        if ($resposta->status() === 401) {
            throw new ExcecaoDeComunicacao('A leitora recusou o usuário/senha (HTTP 401). Confira no cadastro.');
        }

        return $resposta;
    }

    /**
     * Horários já gravados nesta leitora (para não regravar a cada pessoa).
     */
    protected function horarioJaConfigurado(Dispositivo $dispositivo, HorarioAcesso $horario): bool
    {
        return (Cache::get($this->chaveDeHorarios($dispositivo), [])[$horario->numero] ?? null) === RegrasDeAcesso::assinaturaDoHorario($horario);
    }

    protected function marcarHorarioConfigurado(Dispositivo $dispositivo, HorarioAcesso $horario): void
    {
        $chave = $this->chaveDeHorarios($dispositivo);
        Cache::forever($chave, [...Cache::get($chave, []), $horario->numero => RegrasDeAcesso::assinaturaDoHorario($horario)]);
    }

    public function esquecerConfiguracao(Dispositivo $dispositivo): void
    {
        Cache::forget($this->chaveDeHorarios($dispositivo));
    }

    private function chaveDeHorarios(Dispositivo $dispositivo): string
    {
        return 'leitora:'.$dispositivo->id.':horarios';
    }

    protected function numeroDaPorta(Dispositivo $dispositivo, int $canal): int
    {
        return max(1, $canal);
    }

    /**
     * Nome como a leitora aceita (sem quebras de linha, tamanho limitado).
     */
    protected static function nomeCurto(string $nome, int $limite = 32): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', $nome)), 0, $limite);
    }
}
