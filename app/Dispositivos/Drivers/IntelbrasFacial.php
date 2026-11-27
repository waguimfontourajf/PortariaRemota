<?php

namespace App\Dispositivos\Drivers;

use App\Dispositivos\ExcecaoDeComunicacao;
use App\Dispositivos\RegrasDeAcesso;
use App\Dispositivos\Resultado;
use App\Models\HorarioAcesso;
use App\Models\Dispositivo;
use Illuminate\Http\Client\Response;

/**
 * Leitoras faciais Intelbras (linha Bio-T: SS 3530, SS 3532, SS 3540,
 * SS 5530...) pela API HTTP (CGI) com Digest.
 *
 * Pessoa = AccessUser (UserID = código da pessoa no sistema).
 * Foto   = AccessFace (PhotoData em base64, até 200 KB).
 * Horário = AccessTimeSchedule[número do horário] (configManager); a pessoa
 *           aponta para ele em TimeSections (255 = qualquer hora).
 */
class IntelbrasFacial extends LeitoraFacialBase
{
    public static function chave(): string
    {
        return 'intelbras_facial';
    }

    public static function nome(): string
    {
        return 'Leitora facial Intelbras (SS 3530, SS 5530...)';
    }

    public static function descricao(): string
    {
        return 'Recebe as pessoas do sistema (nome e foto) e abre a porta remotamente. Usuário e senha da página web da leitora.';
    }

    public static function campos(): array
    {
        return static::camposComuns('admin');
    }

    public function enviarPessoa(Dispositivo $dispositivo, int $codigo, string $nome, string $fotoJpeg, RegrasDeAcesso $regras): Resultado
    {
        return $this->executar(function () use ($dispositivo, $codigo, $nome, $fotoJpeg, $regras) {
            if ($regras->horario) {
                $this->configurarHorario($dispositivo, $regras->horario);
            }

            // insertMulti cria ou atualiza a pessoa. Authority 2 = usuário comum;
            // Doors [0] = porta 1; TimeSections [255] = sem restrição de horário.
            $this->exigirOk($this->post($dispositivo, '/cgi-bin/AccessUser.cgi?action=insertMulti', ['UserList' => [[
                'UserID' => (string) $codigo,
                'UserName' => self::nomeCurto($nome),
                'UserType' => 0,
                'Authority' => 2,
                'Doors' => [0],
                'TimeSections' => [$regras->horario ? $regras->horario->numero : 255],
                'ValidFrom' => $regras->de?->format('Y-m-d H:i:s') ?? '2020-01-01 00:00:00',
                'ValidTo' => $regras->ate?->format('Y-m-d H:i:s') ?? '2037-12-31 23:59:59',
            ]]]), 'cadastrar a pessoa');

            $face = ['FaceList' => [['UserID' => (string) $codigo, 'PhotoData' => [base64_encode($fotoJpeg)]]]];
            $resposta = $this->post($dispositivo, '/cgi-bin/AccessFace.cgi?action=insertMulti', $face);

            if (! $this->ok($resposta)) {
                // A pessoa já tinha foto: substitui.
                $atualizacao = $this->post($dispositivo, '/cgi-bin/AccessFace.cgi?action=updateMulti', $face);
                $resposta = $this->ok($atualizacao) ? $atualizacao : $resposta;
            }

            $this->exigirOk($resposta, 'gravar a foto', ' A foto precisa mostrar o rosto de frente, bem iluminado.');

            return Resultado::ok('Pessoa enviada para a leitora.');
        });
    }

    public function removerPessoa(Dispositivo $dispositivo, int $codigo): Resultado
    {
        return $this->executar(function () use ($dispositivo, $codigo) {
            $parametro = 'UserIDList[0]='.rawurlencode((string) $codigo);

            // Se a pessoa não existir na leitora, ela responde erro: tudo bem.
            $this->get($dispositivo, '/cgi-bin/AccessFace.cgi?action=removeMulti&'.$parametro);
            $this->get($dispositivo, '/cgi-bin/AccessUser.cgi?action=removeMulti&'.$parametro);

            return Resultado::ok('Pessoa removida da leitora.');
        });
    }

    /**
     * Grava o horário na leitora: AccessTimeSchedule[n].TimeSchedule[dia][0] =
     * "1 HH:MM:SS-HH:MM:SS" (dia 0 = domingo; o 7 é o de feriados).
     */
    private function configurarHorario(Dispositivo $dispositivo, HorarioAcesso $horario): void
    {
        if ($this->horarioJaConfigurado($dispositivo, $horario)) {
            return;
        }

        $n = $horario->numero;
        $parametros = [
            "AccessTimeSchedule[{$n}].Name=".rawurlencode(self::nomeCurto($horario->nome, 60)),
            "AccessTimeSchedule[{$n}].Enable=true",
        ];

        for ($dia = 0; $dia <= 7; $dia++) {
            $faixa = in_array($dia, $horario->dias, true) ? "1 {$horario->inicio}-{$horario->fim}" : '0 00:00:00-00:00:00';
            $parametros[] = "AccessTimeSchedule[{$n}].TimeSchedule[{$dia}][0]=".rawurlencode($faixa);
        }

        $this->exigirOk($this->get($dispositivo, '/cgi-bin/configManager.cgi?action=setConfig&'.implode('&', $parametros)), "gravar o horário \"{$horario->nome}\"");
        $this->marcarHorarioConfigurado($dispositivo, $horario);
    }

    protected function abrirPorta(Dispositivo $dispositivo, int $porta): void
    {
        $this->exigirOk($this->get($dispositivo, "/cgi-bin/accessControl.cgi?action=openDoor&channel={$porta}&Type=Remote"), 'abrir a porta');
    }

    protected function informacoes(Dispositivo $dispositivo): string
    {
        $resposta = $this->get($dispositivo, '/cgi-bin/magicBox.cgi?action=getSystemInfo');

        if ($resposta->failed()) {
            throw new ExcecaoDeComunicacao('A leitora não respondeu como Intelbras (HTTP '.$resposta->status().'). Confira IP, porta e o driver.');
        }

        parse_str(str_replace(["\r\n", "\n"], '&', trim($resposta->body())), $info);

        return 'Intelbras '.($info['deviceType'] ?? '?').', nº de série '.($info['serialNumber'] ?? '?').'.';
    }

    private function get(Dispositivo $dispositivo, string $caminho): Response
    {
        return $this->registrar('GET', $caminho, $this->http($dispositivo)->get($this->base($dispositivo).$caminho));
    }

    /**
     * @param  array<string, mixed>  $corpo
     */
    private function post(Dispositivo $dispositivo, string $caminho, array $corpo): Response
    {
        $resposta = $this->http($dispositivo)
            ->withBody(json_encode($corpo, JSON_UNESCAPED_UNICODE), 'application/json')
            ->post($this->base($dispositivo).$caminho);

        return $this->registrar('POST', $caminho, $resposta);
    }

    private function ok(Response $resposta): bool
    {
        return $resposta->successful() && str_starts_with(strtoupper(trim($resposta->body())), 'OK');
    }

    private function exigirOk(Response $resposta, string $acao, string $dica = ''): void
    {
        if (! $this->ok($resposta)) {
            $detalhe = trim(preg_replace('/\s+/', ' ', mb_substr($resposta->body(), 0, 200)));

            throw new ExcecaoDeComunicacao("A leitora não conseguiu {$acao} (HTTP {$resposta->status()}: {$detalhe}).{$dica}");
        }
    }
}
