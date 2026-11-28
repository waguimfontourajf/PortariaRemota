<?php

namespace App\Central;

use App\Acoes\AcionarPontoDeAcesso;
use App\Enums\OrigemChamada;
use App\Enums\OrigemEvento;
use App\Enums\Perfil;
use App\Enums\Permissao;
use App\Enums\StatusChamada;
use App\Enums\TipoDispositivo;
use App\Models\Chamada;
use App\Models\Condominio;
use App\Models\Dispositivo;
use App\Models\EventoAcesso;
use App\Models\PontoAcesso;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Ciclo de vida das chamadas de interfone para a central:
 * tocando → em atendimento → encerrada (ou perdida, se ninguém atender).
 */
class Chamadas
{
    public function __construct(private readonly AcionarPontoDeAcesso $acionar) {}

    /**
     * Condomínios cujas chamadas o usuário atende.
     *
     * @return Collection<int, int>
     */
    public static function condominiosQueAtende(User $usuario): Collection
    {
        if (! $usuario->ativo) {
            return collect();
        }

        // Atalho: quem não tem nenhum perfil que atende chamadas não precisa da consulta por condomínio.
        $perfisQueAtendem = collect(Perfil::cases())->filter(fn (Perfil $p) => in_array(Permissao::ChamadasAtender, $p->permissoes(), true))->map->value->all();
        if (! $usuario->isSuperAdmin() && ! $usuario->temAlgumPerfil($perfisQueAtendem)) {
            return collect();
        }

        $condominios = $usuario->condominiosAcessiveis()->get();

        if ($usuario->isSuperAdmin()) {
            return $condominios->pluck('id');
        }

        return $condominios
            ->filter(fn (Condominio $c) => $usuario->noCondominio($c, fn () => $usuario->can(Permissao::ChamadasAtender->value)))
            ->pluck('id')
            ->values();
    }

    public static function podeAtender(?User $usuario, Chamada $chamada): bool
    {
        return $usuario !== null && self::condominiosQueAtende($usuario)->contains($chamada->condominio_id);
    }

    /**
     * Chamada vinda do PABX: identifica o interfone pelo ramal.
     */
    public function porRamal(string $ramal, ?string $idExterno = null): ?Chamada
    {
        $interfone = Dispositivo::query()
            ->where('ramal', $ramal)
            ->where('tipo', TipoDispositivo::Interfone->value)
            ->where('ativo', true)
            ->first();

        return $interfone ? $this->iniciar($interfone, OrigemChamada::Interfone, $idExterno) : null;
    }

    public function iniciar(Dispositivo $interfone, OrigemChamada $origem, ?string $idExterno = null): Chamada
    {
        $this->expirarAntigas();

        if ($idExterno !== null) {
            $existente = Chamada::query()->where('id_externo', $idExterno)->ativas()->first();
            if ($existente) {
                return $existente;
            }
        }

        // Botão apertado várias vezes seguidas: uma chamada só.
        if ($origem === OrigemChamada::Campainha) {
            $repetida = Chamada::query()->where('dispositivo_id', $interfone->id)->where('status', StatusChamada::Tocando->value)->first();
            if ($repetida) {
                return $repetida;
            }
        }

        $ponto = PontoAcesso::query()->where('interfone_id', $interfone->id)->where('ativo', true)->orderBy('ordem')->first();

        return Chamada::create([
            'condominio_id' => $interfone->condominio_id,
            'ponto_acesso_id' => $ponto?->id,
            'dispositivo_id' => $interfone->id,
            'origem' => $origem,
            'ramal_origem' => $interfone->ramal,
            'id_externo' => $idExterno,
            'status' => StatusChamada::Tocando,
        ]);
    }

    /**
     * Atende. Só um operador fica com a chamada: devolve false se outro já atendeu.
     */
    public function atender(Chamada $chamada, User $usuario): bool
    {
        $alteradas = Chamada::query()
            ->whereKey($chamada->id)
            ->where('status', StatusChamada::Tocando->value)
            ->update([
                'status' => StatusChamada::EmAtendimento->value,
                'atendida_por_id' => $usuario->id,
                'atendida_em' => now(),
                'updated_at' => now(),
            ]);

        $chamada->refresh();

        return $alteradas === 1 || ($chamada->status === StatusChamada::EmAtendimento && $chamada->atendida_por_id === $usuario->id);
    }

    /**
     * Encerra. Sem ninguém ter atendido, fica como "não atendida".
     */
    public function encerrar(Chamada $chamada, ?string $observacao = null): void
    {
        if (! $chamada->status->ativa()) {
            // O PABX pode ter registrado o fim um instante antes: guarda a observação mesmo assim.
            if (filled($observacao)) {
                $chamada->forceFill(['observacao' => $observacao])->save();
            }

            return;
        }

        $chamada->forceFill([
            'status' => $chamada->atendida_em ? StatusChamada::Encerrada : StatusChamada::Perdida,
            'encerrada_em' => now(),
            'observacao' => filled($observacao) ? $observacao : $chamada->observacao,
        ])->save();
    }

    /**
     * Abre o portão ligado à chamada (atende automaticamente, se ainda tocava).
     */
    public function abrirPorta(Chamada $chamada, User $usuario, ?string $ip = null): ?EventoAcesso
    {
        $ponto = $chamada->ponto;
        if (! $ponto || $ponto->trashed()) {
            return null;
        }

        if ($chamada->status === StatusChamada::Tocando) {
            $this->atender($chamada, $usuario);
        }

        $evento = ($this->acionar)($ponto, $usuario, OrigemEvento::Central, $ip);

        if ($evento->resultado->value === 'sucesso') {
            $chamada->forceFill(['porta_aberta' => true])->save();
        }

        return $evento;
    }

    /**
     * Chamadas que ficaram "tocando" (o PABX não avisou o fim, botão sem áudio...)
     * viram "não atendida"; atendimentos esquecidos abertos são encerrados.
     */
    public function expirarAntigas(): void
    {
        Chamada::query()
            ->where('status', StatusChamada::Tocando->value)
            ->where('created_at', '<', now()->subSeconds((int) config('portaria.central.tocar_segundos', 90)))
            ->update(['status' => StatusChamada::Perdida->value, 'encerrada_em' => now(), 'updated_at' => now()]);

        Chamada::query()
            ->where('status', StatusChamada::EmAtendimento->value)
            ->where('atendida_em', '<', now()->subMinutes(30))
            ->update(['status' => StatusChamada::Encerrada->value, 'encerrada_em' => now(), 'updated_at' => now()]);
    }

    /**
     * Chamadas em andamento que o usuário pode atender (para o pop-up).
     *
     * @return Collection<int, Chamada>
     */
    public function ativasPara(User $usuario): Collection
    {
        $condominios = self::condominiosQueAtende($usuario);
        if ($condominios->isEmpty()) {
            return collect();
        }

        $this->expirarAntigas();

        return Chamada::query()
            ->ativas()
            ->whereIn('condominio_id', $condominios)
            ->where(fn ($q) => $q->where('status', StatusChamada::Tocando->value)->orWhere('atendida_por_id', $usuario->id))
            ->with(['condominio:id,nome', 'ponto', 'interfone', 'atendente:id,name'])
            ->oldest()
            ->get();
    }
}
