<?php

namespace App\Services;

use App\Models\GmailIntegration;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class GmailClient
{
    private const AUTHORIZATION_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const API_URL = 'https://gmail.googleapis.com/gmail/v1/users/me';

    public function authorizationUrl(string $state): string
    {
        $this->ensureConfigured();

        $parameters = [
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config('services.google.redirect_uri'),
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/gmail.readonly',
            'access_type' => 'offline',
            'prompt' => 'select_account consent',
            'state' => $state,
        ];

        if (config('services.google.gmail_account')) {
            $parameters['login_hint'] = config('services.google.gmail_account');
        }

        return self::AUTHORIZATION_URL.'?'.http_build_query($parameters);
    }

    /** @return array{access_token: string, refresh_token?: string, expires_in: int} */
    public function exchangeCode(string $code): array
    {
        $this->ensureConfigured();

        return Http::asForm()->timeout(20)->post(self::TOKEN_URL, [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => config('services.google.redirect_uri'),
            'grant_type' => 'authorization_code',
            'code' => $code,
        ])->throw()->json();
    }

    /** @return array{emailAddress: string} */
    public function profile(string $accessToken): array
    {
        return $this->request($accessToken)->get(self::API_URL.'/profile')->throw()->json();
    }

    public function findLabelId(string $accessToken, string $labelName): ?string
    {
        $labels = $this->request($accessToken)->get(self::API_URL.'/labels')->throw()->collect('labels');
        $expectedName = mb_strtolower(trim($labelName));
        $label = $labels->first(function (array $label) use ($expectedName): bool {
            $actualName = mb_strtolower(trim((string) ($label['name'] ?? '')));
            $leafName = mb_strtolower(trim((string) str($actualName)->afterLast('/')));

            return $actualName === $expectedName || $leafName === $expectedName;
        });

        return $label['id'] ?? null;
    }

    /**
     * @return \Generator<int, array<int, array<string, mixed>>>
     */
    public function messagePages(GmailIntegration $integration): \Generator
    {
        $accessToken = $this->validAccessToken($integration);
        $labelId = $integration->label_id ?: $this->findLabelId($accessToken, $integration->label_name);

        if ($labelId && $labelId !== $integration->label_id) {
            $integration->update(['label_id' => $labelId]);
        }

        $pageToken = null;

        do {
            $parameters = ['maxResults' => 500];

            if ($labelId) {
                $parameters['labelIds'] = $labelId;
            } else {
                $parameters['q'] = 'label:'.$integration->label_name;
            }

            if ($pageToken) {
                $parameters['pageToken'] = $pageToken;
            }

            $page = $this->request($accessToken)
                ->get(self::API_URL.'/messages', $parameters)
                ->throw()
                ->json();
            $messageReferences = $page['messages'] ?? [];
            $existingMessageIds = $integration->messages()
                ->whereIn('gmail_message_id', collect($messageReferences)->pluck('id'))
                ->pluck('gmail_message_id')
                ->flip();
            $messageReferences = collect($messageReferences)
                ->reject(fn (array $reference): bool => $existingMessageIds->has($reference['id']))
                ->values()
                ->all();
            foreach (array_chunk($messageReferences, 5) as $referenceChunk) {
                $messages = [];
                $responses = Http::pool(fn (Pool $pool): array => collect($referenceChunk)
                    ->mapWithKeys(fn (array $reference): array => [
                        $reference['id'] => $pool->as($reference['id'])
                            ->withToken($accessToken)
                            ->acceptJson()
                            ->connectTimeout(5)
                            ->timeout(20)
                            ->retry([1000, 2000, 5000], when: $this->shouldRetry(...))
                            ->get(self::API_URL.'/messages/'.$reference['id'], ['format' => 'metadata']),
                    ])->all());

                foreach ($responses as $response) {
                    if ($response instanceof Throwable) {
                        throw $response;
                    }

                    $messages[] = $response->throw()->json();
                }

                if (! app()->runningUnitTests()) {
                    usleep(300000);
                }

                yield $messages;
            }
            $pageToken = $page['nextPageToken'] ?? null;
        } while ($pageToken);
    }

    public function validAccessToken(GmailIntegration $integration): string
    {
        if ($integration->token_expires_at?->greaterThan(now()->addMinute())) {
            return $integration->access_token;
        }

        $tokens = Http::asForm()->timeout(20)->post(self::TOKEN_URL, [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $integration->refresh_token,
        ])->throw()->json();

        $integration->update([
            'access_token' => $tokens['access_token'],
            'token_expires_at' => CarbonImmutable::now()->addSeconds((int) $tokens['expires_in']),
        ]);

        return $tokens['access_token'];
    }

    private function request(string $accessToken): PendingRequest
    {
        return Http::withToken($accessToken)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(20)
            ->retry([1000, 2000, 5000], when: $this->shouldRetry(...));
    }

    private function shouldRetry(Throwable $exception): bool
    {
        return $exception instanceof ConnectionException
            || ($exception instanceof RequestException
                && ($exception->response->serverError()
                    || in_array($exception->response->status(), [403, 429], true)));
    }

    private function ensureConfigured(): void
    {
        if (! config('services.google.client_id') || ! config('services.google.client_secret') || ! config('services.google.redirect_uri')) {
            throw new RuntimeException('La connexion Google OAuth n’est pas configurée sur le serveur.');
        }
    }
}
