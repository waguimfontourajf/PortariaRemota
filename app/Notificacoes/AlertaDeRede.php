<?php

namespace App\Notificacoes;

use App\Models\Dispositivo;
use App\Models\QuedaDispositivo;

/**
 * Avisa pelo Telegram quando um equipamento cai ou volta.
 * Vai para o chat geral (sua equipe) e, se configurado, para o chat do condomínio.
 */
class AlertaDeRede
{
    public function __construct(private readonly Telegram $telegram) {}

    public function caiu(Dispositivo $dispositivo, QuedaDispositivo $queda): void
    {
        $this->enviar($dispositivo, sprintf(
            "🔴 <b>%s fora do ar</b>\n🏢 %s\n📍 %s (%s)\n🕒 desde %s%s",
            Telegram::escapar($dispositivo->nome),
            Telegram::escapar($dispositivo->condominio->nome),
            Telegram::escapar($dispositivo->tipo->label()),
            Telegram::escapar((string) ($dispositivo->host ?? '-')),
            $queda->inicio->timezone($dispositivo->condominio->fuso_horario)->format('d/m H:i'),
            $queda->motivo ? "\n⚠️ ".Telegram::escapar($queda->motivo) : '',
        ));
    }

    public function voltou(Dispositivo $dispositivo, QuedaDispositivo $queda): void
    {
        $this->enviar($dispositivo, sprintf(
            "🟢 <b>%s voltou</b>\n🏢 %s\n⏱ ficou fora por %s",
            Telegram::escapar($dispositivo->nome),
            Telegram::escapar($dispositivo->condominio->nome),
            $queda->duracaoTexto(),
        ));
    }

    private function enviar(Dispositivo $dispositivo, string $texto): void
    {
        if (! $this->telegram->ativo()) {
            return;
        }

        $chats = array_unique(array_filter([$this->telegram->chatPadrao(), $dispositivo->condominio->telegram_chat_id]));

        foreach ($chats as $chat) {
            $this->telegram->enviar($texto, $chat);
        }
    }
}
