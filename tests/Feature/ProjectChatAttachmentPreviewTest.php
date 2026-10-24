<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectChatAttachment;
use App\Models\ProjectChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProjectChatAttachmentPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_member_can_preview_an_image_inline(): void
    {
        Storage::fake('local');
        [$user, $attachment] = $this->createAttachment('image/png');

        Storage::disk('local')->put($attachment->path, 'image-content');

        $response = $this
            ->actingAs($user)
            ->get(route('project-chat.download', $attachment).'?preview=1');

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertStringStartsWith(
            'inline;',
            (string) $response->headers->get('Content-Disposition')
        );
    }

    public function test_non_image_attachment_cannot_be_rendered_as_a_preview(): void
    {
        Storage::fake('local');
        [$user, $attachment] = $this->createAttachment('application/pdf');

        Storage::disk('local')->put($attachment->path, 'pdf-content');

        $this
            ->actingAs($user)
            ->get(route('project-chat.download', $attachment).'?preview=1')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=preview.pdf');
    }

    /**
     * @return array{User, ProjectChatAttachment}
     */
    private function createAttachment(string $mimeType): array
    {
        $user = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => 'Preview Organization',
            'slug' => 'preview-organization',
        ]);
        $project = Project::query()->create([
            'organization_id' => $organization->id,
            'created_by' => $user->id,
            'name' => 'Preview Project',
        ]);
        $project->members()->attach($user, [
            'role' => 'member',
            'joined_at' => now(),
        ]);
        $message = ProjectChatMessage::query()->create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'body' => null,
        ]);
        $extension = $mimeType === 'image/png' ? 'png' : 'pdf';
        $attachment = ProjectChatAttachment::query()->create([
            'message_id' => $message->id,
            'original_name' => "preview.{$extension}",
            'disk' => 'local',
            'path' => "project-chats/{$project->id}/preview.{$extension}",
            'mime_type' => $mimeType,
            'size' => 13,
        ]);

        return [$user, $attachment];
    }
}
