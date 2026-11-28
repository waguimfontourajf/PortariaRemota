<?php

namespace App\Central;

use App\Enums\TipoDispositivo;
use App\Models\Configuracao;
use App\Models\Dispositivo;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Integração com o PABX (Asterisk): ramais dos operadores (softphone no
 * navegador), ramais dos interfones e arquivos de configuração gerados.
 *
 * O Asterisk avisa o sistema por HTTP quando um interfone liga e quando a
 * chamada termina; o sistema responde quais operadores devem tocar.
 */
class Pabx
{
    public static function token(): ?string
    {
        return Configuracao::obter('pabx.token');
    }

    public static function gerarToken(): string
    {
        $token = Str::random(48);
        Configuracao::definir('pabx.token', $token);

        return $token;
    }

    public static function confere(?string $token): bool
    {
        $esperado = self::token();

        return filled($esperado) && filled($token) && hash_equals($esperado, (string) $token);
    }

    /** Endereço WebSocket (wss://) do Asterisk para o softphone. Vazio = central sem áudio. */
    public static function wss(): ?string
    {
        return Configuracao::obter('pabx.wss');
    }

    public static function dominio(): string
    {
        return (string) Configuracao::obter('pabx.dominio', parse_url((string) self::wss(), PHP_URL_HOST) ?: 'portaria');
    }

    public static function urlDoSistema(): string
    {
        return rtrim((string) Configuracao::obter('pabx.url_sistema', config('app.url')), '/');
    }

    public static function stun(): ?string
    {
        return Configuracao::obter('pabx.stun', 'stun:stun.l.google.com:19302');
    }

    public static function audioAtivo(): bool
    {
        return filled(self::wss());
    }

    /**
     * Ramal e senha do softphone do operador (criados na primeira vez).
     *
     * @return array{ramal: string, senha: string}
     */
    public static function credenciais(User $usuario): array
    {
        if (blank($usuario->ramal_sip) || blank($usuario->senha_sip)) {
            $usuario->forceFill(['ramal_sip' => 'op'.$usuario->id, 'senha_sip' => Interfones::senhaNova()])->save();
        }

        return ['ramal' => (string) $usuario->ramal_sip, 'senha' => (string) $usuario->senha_sip];
    }

    /**
     * Destinos do Dial do Asterisk: os operadores que atendem aquele condomínio.
     */
    public static function destinosPara(int $condominioId): string
    {
        return self::operadores()
            ->filter(fn (User $u) => Chamadas::condominiosQueAtende($u)->contains($condominioId))
            ->map(fn (User $u) => 'PJSIP/'.self::credenciais($u)['ramal'])
            ->implode('&');
    }

    /**
     * Quem atende chamadas em algum condomínio.
     *
     * @return Collection<int, User>
     */
    public static function operadores(): Collection
    {
        return User::query()->where('ativo', true)->orderBy('id')->get()
            ->filter(fn (User $u) => Chamadas::condominiosQueAtende($u)->isNotEmpty())
            ->values();
    }

    /**
     * @return Collection<int, Dispositivo>
     */
    public static function interfones(): Collection
    {
        return Dispositivo::query()
            ->where('tipo', TipoDispositivo::Interfone->value)
            ->whereNotNull('ramal')
            ->where('ativo', true)
            ->with('condominio:id,nome')
            ->orderBy('ramal')
            ->get();
    }

    public static function senhaDoInterfone(Dispositivo $interfone): string
    {
        $senha = (string) $interfone->opcao('senha_sip', '');
        if ($senha === '') {
            $senha = Interfones::senhaNova();
            $interfone->config = array_merge($interfone->config ?? [], ['senha_sip' => $senha]);
            $interfone->save();
        }

        return $senha;
    }

