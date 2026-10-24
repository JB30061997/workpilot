<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Organization;
use App\Models\TimeSlot;
use App\Models\TimeSlotMember;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TimeSlotMemberController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    /**
     * Resolve current organization.
     */
    private function getCurrentOrganization(
        Request $request
    ): Organization {
        $organizations = $request->user()
            ->organizations()
            ->wherePivot('is_active', true)
            ->orderBy('name')
            ->get();

        abort_if(
            $organizations->isEmpty(),
            403,
            'Aucune société accessible pour votre compte.'
        );

        $organizationId = session(
            'current_organization_id'
        );

        if (
            ! $organizationId
            || ! $organizations->contains(
                'id',
                (int) $organizationId
            )
        ) {
            $organizationId =
                $organizations->first()->id;

            session([
                'current_organization_id' => $organizationId,
            ]);
        }

        return Organization::query()
            ->findOrFail($organizationId);
    }

    /**
     * Resolve current department.
     */
    private function getCurrentDepartment(
        Request $request,
        Organization $organization
    ): Department {
        $departments = $request->user()
            ->departments()
            ->where(
                'departments.organization_id',
                $organization->id
            )
            ->wherePivot('is_active', true)
            ->orderBy('departments.name')
            ->get();

        abort_if(
            $departments->isEmpty(),
            403,
            'Aucun service accessible dans cette société.'
        );

        $departmentId = session(
            'current_department_id'
        );

        if (
            ! $departmentId
            || ! $departments->contains(
                'id',
                (int) $departmentId
            )
        ) {
            $departmentId =
                $departments->first()->id;

            session([
                'current_department_id' => $departmentId,
            ]);
        }

        return Department::query()
            ->where(
                'organization_id',
                $organization->id
            )
            ->findOrFail($departmentId);
    }

    /**
     * Resolve current WorkPilot context.
     */
    private function getContext(
        Request $request
    ): array {
        $organization =
            $this->getCurrentOrganization($request);

        $department =
            $this->getCurrentDepartment(
                $request,
                $organization
            );

        return [
            'organization' => $organization,
            'department' => $department,
        ];
    }

    /**
     * Find TimeSlot inside current context.
     */
    private function findScopedTimeSlot(
        Request $request,
        int $timeSlotId
    ): TimeSlot {
        $context = $this->getContext($request);

        return TimeSlot::query()
            ->whereKey($timeSlotId)
            ->whereHas(
                'workSession',
                function ($query) use ($context) {
                    $query
                        ->where(
                            'organization_id',
                            $context['organization']->id
                        )
                        ->where(
                            'department_id',
                            $context['department']->id
                        );
                }
            )
            ->firstOrFail();
    }

    /**
     * Find membership inside current context.
     */
    private function findScopedMembership(
        Request $request,
        int $membershipId
    ): TimeSlotMember {
        $context = $this->getContext($request);

        return TimeSlotMember::query()
            ->whereKey($membershipId)
            ->whereHas(
                'timeSlot.workSession',
                function ($query) use ($context) {
                    $query
                        ->where(
                            'organization_id',
                            $context['organization']->id
                        )
                        ->where(
                            'department_id',
                            $context['department']->id
                        );
                }
            )
            ->firstOrFail();
    }

    /**
     * Find active user inside current organization/service.
     */
    private function findScopedUser(
        Request $request,
        int $userId
    ): User {
        $context = $this->getContext($request);

        return User::query()
            ->whereKey($userId)

            ->whereHas(
                'organizations',
                function ($query) use ($context) {
                    $query
                        ->where(
                            'organizations.id',
                            $context['organization']->id
                        )
                        ->wherePivot(
                            'is_active',
                            true
                        );
                }
            )

            ->whereHas(
                'departments',
                function ($query) use ($context) {
                    $query
                        ->where(
                            'departments.id',
                            $context['department']->id
                        )
                        ->wherePivot(
                            'is_active',
                            true
                        );
                }
            )

            ->firstOrFail();
    }

    /**
     * Check whether a user already has an overlapping slot.
     */
    private function userHasOverlap(
        User $user,
        TimeSlot $timeSlot
    ): bool {
        return TimeSlotMember::query()

            ->where(
                'user_id',
                $user->id
            )

            ->whereHas(
                'timeSlot',
                function ($query) use ($timeSlot) {
                    $query
                        ->where(
                            'id',
                            '!=',
                            $timeSlot->id
                        )

                        /*
                         * Two intervals overlap when:
                         *
                         * existing.start < new.end
                         * AND
                         * existing.end > new.start
                         */
                        ->where(
                            'start_at',
                            '<',
                            $timeSlot->end_at
                        )
                        ->where(
                            'end_at',
                            '>',
                            $timeSlot->start_at
                        );
                }
            )

            ->exists();
    }

    /**
     * Display members of a TimeSlot.
     */
    public function index(
        Request $request
    ): JsonResponse {
        $context = $this->getContext($request);

        $validated = $request->validate([
            'time_slot_id' => [
                'required',
                'integer',
                'exists:time_slots,id',
            ],

            'user_id' => [
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
                'between:1,'.self::MAX_PER_PAGE,
            ],
        ]);

        $timeSlot = $this->findScopedTimeSlot(
            $request,
            (int) $validated['time_slot_id']
        );

        $members = TimeSlotMember::query()
            ->where(
                'time_slot_id',
                $timeSlot->id
            )

            ->when(
                ! empty($validated['user_id']),
                fn ($query) => $query->where(
                    'user_id',
                    $validated['user_id']
                )
            )

            ->when(
                ! empty($validated['search']),
                function ($query) use ($validated) {
                    $search = trim(
                        $validated['search']
                    );

                    $query->whereHas(
                        'user',
                        function ($userQuery) use ($search) {
                            $userQuery
                                ->where(
                                    'name',
                                    'like',
                                    '%'.$search.'%'
                                )
                                ->orWhere(
                                    'email',
                                    'like',
                                    '%'.$search.'%'
                                );
                        }
                    );
                }
            )

            ->with([
                'user:id,name,email',

                'timeSlot:id,work_session_id,task_id,title,start_at,end_at,status,is_locked',
            ])

            ->latest('id')

            ->paginate(
                $validated['per_page']
                    ?? self::DEFAULT_PER_PAGE
            )
            ->withQueryString();

        return response()->json([
            'organization' => [
                'id' => $context['organization']->id,

                'name' => $context['organization']->name,
            ],

            'department' => [
                'id' => $context['department']->id,

                'name' => $context['department']->name,
            ],

            'time_slot' => [
                'id' => $timeSlot->id,

                'title' => $timeSlot->title,

                'start_at' => $timeSlot->start_at,

                'end_at' => $timeSlot->end_at,

                'status' => $timeSlot->status,

                'is_locked' => $timeSlot->is_locked,
            ],

            'members' => $members,
        ]);
    }

    /**
     * Add user to a TimeSlot.
     */
    public function store(
        Request $request
    ): RedirectResponse {
        $data = $request->validate([
            'time_slot_id' => [
                'required',
                'integer',
                'exists:time_slots,id',
            ],

            'user_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],
        ], [
            'time_slot_id.required' => 'Le créneau est obligatoire.',

            'time_slot_id.exists' => 'Le créneau sélectionné est introuvable.',

            'user_id.required' => 'L’utilisateur est obligatoire.',

            'user_id.exists' => 'L’utilisateur sélectionné est introuvable.',
        ]);

        $timeSlot = $this->findScopedTimeSlot(
            $request,
            (int) $data['time_slot_id']
        );

        /*
         * Locked planning cannot be changed.
         */
        if ($timeSlot->is_locked) {
            throw ValidationException::withMessages([
                'time_slot_id' => 'Ce créneau est verrouillé. Les participants ne peuvent plus être modifiés.',
            ]);
        }

        $user = $this->findScopedUser(
            $request,
            (int) $data['user_id']
        );

        /*
         * Prevent duplicate membership.
         */
        $alreadyMember = TimeSlotMember::query()
            ->where(
                'time_slot_id',
                $timeSlot->id
            )
            ->where(
                'user_id',
                $user->id
            )
            ->exists();

        if ($alreadyMember) {
            throw ValidationException::withMessages([
                'user_id' => 'Cet utilisateur est déjà affecté à ce créneau.',
            ]);
        }

        /*
         * Prevent planning conflicts.
         */
        if (
            $this->userHasOverlap(
                $user,
                $timeSlot
            )
        ) {
            throw ValidationException::withMessages([
                'user_id' => 'Cet utilisateur est déjà affecté à un autre créneau sur cette période.',
            ]);
        }

        DB::transaction(
            function () use (
                $timeSlot,
                $user
            ) {
                TimeSlotMember::create([
                    'time_slot_id' => $timeSlot->id,

                    'user_id' => $user->id,
                ]);
            }
        );

        return back()->with(
            'success',
            'Participant ajouté au créneau avec succès.'
        );
    }

    /**
     * Display one TimeSlot membership.
     */
    public function show(
        Request $request,
        string $id
    ): JsonResponse {
        $membership =
            $this->findScopedMembership(
                $request,
                (int) $id
            );

        $membership->load([
            'user:id,name,email',

            'timeSlot' => function ($query) {
                $query->with([
                    'workSession:id,organization_id,department_id,project_id,name,status',
                    'task:id,title,priority',
                    'creator:id,name,email',
                ]);
            },
        ]);

        return response()->json([
            'membership' => $membership,
        ]);
    }

    /**
     * TimeSlotMember has no editable metadata.
     *
     * Moving a user to another TimeSlot should be:
     * delete old membership + create new membership.
     */
    public function update(
        Request $request,
        string $id
    ): RedirectResponse {
        $this->findScopedMembership(
            $request,
            (int) $id
        );

        throw ValidationException::withMessages([
            'membership' => 'Cette affectation ne contient aucune information modifiable. Pour déplacer un participant, retirez-le du créneau actuel puis affectez-le au nouveau créneau.',
        ]);
    }

    /**
     * Remove user from TimeSlot.
     */
    public function destroy(
        Request $request,
        string $id
    ): RedirectResponse {
        $membership =
            $this->findScopedMembership(
                $request,
                (int) $id
            );

        $timeSlot =
            $membership->timeSlot()
                ->firstOrFail();

        /*
         * Locked planning cannot be changed.
         */
        if ($timeSlot->is_locked) {
            throw ValidationException::withMessages([
                'membership' => 'Ce créneau est verrouillé. Les participants ne peuvent plus être retirés.',
            ]);
        }

        DB::transaction(
            function () use ($membership) {
                $membership->delete();
            }
        );

        return back()->with(
            'success',
            'Participant retiré du créneau avec succès.'
        );
    }
}
