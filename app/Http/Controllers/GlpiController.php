<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateGlpiIntegrationRequest;
use App\Models\Department;
use App\Models\GlpiIntegration;
use App\Models\GmailIntegration;
use App\Models\GmailMessage;
use App\Models\Organization;
use App\Services\GlpiClient;
use App\Services\GlpiTicketAnalysisService;
use App\Support\CurrentDepartmentAuthorizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class GlpiController extends Controller
{
    public function index(
        Request $request,
        CurrentDepartmentAuthorizer $authorizer,
        GlpiTicketAnalysisService $analysisService,
    ): Response {
        $context = $this->context($request);

        $integration = $this->integration($context);

        $gmailIntegration = GmailIntegration::query()
            ->where('organization_id', $context['organization']->id)
            ->where('department_id', $context['department']->id)
            ->first();

        // Filtres des messages Gmail
        $ticketFilters = $request->validate([
            'ticket_search' => ['nullable', 'string', 'max:255'],
            'ticket_type' => [
                'nullable',
                Rule::in(['new', 'updated', 'solved', 'closed']),
            ],
        ]);

        $gmailMessagesQuery = $gmailIntegration
            ? GmailMessage::query()
                ->where('gmail_integration_id', $gmailIntegration->id)
            : null;

        $filteredMessagesQuery = $gmailMessagesQuery?->clone();

        if ($filteredMessagesQuery) {
            $filteredMessagesQuery
                ->when(
                    filled($ticketFilters['ticket_search'] ?? null),
                    function ($query) use ($ticketFilters): void {
                        $search = trim($ticketFilters['ticket_search']);

                        $query->where(function ($subQuery) use ($search): void {
                            $subQuery
                                ->where('subject', 'like', '%'.$search.'%')
                                ->orWhere('ticket_number', 'like', '%'.$search.'%')
                                ->orWhere('sender', 'like', '%'.$search.'%')
                                ->orWhere('sender_email', 'like', '%'.$search.'%');
                        });
                    }
                )
                ->when(
                    filled($ticketFilters['ticket_type'] ?? null),
                    fn ($query) => $query->where(
                        'event_type',
                        $ticketFilters['ticket_type']
                    )
                );
        }

        // Analyse automatique des tickets GLPI
        // Classification basée uniquement sur le subject.
        // Regroupement par numéro de ticket.
        $ticketAnalysis = $gmailIntegration
            ? $analysisService->analyze($gmailIntegration->id)
            : [
                'total' => 0,
                'domains' => [
                    'infrastructure' => [
                        'total' => 0,
                        'statuses' => [],
                        'categories' => [],
                    ],
                    'solutions' => [
                        'total' => 0,
                        'statuses' => [],
                        'categories' => [],
                    ],
                    'unknown' => [
                        'total' => 0,
                        'statuses' => [],
                        'categories' => [],
                    ],
                ],
                'tickets' => [],
            ];

        return Inertia::render('Glpi/Index', [

            // Configuration GLPI
            'integration' => $integration ? [
                'base_url' => $integration->base_url,
                'api_version' => $integration->api_version,
                'entity_id' => $integration->entity_id,
                'is_active' => $integration->is_active,
                'has_app_token' => filled($integration->app_token),
                'has_user_token' => filled($integration->user_token),
                'last_tested_at' => $integration->last_tested_at?->toIso8601String(),
                'last_test_status' => $integration->last_test_status,
                'last_test_message' => $integration->last_test_message,
            ] : null,

            // Permissions
            'canConfigure' => $authorizer->canManageAccess(
                $request->user(),
                $context['organization']->id,
                $context['department']->id,
            ),

            'canManageGmail' => $authorizer->canManageGmail(
                $request->user(),
                $context['organization']->id,
                $context['department']->id,
            ),

            // Intégration Gmail
            'gmailIntegration' => $gmailIntegration ? [
                'email' => $gmailIntegration->email,
                'label_name' => $gmailIntegration->label_name,
                'last_synced_at' => $gmailIntegration->last_synced_at?->toIso8601String(),
                'last_sync_status' => $gmailIntegration->last_sync_status,
                'last_sync_message' => $gmailIntegration->last_sync_message,
            ] : null,

            // Liste des messages Gmail
            'gmailMessages' => $gmailIntegration
                ? $filteredMessagesQuery
                    ->latest('received_at')
                    ->latest('id')
                    ->paginate(25, pageName: 'tickets_page')
                    ->withQueryString()
                : null,

            // Filtres
            'ticketFilters' => $ticketFilters,

            // Statistiques Gmail existantes
            'gmailStats' => $gmailMessagesQuery ? [
                'total' => $gmailMessagesQuery->clone()->count(),
                'solved' => $gmailMessagesQuery->clone()
                    ->where('event_type', 'solved')
                    ->count(),
                'unread' => $gmailMessagesQuery->clone()
                    ->where('is_unread', true)
                    ->count(),
            ] : [
                'total' => 0,
                'solved' => 0,
                'unread' => 0,
            ],

            // Nouvelles statistiques GLPI
            'ticketAnalysis' => $ticketAnalysis,
        ]);
    }

    public function update(
        UpdateGlpiIntegrationRequest $request,
        CurrentDepartmentAuthorizer $authorizer,
    ): RedirectResponse {
        $context = $this->context($request);

        $authorizer->authorizeAccessManagement(
            $request->user(),
            $context['organization']->id,
            $context['department']->id,
        );

        $integration = $this->integration($context);
        $data = $request->validated();

        if (! filled($data['app_token'] ?? null)) {
            unset($data['app_token']);
        }

        if (! filled($data['user_token'] ?? null)) {
            unset($data['user_token']);
        }

        if (! $integration && ! isset($data['user_token'])) {
            throw ValidationException::withMessages([
                'user_token' => 'Le User Token est obligatoire pour la première configuration.',
            ]);
        }

        GlpiIntegration::query()->updateOrCreate(
            [
                'organization_id' => $context['organization']->id,
                'department_id' => $context['department']->id,
            ],
            $data,
        );

        return back()->with(
            'success',
            'La configuration GLPI a été enregistrée en toute sécurité.'
        );
    }

    public function testConnection(
        Request $request,
        CurrentDepartmentAuthorizer $authorizer,
        GlpiClient $client,
    ): RedirectResponse {
        $context = $this->context($request);

        $authorizer->authorizeAccessManagement(
            $request->user(),
            $context['organization']->id,
            $context['department']->id,
        );

        $integration = $this->integration($context);

        abort_unless($integration, 404);

        try {
            $result = $client->test($integration);

            $message = 'Connexion GLPI réussie'
                .($result['version']
                    ? ' — version '.$result['version']
                    : '')
                .'.';

            $integration->update([
                'last_tested_at' => now(),
                'last_test_status' => 'success',
                'last_test_message' => $message,
            ]);

            return back()->with('success', $message);

        } catch (ConnectionException $exception) {
            $message = 'Le serveur GLPI est inaccessible. Vérifiez l’URL et le réseau.';

        } catch (Throwable $exception) {
            report($exception);

            $message = 'Connexion GLPI refusée : '
                .$exception->getMessage();
        }

        $integration->update([
            'last_tested_at' => now(),
            'last_test_status' => 'error',
            'last_test_message' => $message,
        ]);

        return back()->with('error', $message);
    }

    /**
     * @return array{
     *     organization: Organization,
     *     department: Department
     * }
     */
    private function context(Request $request): array
    {
        $organizationId = (int) session('current_organization_id');
        $departmentId = (int) session('current_department_id');

        $organization = $request->user()
            ->organizations()
            ->wherePivot('is_active', true)
            ->findOrFail($organizationId);

        $department = $request->user()
            ->departments()
            ->wherePivot('is_active', true)
            ->where('departments.organization_id', $organization->id)
            ->findOrFail($departmentId);

        return [
            'organization' => $organization,
            'department' => $department,
        ];
    }

    /**
     * @param array{
     *     organization: Organization,
     *     department: Department
     * } $context
     */
    private function integration(array $context): ?GlpiIntegration
    {
        return GlpiIntegration::query()
            ->where('organization_id', $context['organization']->id)
            ->where('department_id', $context['department']->id)
            ->first();
    }
}
