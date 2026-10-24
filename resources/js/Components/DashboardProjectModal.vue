<script setup>
import AppIcon from "@/Components/AppIcon.vue";
import InputError from "@/Components/InputError.vue";
import Modal from "@/Components/Modal.vue";
import { useSweetAlert } from "@/Composables/useSweetAlert";
import { router, useForm } from "@inertiajs/vue3";
import { computed, ref, watch } from "vue";

const props = defineProps({
    show: { type: Boolean, default: false },
    project: { type: Object, default: null },
});

const emit = defineEmits(["close"]);
const { confirmAction } = useSweetAlert();
const selectedActionId = ref(null);
const isActionFormOpen = ref(false);
const editingActionId = ref(null);
const editingCommentId = ref(null);
const commentingActionId = ref(null);
const inlineActionEdit = ref(null);
const isSavingInlineAction = ref(false);

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
    progress: 0,
    position: 1,
});

const commentForm = useForm({ action_id: "", content: "" });
const editCommentForm = useForm({ content: "" });

const selectedAction = computed(
    () =>
        props.project?.actions?.find(
            (action) => Number(action.id) === Number(selectedActionId.value),
        ) ?? null,
);

watch(
    () => props.project?.id,
    () => {
        selectedActionId.value = null;
        isActionFormOpen.value = false;
        editingActionId.value = null;
        editingCommentId.value = null;
        inlineActionEdit.value = null;
    },
);

const statusLabels = {
    not_started: "Non débutée",
    in_progress: "En cours",
    on_hold: "En attente",
    completed: "Terminée",
    cancelled: "Annulée",
};

const priorityLabels = {
    low: "Faible",
    medium: "Moyenne",
    high: "Haute",
    critical: "Critique",
};
const statusClasses = {
    not_started: "bg-slate-100 text-slate-600",
    in_progress: "bg-blue-50 text-blue-700",
    on_hold: "bg-amber-50 text-amber-700",
    completed: "bg-emerald-50 text-emerald-700",
    cancelled: "bg-rose-50 text-rose-700",
};

const formatDate = (value) =>
    value
        ? new Intl.DateTimeFormat("fr-FR", {
              day: "2-digit",
              month: "short",
              year: "numeric",
          }).format(new Date(value))
        : "—";
const formatDateTime = (value) =>
    value
        ? new Intl.DateTimeFormat("fr-FR", {
              day: "2-digit",
              month: "short",
              hour: "2-digit",
              minute: "2-digit",
          }).format(new Date(value))
        : "";
const dateInputValue = (value) => value?.slice(0, 10) ?? "";
const safeProgress = (value) => Math.min(100, Math.max(0, Number(value) || 0));
const startInlineActionEdit = (action, field) => {
    if (!props.project?.can_manage_actions || isSavingInlineAction.value) {
        return;
    }

    inlineActionEdit.value = {
        actionId: action.id,
        field,
        value: field === "progress"
            ? safeProgress(action.progress)
            : field.includes("date")
                ? dateInputValue(action[field])
                : action[field],
        error: "",
    };
};
const isInlineActionEditing = (action, field) => inlineActionEdit.value?.actionId === action.id
    && inlineActionEdit.value?.field === field;
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

    const data = { [edit.field]: edit.field === "progress" ? safeProgress(edit.value) : edit.value || null };

    if (["start_date", "due_date"].includes(edit.field)) {
        data.start_date = edit.field === "start_date" ? data.start_date : dateInputValue(action.start_date) || null;
        data.due_date = edit.field === "due_date" ? data.due_date : dateInputValue(action.due_date) || null;
    }

    if (["actual_start_date", "actual_end_date"].includes(edit.field)) {
        data.actual_start_date = edit.field === "actual_start_date" ? data.actual_start_date : dateInputValue(action.actual_start_date) || null;
        data.actual_end_date = edit.field === "actual_end_date" ? data.actual_end_date : dateInputValue(action.actual_end_date) || null;
    }

    edit.error = "";
    isSavingInlineAction.value = true;
    router.patch(route("actions.update", action.id), data, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            inlineActionEdit.value = null;
        },
        onError: (errors) => {
            edit.error = errors[edit.field] || Object.values(errors)[0] || "La modification n’a pas pu être enregistrée.";
        },
        onFinish: () => {
            isSavingInlineAction.value = false;
        },
    });
};
const initials = (name) =>
    name
        ?.split(" ")
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join("")
        .toUpperCase() || "?";

const resetActionForm = () => {
    actionForm.reset();
    actionForm.clearErrors();
    actionForm.project_id = props.project?.id ?? "";
    actionForm.action_plan_id = props.project?.action_plan_id ?? "";
    actionForm.axis_id = props.project?.axis_id ?? "";
    actionForm.status = "not_started";
    actionForm.priority = "medium";
    actionForm.progress = 0;
    actionForm.position = (props.project?.actions?.length ?? 0) + 1;
};

const openCreateAction = () => {
    editingActionId.value = null;
    resetActionForm();
    isActionFormOpen.value = true;
};

