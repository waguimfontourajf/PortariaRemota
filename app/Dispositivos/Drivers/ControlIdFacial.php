<?php

namespace App\Dispositivos\Drivers;

use App\Dispositivos\Campo;
use App\Dispositivos\ExcecaoDeComunicacao;
use App\Dispositivos\PassagemNaLeitora;
use App\Dispositivos\RegrasDeAcesso;
use App\Dispositivos\Resultado;
use App\Models\Dispositivo;
use App\Models\HorarioAcesso;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Leitoras faciais Control iD (iDFace, iDFace Max) pela API de acesso.
 * Documentação: https://www.controlid.com.br/docs/access-api-pt/
 *
 * Fluxo: login.fcgi (sessão) → users (criar/alterar, com validade em
 * begin_time/end_time) → user_groups (grupo de acesso) → user_set_image.fcgi
 * (foto) → logout.fcgi.
 *
 * Horário: para o horário nº N, a leitora recebe time_zone, access_rule e
 * group de id 1000+N (com time_spans, vínculo ao portal e ao grupo); a pessoa
 * entra nesse grupo em vez do grupo padrão.
 */
class ControlIdFacial extends LeitoraFacialBase
{
    public static function chave(): string
    {
        return 'controlid_facial';
    }

    public static function nome(): string
    {
        return 'Leitora facial Control iD (iDFace)';
    }

    public static function descricao(): string
    {
        return 'Recebe as pessoas do sistema (nome e foto) e abre a porta remotamente. Usuário e senha da página web da leitora (padrão: admin / admin).';
    }

    public static function campos(): array
    {
        return static::camposComuns('admin', [
            new Campo('acionamento', 'Como a porta é aberta', 'selecao', obrigatorio: true, padrao: 'door', opcoes: [
                'door' => 'Relé da própria leitora',
                'sec_box' => 'Módulo de acionamento externo (SecBox)',
            ]),
            new Campo('grupo', 'Grupo de acesso (ID)', 'numero', padrao: 1, ajuda: 'Grupo da leitora com permissão de passagem, para quem não tem horário. Normalmente 1 (Padrão).', regras: ['integer', 'min:1']),
            new Campo('portal', 'Portal (ID)', 'numero', padrao: 1, ajuda: 'Usado nos horários de acesso. Normalmente 1.', regras: ['integer', 'min:1']),
        ]);
    }

    public function enviarPessoa(Dispositivo $dispositivo, int $codigo, string $nome, string $fotoJpeg, RegrasDeAcesso $regras): Resultado
    {
        return $this->executar(fn () => $this->comSessao($dispositivo, function (string $sessao) use ($dispositivo, $codigo, $nome, $fotoJpeg, $regras) {
            // begin_time/end_time = 0 desliga a validade. Atenção: ao vencer o
            // end_time, a própria leitora apaga as credenciais da pessoa.
            $dados = [
                'name' => self::nomeCurto($nome, 100),
                'begin_time' => $regras->de?->timestamp ?? 0,
                'end_time' => $regras->ate?->timestamp ?? 0,
            ];
            $existe = ($this->api($dispositivo, $sessao, 'load_objects.fcgi', [
                'object' => 'users', 'where' => ['users' => ['id' => $codigo]],
            ])['users'] ?? []) !== [];

            if ($existe) {
                $this->api($dispositivo, $sessao, 'modify_objects.fcgi', [
                    'object' => 'users', 'values' => $dados, 'where' => ['users' => ['id' => $codigo]],
                ]);
            } else {
                $this->api($dispositivo, $sessao, 'create_objects.fcgi', [
                    'object' => 'users', 'values' => [['id' => $codigo, 'registration' => (string) $codigo, ...$dados]],
                ]);
            }

            // Sem grupo de acesso, a leitora reconhece o rosto mas nega a passagem.
            $grupo = $regras->horario
                ? $this->configurarHorario($dispositivo, $sessao, $regras->horario)
                : (int) $dispositivo->opcao('grupo', 1);

            $this->api($dispositivo, $sessao, 'destroy_objects.fcgi', [
                'object' => 'user_groups', 'where' => ['user_groups' => ['user_id' => $codigo]],
            ]);
            $this->api($dispositivo, $sessao, 'create_objects.fcgi', [
                'object' => 'user_groups', 'values' => [['user_id' => $codigo, 'group_id' => $grupo]],
            ]);

            $caminho = 'user_set_image.fcgi?'.http_build_query(['user_id' => $codigo, 'timestamp' => time(), 'match' => 0, 'session' => $sessao]);
            $resposta = $this->registrar('POST', $caminho, $this->http($dispositivo, false)
                ->withBody($fotoJpeg, 'application/octet-stream')
                ->post($this->base($dispositivo).'/'.$caminho));
            $retorno = $resposta->json() ?? [];

            if ($resposta->failed() || ($retorno['success'] ?? true) === false) {
                throw new ExcecaoDeComunicacao('A leitora recusou a foto: '.$this->erroDaFoto($retorno, $resposta->body()));
            }

            return Resultado::ok('Pessoa enviada para a leitora.');
        }));
    }

