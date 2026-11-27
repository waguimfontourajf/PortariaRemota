<?php

namespace App\Dispositivos\Drivers;

use App\Dispositivos\Campo;
use App\Dispositivos\ExcecaoDeComunicacao;
use App\Dispositivos\PassagemNaLeitora;
use App\Dispositivos\RegrasDeAcesso;
use App\Dispositivos\Resultado;
use App\Models\HorarioAcesso;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use App\Models\Dispositivo;
use Illuminate\Http\Client\Response;

/**
 * Leitoras faciais Hikvision (linha DS-K1T...) pela ISAPI, com Digest.
 *
 * Pessoa = UserInfo (employeeNo = código da pessoa no sistema).
 * Foto   = FDLib (biblioteca de faces), FPID = employeeNo.
 * Horário = plano semanal + modelo (template) nº "número do horário + 1"
 *           (o modelo 1 é o padrão da leitora: o dia todo).
 */
class HikvisionFacial extends LeitoraFacialBase
{
    public static function chave(): string
    {
        return 'hikvision_facial';
    }

    public static function nome(): string
    {
        return 'Leitora facial Hikvision (ISAPI)';
    }

    public static function descricao(): string
    {
        return 'Recebe as pessoas do sistema (nome e foto) e abre a porta remotamente. Usuário e senha da página web da leitora.';
    }

    public static function campos(): array
    {
        return static::camposComuns('admin', [
            new Campo('fdid', 'Biblioteca de faces (FDID)', padrao: '1', ajuda: 'Normalmente 1.', regras: ['max:10']),
        ]);
    }

    public function enviarPessoa(Dispositivo $dispositivo, int $codigo, string $nome, string $fotoJpeg, RegrasDeAcesso $regras): Resultado
    {
        return $this->executar(function () use ($dispositivo, $codigo, $nome, $fotoJpeg, $regras) {
            if ($regras->horario) {
                $this->configurarHorario($dispositivo, $regras->horario);
            }

            $usuario = ['UserInfo' => [
                'employeeNo' => (string) $codigo,
                'name' => self::nomeCurto($nome),
                'userType' => 'normal',
                'Valid' => [
                    'enable' => $regras->de !== null || $regras->ate !== null,
                    'beginTime' => $regras->de?->format('Y-m-d\TH:i:s') ?? '2020-01-01T00:00:00',
                    'endTime' => $regras->ate?->format('Y-m-d\TH:i:s') ?? '2037-12-31T23:59:59',
                    'timeType' => 'local',
                ],
                'doorRight' => '1',
                'RightPlan' => [['doorNo' => 1, 'planTemplateNo' => (string) ($regras->horario ? $regras->horario->numero + 1 : 1)]],
            ]];

            // SetUp = cria ou altera (firmwares recentes). Senão, Record e, se já existir, Modify.
            $resposta = $this->json($dispositivo, 'PUT', '/ISAPI/AccessControl/UserInfo/SetUp?format=json', $usuario);

            if ($this->naoSuportado($resposta)) {
                $resposta = $this->json($dispositivo, 'POST', '/ISAPI/AccessControl/UserInfo/Record?format=json', $usuario);

                if (str_contains($this->erro($resposta), 'employeeNoAlreadyExist')) {
                    $resposta = $this->json($dispositivo, 'PUT', '/ISAPI/AccessControl/UserInfo/Modify?format=json', $usuario);
                }
            }

            $this->exigirSucesso($resposta, 'cadastrar a pessoa');

            $registro = json_encode(['faceLibType' => 'blackFD', 'FDID' => (string) $dispositivo->opcao('fdid', '1'), 'FPID' => (string) $codigo]);
            $resposta = $this->foto($dispositivo, 'PUT', '/ISAPI/Intelligent/FDLib/FDSetUp?format=json', $registro, $fotoJpeg);

            if ($this->naoSuportado($resposta)) {
                $resposta = $this->foto($dispositivo, 'POST', '/ISAPI/Intelligent/FDLib/FaceDataRecord?format=json', $registro, $fotoJpeg);
            }

            $this->exigirSucesso($resposta, 'gravar a foto');

            return Resultado::ok('Pessoa enviada para a leitora.');
        });
    }

