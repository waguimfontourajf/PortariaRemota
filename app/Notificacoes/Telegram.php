<?php

namespace App\Notificacoes;

use App\Models\Configuracao;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envio de mensagens por um bot do Telegram.
 * Token e chat são configurados em Administração > Alertas (Telegram).
 */
class Telegram
{
    public function token(): ?string
    {
        return Configuracao::obter('telegram.token');
    }

    public function chatPadrao(): ?string
    {
        return Configuracao::obter('telegram.chat_id');
    }

    public function ativo(): bool
    {
        return filled($this->token()) && Configuracao::obter('telegram.ativo', '1') === '1';
    }

    /**
     * Envia uma mensagem (HTML simples: <b>, <i>, <code>). Nunca lança
     * exceção: uma falha no Telegram não pode atrapalhar o monitoramento.
     *
     * @return true|string true ou a mensagem de erro
     */
    public function enviar(string $texto, ?string $chatId = null, ?string $token = null): true|string
    {
        $token ??= $this->token();
        $chatId ??= $this->chatPadrao();

        if (blank($token) || blank($chatId)) {
            return 'Telegram não configurado (falta o token do bot ou o chat).';
        }

        try {
            $resposta = Http::timeout(10)->asJson()->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $texto,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);
        } catch (Throwable $e) {
            Log::warning('Telegram: falha de conexão', ['erro' => $e->getMessage()]);

            return 'Sem conexão com o Telegram: '.$e->getMessage();
        }

        if (! $resposta->json('ok')) {
            $erro = (string) ($resposta->json('description') ?? 'HTTP '.$resposta->status());
            Log::warning('Telegram: mensagem recusada', ['erro' => $erro]);

            return 'O Telegram recusou: '.$erro;
        }

        return true;
    }

    /**
     * Conversas/grupos que falaram com o bot recentemente (para descobrir o chat id).
     *
     * @return list<array{id: string, nome: string, tipo: string}>|string
     */
    public function conversasRecentes(?string $token = null): array|string
    {
        $token ??= $this->token();

        if (blank($token)) {
            return 'Informe o token do bot.';
        }

        try {
            $resposta = Http::timeout(10)->get("https://api.telegram.org/bot{$token}/getUpdates");
        } catch (Throwable $e) {
            return 'Sem conexão com o Telegram: '.$e->getMessage();
        }

        if (! $resposta->json('ok')) {
            return 'O Telegram recusou: '.($resposta->json('description') ?? 'token inválido?');
        }

        return collect($resposta->json('result', []))
            ->map(fn ($u) => $u['message']['chat'] ?? $u['my_chat_member']['chat'] ?? $u['channel_post']['chat'] ?? null)
            ->filter()
            ->unique('id')
            ->map(fn ($c) => [
                'id' => (string) $c['id'],
                'nome' => (string) ($c['title'] ?? trim(($c['first_name'] ?? '').' '.($c['last_name'] ?? '')) ?: ($c['username'] ?? '?')),
                'tipo' => (string) ($c['type'] ?? ''),
            ])
            ->values()
            ->all();
    }

    public static function escapar(string $texto): string
    {
        return htmlspecialchars($texto, ENT_NOQUOTES, 'UTF-8');
    }
}
