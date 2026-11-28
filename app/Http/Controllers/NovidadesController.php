<?php

namespace App\Http\Controllers;

use App\Enums\Permissao;
use App\Enums\ResultadoEvento;
use App\Models\EventoAcesso;
use App\Models\QuedaDispositivo;
use App\Support\CondominioAtual;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * "Tem novidade?" — consultado pelas telas a cada poucos segundos.
 *
 * Devolve só o que aconteceu depois do cursor recebido (acessos e quedas
 * de equipamentos), respeitando o que cada perfil pode ver. A primeira
 * chamada (sem cursor) só devolve o cursor, para não repetir o passado.
 */
class NovidadesController extends Controller
{
    private const LIMITE = 50;

    public function __invoke(Request $request, CondominioAtual $condominioAtual): JsonResponse
    {
        $usuario = $request->user();
        $condominio = $condominioAtual->get();

        // Lê os máximos ANTES de consultar: o que for gravado depois fica para a próxima.
        $maxEvento = (int) EventoAcesso::max('id');
        $maxQueda = (int) QuedaDispositivo::max('id');
        $agora = now();

        if (! $request->filled('e')) {
            return $this->responder(['e' => $maxEvento, 'q' => $maxQueda, 't' => $agora->timestamp], [], []);
        }

        $desdeEvento = (int) $request->integer('e');
        $desdeQueda = (int) $request->integer('q');
        $desdeHora = Carbon::createFromTimestamp(min((int) $request->integer('t'), $agora->timestamp));

        $eventos = [];
        if ($condominio) {
            $verTudo = $usuario->can(Permissao::HistoricoVer->value);
            $verProprios = $verTudo || $usuario->can(Permissao::HistoricoProprioVer->value) || $usuario->can(Permissao::AcessosAbrir->value);

            if ($verProprios) {
                $fuso = $condominio->fuso_horario;
                $eventos = EventoAcesso::query()
                    ->doCondominio($condominio)
                    ->whereBetween('id', [$desdeEvento + 1, $maxEvento])
                    ->unless($verTudo, fn ($q) => $q->where('user_id', $usuario->id))
                    ->orderBy('id')
                    ->limit(self::LIMITE)
                    ->get()
                    ->map(fn (EventoAcesso $e) => [
                        'id' => $e->id,
                        'ponto_id' => $e->ponto_acesso_id,
                        'ponto' => $e->ponto_nome,
                        'usuario' => $e->usuario_nome ?? __('Sistema'),
                        'acao' => $e->acao->value,
                        'acao_nome' => $e->acao->label(),
                        'resultado' => $e->resultado->value,
                        'resultado_nome' => $e->resultado->label(),
                        'detalhe' => $e->detalhe,
                        'hora' => $e->created_at->timezone($fuso)->format('H:i:s'),
                        // Aviso na tela só para falhas de OUTRAS pessoas (quem clicou já vê o resultado).
                        'alerta' => $e->user_id !== $usuario->id && in_array($e->resultado, [ResultadoEvento::Falha, ResultadoEvento::Negado], true),
                    ])
                    ->all();
            }
        }

        $rede = $this->rede($usuario, $condominio?->id, $desdeQueda, $maxQueda, $desdeHora);

        return $this->responder(['e' => $maxEvento, 'q' => $maxQueda, 't' => $agora->timestamp], $eventos, $rede);
    }

    /**
     * Quedas e voltas de equipamentos que o usuário acompanha.
     *
     * @return list<array<string, mixed>>
     */
    private function rede($usuario, ?int $condominioId, int $desdeQueda, int $maxQueda, Carbon $desdeHora): array
    {
        if ($usuario->ehEquipeDaCentral()) {
            $condominios = $usuario->condominiosAcessiveis()->pluck('id');
        } elseif ($condominioId && ($usuario->can(Permissao::DispositivosGerenciar->value) || $usuario->can(Permissao::HistoricoVer->value))) {
            $condominios = collect([$condominioId]);
        } else {
            return [];
        }

        $base = fn () => QuedaDispositivo::query()->with(['dispositivo:id,nome,condominio_id', 'condominio:id,nome'])->whereIn('condominio_id', $condominios);

        $cairam = $base()->whereBetween('id', [$desdeQueda + 1, $maxQueda])->orderBy('id')->limit(self::LIMITE)->get()
            ->map(fn (QuedaDispositivo $q) => $this->itemDeRede('caiu', $q));

        $voltaram = $base()->whereNotNull('fim')->where('fim', '>=', $desdeHora)->orderBy('fim')->limit(self::LIMITE)->get()
            ->map(fn (QuedaDispositivo $q) => $this->itemDeRede('voltou', $q));

        return $cairam->concat($voltaram)->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function itemDeRede(string $tipo, QuedaDispositivo $q): array
    {
        return [
            'chave' => $tipo.'-'.$q->id,
            'tipo' => $tipo,
            'dispositivo_id' => $q->dispositivo_id,
            'dispositivo' => $q->dispositivo?->nome,
            'condominio_id' => $q->condominio_id,
            'condominio' => $q->condominio?->nome,
            'duracao' => $q->duracaoTexto(),
        ];
    }

    /**
     * @param  array<string, int>  $cursor
     * @param  list<array<string, mixed>>  $eventos
     * @param  list<array<string, mixed>>  $rede
     */
    private function responder(array $cursor, array $eventos, array $rede): JsonResponse
    {
        return response()->json(['cursor' => $cursor, 'eventos' => $eventos, 'rede' => $rede])
            ->header('Cache-Control', 'no-store');
    }
}
