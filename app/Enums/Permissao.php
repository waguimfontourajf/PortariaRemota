<?php

namespace App\Enums;

/**
 * Permissões do sistema. Sempre avaliadas dentro do condomínio atual.
 *
 * Para adicionar uma permissão: crie o case aqui, inclua-a nos perfis em
 * App\Enums\Perfil e rode "php artisan db:seed --class=PerfisSeeder".
 */
enum Permissao: string
{
    case CondominioGerenciar = 'condominio.gerenciar';
    case UsuariosGerenciar = 'usuarios.gerenciar';
    case CadastrosGerenciar = 'cadastros.gerenciar';
    case DispositivosGerenciar = 'dispositivos.gerenciar';
    case AcessosAbrir = 'acessos.abrir';
    case HistoricoVer = 'historico.ver';
    case HistoricoProprioVer = 'historico.proprio.ver';
    case CamerasVer = 'cameras.ver';
    case ChamadasAtender = 'chamadas.atender';
    case OcorrenciasRegistrar = 'ocorrencias.registrar';
    case VisitasAutorizar = 'visitas.autorizar';

    public function label(): string
    {
        return match ($this) {
            self::CondominioGerenciar => 'Gerenciar dados do condomínio',
            self::UsuariosGerenciar => 'Gerenciar usuários',
            self::CadastrosGerenciar => 'Gerenciar blocos, unidades e pessoas',
            self::DispositivosGerenciar => 'Gerenciar dispositivos e pontos de acesso',
            self::AcessosAbrir => 'Abrir portões e portas',
            self::HistoricoVer => 'Ver histórico completo',
            self::HistoricoProprioVer => 'Ver o próprio histórico',
            self::CamerasVer => 'Ver câmeras',
            self::ChamadasAtender => 'Atender chamadas de interfone',
            self::OcorrenciasRegistrar => 'Registrar ocorrências',
            self::VisitasAutorizar => 'Autorizar visitantes',
        };
    }
}
