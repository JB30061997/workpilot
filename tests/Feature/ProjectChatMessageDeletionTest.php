<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectChatAttachment;
use App\Models\ProjectChatMessage;
use App\Models\ProjectChatRead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProjectChatMessageDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_sender_can_delete_an_unread_message_and_its_attachment(): void
    {
        Storage::fake('local');
        [$sender, , $project, $message] = $this->context();
        $path = "project-chats/{$project->id}/message.txt";
        Storage::disk('local')->put($path, 'content');
        ProjectChatAttachment::query()->create([
            'message_id' => $message->id,
            'original_name' => 'message.txt',
            'disk' => 'local',
            'path' => $path,
            'mime_type' => 'text/plain',
            'size' => 7,
        ]);

        $this->actingAs($sender)
            ->deleteJson(route('project-chat.destroy', [$project, $message]))
            ->assertOk()
            ->assertJsonPath('message', 'Message supprimé.');

        $this->assertSoftDeleted($message);
        $this->assertDatabaseMissing('project_chat_attachments', ['message_id' => $message->id]);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_sender_cannot_delete_a_message_read_by_another_member(): void
    {
        [$sender, $reader, $project, $message] = $this->context();
        ProjectChatRead::query()->create([
            'project_id' => $project->id,
            'user_id' => $reader->id,
            'last_read_message_id' => $message->id,
            'last_read_at' => now(),
        ]);

        $this->actingAs($sender)
            ->deleteJson(route('project-chat.destroy', [$project, $message]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('message');

        $this->assertDatabaseHas('project_chat_messages', ['id' => $message->id, 'deleted_at' => null]);
    }

    public function test_another_member_cannot_delete_the_message(): void
    {
        [, $reader, $project, $message] = $this->context();

        $this->actingAs($reader)
            ->deleteJson(route('project-chat.destroy', [$project, $message]))
            ->assertForbidden();

        $this->assertDatabaseHas('project_chat_messages', ['id' => $message->id, 'deleted_at' => null]);
    }

    /** @return array{User, User, Project, ProjectChatMessage} */
    private function context(): array
    {
        $sender = User::factory()->create();
        $reader = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => 'Chat Organization',
            'slug' => 'chat-organization',
        ]);
        $project = Project::query()->create([
            'organization_id' => $organization->id,
            'created_by' => $sender->id,
            'name' => 'Chat Project',
        ]);
        $project->members()->attach([
            $sender->id => ['role' => 'member', 'joined_at' => now()],
            $reader->id => ['role' => 'member', 'joined_at' => now()],
        ]);
        $message = ProjectChatMessage::query()->create([
            'project_id' => $project->id,
            'user_id' => $sender->id,
            'body' => 'Message non lu',
        ]);

        return [$sender, $reader, $project, $message];
    }
}