    public function removerPessoa(Dispositivo $dispositivo, int $codigo): Resultado
    {
        return $this->executar(function () use ($dispositivo, $codigo) {
            // Apagar o usuário apaga também a face vinculada a ele.
            $resposta = $this->json($dispositivo, 'PUT', '/ISAPI/AccessControl/UserInfo/Delete?format=json', [
                'UserInfoDelCond' => ['EmployeeNoList' => [['employeeNo' => (string) $codigo]]],
            ]);

            if (! $this->sucesso($resposta) && ! str_contains($this->erro($resposta), 'NotExist')) {
                $this->exigirSucesso($resposta, 'remover a pessoa');
            }

            return Resultado::ok('Pessoa removida da leitora.');
        });
    }

    private const SEMANA = [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];

    /**
     * Plano semanal (UserRightWeekPlanCfg) + modelo (UserRightPlanTemplate).
     */
    private function configurarHorario(Dispositivo $dispositivo, HorarioAcesso $horario): void
    {
        if ($this->horarioJaConfigurado($dispositivo, $horario)) {
            return;
        }

        $numero = $horario->numero + 1;
        $dias = [];

        foreach (self::SEMANA as $dia => $nome) {
            $dias[] = [
                'week' => $nome, 'id' => 1, 'enable' => in_array($dia, $horario->dias, true),
                'TimeSegment' => ['beginTime' => $horario->inicio, 'endTime' => $horario->fim],
            ];
        }

        $this->exigirSucesso($this->json($dispositivo, 'PUT', "/ISAPI/AccessControl/UserRightWeekPlanCfg/{$numero}?format=json", [
            'UserRightWeekPlanCfg' => ['enable' => true, 'WeekPlanCfg' => $dias],
        ]), "gravar o horário \"{$horario->nome}\"");

        $this->exigirSucesso($this->json($dispositivo, 'PUT', "/ISAPI/AccessControl/UserRightPlanTemplate/{$numero}?format=json", [
            'UserRightPlanTemplate' => ['enable' => true, 'templateName' => self::nomeCurto($horario->nome), 'weekPlanNo' => $numero, 'holidayGroupNo' => ''],
        ]), "gravar o modelo do horário \"{$horario->nome}\"");

        $this->marcarHorarioConfigurado($dispositivo, $horario);
    }

    /**
     * AcsEvent (major 5): minor 75 = rosto reconhecido; 76 = falha no rosto.
     * O cursor é a data/hora do último evento lido.
     */
    public function buscarPassagens(Dispositivo $dispositivo, ?string $cursor, CarbonInterface $desde): array
    {
        $fuso = $dispositivo->condominio?->fuso_horario ?? 'America/Sao_Paulo';
        $inicio = ($cursor !== null ? Carbon::parse($cursor) : Carbon::instance($desde))->setTimezone($fuso);
        $fim = now($fuso)->addDay();
        $busca = (string) Str::uuid();
        $posicao = 0;
        $passagens = [];
        $ultimo = $cursor;
        $mais = false;

        // Alguns modelos aceitam no máximo 10 resultados por página.
        for ($pagina = 0; $pagina < 30; $pagina++) {
            $resposta = $this->json($dispositivo, 'POST', '/ISAPI/AccessControl/AcsEvent?format=json', ['AcsEventCond' => [
                'searchID' => $busca, 'searchResultPosition' => $posicao, 'maxResults' => 10,
                'major' => 5, 'minor' => 0,
                'startTime' => $inicio->format('Y-m-d\TH:i:sP'), 'endTime' => $fim->format('Y-m-d\TH:i:sP'),
            ]]);
            $this->exigirSucesso($resposta, 'ler os eventos');
            $dados = $resposta->json('AcsEvent') ?? [];

            foreach ($dados['InfoList'] ?? [] as $info) {
                $momento = Carbon::parse($info['time'] ?? 'now');
                $ultimo = $ultimo === null || $momento->gt(Carbon::parse($ultimo)) ? $momento->toIso8601String() : $ultimo;
                $minor = (int) ($info['minor'] ?? 0);

                if (! in_array($minor, [75, 76], true)) {
                    continue;
                }

                $codigo = (int) ($info['employeeNoString'] ?? $info['employeeNo'] ?? 0);
                $passagens[] = new PassagemNaLeitora(
                    referencia: 'hikvision-'.($info['serialNo'] ?? '').'-'.$momento->getTimestamp(),
                    momento: $momento,
                    codigoPessoa: $codigo > 0 ? $codigo : null,
                    liberada: $minor === 75,
                    descricao: $minor === 75 ? 'Liberado por reconhecimento facial.' : 'Rosto não reconhecido ou acesso negado pela leitora.',
                );
            }

            $quantidade = (int) ($dados['numOfMatches'] ?? 0);
            $mais = ($dados['responseStatusStrg'] ?? '') === 'MORE';

            if (! $mais || $quantidade === 0) {
                $mais = false;
                break;
            }

            $posicao += $quantidade;
        }

        return ['passagens' => $passagens, 'cursor' => $ultimo, 'mais' => $mais];
    }