    /**
     * pjsip.conf: transportes, modelos e um ramal por interfone e por operador.
     */
    public static function configPjsip(): string
    {
        $linhas = [
            '; Gerado pela Portaria Remota em '.now()->format('d/m/Y H:i').'. Não edite: gere de novo pelo painel.',
            '',
            '[portaria-udp]',
            'type=transport',
            'protocol=udp',
            'bind=0.0.0.0:5060',
            '',
            '[portaria-ws]',
            'type=transport',
            'protocol=ws',
            'bind=0.0.0.0',
            '',
            '[portaria-interfone](!)',
            'type=endpoint',
            'context=portaria-interfones',
            'disallow=all',
            'allow=ulaw,alaw',
            'direct_media=no',
            'rtp_symmetric=yes',
            'force_rport=yes',
            'rewrite_contact=yes',
            'dtmf_mode=rfc4733',
            '',
            '[portaria-operador](!)',
            'type=endpoint',
            'context=portaria-operadores',
            'disallow=all',
            'allow=opus,ulaw,alaw',
            'webrtc=yes',
            'dtmf_mode=rfc4733',
            '',
        ];

        foreach (self::interfones() as $i) {
            array_push($linhas, ...self::ramal($i->ramal, self::senhaDoInterfone($i), 'portaria-interfone', $i->condominio?->nome.' — '.$i->nome));
        }

        foreach (self::operadores() as $u) {
            $c = self::credenciais($u);
            array_push($linhas, ...self::ramal($c['ramal'], $c['senha'], 'portaria-operador', 'Operador: '.$u->name));
        }

        return implode("\n", $linhas)."\n";
    }

    /**
     * @return list<string>
     */
    private static function ramal(string $ramal, string $senha, string $modelo, string $descricao): array
    {
        $descricao = str_replace(["\n", "\r", ';'], ' ', $descricao);

        return [
            '; '.$descricao,
            "[{$ramal}]({$modelo})",
            "auth={$ramal}",
            "aors={$ramal}",
            "callerid=\"{$ramal}\" <{$ramal}>",
            '',
            "[{$ramal}]",
            'type=auth',
            'auth_type=userpass',
            "username={$ramal}",
            "password={$senha}",
            '',
            "[{$ramal}]",
            'type=aor',
            'max_contacts=1',
            'remove_existing=yes',
            'qualify_frequency=30',
            '',
        ];
    }

    /**
     * extensions.conf: interfone liga → avisa o sistema → toca nos operadores.
     */
    public static function configExtensoes(): string
    {
        $url = self::urlDoSistema().'/pabx/evento';
        $token = (string) self::token();
        $numero = (string) config('portaria.central.numero');
        $tempo = (int) config('portaria.central.tocar_segundos', 90);

        return <<<CONF
; Gerado pela Portaria Remota. Não edite: gere de novo pelo painel.

[globals]
PORTARIA_URL={$url}
PORTARIA_TOKEN={$token}

; Interfones: qualquer número discado chama a central (o padrão é {$numero}).
[portaria-interfones]
exten => _X.,1,NoOp(Interfone \${CALLERID(num)} chamando a central)
 same => n,Set(RESPOSTA=\${CURL(\${PORTARIA_URL},token=\${PORTARIA_TOKEN}&evento=inicio&ramal=\${CALLERID(num)}&id=\${UNIQUEID})})
 same => n,Set(CHAMADA=\${CUT(RESPOSTA,|,1)})
 same => n,Set(DESTINOS=\${CUT(RESPOSTA,|,2)})
 same => n,GotoIf(\$[\${REGEX("^PJSIP/" \${DESTINOS})}]?:sem_operador)
 same => n,Dial(\${DESTINOS},{$tempo},b(portaria-cabecalho^s^1(\${CHAMADA})))
 same => n,Hangup()
 same => n(sem_operador),Playback(vm-nobodyavail)
 same => n,Hangup()

exten => h,1,Set(FIM=\${CURL(\${PORTARIA_URL},token=\${PORTARIA_TOKEN}&evento=fim&id=\${UNIQUEID}&status=\${DIALSTATUS})})

; Marca a chamada para o navegador do operador saber qual é.
[portaria-cabecalho]
exten => s,1,Set(PJSIP_HEADER(add,X-Portaria-Chamada)=\${ARG1})
 same => n,Return()

; Operadores podem ligar para um interfone pelo ramal.
[portaria-operadores]
exten => _X.,1,Dial(PJSIP/\${EXTEN},60)
 same => n,Hangup()

CONF;
    }

    /**
     * http.conf (WebSocket do softphone) e rtp.conf (áudio).
     */
    public static function configHttpRtp(): string
    {
        $stun = preg_replace('#^stuns?:#', '', (string) self::stun());

        return <<<CONF
; ===== modules.conf (seção [modules]) =====
; O chan_sip antigo disputa as ligações com o PJSIP: desligue-o.
noload => chan_sip.so

; ===== http.conf =====
[general]
enabled=yes
bindaddr=0.0.0.0
bindport=8088

; ===== rtp.conf =====
[general]
rtpstart=10000
rtpend=10200
icesupport=yes
stunaddr={$stun}

CONF;
    }
}
