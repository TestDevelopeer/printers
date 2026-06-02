<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Http;
use Throwable;

class TelegramBotService
{
    public function sendMessage(string $message): void
    {
        $token = config('printers.telegram.bot_token');
        $chatId = config('printers.telegram.chat_id');

        if (blank($token) || blank($chatId)) {
            return;
        }

        try {
            Http::asForm()
                ->timeout(10)
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $message,
                ])
                ->throw();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
