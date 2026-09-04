<?php

namespace App\Services;

use App\Models\DeviceToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin push-notification sender. Uses the FCM legacy HTTP API when
 * FCM_SERVER_KEY is configured; otherwise degrades to a log line so local /
 * dev environments never error. Swap in APNs or a vendor SDK the same way.
 */
class NotificationService
{
    public function sendToUser(int $userId, string $title, string $body, array $data = []): void
    {
        foreach (DeviceToken::where('user_id', $userId)->pluck('token') as $token) {
            $this->send($token, $title, $body, $data);
        }
    }

    public function sendToRole(string $role, string $title, string $body, array $data = []): void
    {
        foreach (DeviceToken::where('role', $role)->pluck('token') as $token) {
            $this->send($token, $title, $body, $data);
        }
    }

    public function send(string $token, string $title, string $body, array $data = []): bool
    {
        $serverKey = config('notification.fcm_server_key');
        if (! $serverKey) {
            Log::info('[push:dev]', ['token' => $token, 'title' => $title, 'body' => $body]);
            return false;
        }

        try {
            $res = Http::withHeaders(['Authorization' => 'key=' . $serverKey])
                ->post('https://fcm.googleapis.com/fcm/send', [
                    'to'           => $token,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data'         => $data,
                ]);

            return $res->successful();
        } catch (\Throwable $e) {
            Log::error('FCM send failed: ' . $e->getMessage());
            return false;
        }
    }
}