const openEditAction = (action) => {
    editingActionId.value = action.id;
    actionForm.clearErrors();
    Object.assign(actionForm, {
        project_id: props.project.id,
        action_plan_id: props.project.action_plan_id ?? "",
        axis_id: props.project.axis_id ?? "",
        title: action.title ?? "",
        description: action.description ?? "",
        start_date: dateInputValue(action.start_date),
        due_date: dateInputValue(action.due_date),
        actual_start_date: dateInputValue(action.actual_start_date),
        actual_end_date: dateInputValue(action.actual_end_date),
        status: action.status,
        priority: action.priority,
        progress: action.progress ?? 0,
        position: action.position ?? 1,
    });
    isActionFormOpen.value = true;
};

const closeActionForm = () => {
    if (!actionForm.processing) {
        isActionFormOpen.value = false;
        editingActionId.value = null;
        resetActionForm();
    }
};

const submitAction = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            selectedActionId.value = editingActionId.value;
            isActionFormOpen.value = false;
            editingActionId.value = null;
        },
    };

    if (editingActionId.value) {
        actionForm.patch(
            route("actions.update", editingActionId.value),
            options,
        );
        return;
    }

    actionForm.post(route("actions.store"), options);
};

const deleteAction = async (action) => {
    const confirmed = await confirmAction({
        title: `Supprimer « ${action.title} » ?`,
        text: "L’action et son historique seront supprimés définitivement.",
        confirmButtonText: "Supprimer",
    });

    if (!confirmed) return;

    router.delete(route("actions.destroy", action.id), {
        preserveScroll: true,
        onSuccess: () => {
            selectedActionId.value = null;
        },
    });
};

const submitComment = (action) => {
    commentForm.action_id = action.id;
    commentForm.post(route("action-comments.store"), {
        preserveScroll: true,
        onSuccess: () => {
            commentForm.reset();
            commentingActionId.value = null;
        },
    });
};

const startEditingComment = (comment) => {
    editingCommentId.value = comment.id;
    editCommentForm.content = comment.content;
    editCommentForm.clearErrors();
};

const updateComment = () => {
    editCommentForm.patch(
        route("action-comments.update", editingCommentId.value),
        {
            preserveScroll: true,
            onSuccess: () => {
                editingCommentId.value = null;
                editCommentForm.reset();
            },
        },
    );
};

const deleteComment = async (comment) => {
    if (
        !(await confirmAction({
            title: "Supprimer ce commentaire ?",
            confirmButtonText: "Supprimer",
        }))
    )
        return;

    router.delete(route("action-comments.destroy", comment.id), {
        preserveScroll: true,
    });
};

const toggleAction = (action) => {
    selectedActionId.value =
        Number(selectedActionId.value) === Number(action.id) ? null : action.id;
    editingCommentId.value = null;
    commentForm.reset();
};

const openCommentForm = (action) => {
    commentingActionId.value = action.id;
    commentForm.reset();
    commentForm.clearErrors();
};
</script>

