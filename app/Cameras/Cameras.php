<?php

namespace App\Cameras;

use App\Dispositivos\Campo;
use App\Dispositivos\ExcecaoDeComunicacao;
use App\Enums\Permissao;
use App\Enums\TipoDispositivo;
use App\Models\Dispositivo;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Imagem ao vivo das câmeras (por quadros: o navegador pede uma foto nova
 * a cada ~0,7 s). A foto passa SEMPRE pelo sistema, com login e permissão:
 * o endereço e a senha da câmera nunca chegam ao navegador.
 *
 * Intelbras (e outras de base Dahua), Hikvision ou qualquer câmera com URL
 * de "snapshot". O endereço RTSP fica guardado para o vídeo fluido (go2rtc).
 */
class Cameras
{
    /** Uma foto serve todos que estão olhando a mesma câmera neste intervalo. */
    private const CACHE_MS = 600;

    public const MARCAS = [
        'intelbras' => 'Intelbras (e câmeras/DVRs de base Dahua)',
        'hikvision' => 'Hikvision',
        'generica' => 'Outra marca (URL da imagem)',
    ];

    /**
     * Campos de configuração da câmera no cadastro do dispositivo.
     *
     * @return list<Campo>
     */
    public static function campos(): array
    {
        return [
            new Campo('marca', 'Marca', 'selecao', obrigatorio: true, padrao: 'intelbras', opcoes: self::MARCAS),
            new Campo('usuario', 'Usuário da câmera', padrao: 'admin'),
            new Campo('senha', 'Senha da câmera', 'senha'),
            new Campo('canal', 'Canal', 'numero', padrao: 1, ajuda: 'Câmera IP: 1. Em DVR/NVR, o número da câmera no gravador.', regras: ['integer', 'min:1', 'max:128']),
            new Campo('url_imagem', 'URL da imagem (só para "Outra marca")', placeholder: '/cgi-bin/snapshot.cgi?channel={canal}', ajuda: 'Endereço que devolve uma foto JPEG. Começando com "/", usa o IP e a porta acima.'),
            new Campo('url_rtsp', 'Endereço RTSP (opcional)', placeholder: 'rtsp://IP:554/cam/realmonitor?channel=1&subtype=1', ajuda: 'Para o vídeo fluido no futuro. Em branco, o sistema monta pela marca.'),
            new Campo('liberada_moradores', 'Moradores podem ver esta câmera', 'booleano', padrao: false, ajuda: 'Ex.: câmera do portão social. Deixe desligado em áreas internas.'),
            new Campo('timeout', 'Tempo limite (segundos)', 'numero', padrao: 5, regras: ['min:1', 'max:20']),
        ];
    }

    public static function ehCamera(Dispositivo $d): bool
    {
        return $d->tipo === TipoDispositivo::Camera;
    }

    /**
     * Quem pode ver a câmera: quem tem "ver câmeras" no condomínio (equipe),
     * ou o morador, se a câmera estiver liberada para moradores.
     */
    public static function podeVer(?User $usuario, Dispositivo $camera): bool
    {
        if (! $usuario || ! $usuario->ativo || ! self::ehCamera($camera) || ! $camera->ativo) {
            return false;
        }

        if ($usuario->isSuperAdmin()) {
            return true;
        }

        if (! $usuario->podeAcessarCondominio($camera->condominio)) {
            return false;
        }

        return $usuario->noCondominio($camera->condominio, fn () => $usuario->can(Permissao::CamerasVer->value)
            || ((bool) $camera->opcao('liberada_moradores') && $usuario->can(Permissao::AcessosAbrir->value)));
    }

    public function urlImagem(Dispositivo $camera): string
    {
        if (blank($camera->host)) {
            throw new ExcecaoDeComunicacao('Endereço (IP) da câmera não configurado.');
        }

        $canal = max(1, (int) $camera->opcao('canal', 1));
        $base = 'http://'.$camera->host.($camera->porta && $camera->porta !== 80 ? ':'.$camera->porta : '');

        $caminho = match ($camera->opcao('marca', 'intelbras')) {
            'hikvision' => '/ISAPI/Streaming/channels/'.$canal.'01/picture',
            'generica' => strtr((string) $camera->opcao('url_imagem', ''), ['{canal}' => (string) $canal]),
            default => '/cgi-bin/snapshot.cgi?channel='.$canal,
        };

        if ($caminho === '') {
            throw new ExcecaoDeComunicacao('Informe a URL da imagem da câmera.');
        }

        return str_starts_with($caminho, 'http') ? $caminho : $base.$caminho;
    }

    /**
     * Endereço RTSP (para o vídeo fluido). Sem senha: ela é passada à parte.
     */
    public function urlRtsp(Dispositivo $camera, bool $subStream = true): ?string
    {
        if (filled($camera->opcao('url_rtsp'))) {
            return (string) $camera->opcao('url_rtsp');
        }

        if (blank($camera->host)) {
            return null;
        }

        $canal = max(1, (int) $camera->opcao('canal', 1));

        return match ($camera->opcao('marca', 'intelbras')) {
            'hikvision' => "rtsp://{$camera->host}:554/Streaming/Channels/{$canal}0".($subStream ? '2' : '1'),
            'generica' => null,
            default => "rtsp://{$camera->host}:554/cam/realmonitor?channel={$canal}&subtype=".($subStream ? '1' : '0'),
        };
    }

    /**
     * Busca uma foto JPEG da câmera (com cache bem curto).
     */
    public function imagem(Dispositivo $camera): string
    {
        $chave = 'camera:quadro:'.$camera->id.':'.intdiv((int) (microtime(true) * 1000), self::CACHE_MS);

        return Cache::store('file')->remember($chave, 2, fn () => $this->buscar($camera));
    }

    /**
     * Foto na hora, sem cache (usado no botão "Testar" do cadastro).
     */
    public function buscar(Dispositivo $camera): string
    {
        $timeout = (float) $camera->opcao('timeout', 5);
        $usuario = (string) $camera->opcao('usuario', 'admin');
        $senha = (string) $camera->opcao('senha', '');

        try {
            $requisicao = Http::timeout($timeout)->connectTimeout(min(3, $timeout))->withoutVerifying()->accept('image/jpeg');
            $resposta = $senha !== ''
                ? $requisicao->withDigestAuth($usuario, $senha)->get($this->urlImagem($camera))
                : $requisicao->get($this->urlImagem($camera));
        } catch (ConnectionException) {
            throw new ExcecaoDeComunicacao('A câmera não respondeu (rede, IP ou porta).');
        }

        // Algumas câmeras/configurações usam Basic em vez de Digest.
        if ($resposta->status() === 401 && $senha !== '') {
            $resposta = Http::timeout($timeout)->withoutVerifying()->withBasicAuth($usuario, $senha)->get($this->urlImagem($camera));
        }

        if ($resposta->status() === 401) {
            throw new ExcecaoDeComunicacao('A câmera recusou o usuário/senha.');
        }

        $corpo = $resposta->body();

        if (! $resposta->successful() || ! str_starts_with($corpo, "\xFF\xD8")) {
            throw new ExcecaoDeComunicacao('A câmera não devolveu uma imagem (HTTP '.$resposta->status().'). Confira a marca, o canal e a URL.');
        }

        return $corpo;
    }
}
