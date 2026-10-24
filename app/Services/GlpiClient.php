<?php

namespace App\Services;

use App\Models\GlpiIntegration;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GlpiClient
{
    /** @return array{version: string|null, session_token: string} */
    public function test(GlpiIntegration $integration): array
    {
        $response = $this->request($integration)
            ->withHeaders(['Authorization' => 'user_token '.$integration->user_token])
            ->get($this->endpoint($integration, 'initSession'));

        if (! $response->successful() || ! $response->json('session_token')) {
            throw new RuntimeException($this->errorMessage($response->json(), $response->status()));
        }

        $sessionToken = (string) $response->json('session_token');
        $configResponse = $this->request($integration)
            ->withHeaders(['Session-Token' => $sessionToken])
            ->get($this->endpoint($integration, 'getGlpiConfig'));

        $this->request($integration)
            ->withHeaders(['Session-Token' => $sessionToken])
            ->get($this->endpoint($integration, 'killSession'));

        return [
            'version' => $configResponse->json('cfg_glpi.version') ?? $configResponse->json('version'),
            'session_token' => $sessionToken,
        ];
    }

    private function request(GlpiIntegration $integration): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->connectTimeout(4)
            ->timeout(12)
            ->withHeaders(array_filter(['App-Token' => $integration->app_token]));
    }

    private function endpoint(GlpiIntegration $integration, string $path): string
    {
        return rtrim($integration->base_url, '/').'/'.ltrim($path, '/');
    }

    private function errorMessage(mixed $payload, int $status): string
    {
        if (is_array($payload)) {
            $message = $payload['message'] ?? $payload[1] ?? null;

            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        return "GLPI a répondu avec le code HTTP {$status}.";
    }
}