<template>
    <Modal :show="show" max-width="screen" @close="emit('close')">
        <div
            v-if="project"
            class="workpilot-project-modal flex max-h-[94dvh] min-h-0 flex-col overflow-hidden rounded-[3px] bg-[#f0f3f7] text-slate-900"
        >
            <header
                class="shrink-0 border-b border-[#cbd5e1] bg-[#f6f8fc] px-4 py-4 sm:px-7"
            >
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0 flex-1">
                        <div
                            class="flex flex-wrap gap-x-3 gap-y-1 text-[11px] font-semibold uppercase tracking-wide text-blue-700"
                        >
                            <span class="inline-flex items-center gap-1.5"
                                ><svg
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    class="h-4 w-4"
                                >
                                    <path d="M4 7h16v13H4zM8 7V4h8v3" /></svg
                                >{{ project.code || "Projet" }}</span
                            ><span class="text-slate-400">/</span
                            ><span>{{
                                project.action_plan?.name || "Plan d’action"
                            }}</span>
                        </div>
                        <h2
                            class="mt-1 text-xl font-bold leading-tight tracking-tight text-[#162238] sm:text-2xl"
                        >
                            {{ project.name }}
                        </h2>
                    </div>
                    <button
                        type="button"
                        class="grid h-9 w-9 shrink-0 place-items-center rounded-[3px] border border-slate-300 bg-white text-xl text-slate-600 hover:bg-slate-100 focus-visible:ring-2 focus-visible:ring-blue-500"
                        aria-label="Fermer"
                        @click="emit('close')"
                    >
                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            class="h-4 w-4"
                        >
                            <path d="M18 6 6 18M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <div
                        class="rounded-[3px] border border-[#cbd9ed] bg-[#eaf1fc] px-3 py-2"
                    >
                        <p
                            class="text-[10px] font-semibold uppercase tracking-wide text-slate-500"
                        >
                            Actions
                        </p>
                        <p class="mt-1 text-lg font-bold">
                            {{ project.actions?.length ?? 0 }}
                        </p>
                    </div>
                    <div
                        class="rounded-[3px] border border-[#c8d7f5] bg-[#e8efff] px-3 py-2"
                    >
                        <p
                            class="text-[10px] font-semibold uppercase tracking-wide text-slate-500"
                        >
                            Progression globale
                        </p>
                        <p class="mt-1 text-lg font-bold text-blue-700">
                            {{ project.progress ?? 0 }}%
                        </p>
                    </div>
                    <div
                        class="rounded-[3px] border border-[#b9e4d4] bg-[#e4f5ec] px-3 py-2"
                    >
                        <p
                            class="text-[10px] font-semibold uppercase tracking-wide text-slate-500"
                        >
                            Actions terminées
                        </p>
                        <p class="mt-1 text-lg font-bold text-emerald-700">
                            {{
                                project.actions?.filter(
                                    (a) => a.status === "completed",
                                ).length ?? 0
                            }}
                        </p>
                    </div>
                    <div
                        class="rounded-[3px] border border-[#f1d2a7] bg-[#fff2df] px-3 py-2"
                    >
                        <p
                            class="text-[10px] font-semibold uppercase tracking-wide text-slate-500"
                        >
                            <span class="inline-flex items-center gap-1"
                                ><svg
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    class="h-3.5 w-3.5"
                                >
                                    <rect
                                        x="3"
                                        y="5"
                                        width="18"
                                        height="16"
                                        rx="1"
                                    />
                                    <path d="M7 3v4M17 3v4M3 10h18" /></svg
                                >Échéance projet</span
                            >
                        </p>
                        <p class="mt-1 text-sm font-bold text-amber-800">
                            {{ formatDate(project.due_date) }}
                        </p>
                    </div>
                </div>
                <div
                    class="mt-3 h-1.5 overflow-hidden rounded-[2px] bg-slate-200"
                >
                    <div
                        class="h-full bg-blue-600"
                        :style="{
                            width: `${Math.min(100, Math.max(0, Number(project.progress) || 0))}%`,
                        }"
                    />
                </div>
            </header>

            <div class="min-h-0 flex-1 overflow-y-auto p-3 sm:p-6">
                <section
                    v-if="!isActionFormOpen"
                    class="overflow-hidden rounded-[3px] border border-[#d8dee8] bg-[#fafbfc]"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 sm:px-5"
                    >
                        <div>
                            <h3 class="text-base font-bold">
                                Actions du projet
                            </h3>
                            <p class="text-xs text-slate-500">
                                Suivi opérationnel et progression par action
                            </p>
                            <p v-if="project.can_manage_actions" class="mt-1 text-[10px] font-medium text-blue-600">
                                Double-cliquez sur une valeur · Entrée pour enregistrer · Échap pour annuler
                            </p>
                        </div>
                        <button
                            v-if="project.can_manage_actions"
                            type="button"
                            class="rounded-[3px] bg-blue-700 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-800"
                            @click="openCreateAction"
                        >
                            <span class="inline-flex items-center gap-1.5"
                                ><svg
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    class="h-4 w-4"
                                >
                                    <path d="M12 5v14M5 12h14" /></svg
                                >Ajouter une action</span
                            >
                        </button>
                    </div>
                    <div
                        class="w-full max-w-full overflow-x-auto overscroll-x-contain"
                    >
                        <table
                            class="w-full min-w-[1420px] table-fixed border-collapse text-left text-xs"
                        >
                            <colgroup>
                                <col style="width: 28%" />
                                <col style="width: 11%" />
                                <col style="width: 8%" />
                                <col style="width: 12%" />
                                <col style="width: 12%" />
                                <col style="width: 13%" />
                                <col style="width: 16%" />
                            </colgroup>
                            <thead
                                class="bg-[#e9eef5] text-[11px] font-semibold uppercase tracking-wide text-slate-600"
                            >
                                <tr>
                                    <th class="px-4 py-3">Action</th>
                                    <th class="px-3 py-3">Statut</th>
                                    <th class="px-3 py-3">Priorité</th>
                                    <th class="px-3 py-3">Début prévu</th>
                                    <th class="px-3 py-3">Échéance</th>
                                    <th class="px-3 py-3">Progression</th>
                                    <th class="px-3 py-3 text-right">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200">
                                <template
                                    v-for="(action, index) in project.actions ||
                                    []"
                                    :key="action.id"
                                >
                                    <tr
                                        class="bg-[#fafbfc] hover:bg-blue-50/50"
                                        :class="
                                            Number(selectedActionId) ===
                                            Number(action.id)
                                                ? 'bg-blue-50/70'
                                                : ''
                                        "
                                    >
                                        <td class="px-4 py-3">
                                            <div class="flex items-start gap-2">
                                                <span
                                                    class="mt-0.5 min-w-5 text-[11px] font-semibold text-slate-400"
                                                    >{{ index + 1 }}.</span
                                                >
                                                <div class="min-w-0">
                                                    <p
                                                        class="font-semibold leading-5 text-slate-900 break-words"
                                                    >
                                                        {{ action.title }}
                                                    </p>
                                                    <p
                                                        class="mt-0.5 text-[11px] text-slate-500"
                                                    >
                                                        {{
                                                            action.comments
                                                                ?.length ?? 0
                                                        }}
                                                        commentaire(s)
                                                    </p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-3 py-3">
                                            <select
                                                v-if="isInlineActionEditing(action, 'status')"
                                                v-model="inlineActionEdit.value"
                                                autofocus
                                                class="w-full rounded-[3px] border-blue-400 py-1 text-xs focus:border-blue-500 focus:ring-blue-500"
                                                :disabled="isSavingInlineAction"
                                                @keydown.enter.prevent="saveInlineActionEdit(action)"
                                                @keydown.escape.stop.prevent="cancelInlineActionEdit"
                                            >
                                                <option v-for="(label, status) in statusLabels" :key="status" :value="status">{{ label }}</option>
                                            </select>
                                            <span
                                                v-else
                                                class="inline-flex rounded-[2px] px-2 py-1 text-[11px] font-semibold"
                                                :class="[statusClasses[action.status] || statusClasses.not_started, project.can_manage_actions ? 'cursor-pointer hover:ring-2 hover:ring-blue-300' : '']"
                                                title="Double-cliquez pour modifier"
                                                @dblclick="startInlineActionEdit(action, 'status')"
                                                >{{
                                                    statusLabels[
                                                        action.status
                                                    ] || action.status
                                                }}</span
                                            >
                                        </td>
                                        <td class="px-3 py-3 font-medium">
                                            <select
                                                v-if="isInlineActionEditing(action, 'priority')"
                                                v-model="inlineActionEdit.value"
                                                autofocus
                                                class="w-full rounded-[3px] border-blue-400 py-1 text-xs focus:border-blue-500 focus:ring-blue-500"
                                                :disabled="isSavingInlineAction"
                                                @keydown.enter.prevent="saveInlineActionEdit(action)"
                                                @keydown.escape.stop.prevent="cancelInlineActionEdit"
                                            >
                                                <option v-for="(label, priority) in priorityLabels" :key="priority" :value="priority">{{ label }}</option>
                                            </select>
                                            <span v-else class="rounded px-1.5 py-1" :class="project.can_manage_actions ? 'cursor-pointer hover:bg-blue-50 hover:ring-2 hover:ring-blue-200' : ''" title="Double-cliquez pour modifier" @dblclick="startInlineActionEdit(action, 'priority')">{{ priorityLabels[action.priority] || action.priority }}</span>
                                        </td>
                                        <td
                                            class="px-3 py-3 font-semibold text-sky-800"
                                        >
                                            <input
                                                v-if="isInlineActionEditing(action, 'start_date')"
                                                v-model="inlineActionEdit.value"
                                                autofocus
                                                type="date"
                                                class="w-full rounded-[3px] border-sky-400 py-1 text-xs focus:border-sky-500 focus:ring-sky-500"
                                                :disabled="isSavingInlineAction"
                                                @keydown.enter.prevent="saveInlineActionEdit(action)"
                                                @keydown.escape.stop.prevent="cancelInlineActionEdit"
                                            />
                                            <span
                                                v-else
                                                class="inline-flex items-center gap-1.5"
                                                :class="project.can_manage_actions ? 'cursor-pointer rounded px-1.5 py-1 hover:bg-sky-50 hover:ring-2 hover:ring-sky-200' : ''"
                                                title="Double-cliquez pour modifier"
                                                @dblclick="startInlineActionEdit(action, 'start_date')"
                                                ><svg
                                                    viewBox="0 0 24 24"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    stroke-width="1.8"
                                                    class="h-3.5 w-3.5 shrink-0"
                                                >
                                                    <rect
                                                        x="3"
                                                        y="5"
                                                        width="18"
                                                        height="16"
                                                        rx="1"
                                                    />
                                                    <path
                                                        d="M7 3v4M17 3v4M3 10h18"
                                                    /></svg
                                                >{{
                                                    formatDate(
                                                        action.start_date,
                                                    )
                                                }}</span
                                            >
                                        </td>
                                        <td
                                            class="px-3 py-3 font-semibold text-amber-800"
                                        >
                                            <input
                                                v-if="isInlineActionEditing(action, 'due_date')"
                                                v-model="inlineActionEdit.value"
                                                autofocus
                                                type="date"
                                                class="w-full rounded-[3px] border-amber-400 py-1 text-xs focus:border-amber-500 focus:ring-amber-500"
                                                :disabled="isSavingInlineAction"
                                                @keydown.enter.prevent="saveInlineActionEdit(action)"
                                                @keydown.escape.stop.prevent="cancelInlineActionEdit"
                                            />
                                            <span
                                                v-else
                                                class="inline-flex items-center gap-1.5"
                                                :class="project.can_manage_actions ? 'cursor-pointer rounded px-1.5 py-1 hover:bg-amber-50 hover:ring-2 hover:ring-amber-200' : ''"
                                                title="Double-cliquez pour modifier"
                                                @dblclick="startInlineActionEdit(action, 'due_date')"
                                                ><svg
                                                    viewBox="0 0 24 24"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    stroke-width="1.8"
                                                    class="h-3.5 w-3.5 shrink-0"
                                                >
                                                    <rect
                                                        x="3"
                                                        y="5"
                                                        width="18"
                                                        height="16"
                                                        rx="1"
                                                    />
                                                    <path
                                                        d="M7 3v4M17 3v4M3 10h18"
                                                    /></svg
                                                >{{
                                                    formatDate(action.due_date)
                                                }}</span
                                            >
                                        </td>
                                        <td class="px-3 py-3">
                                            <input
                                                v-if="isInlineActionEditing(action, 'progress')"
                                                v-model.number="inlineActionEdit.value"
                                                autofocus
                                                type="number"
                                                min="0"
                                                max="100"
                                                class="w-20 rounded-[3px] border-blue-400 py-1 text-xs font-bold focus:border-blue-500 focus:ring-blue-500"
                                                :disabled="isSavingInlineAction"
                                                @keydown.enter.prevent="saveInlineActionEdit(action)"
                                                @keydown.escape.stop.prevent="cancelInlineActionEdit"
                                            />
                                            <div
                                                v-else
                                                class="flex min-w-[145px] items-center gap-2 whitespace-nowrap"
                                                :class="project.can_manage_actions ? 'cursor-pointer rounded px-1 py-1 hover:bg-blue-50' : ''"
                                                title="Double-cliquez pour modifier"
                                                @dblclick="startInlineActionEdit(action, 'progress')"
                                            >
                                                <div
                                                    class="h-1.5 min-w-[75px] flex-1 overflow-hidden rounded-[2px] bg-slate-200"
                                                >
                                                    <div
                                                        class="h-full bg-blue-600"
                                                        :style="{
                                                            width: `${Math.min(100, Math.max(0, Number(action.progress) || 0))}%`,
                                                        }"
                                                    />
                                                </div>
                                                <span
                                                    class="w-9 text-right font-semibold"
                                                    >{{
                                                        action.progress ?? 0
                                                    }}%</span
                                                >
                                            </div>
                                        </td>
                                        <td class="px-3 py-3">
                                            <div
                                                class="flex min-w-[205px] flex-nowrap justify-end gap-1 whitespace-nowrap"
                                            >
                                                <button
                                                    type="button"
                                                    class="rounded-[3px] border border-slate-300 bg-white px-2 py-1.5 font-semibold text-blue-700 hover:bg-blue-50"
                                                    :aria-expanded="
                                                        Number(
                                                            selectedActionId,
                                                        ) === Number(action.id)
                                                    "
                                                    @click="
                                                        toggleAction(action)
                                                    "
                                                >
                                                    <span
                                                        class="inline-flex items-center gap-1"
                                                        ><svg
                                                            v-if="
                                                                Number(
                                                                    selectedActionId,
                                                                ) ===
                                                                Number(
                                                                    action.id,
                                                                )
                                                            "
                                                            viewBox="0 0 24 24"
                                                            fill="none"
                                                            stroke="currentColor"
                                                            stroke-width="1.8"
                                                            stroke-linecap="round"
                                                            class="h-3.5 w-3.5"
                                                        >
                                                            <path
                                                                d="M3 3l18 18M10.6 5.1A10.7 10.7 0 0 1 12 5c6 0 10 7 10 7a16 16 0 0 1-3.2 3.6M6.2 6.2C3.6 8.1 2 12 2 12s4 7 10 7a9.5 9.5 0 0 0 4.2-1"
                                                            />
                                                            <path
                                                                d="M10 10a3 3 0 0 0 4 4"
                                                            /></svg
                                                        ><svg
                                                            v-else
                                                            viewBox="0 0 24 24"
                                                            fill="none"
                                                            stroke="currentColor"
                                                            stroke-width="1.8"
                                                            class="h-3.5 w-3.5"
                                                        >
                                                            <path
                                                                d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z"
                                                            />
                                                            <circle
                                                                cx="12"
                                                                cy="12"
                                                                r="3"
                                                            /></svg
                                                        >{{
                                                            Number(
                                                                selectedActionId,
                                                            ) ===
                                                            Number(action.id)
                                                                ? "Fermer"
                                                                : "Détails"
                                                        }}</span
                                                    ></button
                                                ><button
                                                    v-if="
                                                        project.can_manage_actions
                                                    "
                                                    type="button"
                                                    class="rounded-[3px] border border-slate-300 bg-white px-2 py-1.5 font-semibold text-slate-700 hover:bg-slate-100"
                                                    @click="
                                                        openEditAction(action)
                                                    "
                                                >
                                                    <span
                                                        class="inline-flex items-center gap-1"
                                                        ><svg
                                                            viewBox="0 0 24 24"
                                                            fill="none"
                                                            stroke="currentColor"
                                                            stroke-width="1.8"
                                                            class="h-3.5 w-3.5"
                                                        >
                                                            <path
                                                                d="m4 20 4-.8L20 7l-3-3L5 16z"
                                                            /></svg
                                                        >Modifier</span
                                                    ></button
                                                ><button
                                                    v-if="
                                                        project.can_manage_actions
                                                    "
                                                    type="button"
                                                    class="rounded-[3px] border border-rose-200 bg-rose-50 px-2 py-1.5 font-semibold text-rose-700 hover:bg-rose-100"
                                                    @click="
                                                        deleteAction(action)
                                                    "
                                                    aria-label="Supprimer"
                                                >
                                                    <svg
                                                        viewBox="0 0 24 24"
                                                        fill="none"
                                                        stroke="currentColor"
                                                        stroke-width="1.8"
                                                        class="h-4 w-4"
                                                    >
                                                        <path
                                                            d="M4 7h16M10 4h4M6 7l1 14h10l1-14M10 11v6M14 11v6"
                                                        />
                                                    </svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr
                                        v-if="
                                            Number(selectedActionId) ===
                                            Number(action.id)
                                        "
                                    >
                                        <td
                                            colspan="7"
                                            class="bg-[#f1f4f8] px-4 py-4 sm:px-6"
                                        >
                                            <div
                                                class="grid gap-4 lg:grid-cols-[1fr_1.2fr]"
                                            >
                                                <div class="space-y-3">
                                                    <div>
                                                        <h4
                                                            class="text-xs font-bold uppercase tracking-wide text-slate-600"
                                                        >
                                                            Description
                                                        </h4>
                                                        <p
                                                            class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-700"
                                                        >
                                                            {{
                                                                action.description ||
                                                                "Aucune description renseignée."
                                                            }}
                                                        </p>
                                                    </div>
                                                    <div
                                                        class="grid grid-cols-1 gap-2 sm:grid-cols-2"
                                                    >
                                                        <div
                                                            v-for="item in [
                                                                {
                                                                    label: 'Début prévu',
                                                                    field: 'start_date',
                                                                    value: action.start_date,
                                                                    box: 'border-sky-200 bg-sky-50',
                                                                    ink: 'text-sky-800',
                                                                    icon: 'calendar',
                                                                },
                                                                {
                                                                    label: 'Fin prévue',
                                                                    field: 'due_date',
                                                                    value: action.due_date,
                                                                    box: 'border-amber-200 bg-amber-50',
                                                                    ink: 'text-amber-800',
                                                                    icon: 'calendar',
                                                                },
                                                                {
                                                                    label: 'Début réel',
                                                                    field: 'actual_start_date',
                                                                    value: action.actual_start_date,
                                                                    box: 'border-violet-200 bg-violet-50',
                                                                    ink: 'text-violet-800',
                                                                    icon: 'calendar',
                                                                },
                                                                {
                                                                    label: 'Fin réelle',
                                                                    field: 'actual_end_date',
                                                                    value: action.actual_end_date,
                                                                    box: 'border-emerald-200 bg-emerald-50',
                                                                    ink: 'text-emerald-800',
                                                                    icon: 'calendar',
                                                                },
                                                            ]"
                                                            :key="item.label"
                                                            class="rounded-[3px] border p-3"
                                                            :class="item.box"
                                                        >
                                                            <p
                                                                class="flex items-center gap-1.5 text-[11px] font-semibold"
                                                                :class="
                                                                    item.ink
                                                                "
                                                            >
                                                                <svg
                                                                    viewBox="0 0 24 24"
                                                                    fill="none"
                                                                    stroke="currentColor"
                                                                    stroke-width="1.8"
                                                                    class="h-4 w-4"
                                                                >
                                                                    <rect
                                                                        x="3"
                                                                        y="5"
                                                                        width="18"
                                                                        height="16"
                                                                        rx="1"
                                                                    />
                                                                    <path
                                                                        d="M7 3v4M17 3v4M3 10h18"
                                                                    /></svg
                                                                >{{
                                                                    item.label
                                                                }}
                                                            </p>
                                                            <input
                                                                v-if="isInlineActionEditing(action, item.field)"
                                                                v-model="inlineActionEdit.value"
                                                                autofocus
                                                                type="date"
                                                                class="mt-2 block w-full rounded-[3px] border-slate-300 bg-white py-1.5 text-xs text-slate-800 focus:border-blue-500 focus:ring-blue-500"
                                                                :disabled="isSavingInlineAction"
                                                                @keydown.enter.prevent="saveInlineActionEdit(action)"
                                                                @keydown.escape.stop.prevent="cancelInlineActionEdit"
                                                            />
                                                            <p
                                                                v-else
                                                                class="mt-1 text-sm font-bold"
                                                                :class="[item.ink, project.can_manage_actions ? 'cursor-pointer rounded px-1 py-1 hover:bg-white/70 hover:ring-2 hover:ring-blue-200' : '']"
                                                                title="Double-cliquez pour modifier"
                                                                @dblclick="startInlineActionEdit(action, item.field)"
                                                            >
                                                                {{
                                                                    formatDate(
                                                                        item.value,
                                                                    )
                                                                }}
                                                            </p>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div
                                                    class="rounded-[3px] border border-indigo-200 bg-indigo-50/60 p-3"
                                                >
                                                    <div
                                                        class="flex items-center justify-between gap-2"
                                                    >
                                                        <h4
                                                            class="flex items-center gap-2 text-sm font-bold text-indigo-900"
                                                        >
                                                            <svg
                                                                viewBox="0 0 24 24"
                                                                fill="none"
                                                                stroke="currentColor"
                                                                stroke-width="1.8"
                                                                class="h-4 w-4"
                                                            >
                                                                <path
                                                                    d="M21 11.5a8.5 8.5 0 0 1-8.5 8.5 9 9 0 0 1-4-.9L3 21l1.9-5.5A9 9 0 1 1 21 11.5Z"
                                                                /></svg
                                                            >Commentaires ({{
                                                                action.comments
                                                                    ?.length ??
                                                                0
                                                            }})
                                                        </h4>
                                                        <button
                                                            type="button"
                                                            class="rounded-[3px] border border-indigo-300 bg-white px-2 py-1 text-xs font-semibold text-indigo-800 hover:bg-indigo-100"
                                                            @click="
                                                                openCommentForm(
                                                                    action,
                                                                )
                                                            "
                                                        >
                                                            + Ajouter
                                                        </button>
                                                    </div>
                                                    <div
                                                        v-if="
                                                            action.comments
                                                                ?.length
                                                        "
                                                        class="mt-3 max-h-52 space-y-2 overflow-y-auto"
                                                    >
                                                        <div
                                                            v-for="comment in action.comments"
                                                            :key="comment.id"
                                                            class="rounded-[3px] border border-indigo-100 bg-white p-3"
                                                        >
                                                            <div
                                                                class="flex flex-wrap items-center justify-between gap-2"
                                                            >
                                                                <div>
                                                                    <span
                                                                        class="text-xs font-bold"
                                                                        >{{
                                                                            comment
                                                                                .user
                                                                                ?.name ||
                                                                            "Utilisateur"
                                                                        }}</span
                                                                    ><span
                                                                        class="ml-2 text-[10px] text-slate-500"
                                                                        >{{
                                                                            formatDateTime(
                                                                                comment.created_at,
                                                                            )
                                                                        }}</span
                                                                    >
                                                                </div>
                                                                <div
                                                                    v-if="
                                                                        comment.can_edit
                                                                    "
                                                                    class="flex gap-2"
                                                                >
                                                                    <button
                                                                        type="button"
                                                                        class="text-xs font-semibold text-blue-700"
                                                                        @click="
                                                                            startEditingComment(
                                                                                comment,
                                                                            )
                                                                        "
                                                                    >
                                                                        Modifier</button
                                                                    ><button
                                                                        type="button"
                                                                        class="text-xs font-semibold text-rose-700"
                                                                        @click="
                                                                            deleteComment(
                                                                                comment,
                                                                            )
                                                                        "
                                                                    >
                                                                        Supprimer
                                                                    </button>
                                                                </div>
                                                            </div>
                                                            <form
                                                                v-if="
                                                                    Number(
                                                                        editingCommentId,
                                                                    ) ===
                                                                    Number(
                                                                        comment.id,
                                                                    )
                                                                "
                                                                class="mt-2 space-y-2"
                                                                @submit.prevent="
                                                                    updateComment
                                                                "
                                                            >
                                                                <textarea
                                                                    v-model="
                                                                        editCommentForm.content
                                                                    "
                                                                    rows="2"
                                                                    class="w-full rounded-[3px] border-slate-300 text-sm"
                                                                    required
                                                                ></textarea
                                                                ><InputError
                                                                    :message="
                                                                        editCommentForm
                                                                            .errors
                                                                            .content
                                                                    "
                                                                />
                                                                <div
                                                                    class="flex justify-end gap-2"
                                                                >
                                                                    <button
                                                                        type="button"
                                                                        class="rounded-[3px] border border-slate-300 px-2 py-1 text-xs"
                                                                        @click="
                                                                            editingCommentId =
                                                                                null
                                                                        "
                                                                    >
                                                                        Annuler</button
                                                                    ><button
                                                                        type="submit"
                                                                        :disabled="
                                                                            editCommentForm.processing
                                                                        "
                                                                        class="rounded-[3px] bg-blue-700 px-2 py-1 text-xs text-white"
                                                                    >
                                                                        Enregistrer
                                                                    </button>
                                                                </div>
                                                            </form>
                                                            <p
                                                                v-else
                                                                class="mt-1 whitespace-pre-line text-xs text-slate-700"
                                                            >
                                                                {{
                                                                    comment.content
                                                                }}
                                                            </p>
                                                        </div>
                                                    </div>
                                                    <p
                                                        v-else
                                                        class="mt-3 rounded-[3px] border border-dashed border-indigo-200 bg-white/80 p-3 text-xs text-indigo-700"
                                                    >
                                                        Aucun commentaire pour
                                                        cette action.
                                                    </p>
                                                    <form
                                                        v-if="
                                                            Number(
                                                                commentingActionId,
                                                            ) ===
                                                            Number(action.id)
                                                        "
                                                        class="mt-3 space-y-2 border-t border-slate-200 pt-3"
                                                        @submit.prevent="
                                                            submitComment(
                                                                action,
                                                            )
                                                        "
                                                    >
                                                        <textarea
                                                            v-model="
                                                                commentForm.content
                                                            "
                                                            required
                                                            rows="2"
                                                            placeholder="Votre commentaire…"
                                                            class="w-full rounded-[3px] border-slate-300 text-sm"
                                                        ></textarea
                                                        ><InputError
                                                            :message="
                                                                commentForm
                                                                    .errors
                                                                    .content
                                                            "
                                                        />
                                                        <div
                                                            class="flex justify-end gap-2"
                                                        >
                                                            <button
                                                                type="button"
                                                                class="rounded-[3px] border border-slate-300 px-3 py-1.5 text-xs"
                                                                @click="
                                                                    commentingActionId =
                                                                        null
                                                                "
                                                            >
                                                                Annuler</button
                                                            ><button
                                                                type="submit"
                                                                :disabled="
                                                                    commentForm.processing
                                                                "
                                                                class="rounded-[3px] bg-blue-700 px-3 py-1.5 text-xs font-semibold text-white"
                                                            >
                                                                Publier
                                                            </button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                                <tr v-if="!project.actions?.length">
                                    <td
                                        colspan="7"
                                        class="px-4 py-12 text-center text-sm text-slate-500"
                                    >
                                        Aucune action pour ce projet.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <div v-if="inlineActionEdit?.error" class="border-t border-rose-200 bg-rose-50 px-4 py-2 text-xs font-semibold text-rose-700">
                            {{ inlineActionEdit.error }}
                        </div>
                    </div>
                    <div
                        class="border-t border-slate-200 bg-[#f1f4f8] px-4 py-2 text-xs text-slate-600"
                    >
                        Total :
                        <strong>{{ project.actions?.length ?? 0 }}</strong>
                        actions · Progression globale :
                        <strong>{{ project.progress ?? 0 }}%</strong>
                    </div>
                </section>

                <section
                    v-else
                    class="mx-auto max-w-4xl overflow-hidden rounded-[3px] border border-slate-200 bg-[#fafbfc]"
                >
                    <div
                        class="flex items-center justify-between border-b border-slate-200 px-4 py-3"
                    >
                        <h3 class="text-base font-bold">
                            {{
                                editingActionId
                                    ? "Modifier l’action"
                                    : "Nouvelle action"
                            }}
                        </h3>
                        <button
                            type="button"
                            class="rounded-[3px] border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold"
                            @click="closeActionForm"
                        >
                            Retour au tableau
                        </button>
                    </div>
                    <form
                        class="grid gap-4 p-4 sm:grid-cols-2 sm:p-6"
                        @submit.prevent="submitAction"
                    >
                        <label
                            class="grid gap-1 text-xs font-semibold sm:col-span-2"
                            >Titre<input
                                v-model="actionForm.title"
                                required
                                class="w-full rounded-[3px] border-slate-300 text-sm" /><InputError
                                :message="actionForm.errors.title"
                        /></label>
                        <label class="grid gap-1 text-xs font-semibold"
                            >Statut<select
                                v-model="actionForm.status"
                                class="w-full rounded-[3px] border-slate-300 text-sm"
                            >
                                <option
                                    v-for="(label, value) in statusLabels"
                                    :key="value"
                                    :value="value"
                                >
                                    {{ label }}
                                </option></select
                            ><InputError :message="actionForm.errors.status"
                        /></label>
                        <label class="grid gap-1 text-xs font-semibold"
                            >Priorité<select
                                v-model="actionForm.priority"
                                class="w-full rounded-[3px] border-slate-300 text-sm"
                            >
                                <option
                                    v-for="(label, value) in priorityLabels"
                                    :key="value"
                                    :value="value"
                                >
                                    {{ label }}
                                </option></select
                            ><InputError :message="actionForm.errors.priority"
                        /></label>
                        <label class="grid gap-1 text-xs font-semibold"
                            >Indice de priorité<input
                                v-model.number="actionForm.position"
                                type="number"
                                min="1"
                                required
                                class="w-full rounded-[3px] border-slate-300 text-sm" /><InputError
                                :message="actionForm.errors.position"
                        /></label>
                        <label
                            class="grid gap-2 text-xs font-semibold sm:col-span-2"
                            ><span class="flex justify-between"
                                >Progression
                                <strong class="text-blue-700"
                                    >{{ actionForm.progress }}%</strong
                                ></span
                            ><input
                                v-model.number="actionForm.progress"
                                type="range"
                                min="0"
                                max="100"
                                class="accent-blue-700" /><InputError
                                :message="actionForm.errors.progress"
                        /></label>
                        <label
                            v-for="item in [
                                { key: 'start_date', label: 'Début prévu' },
                                { key: 'due_date', label: 'Fin prévue' },
                                {
                                    key: 'actual_start_date',
                                    label: 'Début réel',
                                },
                                { key: 'actual_end_date', label: 'Fin réelle' },
                            ]"
                            :key="item.key"
                            class="grid gap-1 text-xs font-semibold"
                            >{{ item.label
                            }}<input
                                v-model="actionForm[item.key]"
                                type="date"
                                class="w-full rounded-[3px] border-slate-300 text-sm" /><InputError
                                :message="actionForm.errors[item.key]"
                        /></label>
                        <label
                            class="grid gap-1 text-xs font-semibold sm:col-span-2"
                            >Description<textarea
                                v-model="actionForm.description"
                                rows="4"
                                class="w-full rounded-[3px] border-slate-300 text-sm"
                            ></textarea
                            ><InputError
                                :message="actionForm.errors.description"
                        /></label>
                        <div
                            class="flex justify-end gap-2 border-t border-slate-200 pt-4 sm:col-span-2"
                        >
                            <button
                                type="button"
                                class="rounded-[3px] border border-slate-300 bg-white px-4 py-2 text-xs font-semibold"
                                @click="closeActionForm"
                            >
                                Annuler</button
                            ><button
                                type="submit"
                                :disabled="actionForm.processing"
                                class="rounded-[3px] bg-blue-700 px-4 py-2 text-xs font-semibold text-white hover:bg-blue-800 disabled:opacity-50"
                            >
                                {{
                                    actionForm.processing
                                        ? "Enregistrement…"
                                        : "Enregistrer"
                                }}
                            </button>
                        </div>
                    </form>
                </section>
            </div>
        </div>
    </Modal>
</template>

<style>
/* Modal.vue possède son propre conteneur arrondi : le cibler aussi. */
.rounded-lg:has(> .workpilot-project-modal),
.rounded-xl:has(> .workpilot-project-modal),
.rounded-2xl:has(> .workpilot-project-modal),
.rounded-3xl:has(> .workpilot-project-modal),
div:has(> .workpilot-project-modal) {
    border-radius: 3px !important;
}
.workpilot-project-modal,
.workpilot-project-modal section {
    border-radius: 3px !important;
}
.workpilot-project-modal button,
.workpilot-project-modal input,
.workpilot-project-modal textarea,
.workpilot-project-modal select {
    border-radius: 3px !important;
}
.workpilot-project-modal .rounded-full {
    border-radius: 9999px !important;
}
</style>
