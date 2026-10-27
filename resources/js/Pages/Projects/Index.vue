<script setup>
import InputError from "@/Components/InputError.vue";
import AppIcon from "@/Components/AppIcon.vue";
import Modal from "@/Components/Modal.vue";
import { useSweetAlert } from "@/Composables/useSweetAlert";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, Link, router, useForm, usePage } from "@inertiajs/vue3";
import {
    ChevronDown,
    ChevronUp,
    ChevronsUpDown,
    CalendarDays,
    Eye,
    EyeOff,
    LoaderCircle,
    MessageCircleMore,
    MoveVertical,
    Plus,
    Table2,
    Workflow,
} from "lucide-vue-next";
import { computed, onMounted, onUnmounted, ref, watch } from "vue";

const props = defineProps({
    organization: Object,
    department: Object,
    projects: Object,
    filters: Object,
    actionPlans: Array,
    managers: Array,
    departments: Array,
    projectCategories: Array,
    statuses: Array,
    priorities: Array,
    projectWorkspace: Array,
    canCreateProjects: Boolean,
    portfolioCounts: Object,
    openCreateProject: Boolean,
});

const { confirmAction } = useSweetAlert();
const page = usePage();
const currentUserId = computed(() => page.props.auth?.user?.id ?? null);
const canAccessProjectChat = (project) =>
    (project.project_members ?? project.members ?? []).some(
        (member) =>
            String(member.user_id ?? member.user?.id ?? member.id) ===
            String(currentUserId.value),
    );

