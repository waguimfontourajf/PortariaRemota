<?php

namespace App\Dispositivos\Drivers;

use App\Dispositivos\Campo;
use App\Dispositivos\ExcecaoDeComunicacao;
use App\Dispositivos\Resultado;
use App\Models\Dispositivo;

/**
 * Leitoras faciais Control iD (iDFace, iDFace Max) pela API de acesso.
 * Documentação: https://www.controlid.com.br/docs/access-api-pt/
 *
 * Fluxo: login.fcgi (sessão) → users (criar/alterar) → user_groups (grupo de
 * acesso) → user_set_image.fcgi (foto) → logout.fcgi.
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
            new Campo('grupo', 'Grupo de acesso (ID)', 'numero', padrao: 1, ajuda: 'Grupo da leitora com permissão de passagem. Normalmente 1 (Padrão).', regras: ['integer', 'min:1']),
        ]);
    }

    public function enviarPessoa(Dispositivo $dispositivo, int $codigo, string $nome, string $fotoJpeg): Resultado
    {
        return $this->executar(fn () => $this->comSessao($dispositivo, function (string $sessao) use ($dispositivo, $codigo, $nome, $fotoJpeg) {
            $nome = self::nomeCurto($nome, 100);
            $existe = ($this->api($dispositivo, $sessao, 'load_objects.fcgi', [
                'object' => 'users', 'where' => ['users' => ['id' => $codigo]],
            ])['users'] ?? []) !== [];

            if ($existe) {
                $this->api($dispositivo, $sessao, 'modify_objects.fcgi', [
                    'object' => 'users', 'values' => ['name' => $nome], 'where' => ['users' => ['id' => $codigo]],
                ]);
            } else {
                $this->api($dispositivo, $sessao, 'create_objects.fcgi', [
                    'object' => 'users', 'values' => [['id' => $codigo, 'name' => $nome, 'registration' => (string) $codigo]],
                ]);
            }

            // Sem grupo de acesso, a leitora reconhece o rosto mas nega a passagem.
            $grupo = (int) $dispositivo->opcao('grupo', 1);
            $temGrupo = ($this->api($dispositivo, $sessao, 'load_objects.fcgi', [
                'object' => 'user_groups', 'where' => ['user_groups' => ['user_id' => $codigo, 'group_id' => $grupo]],
            ])['user_groups'] ?? []) !== [];

            if (! $temGrupo) {
                $this->api($dispositivo, $sessao, 'create_objects.fcgi', [
                    'object' => 'user_groups', 'values' => [['user_id' => $codigo, 'group_id' => $grupo]],
                ]);
            }

            $caminho = 'user_set_image.fcgi?'.http_build_query(['user_id' => $codigo, 'timestamp' => time(), 'match' => 0, 'session' => $sessao]);
            $resposta = $this->registrar('POST', $caminho, $this->http($dispositivo, false)
                ->withBody($fotoJpeg, 'application/octet-stream')
                ->post($this->base($dispositivo).'/'.$caminho));
            $dados = $resposta->json() ?? [];

            if ($resposta->failed() || ($dados['success'] ?? true) === false) {
                throw new ExcecaoDeComunicacao('A leitora recusou a foto: '.$this->erroDaFoto($dados, $resposta->body()));
            }

            return Resultado::ok('Pessoa enviada para a leitora.');
        }));
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