    protected function abrirPorta(Dispositivo $dispositivo, int $porta): void
    {
        $caminho = "/ISAPI/AccessControl/RemoteControl/door/{$porta}";
        $resposta = $this->registrar('PUT', $caminho, $this->http($dispositivo)
            ->withBody('<RemoteControlDoor><cmd>open</cmd></RemoteControlDoor>', 'application/xml')
            ->put($this->base($dispositivo).$caminho));

        $this->exigirSucesso($resposta, 'abrir a porta');
    }

    protected function informacoes(Dispositivo $dispositivo): string
    {
        $resposta = $this->registrar('GET', '/ISAPI/System/deviceInfo', $this->http($dispositivo)->get($this->base($dispositivo).'/ISAPI/System/deviceInfo'));

        if ($resposta->failed()) {
            throw new ExcecaoDeComunicacao('A leitora não respondeu como Hikvision (HTTP '.$resposta->status().'). Confira IP, porta e o driver.');
        }

        $campo = fn (string $nome) => preg_match("#<{$nome}>(.*?)</{$nome}>#s", $resposta->body(), $m) ? trim($m[1]) : '?';

        return 'Hikvision '.$campo('model').', firmware '.$campo('firmwareVersion').'.';
    }

    /**
     * @param  array<string, mixed>  $corpo
     */
    private function json(Dispositivo $dispositivo, string $metodo, string $caminho, array $corpo): Response
    {
        $requisicao = $this->http($dispositivo)->withBody(json_encode($corpo, JSON_UNESCAPED_UNICODE), 'application/json');

        return $this->registrar($metodo, $caminho, $requisicao->send($metodo, $this->base($dispositivo).$caminho));
    }

    private function foto(Dispositivo $dispositivo, string $metodo, string $caminho, string $registro, string $jpeg): Response
    {
        $requisicao = $this->http($dispositivo)
            ->attach('FaceDataRecord', $registro, null, ['Content-Type' => 'application/json'])
            ->attach('img', $jpeg, 'face.jpg', ['Content-Type' => 'image/jpeg']);

        // put()/post() com [] (e não send()): só assim o Laravel junta os anexos ao corpo multipart.
        $url = $this->base($dispositivo).$caminho;

        return $this->registrar($metodo, $caminho, $metodo === 'PUT' ? $requisicao->put($url, []) : $requisicao->post($url, []));
    }

    private function sucesso(Response $resposta): bool
    {
        if (! $resposta->successful()) {
            return false;
        }

        $codigo = $resposta->json('statusCode');

        if ($codigo === null && preg_match('#<statusCode>(\d+)</statusCode>#', $resposta->body(), $m)) {
            $codigo = $m[1];
        }

        return $codigo === null || (int) $codigo === 1;
    }

    private function naoSuportado(Response $resposta): bool
    {
        return in_array($resposta->status(), [404, 405], true)
            || str_contains($this->erro($resposta), 'notSupport')
            || str_contains($this->erro($resposta), 'invalidOperation');
    }

    private function erro(Response $resposta): string
    {
        $json = $resposta->json() ?? [];
        $partes = array_filter([$json['subStatusCode'] ?? null, $json['errorMsg'] ?? null, $json['statusString'] ?? null]);

        if ($partes === [] && preg_match('#<subStatusCode>(.*?)</subStatusCode>#', $resposta->body(), $m)) {
            $partes[] = $m[1];
        }

        return $partes === [] ? mb_substr($resposta->body(), 0, 200) : implode(' / ', $partes);
    }

    private function exigirSucesso(Response $resposta, string $acao): void
    {
        if (! $this->sucesso($resposta)) {
            $detalhe = $this->erro($resposta);
            $dica = str_contains($detalhe, 'face') || str_contains($detalhe, 'picture') || str_contains($detalhe, 'FD')
                ? ' (a foto precisa mostrar o rosto de frente, bem iluminado)' : '';

            throw new ExcecaoDeComunicacao("A leitora não conseguiu {$acao}: {$detalhe}{$dica}");
        }
    }
}
