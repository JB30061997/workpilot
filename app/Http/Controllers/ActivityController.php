<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    /**
     * Maximum number of activities returned per page.
     */
    private const MAX_PER_PAGE = 100;

    /**
     * Default number of activities returned per page.
     */
    private const DEFAULT_PER_PAGE = 25;

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
     * Find an activity strictly inside the current organization.
     *
     * This prevents a user from reading the audit history
     * of another organization by changing the activity ID.
     */
    private function findScopedActivity(
        Request $request,
        int $activityId
    ): Activity {
        $organization = $this->getCurrentOrganization($request);

        return Activity::query()
            ->whereKey($activityId)
            ->where('organization_id', $organization->id)
            ->firstOrFail();
    }

    /**
     * Display the audit/activity history
     * of the current organization.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->getCurrentOrganization($request);

        $validated = $request->validate([
            'event' => [
                'nullable',
                'string',
                'max:100',
            ],

            'user_id' => [
                'nullable',
                'integer',
                'exists:users,id',
            ],

            'subject_type' => [
                'nullable',
                'string',
                'max:255',
            ],

            'subject_id' => [
                'nullable',
                'integer',
                'min:1',
            ],

            'search' => [
                'nullable',
                'string',
                'max:255',
            ],

            'date_from' => [
                'nullable',
                'date',
            ],

            'date_to' => [
                'nullable',
                'date',
                'after_or_equal:date_from',
            ],

            'per_page' => [
                'nullable',
                'integer',
                'between:1,'.self::MAX_PER_PAGE,
            ],
        ], [
            'date_to.after_or_equal' => 'La date de fin doit être postérieure ou égale à la date de début.',

            'per_page.between' => 'Le nombre d’éléments par page doit être compris entre 1 et '
                .self::MAX_PER_PAGE
                .'.',
        ]);

        $perPage = $validated['per_page']
            ?? self::DEFAULT_PER_PAGE;

        $activities = Activity::query()
            ->where(
                'organization_id',
                $organization->id
            )

            /*
             * User who generated the event.
             */
            ->with([
                'user:id,name,email',
            ])

            /*
             * Filter by event type:
             * created, updated, deleted, etc.
             */
            ->when(
                ! empty($validated['event']),
                fn (Builder $query) => $query->where(
                    'event',
                    $validated['event']
                )
            )

            /*
             * Filter by actor.
             */
            ->when(
                ! empty($validated['user_id']),
                fn (Builder $query) => $query->where(
                    'user_id',
                    $validated['user_id']
                )
            )

            /*
             * Filter by polymorphic subject type.
             */
            ->when(
                ! empty($validated['subject_type']),
                fn (Builder $query) => $query->where(
                    'subject_type',
                    $validated['subject_type']
                )
            )

            /*
             * Filter by polymorphic subject ID.
             */
            ->when(
                ! empty($validated['subject_id']),
                fn (Builder $query) => $query->where(
                    'subject_id',
                    $validated['subject_id']
                )
            )

            /*
             * Free text search.
             *
             * We deliberately search only textual audit fields.
             */
            ->when(
                ! empty($validated['search']),
                function (Builder $query) use ($validated) {
                    $search = trim($validated['search']);

                    $query->where(function (Builder $subQuery) use ($search) {
                        $subQuery
                            ->where(
                                'description',
                                'like',
                                '%'.$search.'%'
                            )
                            ->orWhere(
                                'event',
                                'like',
                                '%'.$search.'%'
                            );
                    });
                }
            )

            /*
             * Date range filters.
             */
            ->when(
                ! empty($validated['date_from']),
                fn (Builder $query) => $query->whereDate(
                    'created_at',
                    '>=',
                    $validated['date_from']
                )
            )

            ->when(
                ! empty($validated['date_to']),
                fn (Builder $query) => $query->whereDate(
                    'created_at',
                    '<=',
                    $validated['date_to']
                )
            )

            /*
             * Most recent audit events first.
             */
            ->latest('created_at')
            ->latest('id')

            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],

            'filters' => [
                'event' => $validated['event'] ?? null,
                'user_id' => $validated['user_id'] ?? null,
                'subject_type' => $validated['subject_type'] ?? null,
                'subject_id' => $validated['subject_id'] ?? null,
                'search' => $validated['search'] ?? null,
                'date_from' => $validated['date_from'] ?? null,
                'date_to' => $validated['date_to'] ?? null,
            ],

            'activities' => $activities,
        ]);
    }

    /**
     * Display one audit entry.
     */
    public function show(
        Request $request,
        string $id
    ): JsonResponse {
        $activity = $this->findScopedActivity(
            $request,
            (int) $id
        );

        /*
         * Load the actor and polymorphic subject.
         *
         * subject can be an ActionPlan, Project, Task, Action,
         * WorkSession, etc., depending on what generated the audit entry.
         */
        $activity->load([
            'user:id,name,email',
            'subject',
        ]);

        return response()->json([
            'activity' => $activity,
        ]);
    }
}