const isFormOpen = ref(false);
const editingProject = ref(null);
const isWorkspaceOpen = ref(false);
const selectedProjectId = ref(null);
const workspaceSearch = ref("");
const workspacePlanId = ref("");
const workspacePickerOpen = ref(false);
const expandedActionId = ref(null);
const inlineActionEdit = ref(null);
const isSavingInlineAction = ref(false);
const workspaceViewMode = ref("table");
const planningDateMode = ref("planned");
const millisecondsPerDay = 86_400_000;
const dateKey = (date) =>
    `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
const parsePlanningDate = (value) => {
    if (!value) {
        return null;
    }

    const [year, month, day] = value.slice(0, 10).split("-").map(Number);

    return new Date(year, month - 1, day);
};
const addPlanningDays = (date, days) =>
    new Date(date.getFullYear(), date.getMonth(), date.getDate() + days);
const actionRange = (action) => {
    const start = dateInputValue(
        planningDateMode.value === "planned"
            ? action.start_date
            : action.actual_start_date,
    );
    const end = dateInputValue(
        planningDateMode.value === "planned"
            ? action.due_date
            : action.actual_end_date,
    );
    return { start, end };
};
const planningActionCount = computed(
    () =>
        (selectedWorkspaceProject.value?.actions || []).filter((a) => {
            const { start, end } = actionRange(a);
            return Boolean(start || end);
        }).length,
);
const planningRange = computed(() => {
    const dates = (selectedWorkspaceProject.value?.actions || [])
        .flatMap((action) => Object.values(actionRange(action)))
        .filter(Boolean)
        .map(parsePlanningDate)
        .filter(Boolean);

    if (!dates.length) {
        return null;
    }

    const firstDate = new Date(
        Math.min(...dates.map((date) => date.getTime())),
    );
    const lastDate = new Date(Math.max(...dates.map((date) => date.getTime())));

    return {
        start: addPlanningDays(firstDate, -1),
        end: addPlanningDays(lastDate, 1),
    };
});
const planningDayCount = computed(() => {
    if (!planningRange.value) {
        return 0;
    }

    return (
        Math.round(
            (planningRange.value.end - planningRange.value.start) /
                millisecondsPerDay,
        ) + 1
    );
});
const planningScale = computed(() =>
    planningDayCount.value > 120 ? "week" : "day",
);
const planningPeriods = computed(() => {
    if (!planningRange.value) {
        return [];
    }

    const periodLength = planningScale.value === "week" ? 7 : 1;
    const periods = [];

    for (
        let date = new Date(planningRange.value.start);
        date <= planningRange.value.end;
        date = addPlanningDays(date, periodLength)
    ) {
        periods.push({
            start: new Date(date),
            end: new Date(
                Math.min(
                    addPlanningDays(date, periodLength - 1).getTime(),
                    planningRange.value.end.getTime(),
                ),
            ),
        });
    }

    return periods;
});
const planningGridStyle = computed(() => ({
    gridTemplateColumns: `320px repeat(${planningPeriods.value.length}, ${planningScale.value === "week" ? "64px" : "34px"})`,
}));
const planningTimelineStyle = computed(() => ({
    minWidth: `${320 + planningPeriods.value.length * (planningScale.value === "week" ? 64 : 34)}px`,
}));
const planningRangeLabel = computed(() => {
    if (!planningRange.value) {
        return "Aucune période disponible";
    }

    return `${formatDate(dateKey(addPlanningDays(planningRange.value.start, 1)))} → ${formatDate(dateKey(addPlanningDays(planningRange.value.end, -1)))}`;
});
const planningPeriodLabel = (period) =>
    planningScale.value === "week"
        ? new Intl.DateTimeFormat("fr-FR", {
              day: "2-digit",
              month: "short",
          }).format(period.start)
        : new Intl.DateTimeFormat("fr-FR", {
              day: "2-digit",
              month: "2-digit",
          }).format(period.start);
const planningPeriodCaption = (period) =>
    planningScale.value === "week"
        ? "Sem."
        : new Intl.DateTimeFormat("fr-FR", { weekday: "short" })
              .format(period.start)
              .slice(0, 2);
const planningPeriodIsToday = (period) =>
    new Date() >= period.start && new Date() <= addPlanningDays(period.end, 1);
const actionPlanningSpan = (action) => {
    const { start, end } = actionRange(action);

    if ((!start && !end) || !planningPeriods.value.length) {
        return null;
    }

    const rangeStart = parsePlanningDate(start || end);
    const rangeEnd = parsePlanningDate(end || start);
    const normalizedStart = rangeStart <= rangeEnd ? rangeStart : rangeEnd;
    const normalizedEnd = rangeStart <= rangeEnd ? rangeEnd : rangeStart;
    const startIndex = planningPeriods.value.findIndex(
        (period) => period.end >= normalizedStart,
    );
    const endIndex = planningPeriods.value.findLastIndex(
        (period) => period.start <= normalizedEnd,
    );

    if (startIndex < 0 || endIndex < 0) {
        return null;
    }

    return {
        gridColumn: `${startIndex + 2} / ${endIndex + 3}`,
        label: `${formatDate(dateKey(normalizedStart))} → ${formatDate(dateKey(normalizedEnd))}`,
    };
};

const isActionFormOpen = ref(false);
const editingWorkspaceAction = ref(null);
const orderedProjects = ref([...props.projects.data]);
const draggedProjectId = ref(null);
const dragOverProjectId = ref(null);
const isSavingProjectOrder = ref(false);

const filtersForm = useForm({
    search: props.filters?.search ?? "",
    status: props.filters?.status ?? "",
    priority: props.filters?.priority ?? "",
    project_category_id: props.filters?.project_category_id ?? "",
    action_plan_id: props.filters?.action_plan_id ?? "",
    portfolio: props.filters?.portfolio ?? "solutions",
    sort: props.filters?.sort ?? "position",
    direction: props.filters?.direction ?? "asc",
});

const hasNonCategoryProjectFilters = computed(
    () =>
        Boolean(props.filters?.search) ||
        Boolean(props.filters?.status) ||
        Boolean(props.filters?.priority) ||
        Boolean(props.filters?.action_plan_id),
);

const selectedReorderCategoryId = computed(
    () => props.filters?.project_category_id ?? "",
);

const canReorderProjects = computed(
    () =>
        Boolean(selectedReorderCategoryId.value) &&
        props.filters?.sort === "position" &&
        props.filters?.direction === "asc" &&
        !hasNonCategoryProjectFilters.value,
);

watch(
    () => props.projects.data,
    (projects) => {
        orderedProjects.value = [...projects];
    },
);

const projectForm = useForm({
    creation_token: crypto.randomUUID(),
    name: "",
    description: "",
    action_plan_id: "",
    axis_id: "",
    department_ids: [props.department.id],
    project_members: [],
    department_responsibles: {},
    project_category_id: "",
    start_date: "",
    due_date: "",
    status: "draft",
    priority: "medium",
    position: 1,
    progress_value: 0,
    is_private: false,
    is_active: true,
});

// Membres et responsables par département.
const memberSearch = ref("");
const departmentUsers = (department) => {
    const source = department.users ?? department.members ?? [];
    return source
        .map((entry) => entry.user ?? entry)
        .filter((user) => user?.id != null);
};
const selectedDepartments = computed(() =>
    (props.departments ?? []).filter((department) =>
        projectForm.department_ids.some(
            (id) => String(id) === String(department.id),
        ),
    ),
);
const filteredDepartmentUsers = (department) =>
    departmentUsers(department).filter((user) =>
        String(user.name ?? "")
            .toLocaleLowerCase("fr")
            .includes(memberSearch.value.toLocaleLowerCase("fr")),
    );
const isProjectMember = (id) =>
    projectForm.project_members.some(
        (memberId) => String(memberId) === String(id),
    );
const toggleProjectMember = (id) => {
    if (isProjectMember(id)) {
        projectForm.project_members = projectForm.project_members.filter(
            (memberId) => String(memberId) !== String(id),
        );
        Object.keys(projectForm.department_responsibles).forEach(
            (departmentId) => {
                if (
                    String(
                        projectForm.department_responsibles[departmentId],
                    ) === String(id)
                ) {
                    delete projectForm.department_responsibles[departmentId];
                }
            },
        );
    } else {
        projectForm.project_members = [...projectForm.project_members, id];
    }
};
const toggleDepartmentResponsible = (departmentId, userId) => {
    const key = String(departmentId);
    if (String(projectForm.department_responsibles[key]) === String(userId)) {
        delete projectForm.department_responsibles[key];
    } else {
        if (!isProjectMember(userId))
            projectForm.project_members = [
                ...projectForm.project_members,
                userId,
            ];
        projectForm.department_responsibles[key] = userId;
    }
};
watch(
    () => [...projectForm.department_ids],
    () => {
        const valid = new Set(
            selectedDepartments.value.flatMap((department) =>
                departmentUsers(department).map((user) => String(user.id)),
            ),
        );
        projectForm.project_members = projectForm.project_members.filter((id) =>
            valid.has(String(id)),
        );
        Object.keys(projectForm.department_responsibles).forEach(
            (departmentId) => {
                if (
                    !selectedDepartments.value.some(
                        (department) => String(department.id) === departmentId,
                    )
                ) {
                    delete projectForm.department_responsibles[departmentId];
                }
            },
        );
    },
);

const actionForm = useForm({
    project_id: "",
    action_plan_id: "",
    axis_id: "",
    title: "",
    description: "",
    start_date: "",
    due_date: "",
    actual_start_date: "",
    actual_end_date: "",
    status: "not_started",
    priority: "medium",
    progress_value: 0,
    position: 1,
});

const actionStatuses = [
    "not_started",
    "in_progress",
    "on_hold",
    "completed",
    "cancelled",
];
const actionPriorities = ["low", "medium", "high", "critical"];

const availableAxes = computed(() => {
    const plan = props.actionPlans.find(
        (item) => Number(item.id) === Number(projectForm.action_plan_id),
    );

    return plan?.axes ?? [];
});

const selectedActionPlan = computed(
    () =>
        props.actionPlans.find(
            (item) => Number(item.id) === Number(projectForm.action_plan_id),
        ) ?? null,
);

const selectedAxis = computed(
    () =>
        availableAxes.value.find(
            (item) => Number(item.id) === Number(projectForm.axis_id),
        ) ?? null,
);

const handleActionPlanChange = () => {
    projectForm.axis_id =
        availableAxes.value.length === 1 ? availableAxes.value[0].id : "";
};

const statusLabels = {
    draft: "Brouillon",
    planned: "Planifié",
    in_progress: "En cours",
    on_hold: "En attente",
    completed: "Terminé",
    cancelled: "Annulé",
};

const priorityLabels = {
    low: "Faible",
    medium: "Moyenne",
    high: "Haute",
    critical: "Critique",
};

const statusClasses = {
    draft: "bg-slate-100 text-slate-700",
    planned: "bg-blue-50 text-blue-700",
    in_progress: "bg-amber-50 text-amber-700",
    on_hold: "bg-orange-50 text-orange-700",
    completed: "bg-emerald-50 text-emerald-700",
    cancelled: "bg-rose-50 text-rose-700",
};

const priorityClasses = {
    low: "text-slate-600",
    medium: "text-blue-700",
    high: "text-amber-700",
    critical: "text-rose-700",
};

const actionStatusLabels = {
    not_started: "Non débutée",
    in_progress: "En cours",
    on_hold: "En attente",
    completed: "Terminée",
    cancelled: "Annulée",
};

const actionStatusClasses = {
    not_started: "bg-slate-100 text-slate-700 ring-slate-200",
    in_progress: "bg-blue-50 text-blue-700 ring-blue-200",
    on_hold: "bg-amber-50 text-amber-700 ring-amber-200",
    completed: "bg-emerald-50 text-emerald-700 ring-emerald-200",
    cancelled: "bg-rose-50 text-rose-700 ring-rose-200",
};

const progressColor = (progress) => {
    if (progress >= 100) return "bg-emerald-500";
    if (progress >= 50) return "bg-blue-600";
    if (progress > 0) return "bg-amber-500";

    return "bg-slate-300";
};

const workspacePlans = computed(() => {
    const plans = new Map();

    props.projectWorkspace.forEach((project) => {
        if (project.action_plan) {
            plans.set(project.action_plan.id, project.action_plan);
        }
    });

    return [...plans.values()];
});

const filteredWorkspaceProjects = computed(() => {
    const search = workspaceSearch.value.trim().toLocaleLowerCase("fr");

    return props.projectWorkspace.filter((project) => {
        const matchesPlan =
            !workspacePlanId.value ||
            Number(project.action_plan_id) === Number(workspacePlanId.value);
        const matchesSearch =
            !search ||
            project.name.toLocaleLowerCase("fr").includes(search) ||
            project.axis?.name?.toLocaleLowerCase("fr").includes(search);

        return matchesPlan && matchesSearch;
    });
});

const selectedWorkspaceProject = computed(
    () =>
        props.projectWorkspace.find(
            (project) => Number(project.id) === Number(selectedProjectId.value),
        ) ?? null,
);

const selectFirstVisibleProject = () => {
    if (
        !filteredWorkspaceProjects.value.some(
            (project) => Number(project.id) === Number(selectedProjectId.value),
        )
    ) {
        selectedProjectId.value =
            filteredWorkspaceProjects.value[0]?.id ?? null;
    }
};

watch([workspaceSearch, workspacePlanId], selectFirstVisibleProject);
watch(isWorkspaceOpen, (isOpen) => {
    document.body.style.overflow = isOpen ? "hidden" : "";
});

const closeWorkspaceOnEscape = (event) => {
    if (event.key === "Escape" && isWorkspaceOpen.value) {
        closeWorkspace();
    }
};

onMounted(() => {
    document.addEventListener("keydown", closeWorkspaceOnEscape);

    if (props.openCreateProject && props.canCreateProjects) {
        openCreateForm();
        projectForm.action_plan_id = props.filters?.action_plan_id ?? "";
        handleActionPlanChange();
    }
});
onUnmounted(() => {
    document.removeEventListener("keydown", closeWorkspaceOnEscape);
    document.body.style.overflow = "";
});

const openWorkspace = (project = null) => {
    selectedProjectId.value =
        project?.id ?? filteredWorkspaceProjects.value[0]?.id ?? null;
    workspacePickerOpen.value = false;
    expandedActionId.value = null;
    isWorkspaceOpen.value = true;
};

const closeWorkspace = () => {
    if (actionForm.processing) {
        return;
    }

    isActionFormOpen.value = false;
    editingWorkspaceAction.value = null;
    inlineActionEdit.value = null;
    workspacePickerOpen.value = false;
    isWorkspaceOpen.value = false;
};

const safeProgress = (value) => Math.min(100, Math.max(0, Number(value) || 0));
const startInlineActionEdit = (action, field) => {
    if (
        !selectedWorkspaceProject.value?.can_manage ||
        isSavingInlineAction.value
    ) {
        return;
    }

    inlineActionEdit.value = {
        actionId: action.id,
        field,
        value:
            field === "progress"
                ? safeProgress(action.progress)
                : field.includes("date")
                  ? dateInputValue(action[field])
                  : action[field],
        error: "",
    };
};
const isInlineActionEditing = (action, field) =>
    inlineActionEdit.value?.actionId === action.id &&
    inlineActionEdit.value?.field === field;
const cancelInlineActionEdit = () => {
    if (!isSavingInlineAction.value) {
        inlineActionEdit.value = null;
    }
};
const saveInlineActionEdit = (action) => {
    const edit = inlineActionEdit.value;

    if (!edit || edit.actionId !== action.id || isSavingInlineAction.value) {
        return;
    }

    const data = {
        [edit.field]:
            edit.field === "progress"
                ? safeProgress(edit.value)
                : edit.value || null,
    };

    if (["start_date", "due_date"].includes(edit.field)) {
        data.start_date =
            edit.field === "start_date"
                ? data.start_date
                : dateInputValue(action.start_date) || null;
        data.due_date =
            edit.field === "due_date"
                ? data.due_date
                : dateInputValue(action.due_date) || null;
    }

    if (["actual_start_date", "actual_end_date"].includes(edit.field)) {
        data.actual_start_date =
            edit.field === "actual_start_date"
                ? data.actual_start_date
                : dateInputValue(action.actual_start_date) || null;
        data.actual_end_date =
            edit.field === "actual_end_date"
                ? data.actual_end_date
                : dateInputValue(action.actual_end_date) || null;
    }

    isSavingInlineAction.value = true;
    edit.error = "";

    router.patch(route("actions.update", action.id), data, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            inlineActionEdit.value = null;
        },
        onError: (errors) => {
            edit.error =
                errors[edit.field] ||
                Object.values(errors)[0] ||
                "La modification n’a pas pu être enregistrée.";
        },
        onFinish: () => {
            isSavingInlineAction.value = false;
        },
    });
};
const workspaceActionCount = computed(
    () => selectedWorkspaceProject.value?.actions?.length ?? 0,
);
const workspaceCompletedCount = computed(
    () =>
        selectedWorkspaceProject.value?.actions?.filter(
            (a) => a.status === "completed",
        ).length ?? 0,
);
const selectWorkspaceProject = (project) => {
    selectedProjectId.value = project.id;
    expandedActionId.value = null;
    inlineActionEdit.value = null;
    workspacePickerOpen.value = false;
};

const formatDate = (value) => {
    if (!value) {
        return "--";
    }

    return new Intl.DateTimeFormat("fr-FR", {
        day: "2-digit",
        month: "short",
        year: "numeric",
    }).format(
        new Date(
            /^[0-9]{4}-[0-9]{2}-[0-9]{2}$/.test(String(value))
                ? `${value}T12:00:00`
                : value,
        ),
    );
};

const dateInputValue = (value) => value?.slice(0, 10) ?? "";

const resetActionForm = () => {
    actionForm.reset();
    actionForm.clearErrors();
    actionForm.status = "not_started";
    actionForm.priority = "medium";
    actionForm.progress_value = 0;
    actionForm.position = 1;
};

const openCreateActionForm = () => {
    const project = selectedWorkspaceProject.value;

    if (!project) {
        return;
    }

    editingWorkspaceAction.value = null;
    resetActionForm();
    actionForm.project_id = project.id;
    actionForm.action_plan_id = project.action_plan_id ?? "";
    actionForm.axis_id = project.axis_id ?? "";
    actionForm.position = project.actions.length + 1;
    isActionFormOpen.value = true;
};

const openEditActionForm = (action, index) => {
    const project = selectedWorkspaceProject.value;

    editingWorkspaceAction.value = action;
    actionForm.clearErrors();
    actionForm.project_id = project.id;
    actionForm.action_plan_id = project.action_plan_id ?? "";
    actionForm.axis_id = project.axis_id ?? "";
    actionForm.title = action.title ?? "";
    actionForm.description = action.description ?? "";
    actionForm.start_date = dateInputValue(action.start_date);
    actionForm.due_date = dateInputValue(action.due_date);
    actionForm.actual_start_date = dateInputValue(action.actual_start_date);
    actionForm.actual_end_date = dateInputValue(action.actual_end_date);
    actionForm.status = action.status;
    actionForm.priority = action.priority;
    actionForm.progress_value = action.progress ?? 0;
    actionForm.position = index + 1;
    isActionFormOpen.value = true;
};

const closeActionForm = () => {
    if (actionForm.processing) {
        return;
    }

    isActionFormOpen.value = false;
    editingWorkspaceAction.value = null;
    resetActionForm();
};

const submitAction = () => {
    const options = {
        preserveScroll: true,
        onSuccess: closeActionForm,
    };
    const form = actionForm.transform(({ progress_value, ...data }) => ({
        ...data,
        progress: progress_value,
    }));

    if (editingWorkspaceAction.value) {
        form.patch(
            route("actions.update", editingWorkspaceAction.value.id),
            options,
        );

        return;
    }

    form.post(route("actions.store"), options);
};

const deleteAction = async (action) => {
    if (
        !(await confirmAction({
            title: `Supprimer « ${action.title} » ?`,
            text: "Cette action sera supprimée définitivement.",
            confirmButtonText: "Supprimer l’action",
        }))
    ) {
        return;
    }

    router.delete(route("actions.destroy", action.id), {
        preserveScroll: true,
    });
};

const duplicateAction = (action) => {
    router.post(
        route("actions.duplicate", action.id),
        {},
        {
            preserveScroll: true,
        },
    );
};

const resetProjectForm = () => {
    projectForm.reset();
    projectForm.clearErrors();
    projectForm.status = "draft";
    projectForm.priority = "medium";
    projectForm.position = 1;
    projectForm.department_ids = [props.department.id];
    projectForm.project_members = [];
    projectForm.department_responsibles = {};
    memberSearch.value = "";
    projectForm.project_category_id = props.projectCategories[0]?.id ?? "";
    projectForm.progress_value = 0;
    projectForm.is_private = false;
    projectForm.is_active = true;
};

const openCreateForm = () => {
    editingProject.value = null;
    resetProjectForm();
    projectForm.creation_token = crypto.randomUUID();
    isFormOpen.value = true;
};

const openEditForm = (project) => {
    editingProject.value = project;
    projectForm.clearErrors();
    projectForm.name = project.name ?? "";
    projectForm.description = project.description ?? "";
    projectForm.action_plan_id = project.action_plan_id ?? "";
    projectForm.axis_id = project.axis_id ?? "";
    projectForm.project_category_id = project.project_category_id ?? "";
    projectForm.department_ids = (
        project.departments?.length ? project.departments : [props.department]
    ).map((department) => department.id);
    projectForm.project_members = (
        project.project_members ??
        project.members ??
        []
    )
        .map((member) => member.user_id ?? member.user?.id ?? member.id)
        .filter((id) => id != null);
    projectForm.department_responsibles = {
        ...(project.department_responsibles ?? {}),
    };
    memberSearch.value = "";
    projectForm.start_date = dateInputValue(project.start_date);
    projectForm.due_date = dateInputValue(project.due_date);
    projectForm.status = project.status;
    projectForm.priority = project.priority;
    projectForm.position = project.position || 1;
    projectForm.progress_value = project.progress ?? 0;
    projectForm.is_private = Boolean(project.is_private);
    projectForm.is_active = Boolean(project.is_active);
    isFormOpen.value = true;
};

const closeForm = () => {
    if (projectForm.processing) {
        return;
    }

    isFormOpen.value = false;
    editingProject.value = null;
    resetProjectForm();
};

const submitProject = () => {
    const form = projectForm.transform(({ progress_value, ...data }) => data);
    const options = {
        preserveScroll: true,
        onSuccess: closeForm,
    };

    if (editingProject.value) {
        form.patch(route("projects.update", editingProject.value.id), options);
        return;
    }

    form.post(route("projects.store"), options);
};

const applyFilters = () => {
    router.get(route("projects.index"), filtersForm.data(), {
        preserveState: true,
        replace: true,
    });
};

const sortBy = (column) => {
    if (filtersForm.sort === column) {
        filtersForm.direction =
            filtersForm.direction === "asc" ? "desc" : "asc";
    } else {
        filtersForm.sort = column;
        filtersForm.direction = "asc";
    }

    applyFilters();
};

const sortIcon = (column) => {
    if (filtersForm.sort !== column) return ChevronsUpDown;

    return filtersForm.direction === "asc" ? ChevronUp : ChevronDown;
};

const startProjectDrag = (event, project) => {
    if (!canReorderProjects.value || isSavingProjectOrder.value) {
        event.preventDefault();
        return;
    }

    draggedProjectId.value = project.id;
    dragOverProjectId.value = project.id;
    event.dataTransfer.effectAllowed = "move";
    event.dataTransfer.setData("text/plain", String(project.id));
};

const moveDraggedProject = (targetProject) => {
    if (
        draggedProjectId.value === null ||
        Number(draggedProjectId.value) === Number(targetProject.id) ||
        Number(dragOverProjectId.value) === Number(targetProject.id)
    ) {
        return;
    }

    const sourceIndex = orderedProjects.value.findIndex(
        (project) => Number(project.id) === Number(draggedProjectId.value),
    );
    const targetIndex = orderedProjects.value.findIndex(
        (project) => Number(project.id) === Number(targetProject.id),
    );

    if (sourceIndex < 0 || targetIndex < 0) {
        return;
    }

    const [draggedProject] = orderedProjects.value.splice(sourceIndex, 1);
    orderedProjects.value.splice(targetIndex, 0, draggedProject);
    dragOverProjectId.value = targetProject.id;
};

const cancelProjectDrag = () => {
    orderedProjects.value = [...props.projects.data];
    draggedProjectId.value = null;
    dragOverProjectId.value = null;
};

const saveProjectOrder = () => {
    if (draggedProjectId.value === null || isSavingProjectOrder.value) {
        return;
    }

    const firstPosition = Number(props.projects.from) || 1;
    const positions = orderedProjects.value.map((project, index) => ({
        id: project.id,
        position: firstPosition + index,
    }));

    draggedProjectId.value = null;
    dragOverProjectId.value = null;

    router.patch(
        route("projects.reorder"),
        {
            project_category_id: selectedReorderCategoryId.value,
            positions,
        },
        {
            preserveScroll: true,
            onStart: () => {
                isSavingProjectOrder.value = true;
            },
            onError: cancelProjectDrag,
            onFinish: () => {
                isSavingProjectOrder.value = false;
            },
        },
    );
};

const resetFilters = () => {
    const portfolio = filtersForm.portfolio;
    filtersForm.reset();
    filtersForm.portfolio = portfolio;
    filtersForm.search = "";
    filtersForm.status = "";
    filtersForm.priority = "";
    filtersForm.project_category_id = "";
    filtersForm.action_plan_id = "";
    filtersForm.sort = "position";
    filtersForm.direction = "asc";
    router.get(
        route("projects.index"),
        { portfolio, sort: "position", direction: "asc" },
        { replace: true },
    );
};

const switchPortfolio = (portfolio) => {
    filtersForm.portfolio = portfolio;
    router.get(route("projects.index"), filtersForm.data(), {
        preserveState: true,
        replace: true,
    });
};

const deleteProject = async (project) => {
    if (
        !(await confirmAction({
            title: `Supprimer « ${project.name} » ?`,
            text: "Le projet et ses données associées seront supprimés.",
            confirmButtonText: "Supprimer le projet",
        }))
    ) {
        return;
    }

    router.delete(route("projects.destroy", project.id), {
        preserveScroll: true,
    });
};

const duplicateProject = (project) => {
    router.post(
        route("projects.duplicate", project.id),
        {},
        {
            preserveScroll: true,
            onSuccess: (page) => {
                const copiedProjectId = page.props.flash?.open_project_id;

                if (copiedProjectId) {
                    selectedProjectId.value = copiedProjectId;
                    isWorkspaceOpen.value = true;
                }
            },
        },
    );
};
</script>

<template>
    <Head title="Projets - WorkPilot" />

    <AuthenticatedLayout>
        <div class="min-w-0 max-w-full overflow-x-hidden bg-[#f1f3f6]">
            <div
                class="mx-auto grid min-w-0 max-w-[1700px] gap-4 px-3 py-5 sm:px-5 lg:px-7"
            >
                <header
                    class="border border-slate-300 bg-[#f8f9fb] px-5 py-5 sm:px-7"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-4"
                    >
                        <div class="min-w-0">
                            <p
                                class="text-[11px] font-bold uppercase tracking-[.15em] text-blue-700"
                            >
                                {{ organization.name }} · {{ department.name }}
                            </p>
                            <h1 class="mt-1 text-2xl font-bold text-slate-950">
                                Projets
                            </h1>
                            <p class="mt-1 text-sm text-slate-600">
                                Pilotage des projets, responsables, échéances et
                                avancement
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="wp-btn wp-btn-secondary text-blue-700"
                                @click="openWorkspace()"
                            >
                                <svg
                                    class="h-4 w-4"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2"
                                >
                                    <rect
                                        x="3"
                                        y="4"
                                        width="18"
                                        height="16"
                                        rx="1"
                                    />
                                    <path d="M7 9h10M7 13h10" />
                                </svg>
                                Consulter les projets
                            </button>
                            <button
                                v-if="canCreateProjects"
                                type="button"
                                class="wp-btn wp-btn-primary"
                                @click="openCreateForm"
                            >
                                <span class="text-lg leading-none">＋</span>
                                Nouveau projet
                            </button>
                        </div>
                    </div>
                    <div class="mt-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <div class="wp-kpi border-blue-200 bg-blue-50">
                            <span>Total projets</span
                            ><strong>{{
                                (portfolioCounts?.solutions ?? 0) +
                                (portfolioCounts?.infrastructure ?? 0)
                            }}</strong>
                        </div>
                        <div class="wp-kpi border-violet-200 bg-violet-50">
                            <span>Solutions</span
                            ><strong>{{
                                portfolioCounts?.solutions ?? 0
                            }}</strong>
                        </div>
                        <div class="wp-kpi border-cyan-200 bg-cyan-50">
                            <span>Infrastructure</span
                            ><strong>{{
                                portfolioCounts?.infrastructure ?? 0
                            }}</strong>
                        </div>
                        <div class="wp-kpi border-emerald-200 bg-emerald-50">
                            <span>Projets affichés</span
                            ><strong>{{
                                projects.total ?? projects.data.length
                            }}</strong>
                        </div>
                    </div>
                </header>

                <section class="min-w-0 border border-slate-300 bg-[#f8f9fb]">
                    <nav
                        class="flex gap-2 overflow-x-auto border-b border-slate-200 bg-white px-4 pt-3"
                        aria-label="Catégories des projets"
                    >
                        <button
                            type="button"
                            class="flex shrink-0 items-center gap-2 border-b-2 px-4 pb-3 text-sm font-bold transition"
                            :class="
                                filtersForm.portfolio === 'solutions'
                                    ? 'border-blue-600 text-blue-700'
                                    : 'border-transparent text-slate-400 hover:text-slate-700'
                            "
                            @click="switchPortfolio('solutions')"
                        >
                            Projets Solutions
                            <span
                                class="rounded-full bg-blue-50 px-2 py-0.5 text-xs text-blue-700"
                                >{{ portfolioCounts?.solutions ?? 0 }}</span
                            >
                        </button>
                        <button
                            type="button"
                            class="flex shrink-0 items-center gap-2 border-b-2 px-4 pb-3 text-sm font-bold transition"
                            :class="
                                filtersForm.portfolio === 'infrastructure'
                                    ? 'border-blue-600 text-blue-700'
                                    : 'border-transparent text-slate-400 hover:text-slate-700'
                            "
                            @click="switchPortfolio('infrastructure')"
                        >
                            Projets Infrastructure
                            <span
                                class="rounded-full bg-blue-50 px-2 py-0.5 text-xs text-blue-700"
                                >{{
                                    portfolioCounts?.infrastructure ?? 0
                                }}</span
                            >
                        </button>
                    </nav>

                    <form
                        class="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4"
                        @submit.prevent="applyFilters"
                    >
                        <label
                            class="min-w-[210px] flex-1 text-xs font-semibold text-slate-600"
                            >Rechercher
                            <input
                                v-model="filtersForm.search"
                                type="search"
                                placeholder="Nom ou code du projet..."
                                class="wp-field mt-1"
                            />
                        </label>
                        <label
                            class="w-full text-xs font-semibold text-slate-600 sm:w-48"
                            >Statut
                            <select
                                v-model="filtersForm.status"
                                class="wp-field mt-1"
                            >
                                <option value="">Tous les statuts</option>
                                <option
                                    v-for="status in statuses"
                                    :key="status"
                                    :value="status"
                                >
                                    {{ statusLabels[status] }}
                                </option>
                            </select></label
                        >
                        <label
                            class="w-full text-xs font-semibold text-slate-600 sm:w-48"
                            >Niveau
                            <select
                                v-model="filtersForm.priority"
                                class="wp-field mt-1"
                            >
                                <option value="">Tous les niveaux</option>
                                <option
                                    v-for="priority in priorities"
                                    :key="priority"
                                    :value="priority"
                                >
                                    {{ priorityLabels[priority] }}
                                </option>
                            </select></label
                        >
                        <label
                            class="w-full text-xs font-semibold text-slate-600 sm:w-56"
                        >
                            Catégorie
                            <select
                                v-model="filtersForm.project_category_id"
                                class="wp-field mt-1"
                            >
                                <option value="">Toutes les catégories</option>
                                <option
                                    v-for="category in projectCategories"
                                    :key="category.id"
                                    :value="category.id"
                                >
                                    {{ category.name }}
                                </option>
                            </select>
                        </label>
                        <button type="submit" class="wp-btn wp-btn-primary">
                            Filtrer
                        </button>
                        <button
                            type="button"
                            class="wp-btn wp-btn-secondary"
                            @click="resetFilters"
                        >
                            Réinitialiser
                        </button>
                    </form>

                    <div
                        v-if="projects.data.length"
                        class="wp-table-scroll max-w-full overflow-x-auto overscroll-x-contain"
                    >
                        <div
                            class="flex items-center justify-between gap-3 border-b border-slate-200 bg-blue-50 px-4 py-2 text-xs"
                        >
                            <p class="font-semibold text-blue-800">
                                <template v-if="canReorderProjects">
                                    Glissez les projets pour modifier leur
                                    priorité dans cette catégorie.
                                </template>
                                <template
                                    v-else-if="!selectedReorderCategoryId"
                                >
                                    Sélectionnez d’abord une catégorie puis
                                    cliquez sur Filtrer pour activer le
                                    classement.
                                </template>
                                <template v-else>
                                    Gardez uniquement la catégorie et triez la
                                    priorité par ordre croissant pour
                                    réorganiser.
                                </template>
                            </p>
                            <span
                                v-if="isSavingProjectOrder"
                                class="inline-flex shrink-0 items-center gap-1.5 font-bold text-blue-700"
                            >
                                <LoaderCircle class="h-4 w-4 animate-spin" />
                                Enregistrement…
                            </span>
                        </div>
                        <table
                            class="wp-table w-[2320px] min-w-[2320px] table-fixed"
                        >
                            <colgroup>
                                <col class="w-[130px]" />
                                <col class="w-[250px]" />
                                <col class="w-[260px]" />
                                <col class="w-[190px]" />
                                <col class="w-[220px]" />
                                <col class="w-[200px]" />
                                <col class="w-[190px]" />
                                <col class="w-[120px]" />
                                <col class="w-[110px]" />
                                <col class="w-[180px]" />
                                <col class="w-[140px]" />
                                <col class="w-[90px]" />
                                <col class="w-[240px]" />
                            </colgroup>
                            <thead>
                                <tr>
                                    <th>
                                        <button
                                            type="button"
                                            class="wp-sort"
                                            @click="sortBy('position')"
                                        >
                                            Priorité
                                            <component
                                                :is="sortIcon('position')"
                                                class="wp-sort-icon"
                                            />
                                        </button>
                                    </th>
                                    <th>
                                        <button
                                            type="button"
                                            class="wp-sort"
                                            @click="sortBy('name')"
                                        >
                                            Projet
                                            <component
                                                :is="sortIcon('name')"
                                                class="wp-sort-icon"
                                            />
                                        </button>
                                    </th>
                                    <!-- <th>Code Projet</th> -->
                                    <th>
                                        <button
                                            type="button"
                                            class="wp-sort"
                                            @click="sortBy('action_plan')"
                                        >
                                            Plan d’action
                                            <component
                                                :is="sortIcon('action_plan')"
                                                class="wp-sort-icon"
                                            />
                                        </button>
                                    </th>
                                    <th>
                                        <button
                                            type="button"
                                            class="wp-sort"
                                            @click="sortBy('axis')"
                                        >
                                            Établissement
                                            <component
                                                :is="sortIcon('axis')"
                                                class="wp-sort-icon"
                                            />
                                        </button>
                                    </th>
                                    <th>
                                        <button
                                            type="button"
                                            class="wp-sort"
                                            @click="sortBy('departments')"
                                        >
                                            Départements
                                            <component
                                                :is="sortIcon('departments')"
                                                class="wp-sort-icon"
                                            />
                                        </button>
                                    </th>
                                    <th>
                                        <button
                                            type="button"
                                            class="wp-sort"
                                            @click="sortBy('category')"
                                        >
                                            Catégorie
                                            <component
                                                :is="sortIcon('category')"
                                                class="wp-sort-icon"
                                            />
                                        </button>
                                    </th>
                                    <th>
                                        <button
                                            type="button"
                                            class="wp-sort"
                                            @click="sortBy('manager')"
                                        >
                                            Responsable
                                            <component
                                                :is="sortIcon('manager')"
                                                class="wp-sort-icon"
                                            />
                                        </button>
                                    </th>
                                    <th>
                                        <button
                                            type="button"
                                            class="wp-sort"
                                            @click="sortBy('status')"
                                        >
                                            Statut
                                            <component
                                                :is="sortIcon('status')"
                                                class="wp-sort-icon"
                                            />
                                        </button>
                                    </th>
                                    <th>
                                        <button
                                            type="button"
                                            class="wp-sort"
                                            @click="sortBy('priority')"
                                        >
                                            Niveau
                                            <component
                                                :is="sortIcon('priority')"
                                                class="wp-sort-icon"
                                            />
                                        </button>
                                    </th>
                                    <th>
                                        <button
                                            type="button"
                                            class="wp-sort"
                                            @click="sortBy('progress')"
                                        >
                                            Progression
                                            <component
                                                :is="sortIcon('progress')"
                                                class="wp-sort-icon"
                                            />
                                        </button>
                                    </th>
                                    <th>
                                        <button
                                            type="button"
                                            class="wp-sort"
                                            @click="sortBy('due_date')"
                                        >
                                            Échéance
                                            <component
                                                :is="sortIcon('due_date')"
                                                class="wp-sort-icon"
                                            />
                                        </button>
                                    </th>
                                    <th>
                                        <button
                                            type="button"
                                            class="wp-sort"
                                            @click="sortBy('actions_count')"
                                        >
                                            Actions
                                            <component
                                                :is="sortIcon('actions_count')"
                                                class="wp-sort-icon"
                                            />
                                        </button>
                                    </th>
                                    <th class="pr-5 text-right">Gestion</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="project in orderedProjects"
                                    :key="project.id"
                                    class="transition-colors"
                                    :class="[
                                        Number(draggedProjectId) ===
                                        Number(project.id)
                                            ? 'bg-blue-100/80 opacity-60'
                                            : '',
                                        Number(dragOverProjectId) ===
                                            Number(project.id) &&
                                        Number(draggedProjectId) !==
                                            Number(project.id)
                                            ? 'border-t-2 border-blue-500'
                                            : '',
                                    ]"
                                    @dragover.prevent="
                                        moveDraggedProject(project)
                                    "
                                    @drop.prevent="saveProjectOrder"
                                >
                                    <td class="font-bold text-blue-700">
                                        <div class="flex items-center gap-2">
                                            <button
                                                type="button"
                                                class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-blue-200 bg-blue-50 text-blue-600 shadow-sm transition"
                                                :class="
                                                    canReorderProjects &&
                                                    !isSavingProjectOrder
                                                        ? 'cursor-grab hover:border-blue-400 hover:bg-blue-100 hover:text-blue-800 active:cursor-grabbing'
                                                        : 'cursor-not-allowed opacity-40'
                                                "
                                                :draggable="
                                                    canReorderProjects &&
                                                    !isSavingProjectOrder
                                                "
                                                :title="
                                                    canReorderProjects
                                                        ? 'Glisser pour modifier la priorité'
                                                        : 'Sélectionnez une catégorie pour réorganiser'
                                                "
                                                aria-label="Glisser pour modifier la priorité"
                                                @dragstart="
                                                    startProjectDrag(
                                                        $event,
                                                        project,
                                                    )
                                                "
                                                @dragend="
                                                    draggedProjectId !== null
                                                        ? cancelProjectDrag()
                                                        : null
                                                "
                                            >
                                                <MoveVertical
                                                    class="h-4 w-4"
                                                    :stroke-width="2.25"
                                                />
                                            </button>
                                            <span>{{
                                                project.position || "—"
                                            }}</span>
                                        </div>
                                    </td>
                                    <td>
                                        <div
                                            class="font-semibold text-slate-900"
                                        >
                                            {{ project.name }}
                                        </div>
                                    </td>
                                    <!-- <td class="max-w-[260px]">
                                        <div
                                            class="mt-1 font-mono text-xs font-semibold text-blue-700"
                                        >
                                            {{ project.code || "—" }}
                                        </div>
                                    </td> -->
                                    <td>
                                        <div
                                            class="truncate font-medium text-slate-700"
                                            :title="project.action_plan?.name"
                                        >
                                            {{
                                                project.action_plan?.name || "—"
                                            }}
                                        </div>
                                    </td>
                                    <td>
                                        <div
                                            class="truncate text-slate-600"
                                            :title="project.axis?.name"
                                        >
                                            {{ project.axis?.name || "—" }}
                                        </div>
                                    </td>
                                    <td>
                                        <div class="flex flex-wrap gap-1">
                                            <span
                                                v-for="projectDepartment in project.departments"
                                                :key="projectDepartment.id"
                                                class="rounded-full bg-cyan-50 px-2 py-1 text-[10px] font-bold text-cyan-800"
                                                >{{
                                                    projectDepartment.name
                                                }}</span
                                            >
                                        </div>
                                    </td>
                                    <td>
                                        <p class="font-semibold text-slate-800">
                                            {{ project.category?.name || "—" }}
                                        </p>
                                        <p class="text-[10px] text-slate-500">
                                            Chef :
                                            {{
                                                project.category?.manager
                                                    ?.name || "—"
                                            }}
                                        </p>
                                    </td>
                                    <td>
                                        <div
                                            class="truncate text-slate-700"
                                            :title="project.manager?.name"
                                        >
                                            {{
                                                project.manager?.name ||
                                                "Non assigné"
                                            }}
                                        </div>
                                    </td>
                                    <td>
                                        <span
                                            class="wp-status"
                                            :class="
                                                statusClasses[project.status]
                                            "
                                            >{{
                                                statusLabels[project.status] ||
                                                "—"
                                            }}</span
                                        >
                                    </td>
                                    <td>
                                        <span
                                            class="wp-status border border-slate-200 bg-slate-50"
                                            :class="
                                                priorityClasses[
                                                    project.priority
                                                ]
                                            "
                                            >{{
                                                priorityLabels[
                                                    project.priority
                                                ] || "—"
                                            }}</span
                                        >
                                    </td>
                                    <td>
                                        <div
                                            class="flex min-w-[110px] items-center gap-2"
                                        >
                                            <div
                                                class="h-1.5 flex-1 overflow-hidden bg-slate-200"
                                            >
                                                <div
                                                    class="h-full"
                                                    :class="
                                                        progressColor(
                                                            safeProgress(
                                                                project.progress,
                                                            ),
                                                        )
                                                    "
                                                    :style="{
                                                        width: `${safeProgress(project.progress)}%`,
                                                    }"
                                                ></div>
                                            </div>
                                            <span
                                                class="text-xs font-bold text-blue-700"
                                                >{{
                                                    safeProgress(
                                                        project.progress,
                                                    )
                                                }}%</span
                                            >
                                        </div>
                                    </td>
                                    <td
                                        class="whitespace-nowrap font-medium text-amber-800"
                                    >
                                        {{ formatDate(project.due_date) }}
                                    </td>
                                    <td
                                        class="text-center font-bold text-slate-700"
                                    >
                                        {{ project.actions_count }}
                                    </td>
                                    <td class="min-w-[240px] pr-5">
                                        <div
                                            class="flex flex-nowrap items-center justify-end gap-1.5"
                                        >
                                            <button
                                                type="button"
                                                class="wp-icon-btn text-blue-700"
                                                title="Consulter"
                                                aria-label="Consulter"
                                                @click="openWorkspace(project)"
                                            >
                                                <AppIcon
                                                    name="eye"
                                                    class="h-4 w-4"
                                                    :stroke-width="2"
                                                />
                                            </button>
                                            <Link
                                                :href="
                                                    route(
                                                        'workflows.index',
                                                        project.id,
                                                    )
                                                "
                                                class="wp-icon-btn text-cyan-700"
                                                title="Workflows et processus"
                                                aria-label="Workflows et processus"
                                            >
                                                <Workflow
                                                    class="h-4 w-4"
                                                    :stroke-width="2"
                                                />
                                            </Link>
                                            <Link
                                                v-if="canAccessProjectChat(project)"
                                                :href="
                                                    route('project-chat.index', {
                                                        project: project.id,
                                                    })
                                                "
                                                prefetch
                                                class="wp-icon-btn text-emerald-700"
                                                title="Démarrer le chat"
                                                aria-label="Démarrer le chat"
                                            >
                                                <MessageCircleMore
                                                    class="h-4 w-4"
                                                    :stroke-width="2"
                                                />
                                            </Link>
                                            <button
                                                v-if="project.can_manage"
                                                type="button"
                                                class="wp-icon-btn text-slate-700"
                                                title="Dupliquer"
                                                aria-label="Dupliquer"
                                                @click="
                                                    duplicateProject(project)
                                                "
                                            >
                                                <AppIcon
                                                    name="copy"
                                                    class="h-4 w-4"
                                                    :stroke-width="2"
                                                />
                                            </button>
                                            <button
                                                v-if="project.can_manage"
                                                type="button"
                                                class="wp-icon-btn text-violet-700"
                                                title="Modifier"
                                                aria-label="Modifier"
                                                @click="openEditForm(project)"
                                            >
                                                ✎
                                            </button>
                                            <button
                                                v-if="project.can_manage"
                                                type="button"
                                                class="wp-icon-btn text-rose-700"
                                                title="Supprimer"
                                                aria-label="Supprimer"
                                                @click="deleteProject(project)"
                                            >
                                                ✕
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div
                        v-else
                        class="grid place-items-center gap-3 px-6 py-20 text-center"
                    >
                        <div
                            class="flex h-14 w-14 items-center justify-center rounded-[4px] bg-slate-100 text-2xl"
                        >
                            📁
                        </div>
                        <div>
                            <h2 class="font-semibold text-slate-900">
                                Aucun projet trouvé
                            </h2>
                            <p class="mt-1 text-sm text-slate-500">
                                Créez votre premier projet ou modifiez les
                                filtres.
                            </p>
                        </div>
                        <button
                            v-if="canCreateProjects"
                            type="button"
                            class="rounded-[4px] bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500"
                            @click="openCreateForm"
                        >
                            Créer un projet
                        </button>
                    </div>

                    <div
                        v-if="projects.links?.length > 3"
                        class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-4 py-3"
                    >
                        <p class="text-sm text-slate-500">
                            {{ projects.from }}–{{ projects.to }} sur
                            {{ projects.total }}
                        </p>
                        <div class="flex flex-wrap gap-1">
                            <Link
                                v-for="link in projects.links"
                                :key="link.label"
                                :href="link.url || '#'"
                                preserve-scroll
                                class="rounded-[4px] border px-3 py-1.5 text-sm"
                                :class="[
                                    link.active
                                        ? 'border-blue-600 bg-blue-600 text-white'
                                        : 'border-slate-200 bg-white text-slate-600',
                                    !link.url
                                        ? 'pointer-events-none opacity-40'
                                        : 'hover:bg-slate-50',
                                ]"
                                v-html="link.label"
                            />
                        </div>
                    </div>
                </section>
            </div>
        </div>

        <Modal :show="isWorkspaceOpen" max-width="full" @close="closeWorkspace">
            <div
                v-if="isWorkspaceOpen"
                class="flex h-full min-h-0 flex-col bg-white text-slate-800"
                role="document"
                aria-labelledby="workspace-title"
            >
                <header
                    class="shrink-0 border-b border-slate-200 bg-white px-4 py-4 sm:px-6"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p
                                class="text-[11px] font-bold uppercase tracking-widest text-blue-600"
                            >
                                {{ organization.name }}
                                <span class="px-2 text-slate-300">/</span>
                                {{ department.name }}
                            </p>
                            <h2
                                id="workspace-title"
                                class="mt-1 text-xl font-bold tracking-tight text-slate-900 sm:text-2xl"
                            >
                                Pilotage des projets
                            </h2>
                            <p class="mt-1 text-xs text-slate-500">
                                Consultez les projets, leurs actions et leurs
                                échéances.
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[4px] border border-slate-200 text-slate-600 hover:bg-rose-50 hover:text-rose-600"
                            aria-label="Fermer"
                            @click="closeWorkspace"
                        >
                            ✕
                        </button>
                    </div>
                    <div class="mt-4 grid grid-cols-2 gap-2 lg:grid-cols-5">
                        <div
                            class="rounded-[4px] border border-blue-200 bg-blue-50 px-3 py-2"
                        >
                            <p class="text-lg font-bold text-blue-700">
                                {{ selectedWorkspaceProject?.progress ?? 0 }}%
                            </p>
                            <p
                                class="text-[10px] font-bold uppercase tracking-wide text-blue-700"
                            >
                                Progression
                            </p>
                        </div>
                        <div
                            class="rounded-[4px] border border-violet-200 bg-violet-50 px-3 py-2"
                        >
                            <p class="text-lg font-bold text-violet-700">
                                {{ filteredWorkspaceProjects.length }}
                            </p>
                            <p
                                class="text-[10px] font-bold uppercase tracking-wide text-violet-700"
                            >
                                Projets
                            </p>
                        </div>
                        <div
                            class="rounded-[4px] border border-cyan-200 bg-cyan-50 px-3 py-2"
                        >
                            <p class="text-lg font-bold text-cyan-700">
                                {{ workspaceActionCount }}
                            </p>
                            <p
                                class="text-[10px] font-bold uppercase tracking-wide text-cyan-700"
                            >
                                Actions
                            </p>
                        </div>
                        <div
                            class="rounded-[4px] border border-emerald-200 bg-emerald-50 px-3 py-2"
                        >
                            <p class="text-lg font-bold text-emerald-700">
                                {{ workspaceCompletedCount }}
                            </p>
                            <p
                                class="text-[10px] font-bold uppercase tracking-wide text-emerald-700"
                            >
                                Terminées
                            </p>
                        </div>
                        <div
                            class="col-span-2 rounded-[4px] border border-amber-200 bg-amber-50 px-3 py-2 lg:col-span-1"
                        >
                            <p class="text-sm font-bold text-amber-800">
                                {{
                                    formatDate(
                                        selectedWorkspaceProject?.due_date,
                                    )
                                }}
                            </p>
                            <p
                                class="mt-1 text-[10px] font-bold uppercase tracking-wide text-amber-800"
                            >
                                Échéance
                            </p>
                        </div>
                    </div>
                </header>
                <div
                    class="min-h-0 flex-1 overflow-y-auto bg-slate-50 px-3 py-4 sm:px-6"
                >
                    <div class="mb-3 flex flex-wrap items-center gap-2">
                        <div class="relative min-w-[280px] flex-1">
                            <button
                                type="button"
                                class="flex w-full items-center justify-between gap-3 rounded-[4px] border border-slate-300 bg-white px-3 py-2.5 text-left text-sm shadow-sm hover:border-blue-400"
                                :aria-expanded="workspacePickerOpen"
                                @click="
                                    workspacePickerOpen = !workspacePickerOpen
                                "
                            >
                                <span
                                    class="min-w-0 truncate font-semibold text-slate-800"
                                    >⌕ &nbsp;
                                    {{
                                        selectedWorkspaceProject?.name ||
                                        "Sélectionner un projet"
                                    }}</span
                                >
                                <span
                                    class="shrink-0 text-xs font-bold text-blue-600"
                                    >{{
                                        filteredWorkspaceProjects.length
                                    }}
                                    projets &nbsp; ▾</span
                                >
                            </button>
                            <div
                                v-if="workspacePickerOpen"
                                class="absolute inset-x-0 top-full z-30 mt-1 overflow-hidden rounded-[4px] border border-slate-200 bg-white shadow-xl"
                            >
                                <div class="border-b border-slate-200 p-2">
                                    <input
                                        v-model="workspaceSearch"
                                        type="search"
                                        autofocus
                                        placeholder="Rechercher un projet..."
                                        class="w-full rounded-[4px] border-slate-300 text-sm focus:border-blue-500 focus:ring-blue-500"
                                    />
                                </div>
                                <div class="max-h-60 overflow-y-auto">
                                    <button
                                        v-for="project in filteredWorkspaceProjects"
                                        :key="project.id"
                                        type="button"
                                        class="flex w-full items-center justify-between gap-3 border-b border-slate-100 px-3 py-2 text-left text-xs hover:bg-blue-50"
                                        :class="
                                            Number(selectedProjectId) ===
                                            Number(project.id)
                                                ? 'bg-blue-50 text-blue-700'
                                                : 'text-slate-700'
                                        "
                                        @click="selectWorkspaceProject(project)"
                                    >
                                        <span
                                            class="min-w-0 truncate font-semibold"
                                            >{{ project.name }}</span
                                        ><span class="shrink-0 font-bold"
                                            >{{ project.progress }}%</span
                                        >
                                    </button>
                                    <p
                                        v-if="!filteredWorkspaceProjects.length"
                                        class="p-4 text-center text-sm text-slate-500"
                                    >
                                        Aucun projet trouvé
                                    </p>
                                </div>
                            </div>
                        </div>
                        <select
                            v-model="workspacePlanId"
                            class="w-full rounded-[4px] border-slate-300 bg-white py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500 sm:w-[230px]"
                        >
                            <option value="">Tous les plans d’action</option>
                            <option
                                v-for="plan in workspacePlans"
                                :key="plan.id"
                                :value="plan.id"
                            >
                                {{ plan.name }}
                            </option>
                        </select>
                        <div class="ml-auto flex flex-wrap items-center gap-2">
                            <div
                                class="inline-flex rounded-[4px] border border-slate-200 bg-white p-1"
                            >
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-[3px] px-3 py-1.5 text-xs font-semibold"
                                    :class="
                                        workspaceViewMode === 'table'
                                            ? 'bg-blue-600 text-white'
                                            : 'text-slate-600 hover:bg-slate-100'
                                    "
                                    @click="workspaceViewMode = 'table'"
                                >
                                    <Table2 class="h-3.5 w-3.5" />
                                    Tableau
                                </button>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-[3px] px-3 py-1.5 text-xs font-semibold"
                                    :class="
                                        workspaceViewMode === 'planning'
                                            ? 'bg-blue-600 text-white'
                                            : 'text-slate-600 hover:bg-slate-100'
                                    "
                                    @click="workspaceViewMode = 'planning'"
                                >
                                    <CalendarDays class="h-3.5 w-3.5" />
                                    Planning
                                </button>
                            </div>
                            <button
                                v-if="selectedWorkspaceProject?.can_manage"
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-[4px] bg-blue-600 px-3 py-2.5 text-xs font-semibold text-white hover:bg-blue-700"
                                @click="openCreateActionForm"
                            >
                                <Plus class="h-4 w-4" />
                                Ajouter une action
                            </button>
                        </div>
                    </div>
                    <template v-if="selectedWorkspaceProject">
                        <div
                            class="mb-3 flex flex-wrap items-center justify-between gap-2 rounded-[4px] border border-slate-200 bg-white px-3 py-2.5"
                        >
                            <div class="min-w-0">
                                <p
                                    class="truncate text-sm font-bold text-slate-900"
                                >
                                    {{ selectedWorkspaceProject.name }}
                                </p>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    {{
                                        selectedWorkspaceProject.action_plan
                                            ?.name || "--"
                                    }}
                                    <span v-if="selectedWorkspaceProject.axis">
                                        ·
                                        {{
                                            selectedWorkspaceProject.axis.name
                                        }}</span
                                    >
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <div
                                    class="h-1.5 w-20 overflow-hidden rounded-full bg-slate-100"
                                >
                                    <div
                                        class="h-full"
                                        :class="
                                            progressColor(
                                                selectedWorkspaceProject.progress,
                                            )
                                        "
                                        :style="{
                                            width: `${safeProgress(selectedWorkspaceProject.progress)}%`,
                                        }"
                                    />
                                </div>
                                <span class="text-sm font-bold text-blue-700"
                                    >{{
                                        selectedWorkspaceProject.progress
                                    }}%</span
                                >
                            </div>
                        </div>
                        <div class="mb-2">
                            <div
                                class="flex flex-wrap items-center justify-between gap-2"
                            >
                                <h3 class="text-sm font-bold text-slate-900">
                                    Actions du projet
                                    <span class="ml-1 text-slate-500"
                                        >({{ workspaceActionCount }})</span
                                    >
                                </h3>
                                <p
                                    v-if="
                                        selectedWorkspaceProject.can_manage &&
                                        workspaceViewMode === 'table'
                                    "
                                    class="text-[11px] text-slate-500"
                                >
                                    Double-cliquez sur un statut, une date ou la
                                    progression · Entrée pour enregistrer
                                </p>
                            </div>
                        </div>
                        <div
                            v-if="workspaceViewMode === 'planning'"
                            class="mb-3 overflow-hidden rounded-[4px] border border-slate-200 bg-white shadow-sm"
                        >
                            <div
                                class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3"
                            >
                                <div>
                                    <h4
                                        class="text-sm font-bold text-slate-900"
                                    >
                                        Planning complet des actions
                                    </h4>
                                    <p class="mt-0.5 text-xs text-slate-500">
                                        {{ planningActionCount }} action(s)
                                        positionnée(s) ·
                                        {{ planningRangeLabel }}
                                    </p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span
                                        v-if="planningRange"
                                        class="rounded-[4px] bg-slate-100 px-2.5 py-1.5 text-[11px] font-semibold text-slate-600"
                                    >
                                        Vue
                                        {{
                                            planningScale === "day"
                                                ? "journalière"
                                                : "hebdomadaire"
                                        }}
                                    </span>
                                    <select
                                        v-model="planningDateMode"
                                        class="rounded-[4px] border-slate-200 bg-slate-50 py-1.5 text-xs font-semibold text-slate-700 focus:border-blue-500 focus:ring-blue-500"
                                    >
                                        <option value="planned">
                                            Dates prévues
                                        </option>
                                        <option value="actual">
                                            Dates réelles
                                        </option>
                                    </select>
                                </div>
                            </div>
                            <div
                                v-if="planningPeriods.length"
                                class="overflow-x-auto"
                            >
                                <div :style="planningTimelineStyle">
                                    <div
                                        class="grid border-b border-slate-200 bg-slate-50"
                                        :style="planningGridStyle"
                                    >
                                        <div
                                            class="sticky left-0 z-20 flex items-center border-r border-slate-200 bg-slate-50 px-4 py-3 text-xs font-bold text-slate-700"
                                        >
                                            Actions / période
                                        </div>
                                        <div
                                            v-for="period in planningPeriods"
                                            :key="dateKey(period.start)"
                                            class="border-r border-slate-200 py-2 text-center"
                                            :class="
                                                planningPeriodIsToday(period)
                                                    ? 'bg-blue-100 text-blue-700'
                                                    : 'text-slate-600'
                                            "
                                        >
                                            <div class="text-[9px] uppercase">
                                                {{
                                                    planningPeriodCaption(
                                                        period,
                                                    )
                                                }}
                                            </div>
                                            <div
                                                class="whitespace-nowrap text-[11px] font-bold"
                                            >
                                                {{
                                                    planningPeriodLabel(period)
                                                }}
                                            </div>
                                        </div>
                                    </div>
                                    <div
                                        v-for="(
                                            action, index
                                        ) in selectedWorkspaceProject.actions"
                                        :key="action.id"
                                        class="grid min-h-[64px] border-b border-slate-100 last:border-0"
                                        :style="planningGridStyle"
                                    >
                                        <button
                                            type="button"
                                            class="sticky left-0 z-20 border-r border-slate-200 bg-white px-4 py-2 text-left hover:bg-blue-50 disabled:cursor-default"
                                            style="grid-column: 1; grid-row: 1"
                                            :disabled="
                                                !selectedWorkspaceProject.can_manage
                                            "
                                            :title="action.title"
                                            @click="
                                                openEditActionForm(
                                                    action,
                                                    index,
                                                )
                                            "
                                        >
                                            <div
                                                class="truncate text-xs font-semibold text-slate-800"
                                            >
                                                {{ action.title }}
                                            </div>
                                            <div
                                                class="mt-1 flex items-center gap-2 text-[10px] text-slate-500"
                                            >
                                                <span>{{
                                                    actionStatusLabels[
                                                        action.status
                                                    ] || "--"
                                                }}</span>
                                                <span
                                                    class="font-bold text-blue-700"
                                                    >{{
                                                        safeProgress(
                                                            action.progress,
                                                        )
                                                    }}%</span
                                                >
                                            </div>
                                        </button>
                                        <div
                                            v-for="(
                                                period, periodIndex
                                            ) in planningPeriods"
                                            :key="dateKey(period.start)"
                                            class="border-r border-slate-100"
                                            :class="
                                                planningPeriodIsToday(period)
                                                    ? 'bg-blue-50/70'
                                                    : planningScale === 'day' &&
                                                        (period.start.getDay() ===
                                                            0 ||
                                                            period.start.getDay() ===
                                                                6)
                                                      ? 'bg-slate-50'
                                                      : ''
                                            "
                                            :style="{
                                                gridColumn: periodIndex + 2,
                                                gridRow: 1,
                                            }"
                                        />
                                        <div
                                            v-if="actionPlanningSpan(action)"
                                            class="z-10 mx-0.5 my-auto flex h-7 min-w-0 items-center overflow-hidden rounded-[4px] px-2 text-[10px] font-semibold text-white shadow-sm"
                                            :class="
                                                action.status === 'completed'
                                                    ? 'bg-emerald-500'
                                                    : action.status ===
                                                        'on_hold'
                                                      ? 'bg-amber-500'
                                                      : action.status ===
                                                          'cancelled'
                                                        ? 'bg-rose-500'
                                                        : action.status ===
                                                            'in_progress'
                                                          ? 'bg-blue-600'
                                                          : 'bg-slate-500'
                                            "
                                            :style="{
                                                gridColumn:
                                                    actionPlanningSpan(action)
                                                        .gridColumn,
                                                gridRow: 1,
                                            }"
                                            :title="`${action.title} · ${actionPlanningSpan(action).label}`"
                                        >
                                            <span class="truncate">{{
                                                actionPlanningSpan(action).label
                                            }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div v-else class="px-6 py-12 text-center">
                                <CalendarDays
                                    class="mx-auto h-8 w-8 text-slate-300"
                                />
                                <p
                                    class="mt-3 text-sm font-semibold text-slate-700"
                                >
                                    Aucune date
                                    {{
                                        planningDateMode === "planned"
                                            ? "prévue"
                                            : "réelle"
                                    }}
                                    renseignée
                                </p>
                                <p class="mt-1 text-xs text-slate-500">
                                    Ajoutez les dates aux actions pour générer
                                    automatiquement le planning.
                                </p>
                            </div>
                            <div
                                class="flex flex-wrap items-center gap-4 border-t border-slate-200 px-4 py-3 text-[11px] text-slate-600"
                            >
                                <span
                                    ><span
                                        class="mr-1 inline-block h-2.5 w-2.5 rounded-sm bg-slate-500"
                                    ></span
                                    >Non débutée</span
                                >
                                <span
                                    ><span
                                        class="mr-1 inline-block h-2.5 w-2.5 rounded-sm bg-blue-600"
                                    ></span
                                    >En cours</span
                                >
                                <span
                                    ><span
                                        class="mr-1 inline-block h-2.5 w-2.5 rounded-sm bg-emerald-500"
                                    ></span
                                    >Terminée</span
                                >
                                <span
                                    ><span
                                        class="mr-1 inline-block h-2.5 w-2.5 rounded-sm bg-amber-500"
                                    ></span
                                    >En attente</span
                                >
                                <span
                                    ><span
                                        class="mr-1 inline-block h-2.5 w-2.5 rounded-sm bg-rose-500"
                                    ></span
                                    >Annulée</span
                                >
                            </div>
                            <p
                                v-if="
                                    planningActionCount < workspaceActionCount
                                "
                                class="border-t border-slate-100 bg-amber-50 px-4 py-2 text-xs text-amber-700"
                            >
                                {{
                                    workspaceActionCount - planningActionCount
                                }}
                                action(s) sans dates
                                {{
                                    planningDateMode === "planned"
                                        ? "prévues"
                                        : "réelles"
                                }}. Ajoutez leurs dates pour les positionner sur
                                le planning.
                            </p>
                        </div>
                        <div
                            v-if="workspaceViewMode === 'table'"
                            class="overflow-x-auto rounded-[4px] border border-slate-200 bg-white"
                        >
                            <table
                                class="w-full min-w-[1020px] border-collapse text-left text-xs"
                            >
                                <thead
                                    class="bg-slate-100 text-[10px] font-bold uppercase tracking-wider text-slate-600"
                                >
                                    <tr>
                                        <th class="w-10 px-3 py-3">#</th>
                                        <th class="min-w-[240px] px-3 py-3">
                                            Action
                                        </th>
                                        <th class="px-3 py-3">Statut</th>
                                        <th class="px-3 py-3 text-sky-700">
                                            Début prévu
                                        </th>
                                        <th class="px-3 py-3 text-amber-700">
                                            Fin prévue
                                        </th>
                                        <th class="px-3 py-3 text-violet-700">
                                            Début réel
                                        </th>
                                        <th class="px-3 py-3 text-emerald-700">
                                            Fin réelle
                                        </th>
                                        <th class="min-w-[130px] px-3 py-3">
                                            Progression
                                        </th>
                                        <th class="px-3 py-3 text-right">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template
                                        v-for="(
                                            action, index
                                        ) in selectedWorkspaceProject.actions"
                                        :key="action.id"
                                    >
                                        <tr
                                            class="border-t border-slate-200 hover:bg-slate-50"
                                            :class="
                                                expandedActionId === action.id
                                                    ? 'bg-blue-50/50'
                                                    : ''
                                            "
                                        >
                                            <td
                                                class="px-3 py-3 text-slate-500"
                                            >
                                                {{ index + 1 }}
                                            </td>
                                            <td class="px-3 py-3">
                                                <p
                                                    class="font-semibold text-slate-900"
                                                >
                                                    {{ action.title }}
                                                </p>
                                                <p
                                                    class="mt-1 max-w-xs truncate text-[11px] text-slate-500"
                                                >
                                                    {{
                                                        action.description ||
                                                        "--"
                                                    }}
                                                </p>
                                            </td>
                                            <td class="px-3 py-3">
                                                <select
                                                    v-if="
                                                        isInlineActionEditing(
                                                            action,
                                                            'status',
                                                        )
                                                    "
                                                    v-model="
                                                        inlineActionEdit.value
                                                    "
                                                    autofocus
                                                    class="w-32 rounded-[4px] border-blue-400 py-1 text-xs focus:border-blue-500 focus:ring-blue-500"
                                                    :disabled="
                                                        isSavingInlineAction
                                                    "
                                                    @keydown.enter.prevent="
                                                        saveInlineActionEdit(
                                                            action,
                                                        )
                                                    "
                                                    @keydown.escape.stop.prevent="
                                                        cancelInlineActionEdit
                                                    "
                                                >
                                                    <option
                                                        v-for="status in actionStatuses"
                                                        :key="status"
                                                        :value="status"
                                                    >
                                                        {{
                                                            actionStatusLabels[
                                                                status
                                                            ]
                                                        }}
                                                    </option>
                                                </select>
                                                <span
                                                    v-else
                                                    class="inline-flex rounded-[3px] px-2 py-1 font-semibold ring-1 ring-inset"
                                                    :class="[
                                                        actionStatusClasses[
                                                            action.status
                                                        ],
                                                        selectedWorkspaceProject.can_manage
                                                            ? 'cursor-pointer hover:ring-2 hover:ring-blue-400'
                                                            : '',
                                                    ]"
                                                    title="Double-cliquez pour modifier"
                                                    @dblclick="
                                                        startInlineActionEdit(
                                                            action,
                                                            'status',
                                                        )
                                                    "
                                                    >{{
                                                        actionStatusLabels[
                                                            action.status
                                                        ] || "--"
                                                    }}</span
                                                >
                                            </td>
                                            <td class="px-3 py-3">
                                                <input
                                                    v-if="
                                                        isInlineActionEditing(
                                                            action,
                                                            'start_date',
                                                        )
                                                    "
                                                    v-model="
                                                        inlineActionEdit.value
                                                    "
                                                    autofocus
                                                    type="date"
                                                    class="w-32 rounded-[4px] border-sky-400 py-1 text-xs focus:border-sky-500 focus:ring-sky-500"
                                                    :disabled="
                                                        isSavingInlineAction
                                                    "
                                                    @keydown.enter.prevent="
                                                        saveInlineActionEdit(
                                                            action,
                                                        )
                                                    "
                                                    @keydown.escape.stop.prevent="
                                                        cancelInlineActionEdit
                                                    "
                                                />
                                                <span
                                                    v-else
                                                    class="inline-block rounded-[3px] border border-sky-200 bg-sky-50 px-2 py-1 font-semibold text-sky-800"
                                                    :class="
                                                        selectedWorkspaceProject.can_manage
                                                            ? 'cursor-pointer hover:ring-2 hover:ring-sky-300'
                                                            : ''
                                                    "
                                                    title="Double-cliquez pour modifier"
                                                    @dblclick="
                                                        startInlineActionEdit(
                                                            action,
                                                            'start_date',
                                                        )
                                                    "
                                                    >{{
                                                        formatDate(
                                                            action.start_date,
                                                        )
                                                    }}</span
                                                >
                                            </td>
                                            <td class="px-3 py-3">
                                                <input
                                                    v-if="
                                                        isInlineActionEditing(
                                                            action,
                                                            'due_date',
                                                        )
                                                    "
                                                    v-model="
                                                        inlineActionEdit.value
                                                    "
                                                    autofocus
                                                    type="date"
                                                    class="w-32 rounded-[4px] border-amber-400 py-1 text-xs focus:border-amber-500 focus:ring-amber-500"
                                                    :disabled="
                                                        isSavingInlineAction
                                                    "
                                                    @keydown.enter.prevent="
                                                        saveInlineActionEdit(
                                                            action,
                                                        )
                                                    "
                                                    @keydown.escape.stop.prevent="
                                                        cancelInlineActionEdit
                                                    "
                                                />
                                                <span
                                                    v-else
                                                    class="inline-block rounded-[3px] border border-amber-200 bg-amber-50 px-2 py-1 font-semibold text-amber-800"
                                                    :class="
                                                        selectedWorkspaceProject.can_manage
                                                            ? 'cursor-pointer hover:ring-2 hover:ring-amber-300'
                                                            : ''
                                                    "
                                                    title="Double-cliquez pour modifier"
                                                    @dblclick="
                                                        startInlineActionEdit(
                                                            action,
                                                            'due_date',
                                                        )
                                                    "
                                                    >{{
                                                        formatDate(
                                                            action.due_date,
                                                        )
                                                    }}</span
                                                >
                                            </td>
                                            <td class="px-3 py-3">
                                                <input
                                                    v-if="
                                                        isInlineActionEditing(
                                                            action,
                                                            'actual_start_date',
                                                        )
                                                    "
                                                    v-model="
                                                        inlineActionEdit.value
                                                    "
                                                    autofocus
                                                    type="date"
                                                    class="w-32 rounded-[4px] border-violet-400 py-1 text-xs focus:border-violet-500 focus:ring-violet-500"
                                                    :disabled="
                                                        isSavingInlineAction
                                                    "
                                                    @keydown.enter.prevent="
                                                        saveInlineActionEdit(
                                                            action,
                                                        )
                                                    "
                                                    @keydown.escape.stop.prevent="
                                                        cancelInlineActionEdit
                                                    "
                                                />
                                                <span
                                                    v-else
                                                    class="inline-block rounded-[3px] border border-violet-200 bg-violet-50 px-2 py-1 font-semibold text-violet-800"
                                                    :class="
                                                        selectedWorkspaceProject.can_manage
                                                            ? 'cursor-pointer hover:ring-2 hover:ring-violet-300'
                                                            : ''
                                                    "
                                                    title="Double-cliquez pour modifier"
                                                    @dblclick="
                                                        startInlineActionEdit(
                                                            action,
                                                            'actual_start_date',
                                                        )
                                                    "
                                                    >{{
                                                        formatDate(
                                                            action.actual_start_date,
                                                        )
                                                    }}</span
                                                >
                                            </td>
                                            <td class="px-3 py-3">
                                                <input
                                                    v-if="
                                                        isInlineActionEditing(
                                                            action,
                                                            'actual_end_date',
                                                        )
                                                    "
                                                    v-model="
                                                        inlineActionEdit.value
                                                    "
                                                    autofocus
                                                    type="date"
                                                    class="w-32 rounded-[4px] border-emerald-400 py-1 text-xs focus:border-emerald-500 focus:ring-emerald-500"
                                                    :disabled="
                                                        isSavingInlineAction
                                                    "
                                                    @keydown.enter.prevent="
                                                        saveInlineActionEdit(
                                                            action,
                                                        )
                                                    "
                                                    @keydown.escape.stop.prevent="
                                                        cancelInlineActionEdit
                                                    "
                                                />
                                                <span
                                                    v-else
                                                    class="inline-block rounded-[3px] border border-emerald-200 bg-emerald-50 px-2 py-1 font-semibold text-emerald-800"
                                                    :class="
                                                        selectedWorkspaceProject.can_manage
                                                            ? 'cursor-pointer hover:ring-2 hover:ring-emerald-300'
                                                            : ''
                                                    "
                                                    title="Double-cliquez pour modifier"
                                                    @dblclick="
                                                        startInlineActionEdit(
                                                            action,
                                                            'actual_end_date',
                                                        )
                                                    "
                                                    >{{
                                                        formatDate(
                                                            action.actual_end_date,
                                                        )
                                                    }}</span
                                                >
                                            </td>
                                            <td class="px-3 py-3">
                                                <input
                                                    v-if="
                                                        isInlineActionEditing(
                                                            action,
                                                            'progress',
                                                        )
                                                    "
                                                    v-model.number="
                                                        inlineActionEdit.value
                                                    "
                                                    autofocus
                                                    type="number"
                                                    min="0"
                                                    max="100"
                                                    class="w-20 rounded-[4px] border-blue-400 py-1 text-xs font-bold focus:border-blue-500 focus:ring-blue-500"
                                                    :disabled="
                                                        isSavingInlineAction
                                                    "
                                                    @keydown.enter.prevent="
                                                        saveInlineActionEdit(
                                                            action,
                                                        )
                                                    "
                                                    @keydown.escape.stop.prevent="
                                                        cancelInlineActionEdit
                                                    "
                                                />
                                                <div
                                                    v-else
                                                    class="flex items-center gap-2"
                                                    :class="
                                                        selectedWorkspaceProject.can_manage
                                                            ? 'cursor-pointer rounded-[4px] p-1 hover:bg-blue-50'
                                                            : ''
                                                    "
                                                    title="Double-cliquez pour modifier"
                                                    @dblclick="
                                                        startInlineActionEdit(
                                                            action,
                                                            'progress',
                                                        )
                                                    "
                                                >
                                                    <div
                                                        class="h-1.5 w-20 overflow-hidden rounded-full bg-slate-200"
                                                    >
                                                        <div
                                                            class="h-full"
                                                            :class="
                                                                progressColor(
                                                                    action.progress,
                                                                )
                                                            "
                                                            :style="{
                                                                width: `${safeProgress(action.progress)}%`,
                                                            }"
                                                        />
                                                    </div>
                                                    <b
                                                        >{{
                                                            action.progress ??
                                                            0
                                                        }}%</b
                                                    >
                                                </div>
                                            </td>
                                            <td class="px-3 py-3 text-right">
                                                <button
                                                    type="button"
                                                    class="whitespace-nowrap rounded-[4px] border border-blue-200 bg-blue-50 px-2.5 py-1.5 font-semibold text-blue-700 hover:bg-blue-100"
                                                    :aria-expanded="
                                                        expandedActionId ===
                                                        action.id
                                                    "
                                                    @click="
                                                        expandedActionId =
                                                            expandedActionId ===
                                                            action.id
                                                                ? null
                                                                : action.id
                                                    "
                                                >
                                                    <span
                                                        class="inline-flex items-center gap-1.5"
                                                    >
                                                        <EyeOff
                                                            v-if="
                                                                expandedActionId ===
                                                                action.id
                                                            "
                                                            class="h-3.5 w-3.5"
                                                            aria-hidden="true"
                                                        />
                                                        <Eye
                                                            v-else
                                                            class="h-3.5 w-3.5"
                                                            aria-hidden="true"
                                                        />
                                                        {{
                                                            expandedActionId ===
                                                            action.id
                                                                ? "Masquer"
                                                                : "Détails"
                                                        }}
                                                    </span>
                                                </button>
                                            </td>
                                        </tr>
                                        <tr
                                            v-if="
                                                expandedActionId === action.id
                                            "
                                            class="border-t border-blue-200 bg-blue-50/60"
                                        >
                                            <td
                                                colspan="9"
                                                class="px-3 py-3 sm:px-5"
                                            >
                                                <div
                                                    class="flex flex-wrap items-start justify-between gap-3"
                                                >
                                                    <div class="min-w-0">
                                                        <p
                                                            class="text-[10px] font-bold uppercase tracking-wide text-blue-700"
                                                        >
                                                            Détail de l’action
                                                        </p>
                                                        <p
                                                            class="mt-1 text-sm font-bold text-slate-900"
                                                        >
                                                            {{ action.title }}
                                                        </p>
                                                    </div>
                                                    <div
                                                        v-if="
                                                            selectedWorkspaceProject.can_manage
                                                        "
                                                        class="flex flex-wrap gap-2"
                                                    >
                                                        <button
                                                            type="button"
                                                            class="rounded-[4px] border border-blue-200 bg-white px-3 py-1.5 font-semibold text-blue-700"
                                                            @click="
                                                                openEditActionForm(
                                                                    action,
                                                                    index,
                                                                )
                                                            "
                                                        >
                                                            ✎ Modifier</button
                                                        ><button
                                                            type="button"
                                                            class="rounded-[4px] border border-slate-200 bg-white px-3 py-1.5 font-semibold text-slate-700"
                                                            @click="
                                                                duplicateAction(
                                                                    action,
                                                                )
                                                            "
                                                        >
                                                            ▣ Dupliquer</button
                                                        ><button
                                                            type="button"
                                                            class="rounded-[4px] border border-rose-200 bg-white px-3 py-1.5 font-semibold text-rose-700"
                                                            @click="
                                                                deleteAction(
                                                                    action,
                                                                )
                                                            "
                                                        >
                                                            ♜ Supprimer
                                                        </button>
                                                    </div>
                                                </div>
                                                <div
                                                    class="mt-3 grid gap-3 md:grid-cols-2"
                                                >
                                                    <div
                                                        class="min-h-20 rounded-[4px] border border-slate-200 bg-white p-3"
                                                    >
                                                        <p
                                                            class="text-[10px] font-bold uppercase text-slate-500"
                                                        >
                                                            Description
                                                        </p>
                                                        <p
                                                            class="mt-2 whitespace-pre-line text-sm text-slate-700"
                                                        >
                                                            {{
                                                                action.description ||
                                                                "--"
                                                            }}
                                                        </p>
                                                    </div>
                                                    <div
                                                        class="min-h-20 rounded-[4px] border border-violet-200 bg-violet-50 p-3"
                                                    >
                                                        <p
                                                            class="text-[10px] font-bold uppercase text-violet-700"
                                                        >
                                                            Commentaires
                                                        </p>
                                                        <p
                                                            class="mt-2 text-sm text-slate-600"
                                                        >
                                                            {{
                                                                action.comments_count ??
                                                                0
                                                            }}
                                                            commentaire(s)
                                                        </p>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                    <tr
                                        v-if="
                                            !selectedWorkspaceProject.actions
                                                ?.length
                                        "
                                    >
                                        <td
                                            colspan="9"
                                            class="px-5 py-12 text-center text-sm text-slate-500"
                                        >
                                            Aucune action pour ce projet.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <div
                                v-if="inlineActionEdit?.error"
                                class="border-t border-rose-200 bg-rose-50 px-4 py-2 text-xs font-semibold text-rose-700"
                            >
                                {{ inlineActionEdit.error }}
                            </div>
                        </div>
                    </template>
                    <div
                        v-else
                        class="rounded-[4px] border border-dashed border-slate-300 bg-white p-10 text-center text-sm text-slate-500"
                    >
                        Aucun projet disponible.
                    </div>
                </div>
            </div>
        </Modal>

        <Modal
            :show="isActionFormOpen"
            max-width="3xl"
            @close="closeActionForm"
        >
            <form
                class="flex h-[min(90dvh,760px)] max-h-[calc(100dvh-1.5rem)] flex-col overflow-hidden bg-white"
                @submit.prevent="submitAction"
            >
                <div
                    class="flex shrink-0 items-start justify-between gap-4 border-b border-slate-200 bg-slate-50 px-5 py-4 sm:px-6"
                >
                    <div>
                        <p
                            class="text-[10px] font-black uppercase tracking-[0.18em] text-blue-600"
                        >
                            {{ selectedWorkspaceProject?.name }}
                        </p>
                        <h2 class="mt-1 text-xl font-black text-slate-900">
                            {{
                                editingWorkspaceAction
                                    ? "Modifier l’action"
                                    : "Nouvelle action"
                            }}
                        </h2>
                    </div>
                    <button
                        type="button"
                        class="rounded-[4px] p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                        @click="closeActionForm"
                    >
                        ✕
                    </button>
                </div>

                <div
                    class="grid min-h-0 flex-1 gap-3 overflow-y-auto px-5 py-4 sm:grid-cols-2 sm:px-6"
                >
                    <div
                        class="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-[4px] border border-blue-100 bg-blue-50/70 px-4 py-2.5 sm:col-span-2"
                    >
                        <p
                            class="text-[10px] font-black uppercase tracking-[0.18em] text-blue-600"
                        >
                            Parcours guidé
                        </p>
                        <p class="text-xs font-semibold text-slate-800">
                            1. Plan d’action → 2. Axe → 3. Projet
                        </p>
                    </div>
                    <label
                        class="grid gap-1 text-xs font-semibold text-slate-700 sm:col-span-2"
                        >Titre de l’action<input
                            v-model="actionForm.title"
                            required
                            class="rounded-[4px] border-slate-200 py-2 text-sm focus:border-blue-500 focus:ring-blue-500" /><InputError
                            :message="actionForm.errors.title"
                    /></label>
                    <label
                        class="grid gap-1 text-xs font-semibold text-slate-700"
                        >Indice de priorité<input
                            v-model.number="actionForm.position"
                            type="number"
                            min="1"
                            required
                            class="rounded-[4px] border-slate-200 py-2 text-sm focus:border-blue-500 focus:ring-blue-500" /><InputError
                            :message="actionForm.errors.position"
                    /></label>
                    <label
                        class="grid gap-1 text-xs font-semibold text-slate-700"
                        >Statut<select
                            v-model="actionForm.status"
                            class="rounded-[4px] border-slate-200 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                        >
                            <option
                                v-for="status in actionStatuses"
                                :key="status"
                                :value="status"
                            >
                                {{ actionStatusLabels[status] }}
                            </option></select
                        ><InputError :message="actionForm.errors.status"
                    /></label>
                    <label
                        class="grid gap-1 text-xs font-semibold text-slate-700"
                        >Priorité<select
                            v-model="actionForm.priority"
                            class="rounded-[4px] border-slate-200 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                        >
                            <option
                                v-for="priority in actionPriorities"
                                :key="priority"
                                :value="priority"
                            >
                                {{ priorityLabels[priority] }}
                            </option></select
                        ><InputError :message="actionForm.errors.priority"
                    /></label>
                    <label
                        class="grid gap-1 text-xs font-semibold text-slate-700"
                        >Progression — {{ actionForm.progress_value }}%<input
                            v-model.number="actionForm.progress_value"
                            type="range"
                            min="0"
                            max="100"
                            class="accent-blue-600" /><InputError
                            :message="actionForm.errors.progress"
                    /></label>
                    <label
                        class="grid gap-1 text-xs font-semibold text-slate-700"
                        >Début prévu<input
                            v-model="actionForm.start_date"
                            type="date"
                            class="rounded-[4px] border-slate-200 py-2 text-sm focus:border-blue-500 focus:ring-blue-500" /><InputError
                            :message="actionForm.errors.start_date"
                    /></label>
                    <label
                        class="grid gap-1 text-xs font-semibold text-slate-700"
                        >Fin prévue<input
                            v-model="actionForm.due_date"
                            type="date"
                            class="rounded-[4px] border-slate-200 py-2 text-sm focus:border-blue-500 focus:ring-blue-500" /><InputError
                            :message="actionForm.errors.due_date"
                    /></label>
                    <label
                        class="grid gap-1 text-xs font-semibold text-emerald-700"
                        >Début réel<input
                            v-model="actionForm.actual_start_date"
                            type="date"
                            class="rounded-[4px] border-emerald-200 py-2 text-sm focus:border-emerald-500 focus:ring-emerald-500" /><InputError
                            :message="actionForm.errors.actual_start_date"
                    /></label>
                    <label
                        class="grid gap-1 text-xs font-semibold text-emerald-700"
                        >Fin réelle<input
                            v-model="actionForm.actual_end_date"
                            type="date"
                            class="rounded-[4px] border-emerald-200 py-2 text-sm focus:border-emerald-500 focus:ring-emerald-500" /><InputError
                            :message="actionForm.errors.actual_end_date"
                    /></label>
                    <label
                        class="grid gap-1 text-xs font-semibold text-slate-700 sm:col-span-2"
                        >Description<textarea
                            v-model="actionForm.description"
                            rows="3"
                            class="resize-none rounded-[4px] border-slate-200 text-sm focus:border-blue-500 focus:ring-blue-500"
                        ></textarea
                        ><InputError :message="actionForm.errors.description"
                    /></label>
                </div>

                <div
                    class="flex shrink-0 justify-end gap-3 border-t border-slate-200 bg-white px-5 py-3 sm:px-6"
                >
                    <button
                        type="button"
                        class="rounded-[4px] border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-700"
                        @click="closeActionForm"
                    >
                        Annuler
                    </button>
                    <button
                        type="submit"
                        class="rounded-[4px] bg-blue-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-blue-500 disabled:opacity-60"
                        :disabled="actionForm.processing"
                    >
                        {{
                            actionForm.processing
                                ? "Enregistrement..."
                                : "Enregistrer l’action"
                        }}
                    </button>
                </div>
            </form>
        </Modal>

        <Modal :show="isFormOpen" max-width="6xl" @close="closeForm">
            <form
                class="flex h-[min(90dvh,850px)] max-h-[calc(100dvh-1rem)] min-w-0 flex-col overflow-hidden bg-white"
                @submit.prevent="submitProject"
            >
                <div
                    class="z-10 flex shrink-0 items-start justify-between gap-4 border-b border-slate-200 bg-slate-50 px-4 py-4 sm:px-7"
                >
                    <div>
                        <h2 class="text-xl font-bold text-slate-900">
                            {{
                                editingProject
                                    ? "Modifier le projet"
                                    : "Nouveau projet"
                            }}
                        </h2>
                        <p class="mt-1 text-sm text-slate-500">
                            Renseignez les informations de pilotage du projet.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="rounded-[4px] p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                        @click="closeForm"
                    >
                        ✕
                    </button>
                </div>

                <div
                    class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 py-5 sm:px-7"
                >
                    <div class="grid min-w-0 grid-cols-1 gap-4 md:grid-cols-2">
                        <label
                            class="grid gap-1.5 text-sm font-medium text-slate-700 md:col-span-2"
                        >
                            Nom du projet
                            <input
                                v-model="projectForm.name"
                                required
                                class="rounded-[4px] border-slate-200 focus:border-blue-500 focus:ring-blue-500"
                            />
                            <InputError :message="projectForm.errors.name" />
                        </label>
                        <fieldset
                            class="grid gap-2 rounded-[4px] border border-cyan-200 bg-cyan-50/40 p-4 md:col-span-2"
                        >
                            <legend
                                class="px-1 text-sm font-bold text-slate-700"
                            >
                                Départements participants
                            </legend>
                            <p class="text-xs text-slate-500">
                                Un projet peut réunir plusieurs départements de
                                {{ organization.name }}.
                            </p>
                            <div
                                class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3"
                            >
                                <label
                                    v-for="projectDepartment in departments"
                                    :key="projectDepartment.id"
                                    class="flex items-center gap-2 rounded border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700"
                                >
                                    <input
                                        v-model="projectForm.department_ids"
                                        type="checkbox"
                                        :value="projectDepartment.id"
                                        class="rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                                    />
                                    {{ projectDepartment.name }}
                                </label>
                            </div>
                            <InputError
                                :message="projectForm.errors.department_ids"
                            />
                        </fieldset>
                        <section
                            class="space-y-3 border border-blue-200 bg-blue-50/30 p-4 md:col-span-2"
                        >
                            <div
                                class="flex flex-wrap items-center justify-between gap-3"
                            >
                                <div>
                                    <h3
                                        class="text-sm font-bold text-slate-900"
                                    >
                                        Membres et responsables par département
                                    </h3>
                                    <p class="mt-1 text-xs text-slate-500">
                                        Cochez les participants et cliquez sur
                                        l’étoile pour désigner un responsable
                                        par département.
                                    </p>
                                </div>
                                <span
                                    class="bg-white px-3 py-1 text-xs font-bold text-blue-700"
                                    >{{
                                        projectForm.project_members.length
                                    }}
                                    membre(s)</span
                                >
                            </div>
                            <input
                                v-model="memberSearch"
                                type="search"
                                placeholder="Rechercher un collaborateur..."
                                class="w-full border-slate-200 bg-white text-sm focus:border-blue-500 focus:ring-blue-500"
                            />
                            <div
                                v-if="!selectedDepartments.length"
                                class="border border-dashed border-slate-300 bg-white px-4 py-6 text-center text-sm text-slate-500"
                            >
                                Sélectionnez au moins un département pour
                                afficher ses collaborateurs.
                            </div>
                            <div class="grid gap-3 lg:grid-cols-2">
                                <div
                                    v-for="selectedDepartment in selectedDepartments"
                                    :key="selectedDepartment.id"
                                    class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm"
                                >
                                <div
                                    class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-3 py-2"
                                >
                                    <h4
                                        class="text-sm font-bold text-slate-800"
                                    >
                                        {{ selectedDepartment.name }}
                                    </h4>
                                    <span class="text-xs text-slate-500"
                                        >{{
                                            departmentUsers(
                                                selectedDepartment,
                                            ).filter((user) =>
                                                isProjectMember(user.id),
                                            ).length
                                        }}
                                        sélectionné(s)</span
                                    >
                                </div>
                                <div
                                    v-if="
                                        !departmentUsers(selectedDepartment)
                                            .length
                                    "
                                    class="px-3 py-3 text-xs text-amber-700"
                                >
                                    Aucun utilisateur transmis pour ce
                                    département. Vérifiez les données envoyées
                                    par le contrôleur.
                                </div>
                                <div
                                    v-else-if="
                                        !filteredDepartmentUsers(
                                            selectedDepartment,
                                        ).length
                                    "
                                    class="px-3 py-3 text-xs text-slate-500"
                                >
                                    Aucun utilisateur correspondant à la
                                    recherche.
                                </div>
                                    <div
                                        v-for="user in filteredDepartmentUsers(
                                            selectedDepartment,
                                        )"
                                        :key="user.id"
                                        class="flex items-center justify-between gap-3 border-b border-slate-100 px-3 py-2 last:border-0"
                                    >
                                    <label
                                        class="flex min-w-0 flex-1 cursor-pointer items-center gap-3 text-sm text-slate-700"
                                    >
                                        <input
                                            type="checkbox"
                                            :checked="isProjectMember(user.id)"
                                            class="rounded border-slate-300 text-blue-600"
                                            @change="
                                                toggleProjectMember(user.id)
                                            "
                                        />
                                        <span class="truncate">{{
                                            user.name
                                        }}</span>
                                    </label>
                                    <button
                                        type="button"
                                        class="flex shrink-0 items-center gap-1.5 px-2 py-1 text-xs font-semibold"
                                        :class="
                                            String(
                                                projectForm
                                                    .department_responsibles[
                                                    String(
                                                        selectedDepartment.id,
                                                    )
                                                ],
                                            ) === String(user.id)
                                                ? 'text-amber-600'
                                                : 'text-slate-400 hover:text-amber-600'
                                        "
                                        :title="
                                            'Définir comme responsable de ' +
                                            selectedDepartment.name
                                        "
                                        :aria-label="
                                            'Responsable de ' +
                                            selectedDepartment.name
                                        "
                                        @click="
                                            toggleDepartmentResponsible(
                                                selectedDepartment.id,
                                                user.id,
                                            )
                                        "
                                    >
                                        <span class="text-xl leading-none">{{
                                            String(
                                                projectForm
                                                    .department_responsibles[
                                                    String(
                                                        selectedDepartment.id,
                                                    )
                                                ],
                                            ) === String(user.id)
                                                ? "★"
                                                : "☆"
                                        }}</span>
                                        <span class="hidden sm:inline"
                                            >Responsable</span
                                        >
                                    </button>
                                    </div>
                                </div>
                            </div>
                            <InputError
                                :message="projectForm.errors.project_members"
                            />
                            <InputError
                                :message="
                                    projectForm.errors.department_responsibles
                                "
                            />
                        </section>
                        <label
                            class="grid gap-1.5 text-sm font-medium text-slate-700 md:col-span-2"
                        >
                            Catégorie du projet
                            <select
                                v-model="projectForm.project_category_id"
                                required
                                class="rounded-[4px] border-cyan-200 bg-cyan-50/40 focus:border-cyan-500 focus:ring-cyan-500"
                            >
                                <option value="" disabled>
                                    Choisir une catégorie
                                </option>
                                <option
                                    v-for="category in projectCategories"
                                    :key="category.id"
                                    :value="category.id"
                                >
                                    {{ category.name }} · Chef :
                                    {{
                                        category.manager?.name || "Non assigné"
                                    }}
                                </option>
                            </select>
                            <InputError
                                :message="
                                    projectForm.errors.project_category_id
                                "
                            />
                        </label>
                        <label
                            class="grid min-w-0 gap-1.5 text-sm font-medium text-slate-700 md:col-span-2"
                        >
                            Plan d’action
                            <select
                                v-model="projectForm.action_plan_id"
                                class="block w-full min-w-0 max-w-full rounded-[4px] border-blue-200 bg-blue-50/40 text-sm focus:border-blue-500 focus:ring-blue-500"
                                @change="handleActionPlanChange"
                            >
                                <option value="">Sans plan</option>
                                <option
                                    v-for="plan in actionPlans"
                                    :key="plan.id"
                                    :value="plan.id"
                                >
                                    {{ plan.code ? `${plan.code} · ` : ""
                                    }}{{ plan.name }}
                                </option>
                            </select>
                            <InputError
                                :message="projectForm.errors.action_plan_id"
                            />
                        </label>
                        <label
                            class="grid min-w-0 gap-1.5 text-sm font-medium text-slate-700 md:col-span-2"
                        >
                            Axe
                            <select
                                v-model="projectForm.axis_id"
                                class="block w-full min-w-0 max-w-full rounded-[4px] border-violet-200 bg-violet-50/40 text-sm focus:border-violet-500 focus:ring-violet-500 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-400"
                                :disabled="
                                    !projectForm.action_plan_id ||
                                    !availableAxes.length
                                "
                            >
                                <option value="">
                                    {{
                                        availableAxes.length
                                            ? "Sans axe"
                                            : "Aucun axe disponible"
                                    }}
                                </option>
                                <option
                                    v-for="axis in availableAxes"
                                    :key="axis.id"
                                    :value="axis.id"
                                >
                                    {{ axis.code ? `${axis.code} · ` : ""
                                    }}{{ axis.name }}
                                </option>
                            </select>
                            <InputError :message="projectForm.errors.axis_id" />
                        </label>
                        <div
                            class="flex flex-wrap items-center gap-2 rounded-[4px] border border-slate-200 bg-slate-50 px-4 py-3 text-xs font-semibold text-slate-600 md:col-span-2"
                        >
                            <span
                                class="rounded-full bg-blue-600 px-2.5 py-1 text-white"
                                >Plan</span
                            >
                            <span>{{
                                selectedActionPlan?.name ?? "Non rattaché"
                            }}</span>
                            <span class="text-slate-300">→</span>
                            <span
                                class="rounded-full bg-violet-600 px-2.5 py-1 text-white"
                                >Axe</span
                            >
                            <span>{{
                                selectedAxis?.name ?? "Non rattaché"
                            }}</span>
                            <span class="text-slate-300">→</span>
                            <span
                                class="rounded-full bg-slate-800 px-2.5 py-1 text-white"
                                >Projet</span
                            >
                            <span>{{ projectForm.name || "À nommer" }}</span>
                        </div>
                        <label
                            class="grid gap-1.5 text-sm font-medium text-slate-700"
                        >
                            Statut
                            <select
                                v-model="projectForm.status"
                                class="rounded-[4px] border-slate-200 focus:border-blue-500 focus:ring-blue-500"
                            >
                                <option
                                    v-for="status in statuses"
                                    :key="status"
                                    :value="status"
                                >
                                    {{ statusLabels[status] }}
                                </option>
                            </select>
                            <InputError :message="projectForm.errors.status" />
                        </label>
                        <label
                            class="grid gap-1.5 text-sm font-medium text-slate-700"
                        >
                            Priorité
                            <select
                                v-model="projectForm.priority"
                                class="rounded-[4px] border-slate-200 focus:border-blue-500 focus:ring-blue-500"
                            >
                                <option
                                    v-for="priority in priorities"
                                    :key="priority"
                                    :value="priority"
                                >
                                    {{ priorityLabels[priority] }}
                                </option>
                            </select>
                            <InputError
                                :message="projectForm.errors.priority"
                            />
                        </label>
                        <label
                            class="grid gap-1.5 text-sm font-medium text-slate-700"
                        >
                            Ordre de priorité
                            <input
                                v-model.number="projectForm.position"
                                type="number"
                                min="1"
                                required
                                class="rounded-[4px] border-slate-200 focus:border-blue-500 focus:ring-blue-500"
                            />
                            <InputError
                                :message="projectForm.errors.position"
                            />
                        </label>
                        <label
                            class="grid gap-1.5 text-sm font-medium text-slate-700"
                        >
                            Date de début
                            <input
                                v-model="projectForm.start_date"
                                type="date"
                                class="rounded-[4px] border-slate-200 focus:border-blue-500 focus:ring-blue-500"
                            />
                            <InputError
                                :message="projectForm.errors.start_date"
                            />
                        </label>
                        <label
                            class="grid gap-1.5 text-sm font-medium text-slate-700"
                        >
                            Échéance
                            <input
                                v-model="projectForm.due_date"
                                type="date"
                                class="rounded-[4px] border-slate-200 focus:border-blue-500 focus:ring-blue-500"
                            />
                            <InputError
                                :message="projectForm.errors.due_date"
                            />
                        </label>
                        <div
                            class="rounded-[4px] border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-700 md:col-span-2"
                        >
                            <strong>Progression automatique</strong>
                            <p class="mt-1 text-xs leading-5 text-blue-600">
                                Calculée à partir de la moyenne des actions du
                                projet.
                            </p>
                        </div>
                        <label
                            class="grid gap-1.5 text-sm font-medium text-slate-700 md:col-span-2"
                        >
                            Description
                            <textarea
                                v-model="projectForm.description"
                                rows="4"
                                class="rounded-[4px] border-slate-200 focus:border-blue-500 focus:ring-blue-500"
                            ></textarea>
                            <InputError
                                :message="projectForm.errors.description"
                            />
                        </label>
                        <label
                            class="flex items-center gap-3 text-sm font-medium text-slate-700"
                        >
                            <input
                                v-model="projectForm.is_active"
                                type="checkbox"
                                class="rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                            />
                            Projet actif
                        </label>
                        <label
                            class="flex items-center gap-3 text-sm font-medium text-slate-700"
                        >
                            <input
                                v-model="projectForm.is_private"
                                type="checkbox"
                                class="rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                            />
                            Projet privé
                        </label>
                    </div>

                    <InputError :message="projectForm.errors.project" />
                </div>

                <div
                    class="z-10 flex shrink-0 flex-wrap justify-end gap-3 border-t border-slate-200 bg-slate-50 px-4 py-3 sm:px-7"
                >
                    <button
                        type="button"
                        class="rounded-[4px] border border-slate-200 px-5 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                        @click="closeForm"
                    >
                        Annuler
                    </button>
                    <button
                        type="submit"
                        class="rounded-[4px] bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-500 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="projectForm.processing"
                    >
                        {{
                            projectForm.processing
                                ? "Enregistrement..."
                                : "Enregistrer"
                        }}
                    </button>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>

<style scoped>
.wp-kpi {
    border-width: 1px;
    padding: 12px 15px;
    display: flex;
    flex-direction: column;
    gap: 4px;
    border-radius: 4px;
}
.wp-kpi span {
    color: #475569;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
}
.wp-kpi strong {
    color: #0f172a;
    font-size: 24px;
    line-height: 1.25;
}
.wp-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    border-radius: 4px;
    padding: 9px 13px;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
    min-height: 38px;
}
.wp-btn-primary {
    background: #2563eb;
    color: white;
}
.wp-btn-primary:hover {
    background: #1d4ed8;
}
.wp-btn-secondary {
    background: #fff;
    border: 1px solid #cbd5e1;
    color: #334155;
}
.wp-btn-secondary:hover {
    background: #f8fafc;
    border-color: #94a3b8;
}
.wp-field {
    display: block;
    width: 100%;
    border: 1px solid #cbd5e1;
    background: white;
    border-radius: 4px;
    padding: 8px 10px;
    font-size: 13px;
}
.wp-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
    color: #334155;
}
.wp-table th {
    background: #e9eef5;
    color: #475569;
    font-size: 10px;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    font-weight: 800;
    text-align: left;
    padding: 13px 12px;
    white-space: nowrap;
}
.wp-sort {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: inherit;
    font: inherit;
    letter-spacing: inherit;
    text-transform: inherit;
    white-space: nowrap;
}
.wp-sort:hover {
    color: #2563eb;
}
.wp-sort-icon {
    width: 14px;
    height: 14px;
    color: #2563eb;
    stroke-width: 2;
}
.wp-table td {
    padding: 11px 12px;
    border-top: 1px solid #e2e8f0;
    vertical-align: middle;
}
.wp-table tbody tr:hover {
    background: #eef4ff;
}
.wp-status {
    display: inline-block;
    padding: 4px 8px;
    font-size: 11px;
    font-weight: 700;
    border-radius: 3px;
    white-space: nowrap;
}
.wp-icon-btn {
    width: 34px;
    height: 34px;
    flex: 0 0 34px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    box-shadow: 0 1px 2px rgb(15 23 42 / 0.06);
    font-size: 16px;
    font-weight: 700;
    transition:
        transform 150ms ease,
        border-color 150ms ease,
        background-color 150ms ease,
        box-shadow 150ms ease;
}
.wp-icon-btn:hover {
    border-color: #93c5fd;
    background: #eff6ff;
    box-shadow: 0 4px 10px rgb(37 99 235 / 0.12);
    transform: translateY(-1px);
}
.wp-icon-btn:focus-visible {
    outline: 2px solid #3b82f6;
    outline-offset: 2px;
}
.wp-table-scroll {
    scrollbar-width: thin;
    scrollbar-color: #94a3b8 #e2e8f0;
    touch-action: pan-x pan-y;
}
.wp-table-scroll::-webkit-scrollbar {
    height: 10px;
}
.wp-table-scroll::-webkit-scrollbar-track {
    background: #e2e8f0;
}
.wp-table-scroll::-webkit-scrollbar-thumb {
    border: 2px solid #e2e8f0;
    border-radius: 9999px;
    background: #94a3b8;
}
.wp-table-scroll::-webkit-scrollbar-thumb:hover {
    background: #64748b;
}
@media (max-width: 640px) {
    .wp-table th,
    .wp-table td {
        padding: 10px 9px;
    }
}
</style>
