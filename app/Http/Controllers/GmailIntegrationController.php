<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\GmailIntegration;
use App\Models\GmailMessage;
use App\Models\Organization;
use App\Services\GmailClient;
use App\Support\CurrentDepartmentAuthorizer;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class GmailIntegrationController extends Controller
{
    public function connect(
        Request $request,
        CurrentDepartmentAuthorizer $authorizer,
        GmailClient $client,
    ): RedirectResponse {
        $context = $this->context($request);
        $authorizer->authorizeGmailManagement(
            $request->user(),
            $context['organization']->id,
            $context['department']->id,
        );

        $state = Str::random(64);
        $request->session()->put('gmail_oauth', [
            'state' => $state,
            'organization_id' => $context['organization']->id,
            'department_id' => $context['department']->id,
        ]);

        return redirect()->away($client->authorizationUrl($state));
    }

    public function callback(
        Request $request,
        CurrentDepartmentAuthorizer $authorizer,
        GmailClient $client,
    ): RedirectResponse {
        $oauthSession = $request->session()->pull('gmail_oauth');

        abort_unless(
            is_array($oauthSession)
            && hash_equals((string) ($oauthSession['state'] ?? ''), $request->string('state')->toString()),
            419,
            'La session Google OAuth a expiré.',
        );

        $organization = $request->user()->organizations()->wherePivot('is_active', true)
            ->findOrFail((int) $oauthSession['organization_id']);
        $department = $request->user()->departments()->wherePivot('is_active', true)
            ->where('departments.organization_id', $organization->id)
            ->findOrFail((int) $oauthSession['department_id']);
        $authorizer->authorizeGmailManagement($request->user(), $organization->id, $department->id);

        if ($request->filled('error')) {
            return redirect()->route('glpi.index', ['tab' => 'tickets'])->with('error', 'Connexion Gmail annulée ou refusée.');
        }

        $request->validate(['code' => ['required', 'string']]);

        try {
            $tokens = $client->exchangeCode($request->string('code')->toString());
            $profile = $client->profile($tokens['access_token']);
            $expectedEmail = config('services.google.gmail_account');

            if ($expectedEmail && strcasecmp($profile['emailAddress'], $expectedEmail) !== 0) {
                throw new \RuntimeException("Sélectionnez le compte Gmail {$expectedEmail}, pas {$profile['emailAddress']}.");
            }

            $labelId = $client->findLabelId($tokens['access_token'], 'GLPI_Tickets');
            $existing = GmailIntegration::query()
                ->where('organization_id', $organization->id)
                ->where('department_id', $department->id)
                ->first();

            if (! isset($tokens['refresh_token']) && ! $existing?->refresh_token) {
                throw new \RuntimeException('Google n’a pas fourni de refresh token. Reconnectez la boîte en acceptant les permissions.');
            }

            GmailIntegration::query()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'department_id' => $department->id,
                ],
                [
                    'email' => $profile['emailAddress'],
                    'access_token' => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token'] ?? $existing?->refresh_token,
                    'token_expires_at' => now()->addSeconds((int) $tokens['expires_in']),
                    'label_name' => 'GLPI_Tickets',
                    'label_id' => $labelId,
                    'is_active' => true,
                    'last_sync_status' => null,
                    'last_sync_message' => null,
                ],
            );
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('glpi.index', ['tab' => 'tickets'])->with(
                'error',
                'Connexion Gmail impossible : '.$exception->getMessage(),
            );
        }

        return redirect()->route('glpi.index', ['tab' => 'tickets'])->with('success', 'Boîte Gmail connectée avec succès.');
    }

    public function sync(
        Request $request,
        CurrentDepartmentAuthorizer $authorizer,
        GmailClient $client,
    ): RedirectResponse {
        $context = $this->context($request);
        $authorizer->authorizeGmailManagement(
            $request->user(),
            $context['organization']->id,
            $context['department']->id,
        );
        $integration = GmailIntegration::query()
            ->where('organization_id', $context['organization']->id)
            ->where('department_id', $context['department']->id)
            ->firstOrFail();

        try {
            set_time_limit(0);
            $integration->update([
                'last_sync_status' => 'syncing',
                'last_sync_message' => 'Synchronisation complète en cours…',
            ]);
            $synchronizedCount = 0;

            foreach ($client->messagePages($integration) as $messages) {
                DB::transaction(function () use ($integration, $messages): void {
                    foreach ($messages as $message) {
                        $this->storeMessage($integration, $message);
                    }
                });

                $synchronizedCount += count($messages);
                $integration->update([
                    'last_sync_message' => $synchronizedCount.' messages GLPI synchronisés…',
                ]);
            }

            $integration->update([
                'last_synced_at' => now(),
                'last_sync_status' => 'success',
                'last_sync_message' => $synchronizedCount.' messages GLPI synchronisés.',
            ]);
        } catch (Throwable $exception) {
            report($exception);
            $message = $exception instanceof RequestException
                && str_contains($exception->getMessage(), 'Quota exceeded')
                    ? 'Le quota Gmail est temporairement atteint. Les messages déjà importés sont conservés ; relancez la synchronisation dans une minute.'
                    : 'Synchronisation Gmail impossible : '.$exception->getMessage();
            $integration->update([
                'last_sync_status' => 'error',
                'last_sync_message' => $message,
            ]);

            return back()->with('error', $message);
        }

        return back()->with('success', $synchronizedCount.' messages GLPI synchronisés.');
    }

    public function disconnect(
        Request $request,
        CurrentDepartmentAuthorizer $authorizer,
    ): RedirectResponse {
        $context = $this->context($request);
        $authorizer->authorizeGmailManagement(
            $request->user(),
            $context['organization']->id,
            $context['department']->id,
        );

        GmailIntegration::query()
            ->where('organization_id', $context['organization']->id)
            ->where('department_id', $context['department']->id)
            ->delete();

        return back()->with('success', 'Boîte Gmail déconnectée.');
    }

    private function eventType(string $subject): string
    {
        $subject = mb_strtolower($subject);

        return match (true) {
            str_contains($subject, 'solved'), str_contains($subject, 'résolu') => 'solved',
            str_contains($subject, 'closing'), str_contains($subject, 'closed'), str_contains($subject, 'clôtur') => 'closed',
            str_contains($subject, 'update') => 'updated',
            default => 'new',
        };
    }

    /** @param array<string, mixed> $message */
    private function storeMessage(GmailIntegration $integration, array $message): void
    {
        $headers = collect($message['payload']['headers'] ?? [])
            ->mapWithKeys(fn (array $header): array => [mb_strtolower($header['name']) => $header['value']]);
        $subject = (string) $headers->get('subject', '(Sans objet)');
        $from = (string) $headers->get('from', '');
        preg_match('/<([^>]+)>/', $from, $emailMatch);
        preg_match('/\[GLPI\s*#?(\d+)\]/i', $subject, $ticketMatch);

        GmailMessage::query()->updateOrCreate(
            [
                'gmail_integration_id' => $integration->id,
                'gmail_message_id' => $message['id'],
            ],
            [
                'thread_id' => $message['threadId'] ?? null,
                'ticket_number' => $ticketMatch[1] ?? null,
                'event_type' => $this->eventType($subject),
                'sender' => trim(preg_replace('/<[^>]+>/', '', $from), " \t\n\r\0\x0B\""),
                'sender_email' => $emailMatch[1] ?? $from,
                'subject' => $subject,
                'snippet' => $message['snippet'] ?? null,
                'received_at' => isset($message['internalDate'])
                    ? CarbonImmutable::createFromTimestampMs((int) $message['internalDate'])
                    : null,
                'is_unread' => in_array('UNREAD', $message['labelIds'] ?? [], true),
            ],
        );
    }

    /** @return array{organization: Organization, department: Department} */
    private function context(Request $request): array
    {
        $organization = $request->user()->organizations()->wherePivot('is_active', true)
            ->findOrFail((int) session('current_organization_id'));
        $department = $request->user()->departments()->wherePivot('is_active', true)
            ->where('departments.organization_id', $organization->id)
            ->findOrFail((int) session('current_department_id'));

        return ['organization' => $organization, 'department' => $department];
    }
}
