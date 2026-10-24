<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectChatAttachment;
use App\Models\ProjectChatMessage;
use App\Models\ProjectChatRead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectChatController extends Controller
{
    /**
     * Liste des projets accessibles à l'utilisateur.
     */
    private function accessibleProjectIds(Request $request)
    {
        return DB::table('project_members')
            ->where('user_id', $request->user()->id)
            ->select('project_id');
    }

    /**
     * Vérifier que l'utilisateur appartient au projet.
     */
    private function authorizeProject(
        Request $request,
        Project $project
    ): void {
        $allowed = DB::table('project_members')
            ->where('project_id', $project->id)
            ->where('user_id', $request->user()->id)
            ->exists();

        abort_unless(
            $allowed,
            403,
            'Vous ne faites pas partie de ce projet.'
        );
    }

    /**
     * Projets autorisés, avec statistiques de discussion.
     */
    private function accessibleProjectsWithStats(Request $request)
    {
        $projects = Project::query()
            ->whereIn('id', $this->accessibleProjectIds($request))
            ->get(['id', 'name', 'code', 'status', 'created_at']);

        return $this->addChatStats($projects, (int) $request->user()->id);
    }

    /**
     * Page principale des discussions.
     */
    public function index(Request $request)
    {
        return Inertia::render('ProjectChat/Index', [
            'projects' => $this->accessibleProjectsWithStats($request),
            'currentUserId' => $request->user()->id,
        ]);
    }

    /**
     * Actualiser les groupes et les compteurs sans recharger la page.
     */
    public function groups(Request $request)
    {
        $projects = Project::query()
            ->whereIn(
                'id',
                $this->accessibleProjectIds($request)
            )
            ->get([
                'id',
                'name',
                'code',
                'status',
                'created_at',
            ]);

        return response()->json([
            'projects' => $this->addChatStats(
                $projects,
                (int) $request->user()->id
            ),
        ]);
    }

    /**
     * Charger la discussion d'un projet.
     */
    public function show(
        Request $request,
        Project $project
    ) {
        $this->authorizeProject($request, $project);

        // Membres du projet
        $members = DB::table('project_members')
            ->join(
                'users',
                'users.id',
                '=',
                'project_members.user_id'
            )
            ->where(
                'project_members.project_id',
                $project->id
            )
            ->select(
                'users.id',
                'users.name',
                'project_members.role'
            )
            ->orderBy('users.name')
            ->get();

        // Les 100 derniers messages
        $messages = ProjectChatMessage::query()
            ->where('project_id', $project->id)
            ->with([
                'user:id,name',
                'attachments',
            ])
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->reverse()
            ->values();

        // Accusés de lecture
        $reads = ProjectChatRead::query()
            ->where('project_id', $project->id)
            ->get([
                'user_id',
                'last_read_message_id',
                'last_read_at',
            ]);

        return response()->json([
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'code' => $project->code,
            ],
            'members' => $members,
            'messages' => $messages,
            'reads' => $reads,
        ]);
    }

    /**
     * Envoyer un message avec ou sans fichiers.
     */
    public function store(
        Request $request,
        Project $project
    ) {
        $this->authorizeProject($request, $project);

        $validated = $request->validate([
            'body' => [
                'nullable',
                'string',
                'max:50000',
            ],
            'files' => [
                'nullable',
                'array',
                'max:20',
            ],
            'files.*' => [
                'required',
                'file',
                'max:102400',
            ],
            'reply_to_id' => [
                'nullable',
                'integer',
            ],
        ]);

        $body = trim($validated['body'] ?? '');
        $files = $request->file('files', []);

        if ($body === '' && count($files) === 0) {
            throw ValidationException::withMessages([
                'body' => 'Veuillez saisir un message ou ajouter un fichier.',
            ]);
        }

        // Vérifier que le message auquel on répond
        // appartient bien au même projet.
        if (! empty($validated['reply_to_id'])) {
            $replyExists = ProjectChatMessage::query()
                ->where('project_id', $project->id)
                ->whereKey($validated['reply_to_id'])
                ->exists();

            if (! $replyExists) {
                throw ValidationException::withMessages([
                    'reply_to_id' => 'Le message sélectionné est introuvable.',
                ]);
            }
        }

        $storedPaths = [];

        try {
            $message = DB::transaction(function () use (
                $request,
                $project,
                $validated,
                $body,
                $files,
                &$storedPaths
            ) {
                // Création du message
                $message = ProjectChatMessage::create([
                    'project_id' => $project->id,
                    'user_id' => $request->user()->id,
                    'body' => $body !== '' ? $body : null,
                    'reply_to_id' => $validated['reply_to_id'] ?? null,
                ]);

                // Enregistrement des pièces jointes
                foreach ($files as $file) {
                    $path = $file->store(
                        "project-chats/{$project->id}",
                        'local'
                    );

                    if ($path === false) {
                        throw new \RuntimeException(
                            'Impossible de stocker le fichier.'
                        );
                    }

                    $storedPaths[] = $path;

                    $message->attachments()->create([
                        'original_name' => $file->getClientOriginalName(),
                        'disk' => 'local',
                        'path' => $path,
                        'mime_type' => $file->getMimeType(),
                        'size' => $file->getSize(),
                    ]);
                }

                // Le créateur a automatiquement lu son message
                $read = ProjectChatRead::query()
                    ->where('project_id', $project->id)
                    ->where('user_id', $request->user()->id)
                    ->lockForUpdate()
                    ->first();

                if ($read) {
                    if (
                        ($read->last_read_message_id ?? 0)
                        < $message->id
                    ) {
                        $read->update([
                            'last_read_message_id' => $message->id,
                            'last_read_at' => now(),
                        ]);
                    }
                } else {
                    ProjectChatRead::updateOrCreate(
                        [
                            'project_id' => $project->id,
                            'user_id' => $request->user()->id,
                        ],
                        [
                            'last_read_message_id' => $message->id,
                            'last_read_at' => now(),
                        ]
                    );
                }

                return $message;
            });
        } catch (\Throwable $e) {
            // Nettoyage si l'enregistrement échoue
            foreach ($storedPaths as $path) {
                Storage::disk('local')->delete($path);
            }

            throw $e;
        }

        return response()->json(
            $message->load([
                'user:id,name',
                'attachments',
            ]),
            201
        );
    }

    /**
     * Marquer les messages comme lus.
     */
    public function markRead(
        Request $request,
        Project $project
    ) {
        $this->authorizeProject($request, $project);

        $validated = $request->validate([
            'message_id' => [
                'required',
                'integer',
            ],
        ]);

        $message = ProjectChatMessage::query()
            ->where('project_id', $project->id)
            ->findOrFail($validated['message_id']);

        DB::transaction(function () use (
            $request,
            $project,
            $message
        ) {
            $read = ProjectChatRead::query()
                ->where('project_id', $project->id)
                ->where('user_id', $request->user()->id)
                ->lockForUpdate()
                ->first();

            if (! $read) {
                ProjectChatRead::updateOrCreate(
                    [
                        'project_id' => $project->id,
                        'user_id' => $request->user()->id,
                    ],
                    [
                        'last_read_message_id' => $message->id,
                        'last_read_at' => now(),
                    ]
                );

                return;
            }

            // Ne jamais revenir vers un ancien message lu
            if (
                ($read->last_read_message_id ?? 0)
                < $message->id
            ) {
                $read->update([
                    'last_read_message_id' => $message->id,
                    'last_read_at' => now(),
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Messages marqués comme lus.',
        ]);
    }

    /**
     * Télécharger une pièce jointe.
     */
    public function download(
        Request $request,
        ProjectChatAttachment $attachment
    ): StreamedResponse {
        $message = $attachment->message;

        abort_unless(
            $message && ! $message->trashed(),
            404
        );

        $project = Project::findOrFail(
            $message->project_id
        );

        // Sécurité : uniquement les membres du projet
        $this->authorizeProject($request, $project);

        $disk = Storage::disk($attachment->disk);

        abort_unless(
            $disk->exists($attachment->path),
            404,
            'Fichier introuvable.'
        );

        $allowedImageTypes = [
            'image/avif',
            'image/bmp',
            'image/gif',
            'image/jpeg',
            'image/png',
            'image/webp',
        ];

        if (
            $request->boolean('preview')
            && in_array(strtolower((string) $attachment->mime_type), $allowedImageTypes, true)
        ) {
            return $disk->response(
                $attachment->path,
                $attachment->original_name,
                [
                    'Cache-Control' => 'private, max-age=3600',
                    'Content-Type' => $attachment->mime_type,
                    'X-Content-Type-Options' => 'nosniff',
                ],
                'inline'
            );
        }

        return $disk->download(
            $attachment->path,
            $attachment->original_name
        );
    }

    /**
     * Tri par dernier message et compteur non lu pour chaque groupe.
     */
    private function addChatStats($projects, int $userId)
    {
        $projectIds = $projects->pluck('id')->all();

        if (empty($projectIds)) {
            return $projects;
        }

        // Dernier message réel de chaque projet.
        $lastMessageIds = DB::table('project_chat_messages')
            ->whereIn('project_id', $projectIds)
            ->select('project_id', DB::raw('MAX(id) as last_id'))
            ->groupBy('project_id')
            ->pluck('last_id', 'project_id');

        $lastMessages = DB::table('project_chat_messages')
            ->whereIn('id', $lastMessageIds->values()->all())
            ->get(['id', 'project_id', 'created_at'])
            ->keyBy('project_id');

        $unreadCounts = DB::table('project_chat_messages as m')
            ->leftJoin('project_chat_reads as r', function ($join) use ($userId) {
                $join->on('r.project_id', '=', 'm.project_id')
                    ->where('r.user_id', '=', $userId);
            })
            ->whereIn('m.project_id', $projectIds)
            ->where('m.user_id', '<>', $userId)
            ->whereRaw('m.id > COALESCE(r.last_read_message_id, 0)')
            ->groupBy('m.project_id')
            ->select('m.project_id', DB::raw('COUNT(*) as total'))
            ->pluck('total', 'project_id');

        return $projects->map(function ($project) use ($lastMessages, $unreadCounts) {
            $last = $lastMessages->get($project->id);
            $project->last_message_id = $last?->id;
            $project->last_message_at = $last?->created_at;
            $project->unread_count = (int) ($unreadCounts[$project->id] ?? 0);

            return $project;
        })->sort(function ($a, $b) {
            // Les groupes actifs en premier ; sans message, tri par création.
            $aTime = (string) ($a->last_message_at ?? $a->created_at ?? '');
            $bTime = (string) ($b->last_message_at ?? $b->created_at ?? '');

            return strcmp($bTime, $aTime) ?: ($b->id <=> $a->id);
        })->values();
    }
}