    /**
     * Cria/atualiza na leitora o horário (time_zone + time_span + access_rule
     * + group, todos com id 1000+N) e devolve o id do grupo.
     */
    private function configurarHorario(Dispositivo $dispositivo, string $sessao, HorarioAcesso $horario): int
    {
        $id = 1000 + $horario->numero;

        if ($this->horarioJaConfigurado($dispositivo, $horario)) {
            return $id;
        }

        $nome = self::nomeCurto('Portaria: '.$horario->nome, 60);
        $this->garantir($dispositivo, $sessao, 'time_zones', $id, ['name' => $nome]);
        $this->api($dispositivo, $sessao, 'destroy_objects.fcgi', [
            'object' => 'time_spans', 'where' => ['time_spans' => ['time_zone_id' => $id]],
        ]);

        $dias = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
        $faixa = ['time_zone_id' => $id, 'start' => HorarioAcesso::segundos($horario->inicio), 'end' => HorarioAcesso::segundos($horario->fim), 'hol1' => 0, 'hol2' => 0, 'hol3' => 0];
        foreach ($dias as $numero => $dia) {
            $faixa[$dia] = in_array($numero, $horario->dias, true) ? 1 : 0;
        }
        $this->api($dispositivo, $sessao, 'create_objects.fcgi', ['object' => 'time_spans', 'values' => [$faixa]]);

        $this->garantir($dispositivo, $sessao, 'access_rules', $id, ['name' => $nome, 'type' => 1, 'priority' => 0]);
        $this->garantir($dispositivo, $sessao, 'groups', $id, ['name' => $nome]);
        $this->vincular($dispositivo, $sessao, 'access_rule_time_zones', ['access_rule_id' => $id, 'time_zone_id' => $id]);
        $this->vincular($dispositivo, $sessao, 'portal_access_rules', ['portal_id' => (int) $dispositivo->opcao('portal', 1), 'access_rule_id' => $id]);
        $this->vincular($dispositivo, $sessao, 'group_access_rules', ['group_id' => $id, 'access_rule_id' => $id]);

        $this->marcarHorarioConfigurado($dispositivo, $horario);

        return $id;
    }

    /**
     * Cria o objeto com este id, ou altera se já existir.
     *
     * @param  array<string, mixed>  $valores
     */
    private function garantir(Dispositivo $dispositivo, string $sessao, string $objeto, int $id, array $valores): void
    {
        $existe = ($this->api($dispositivo, $sessao, 'load_objects.fcgi', ['object' => $objeto, 'where' => [$objeto => ['id' => $id]]])[$objeto] ?? []) !== [];

        $this->api($dispositivo, $sessao, $existe ? 'modify_objects.fcgi' : 'create_objects.fcgi', $existe
            ? ['object' => $objeto, 'values' => $valores, 'where' => [$objeto => ['id' => $id]]]
            : ['object' => $objeto, 'values' => [['id' => $id, ...$valores]]]);
    }

    /**
     * Cria o vínculo (tabela de ligação) se ainda não existir.
     *
     * @param  array<string, int>  $campos
     */
    private function vincular(Dispositivo $dispositivo, string $sessao, string $objeto, array $campos): void
    {
        $existe = ($this->api($dispositivo, $sessao, 'load_objects.fcgi', ['object' => $objeto, 'where' => [$objeto => $campos]])[$objeto] ?? []) !== [];

        if (! $existe) {
            $this->api($dispositivo, $sessao, 'create_objects.fcgi', ['object' => $objeto, 'values' => [$campos]]);
        }
    }

    public function removerPessoa(Dispositivo $dispositivo, int $codigo): Resultado
    {
        return $this->executar(fn () => $this->comSessao($dispositivo, function (string $sessao) use ($dispositivo, $codigo) {
            // Apagar o usuário apaga também a foto e os vínculos de grupo.
            $this->api($dispositivo, $sessao, 'destroy_objects.fcgi', [
                'object' => 'users', 'where' => ['users' => ['id' => $codigo]],
            ]);

            return Resultado::ok('Pessoa removida da leitora.');
        }));
    }

