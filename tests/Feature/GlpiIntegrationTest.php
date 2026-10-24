<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\GlpiIntegration;
use App\Models\GmailIntegration;
use App\Models\GmailMessage;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GlpiIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_member_can_open_glpi_tracking_page(): void
    {
        [$user, $organization, $department] = $this->context();

        $this->actingAs($user)->withSession($this->contextSession($organization, $department))
            ->get(route('glpi.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Glpi/Index')
                ->where('integration', null)
                ->where('canConfigure', false)
                ->where('canManageGmail', true));
    }

    public function test_authorized_manager_can_store_encrypted_glpi_credentials(): void
    {
        [$user, $organization, $department] = $this->context(true);

        $this->actingAs($user)->withSession($this->contextSession($organization, $department))
            ->put(route('glpi.update'), [
                'base_url' => 'https://glpi.example.test/apirest.php', 'api_version' => 'v1',
                'app_token' => 'app-secret', 'user_token' => 'user-secret',
                'entity_id' => 12, 'is_active' => true,
            ])->assertSessionHasNoErrors()->assertRedirect();

        $integration = GlpiIntegration::query()->firstOrFail();
        $this->assertSame('app-secret', $integration->app_token);
        $this->assertSame('user-secret', $integration->user_token);
        $this->assertNotSame('app-secret', $integration->getRawOriginal('app_token'));
        $this->assertSame($organization->id, $integration->organization_id);
        $this->assertSame($department->id, $integration->department_id);
    }

    public function test_ordinary_member_cannot_change_glpi_credentials(): void
    {
        [$user, $organization, $department] = $this->context();

        $this->actingAs($user)->withSession($this->contextSession($organization, $department))
            ->put(route('glpi.update'), [
                'base_url' => 'https://glpi.example.test/apirest.php', 'api_version' => 'v1',
                'app_token' => 'app-secret', 'user_token' => 'user-secret', 'is_active' => true,
            ])->assertForbidden();
    }

    public function test_connection_can_be_verified(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://glpi.example.test/apirest.php/initSession' => Http::response(['session_token' => 'session-secret']),
            'https://glpi.example.test/apirest.php/getGlpiConfig' => Http::response(['cfg_glpi' => ['version' => '10.0.18']]),
            'https://glpi.example.test/apirest.php/killSession' => Http::response([]),
        ]);
        [$user, $organization, $department] = $this->context(true);
        GlpiIntegration::query()->create([
            'organization_id' => $organization->id, 'department_id' => $department->id,
            'base_url' => 'https://glpi.example.test/apirest.php', 'api_version' => 'v1',
            'app_token' => 'app-secret', 'user_token' => 'user-secret',
        ]);

        $this->actingAs($user)->withSession($this->contextSession($organization, $department))
            ->post(route('glpi.test'))
            ->assertSessionHas('success', 'Connexion GLPI réussie — version 10.0.18.');

        $this->assertDatabaseHas('glpi_integrations', ['last_test_status' => 'success']);
        Http::assertSentCount(3);
    }

    public function test_active_department_member_can_start_google_oauth_connection(): void
    {
        config([
            'services.google.client_id' => 'client-id',
            'services.google.client_secret' => 'client-secret',
            'services.google.redirect_uri' => 'http://localhost/glpi/google/callback',
            'services.google.gmail_account' => 'support@example.test',
        ]);
        [$user, $organization, $department] = $this->context();

        $response = $this->actingAs($user)
            ->withSession($this->contextSession($organization, $department))
            ->get(route('glpi.gmail.connect'));

        $response->assertRedirectContains('https://accounts.google.com/o/oauth2/v2/auth');
        $response->assertSessionHas('gmail_oauth.organization_id', $organization->id);
        $response->assertSessionHas('gmail_oauth.department_id', $department->id);
    }

    public function test_google_callback_stores_encrypted_tokens_for_glpi_tickets_label(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'google-access-token',
                'refresh_token' => 'google-refresh-token',
                'expires_in' => 3600,
            ]),
            'https://gmail.googleapis.com/gmail/v1/users/me/profile' => Http::response([
                'emailAddress' => 'support@example.test',
            ]),
            'https://gmail.googleapis.com/gmail/v1/users/me/labels' => Http::response([
                'labels' => [
                    ['id' => 'Label_42', 'name' => 'Support/GLPI_Tickets'],
                    ['id' => 'INBOX', 'name' => 'INBOX'],
                ],
            ]),
        ]);
        config([
            'services.google.client_id' => 'client-id',
            'services.google.client_secret' => 'client-secret',
            'services.google.redirect_uri' => 'http://localhost/glpi/google/callback',
            'services.google.gmail_account' => 'support@example.test',
        ]);
        [$user, $organization, $department] = $this->context(true);

        $this->actingAs($user)
            ->withSession([
                ...$this->contextSession($organization, $department),
                'gmail_oauth' => [
                    'state' => 'known-state',
                    'organization_id' => $organization->id,
                    'department_id' => $department->id,
                ],
            ])
            ->get(route('glpi.gmail.callback', ['state' => 'known-state', 'code' => 'authorization-code']))
            ->assertRedirect(route('glpi.index', ['tab' => 'tickets']))
            ->assertSessionHas('success');

        $integration = GmailIntegration::query()->firstOrFail();
        $this->assertSame('support@example.test', $integration->email);
        $this->assertSame('Label_42', $integration->label_id);
        $this->assertSame('google-access-token', $integration->access_token);
        $this->assertNotSame('google-access-token', $integration->getRawOriginal('access_token'));
        $this->assertNotSame('google-refresh-token', $integration->getRawOriginal('refresh_token'));
        Http::assertSentCount(3);
    }

    public function test_sync_imports_messages_from_the_configured_glpi_label(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::sequence()
                ->push([
                    'messages' => [['id' => 'gmail-message-1', 'threadId' => 'thread-1']],
                    'nextPageToken' => 'next-page',
                ])
                ->push([
                    'messages' => [['id' => 'gmail-message-2', 'threadId' => 'thread-2']],
                ]),
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/gmail-message-1?*' => Http::sequence()
                ->push(['error' => ['message' => 'Quota exceeded']], 403)
                ->push([
                    'id' => 'gmail-message-1',
                    'threadId' => 'thread-1',
                    'labelIds' => ['Label_42', 'UNREAD'],
                    'internalDate' => '1792576800000',
                    'snippet' => 'Aperçu du ticket GLPI.',
                    'payload' => ['headers' => [
                        ['name' => 'From', 'value' => 'Support IT <support@example.test>'],
                        ['name' => 'Subject', 'value' => '[GLPI #0017968] Ticket Solved Création adresse mail'],
                    ]],
                ]),
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/gmail-message-2?*' => Http::response([
                'id' => 'gmail-message-2',
                'threadId' => 'thread-2',
                'labelIds' => ['Label_42'],
                'internalDate' => '1792490400000',
                'snippet' => 'Deuxième page Gmail.',
                'payload' => ['headers' => [
                    ['name' => 'From', 'value' => 'Support IT <support@example.test>'],
                    ['name' => 'Subject', 'value' => '[GLPI #0017967] New Ticket Scanner'],
                ]],
            ]),
        ]);
        config([
            'services.google.client_id' => 'client-id',
            'services.google.client_secret' => 'client-secret',
            'services.google.redirect_uri' => 'http://localhost/glpi/google/callback',
            'services.google.gmail_account' => 'support@example.test',
        ]);
        [$user, $organization, $department] = $this->context(true);
        $integration = GmailIntegration::query()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'email' => 'support@example.test',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
            'label_name' => 'GLPI_Tickets',
            'label_id' => 'Label_42',
        ]);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $department))
            ->post(route('glpi.gmail.sync'))
            ->assertRedirect()
            ->assertSessionHas('success', '2 messages GLPI synchronisés.');

        $message = GmailMessage::query()->where('gmail_message_id', 'gmail-message-1')->firstOrFail();
        $this->assertSame($integration->id, $message->gmail_integration_id);
        $this->assertSame('0017968', $message->ticket_number);
        $this->assertSame('solved', $message->event_type);
        $this->assertTrue($message->is_unread);
        $this->assertDatabaseHas('gmail_messages', [
            'gmail_integration_id' => $integration->id,
            'gmail_message_id' => 'gmail-message-2',
            'ticket_number' => '0017967',
        ]);
        Http::assertSentCount(5);
    }

    public function test_glpi_ticket_messages_are_paginated(): void
    {
        [$user, $organization, $department] = $this->context();
        $integration = GmailIntegration::query()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'email' => 'support@example.test',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
            'label_name' => 'GLPI_Tickets',
            'label_id' => 'Label_42',
        ]);

        foreach (range(1, 30) as $number) {
            GmailMessage::query()->create([
                'gmail_integration_id' => $integration->id,
                'gmail_message_id' => 'message-'.$number,
                'ticket_number' => str_pad((string) $number, 7, '0', STR_PAD_LEFT),
                'event_type' => 'new',
                'subject' => 'Ticket '.$number,
                'received_at' => now()->subMinutes($number),
            ]);
        }

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $department))
            ->get(route('glpi.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Glpi/Index')
                ->has('gmailMessages.data', 25)
                ->where('gmailMessages.total', 30)
                ->where('gmailMessages.per_page', 25)
                ->where('gmailStats.total', 30));
    }

    public function test_sync_uses_gmail_search_when_the_label_id_is_not_returned(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/labels' => Http::response([
                'labels' => [['id' => 'INBOX', 'name' => 'INBOX']],
            ]),
            'https://gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response([
                'messages' => [],
            ]),
        ]);
        config([
            'services.google.client_id' => 'client-id',
            'services.google.client_secret' => 'client-secret',
            'services.google.redirect_uri' => 'http://localhost/glpi/google/callback',
            'services.google.gmail_account' => 'support@example.test',
        ]);
        [$user, $organization, $department] = $this->context(true);
        GmailIntegration::query()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'email' => 'support@example.test',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
            'label_name' => 'GLPI_Tickets',
            'label_id' => null,
        ]);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $department))
            ->post(route('glpi.gmail.sync'))
            ->assertSessionHas('success', '0 messages GLPI synchronisés.');

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/messages?')) {
                return false;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['q'] ?? null) === 'label:GLPI_Tickets';
        });
    }

    /** @return array{User, Organization, Department} */
    private function context(bool $manager = false): array
    {
        $user = User::factory()->create();
        $organization = Organization::query()->create(['name' => 'WorkPilot', 'slug' => 'workpilot']);
        $department = Department::query()->create(['organization_id' => $organization->id, 'name' => 'IT']);
        $user->organizations()->attach($organization, ['status' => 'active', 'is_active' => true]);
        $user->departments()->attach($department, ['is_active' => true, 'is_manager' => $manager]);

        if ($manager) {
            $role = Role::query()->create(['organization_id' => $organization->id, 'name' => 'Administrateur', 'slug' => 'administrator']);
            $user->roles()->attach($role, ['organization_id' => $organization->id]);
        }

        return [$user, $organization, $department];
    }

    /** @return array{current_organization_id: int, current_department_id: int} */
    private function contextSession(Organization $organization, Department $department): array
    {
        return ['current_organization_id' => $organization->id, 'current_department_id' => $department->id];
    }
}
