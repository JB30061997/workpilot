<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    /**
     * Maximum allowed file size in KB.
     *
     * 20 MB = 20 * 1024 KB.
     */
    private const MAX_FILE_SIZE_KB = 20480;

    /**
     * Storage disk used by WorkPilot attachments.
     */
    private const STORAGE_DISK = 'local';

    /**
     * Resolve the active organization of the authenticated user.
     */
    private function getCurrentOrganization(Request $request): Organization
    {
        $organization = $request->user()
            ->organizations()
            ->wherePivot('is_active', true)
            ->when(
                session('current_organization_id'),
                fn ($query, $organizationId) => $query->whereKey($organizationId)
            )
            ->first();

        if (! $organization && session()->has('current_organization_id')) {
            session()->forget([
                'current_organization_id',
                'current_department_id',
            ]);

            return $this->getCurrentOrganization($request);
        }

        abort_if(
            ! $organization,
            403,
            'Aucune société active n’est associée à votre compte.'
        );

        return $organization;
    }

    /**
     * Find an attachment strictly inside the current organization.
     *
     * This prevents users from accessing files belonging
     * to another organization by changing the attachment ID.
     */
    private function findScopedAttachment(
        Request $request,
        int $attachmentId
    ): Attachment {
        $organization = $this->getCurrentOrganization($request);

        return Attachment::query()
            ->whereKey($attachmentId)
            ->where(
                'organization_id',
                $organization->id
            )
            ->firstOrFail();
    }

    /**
     * Display attachments belonging to the current organization.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->getCurrentOrganization($request);

        $validated = $request->validate([
            'attachable_type' => [
                'nullable',
                'string',
                'max:255',
            ],

            'attachable_id' => [
                'nullable',
                'integer',
                'min:1',
            ],

            'uploaded_by' => [
                'nullable',
                'integer',
                'exists:users,id',
            ],

            'search' => [
                'nullable',
                'string',
                'max:255',
            ],

            'per_page' => [
                'nullable',
                'integer',
                'between:1,100',
            ],
        ]);

        $perPage = $validated['per_page'] ?? 25;

        $attachments = Attachment::query()
            ->where(
                'organization_id',
                $organization->id
            )
            ->with([
                'uploader:id,name,email',
            ])

            ->when(
                ! empty($validated['attachable_type']),
                fn ($query) => $query->where(
                    'attachable_type',
                    $validated['attachable_type']
                )
            )

            ->when(
                ! empty($validated['attachable_id']),
                fn ($query) => $query->where(
                    'attachable_id',
                    $validated['attachable_id']
                )
            )

            ->when(
                ! empty($validated['uploaded_by']),
                fn ($query) => $query->where(
                    'uploaded_by',
                    $validated['uploaded_by']
                )
            )

            ->when(
                ! empty($validated['search']),
                function ($query) use ($validated) {
                    $search = trim($validated['search']);

                    $query->where(function ($subQuery) use ($search) {
                        $subQuery
                            ->where(
                                'original_name',
                                'like',
                                '%'.$search.'%'
                            )
                            ->orWhere(
                                'file_name',
                                'like',
                                '%'.$search.'%'
                            );
                    });
                }
            )

            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],

            'attachments' => $attachments,
        ]);
    }

    /**
     * Store a newly uploaded attachment.
     */
    public function store(Request $request): RedirectResponse
    {
        $organization = $this->getCurrentOrganization($request);

        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.self::MAX_FILE_SIZE_KB,
            ],

            'attachable_type' => [
                'required',
                'string',
                'max:255',
            ],

            'attachable_id' => [
                'required',
                'integer',
                'min:1',
            ],
        ], [
            'file.required' => 'Veuillez sélectionner un fichier.',

            'file.file' => 'Le fichier envoyé est invalide.',

            'file.max' => 'Le fichier ne doit pas dépasser 20 Mo.',

            'attachable_type.required' => 'Le type de ressource est obligatoire.',

            'attachable_id.required' => 'La ressource associée est obligatoire.',
        ]);

        /*
         * Resolve the polymorphic model using Laravel.
         *
         * We do not create the attachment until we verify that
         * the target resource really exists.
         */
        $modelClass = $validated['attachable_type'];

        if (
            ! class_exists($modelClass)
            || ! is_subclass_of(
                $modelClass,
                Model::class
            )
        ) {
            throw ValidationException::withMessages([
                'attachable_type' => 'Le type de ressource sélectionné est invalide.',
            ]);
        }

        $attachable = $modelClass::query()
            ->find($validated['attachable_id']);

        if (! $attachable) {
            throw ValidationException::withMessages([
                'attachable_id' => 'La ressource associée est introuvable.',
            ]);
        }

        /*
         * If the target model contains organization_id,
         * enforce tenant isolation.
         */
        if (
            isset($attachable->organization_id)
            && (int) $attachable->organization_id
            !== (int) $organization->id
        ) {
            abort(
                403,
                'Cette ressource appartient à une autre société.'
            );
        }

        $file = $request->file('file');

        $originalName = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();

        /*
         * Generate a server-controlled unique file name.
         *
         * Never trust the original filename for physical storage.
         */
        $generatedName = (string) Str::uuid();

        if ($extension !== '') {
            $generatedName .= '.'.strtolower($extension);
        }

        $directory = sprintf(
            'attachments/%d/%s/%d',
            $organization->id,
            Str::slug(class_basename($attachable)),
            $attachable->getKey()
        );

        $storedPath = null;

        try {
            $storedPath = $file->storeAs(
                $directory,
                $generatedName,
                self::STORAGE_DISK
            );

            if (! $storedPath) {
                throw ValidationException::withMessages([
                    'file' => 'Impossible d’enregistrer le fichier.',
                ]);
            }

            DB::transaction(function () use (
                $organization,
                $request,
                $attachable,
                $originalName,
                $generatedName,
                $storedPath,
                $file
            ) {
                Attachment::create([
                    'organization_id' => $organization->id,
                    'uploaded_by' => $request->user()->id,

                    'attachable_type' => $attachable->getMorphClass(),
                    'attachable_id' => $attachable->getKey(),

                    'original_name' => $originalName,
                    'file_name' => $generatedName,

                    'path' => $storedPath,
                    'disk' => self::STORAGE_DISK,

                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                ]);
            });
        } catch (\Throwable $exception) {
            /*
             * If the physical file was stored but the database
             * operation failed, remove the orphan file.
             */
            if (
                $storedPath
                && Storage::disk(
                    self::STORAGE_DISK
                )->exists($storedPath)
            ) {
                Storage::disk(
                    self::STORAGE_DISK
                )->delete($storedPath);
            }

            throw $exception;
        }

        return back()->with(
            'success',
            'Pièce jointe ajoutée avec succès.'
        );
    }

    /**
     * Display metadata of one attachment.
     */
    public function show(
        Request $request,
        string $id
    ): JsonResponse {
        $attachment = $this->findScopedAttachment(
            $request,
            (int) $id
        );

        $attachment->load([
            'organization',
            'uploader:id,name,email',
            'attachable',
        ]);

        return response()->json([
            'attachment' => $attachment,
        ]);
    }

    /**
     * Download an attachment securely.
     */
    public function download(
        Request $request,
        string $id
    ): StreamedResponse {
        $attachment = $this->findScopedAttachment(
            $request,
            (int) $id
        );

        $disk = Storage::disk($attachment->disk);

        if (! $disk->exists($attachment->path)) {
            abort(
                404,
                'Le fichier physique est introuvable.'
            );
        }

        return $disk->download(
            $attachment->path,
            $attachment->original_name
        );
    }

    /**
     * Remove an attachment and its physical file.
     */
    public function destroy(
        Request $request,
        string $id
    ): RedirectResponse {
        $attachment = $this->findScopedAttachment(
            $request,
            (int) $id
        );

        $diskName = $attachment->disk;
        $path = $attachment->path;

        /*
         * Delete database record first inside a transaction.
         *
         * The physical file is removed only after the database
         * deletion succeeds.
         */
        DB::transaction(function () use ($attachment) {
            $attachment->delete();
        });

        /*
         * A missing physical file should not prevent cleanup
         * of an already valid database record.
         */
        $disk = Storage::disk($diskName);

        if ($disk->exists($path)) {
            $disk->delete($path);
        }

        return back()->with(
            'success',
            'Pièce jointe supprimée avec succès.'
        );
    }
}