    /**
     * access_logs (eventos 7 = liberado, 6 = negado, 3 e 10 = não identificado).
     */
    public function buscarPassagens(Dispositivo $dispositivo, ?string $cursor, CarbonInterface $desde): array
    {
        return $this->comSessao($dispositivo, function (string $sessao) use ($dispositivo, $cursor, $desde) {
            $limite = 200;
            $onde = $cursor !== null
                ? ['access_logs' => ['id' => ['>' => (int) $cursor]]]
                : ['access_logs' => ['time' => ['>' => $desde->getTimestamp()]]];

            $registros = $this->api($dispositivo, $sessao, 'load_objects.fcgi', [
                'object' => 'access_logs', 'where' => $onde, 'order' => ['id'], 'limit' => $limite,
            ])['access_logs'] ?? [];

            $passagens = [];
            $ultimo = $cursor;

            foreach ($registros as $registro) {
                $ultimo = (string) max((int) $ultimo, (int) ($registro['id'] ?? 0));
                $evento = (int) ($registro['event'] ?? 0);

                if (! in_array($evento, [3, 6, 7, 10], true)) {
                    continue; // botoeira, abertura remota, pendências...
                }

                $codigo = (int) ($registro['user_id'] ?? 0);
                $passagens[] = new PassagemNaLeitora(
                    referencia: 'controlid-'.$registro['id'],
                    momento: Carbon::createFromTimestamp((int) ($registro['time'] ?? 0)),
                    codigoPessoa: $codigo > 0 ? $codigo : null,
                    liberada: $evento === 7,
                    descricao: match ($evento) {
                        7 => 'Liberado por reconhecimento facial.',
                        6 => 'Acesso negado pela leitora (fora do horário, validade ou regra).',
                        default => 'Rosto não reconhecido.',
                    },
                );
            }

            return ['passagens' => $passagens, 'cursor' => $ultimo, 'mais' => count($registros) >= $limite];
        });
    }

    protected function abrirPorta(Dispositivo $dispositivo, int $porta): void
    {
        $this->comSessao($dispositivo, function (string $sessao) use ($dispositivo, $porta) {
            $acao = $dispositivo->opcao('acionamento', 'door') === 'sec_box'
                ? ['action' => 'sec_box', 'parameters' => 'id=65793,reason=3']
                : ['action' => 'door', 'parameters' => "door={$porta}"];

            $this->api($dispositivo, $sessao, 'execute_actions.fcgi', ['actions' => [$acao]]);
        });
    }

    protected function informacoes(Dispositivo $dispositivo): string
    {
        return $this->comSessao($dispositivo, function (string $sessao) use ($dispositivo) {
            $info = $this->api($dispositivo, $sessao, 'system_information.fcgi', []);

            return 'Control iD, nº de série '.($info['serial'] ?? '?').', firmware '.($info['version'] ?? '?').'.';
        });
    }

    /**
     * Faz login, executa e encerra a sessão (a leitora tem limite de sessões).
     *
     * @template T
     *
     * @param  callable(string): T  $acao
     * @return T
     */
    private function comSessao(Dispositivo $dispositivo, callable $acao): mixed
    {
        $resposta = $this->registrar('POST', 'login.fcgi', $this->http($dispositivo, false)->post($this->base($dispositivo).'/login.fcgi', [
            'login' => (string) $dispositivo->opcao('usuario', 'admin'),
            'password' => (string) $dispositivo->opcao('senha', ''),
        ]));
        $sessao = $resposta->json('session');

        if (! is_string($sessao) || $sessao === '') {
            throw new ExcecaoDeComunicacao('A leitora recusou o login: confira usuário e senha. '.mb_substr((string) $resposta->json('error', ''), 0, 150));
        }

        try {
            return $acao($sessao);
        } finally {
            try {
                $this->http($dispositivo, false)->post($this->base($dispositivo).'/logout.fcgi?session='.urlencode($sessao));
            } catch (\Throwable) {
                // encerrar a sessão é só uma gentileza
            }
        }
    }

    /**
     * @param  array<string, mixed>  $corpo
     * @return array<string, mixed>
     */
    private function api(Dispositivo $dispositivo, string $sessao, string $comando, array $corpo): array
    {
        $caminho = $comando.'?session='.urlencode($sessao);
        $resposta = $this->registrar('POST', $caminho, $this->http($dispositivo, false)
            ->withBody(json_encode($corpo === [] ? new \stdClass : $corpo, JSON_UNESCAPED_UNICODE), 'application/json')
            ->post($this->base($dispositivo).'/'.$caminho));

        if ($resposta->failed()) {
            throw new ExcecaoDeComunicacao("A leitora recusou o comando {$comando}: ".mb_substr((string) ($resposta->json('error') ?? $resposta->body()), 0, 200));
        }

        return $resposta->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function erroDaFoto(array $dados, string $corpo): string
    {
        $codigo = (int) ($dados['errors'][0]['code'] ?? 0);

        return match ($codigo) {
            2 => 'nenhum rosto encontrado na foto.',
            3 => 'este rosto já está cadastrado para outra pessoa na leitora.',
            4 => 'rosto fora do centro da foto.',
            5 => 'rosto muito distante (aproxime a câmera).',
            6 => 'rosto muito próximo (afaste a câmera).',
            7 => 'rosto virado: a pessoa deve olhar de frente.',
            8 => 'foto sem nitidez (tremida ou desfocada).',
            9 => 'rosto muito perto da borda da foto.',
            default => mb_substr((string) ($dados['errors'][0]['message'] ?? $dados['error'] ?? $corpo), 0, 200),
        };
    }
}
