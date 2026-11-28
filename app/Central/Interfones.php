<?php

namespace App\Central;

use App\Dispositivos\Campo;
use App\Models\Dispositivo;
use Illuminate\Support\Str;

/**
 * Configuração dos interfones (ramal SIP no PABX e "campainha" por URL).
 */
class Interfones
{
    /**
     * @return list<Campo>
     */
    public static function campos(): array
    {
        return [
            new Campo('senha_sip', 'Senha SIP do ramal', 'senha', ajuda: 'Em branco, o sistema cria uma senha forte. Ela aparece na tela "Central telefônica" para configurar o interfone.'),
            new Campo('dtmf_abrir', 'Código para abrir pelo interfone (opcional)', placeholder: '#1', ajuda: 'Se o próprio interfone aciona a fechadura com um código (DTMF), a central mostra o botão "Abrir pelo interfone".', regras: ['regex:/^[0-9*#]{1,8}$/']),
        ];
    }

    public static function senhaNova(): string
    {
        return Str::password(20, symbols: false);
    }

    /**
     * Cria (ou troca) o link da campainha. Devolve o link: ele só é mostrado agora.
     */
    public static function gerarLinkCampainha(Dispositivo $interfone): string
    {
        $token = Str::random(40);
        $interfone->forceFill(['token_hash' => hash('sha256', $token)])->save();

        return route('campainha', $token);
    }
}
