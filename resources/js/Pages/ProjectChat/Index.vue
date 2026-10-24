<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref } from "vue";
import { Head } from "@inertiajs/vue3";
import axios from "axios";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";

const props = defineProps({
    projects: {
        type: Array,
        default: () => [],
    },
    currentUserId: {
        type: Number,
        required: true,
    },
});

const search = ref("");
const chatProjects = ref([...props.projects]);
const refreshingGroups = ref(false);
let groupsTimer = null;
const selected = ref(null);
const messages = ref([]);
const members = ref([]);
const reads = ref([]);
const draft = ref("");
const files = ref([]);

const loading = ref(false);
const sending = ref(false);
const error = ref("");
const showMembers = ref(false);
const mobileChat = ref(false);
const detailsId = ref(null);

const messagePane = ref(null);
const filePicker = ref(null);

let timer = null;
let sequence = 0;
let loadingMessages = false;

const colors = [
    { bg: "#dbeafe", text: "#1d4ed8", accent: "#2563eb" },
    { bg: "#fce7f3", text: "#be185d", accent: "#db2777" },
    { bg: "#dcfce7", text: "#15803d", accent: "#16a34a" },
    { bg: "#fef3c7", text: "#b45309", accent: "#d97706" },
    { bg: "#ede9fe", text: "#6d28d9", accent: "#7c3aed" },
    { bg: "#ccfbf1", text: "#0f766e", accent: "#0d9488" },
    { bg: "#ffedd5", text: "#c2410c", accent: "#ea580c" },
    { bg: "#e0e7ff", text: "#4338ca", accent: "#4f46e5" },
    { bg: "#f1f5f9", text: "#334155", accent: "#475569" },
    { bg: "#fae8ff", text: "#a21caf", accent: "#c026d3" },
];

function projectColor(project) {
    const id = Number(project?.id || 0);
    return colors[Math.abs(id) % colors.length];
}

function avatarStyle(project) {
    const color = projectColor(project);

    return {
        backgroundColor: color.bg,
        color: color.text,
    };
}

const filteredProjects = computed(() => {
    const q = search.value.trim().toLowerCase();

    return chatProjects.value.filter((p) =>
        `${p.name} ${p.code || ""}`.toLowerCase().includes(q),
    );
});

const endpoint = (id) => `/project-chat/projects/${id}`;

function sortProjects(projects) {
    return [...projects].sort((a, b) => {
        const lastA = Number(a.last_message_id || 0);
        const lastB = Number(b.last_message_id || 0);
        if (lastA !== lastB) return lastB - lastA;
        const dateA = Date.parse(a.last_message_at || a.created_at || 0) || 0;
        const dateB = Date.parse(b.last_message_at || b.created_at || 0) || 0;
        return (
            dateB - dateA || String(a.name).localeCompare(String(b.name), "fr")
        );
    });
}

async function refreshGroups() {
    if (refreshingGroups.value) return;
    refreshingGroups.value = true;
    try {
        const { data } = await axios.get("/project-chat/groups");
        if (Array.isArray(data.projects)) {
            chatProjects.value = sortProjects(data.projects);
            if (selected.value) {
                selected.value =
                    chatProjects.value.find(
                        (p) => Number(p.id) === Number(selected.value.id),
                    ) || selected.value;
            }
        }
    } catch (e) {
        console.warn("Actualisation des groupes impossible", e);
    } finally {
        refreshingGroups.value = false;
    }
}

function groupTime(project) {
    const date = project.last_message_at;
    if (!date) return "";
    const d = new Date(date);
    if (Number.isNaN(d.getTime())) return "";
    const today = new Date();
    return d.toDateString() === today.toDateString()
        ? formatTime(date)
        : d.toLocaleDateString("fr-FR", { day: "2-digit", month: "2-digit" });
}

function initials(name) {
    return (name || "?")
        .trim()
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word[0].toUpperCase())
        .join("");
}

function formatTime(date) {
    if (!date) return "";

    const value = new Date(date);

    if (Number.isNaN(value.getTime())) return "";

    return value.toLocaleTimeString("fr-FR", {
        hour: "2-digit",
        minute: "2-digit",
    });
}

function formatDate(date) {
    if (!date) return "";

    const value = new Date(date);

    if (Number.isNaN(value.getTime())) return "";

    return value.toLocaleDateString("fr-FR", {
        day: "2-digit",
        month: "long",
        year: "numeric",
    });
}

function formatSize(bytes) {
    if (!bytes) return "0 Ko";

    if (bytes >= 1048576) {
        return `${(bytes / 1048576).toFixed(1)} Mo`;
    }

    return `${Math.ceil(bytes / 1024)} Ko`;
}

function isMine(message) {
    return Number(message.user_id) === Number(props.currentUserId);
}

function seen(message) {
    return members.value.filter(
        (user) =>
            Number(user.id) !== Number(message.user_id) &&
            reads.value.some(
                (read) =>
                    Number(read.user_id) === Number(user.id) &&
                    Number(read.last_read_message_id || 0) >=
                        Number(message.id),
            ),
    );
}

function unseen(message) {
    const seenIds = seen(message).map((user) => Number(user.id));

    return members.value.filter(
        (user) =>
            Number(user.id) !== Number(message.user_id) &&
            !seenIds.includes(Number(user.id)),
    );
}

function scrollEnd() {
    nextTick(() => {
        if (messagePane.value) {
            messagePane.value.scrollTop = messagePane.value.scrollHeight;
        }
    });
}

async function markRead() {
    if (
        document.visibilityState !== "visible" ||
        !selected.value ||
        !messages.value.length
    ) {
        return;
    }

    const projectId = selected.value.id;
    const lastId = messages.value.at(-1).id;

    const existing = reads.value.find(
        (r) => Number(r.user_id) === Number(props.currentUserId),
    );

    if (Number(existing?.last_read_message_id || 0) >= Number(lastId)) {
        return;
    }

    try {
        await axios.post(`${endpoint(projectId)}/read`, {
            message_id: lastId,
        });

        if (selected.value?.id !== projectId) return;

        if (existing) {
            existing.last_read_message_id = lastId;
        } else {
            reads.value.push({
                user_id: props.currentUserId,
                last_read_message_id: lastId,
            });
        }
    } catch (e) {
        console.warn("Lecture non confirmée", e);
    }
}

async function load(silent = false) {
    if (!selected.value || loadingMessages) return;

    const id = selected.value.id;
    const version = sequence;

    loadingMessages = true;

    if (!silent) loading.value = true;

    try {
        const { data } = await axios.get(endpoint(id));

        if (version !== sequence || selected.value?.id !== id) {
            return;
        }

        const previousId = messages.value.at(-1)?.id;

        messages.value = data.messages || [];
        members.value = data.members || [];
        reads.value = data.reads || [];

        if (!silent || previousId !== messages.value.at(-1)?.id) {
            scrollEnd();
        }

        await markRead();
        await refreshGroups();
    } catch (e) {
        if (version !== sequence) return;

        if (e.response?.status === 403) {
            error.value = "Vous n'avez plus accès à ce projet.";
            clearInterval(timer);
        } else if (!silent) {
            error.value = "Impossible de charger la discussion.";
        }
    } finally {
        loadingMessages = false;

        if (version === sequence) {
            loading.value = false;
        }
    }
}

function openProject(project) {
    sequence++;
    clearInterval(timer);

    selected.value = project;
    mobileChat.value = true;

    messages.value = [];
    members.value = [];
    reads.value = [];
    files.value = [];
    draft.value = "";
    detailsId.value = null;
    showMembers.value = false;
    error.value = "";

    window.history.replaceState({}, "", `/project-chat?project=${project.id}`);

    load();

    timer = setInterval(() => {
        if (
            document.visibilityState === "visible" &&
            !loading.value &&
            !sending.value
        ) {
            load(true);
        }
    }, 5000);
}

function addFiles(event) {
    const incoming = [...(event.target.files || [])];

    if (files.value.length + incoming.length > 20) {
        error.value = "Maximum 20 fichiers par message.";
        event.target.value = "";
        return;
    }

    const tooLarge = incoming.find((file) => file.size > 100 * 1024 * 1024);

    if (tooLarge) {
        error.value = `Le fichier ${tooLarge.name} dépasse 100 Mo.`;
        event.target.value = "";
        return;
    }

    files.value.push(...incoming);
    error.value = "";
    event.target.value = "";
}

function removeFile(index) {
    files.value.splice(index, 1);
}

async function send() {
    if (
        !selected.value ||
        sending.value ||
        (!draft.value.trim() && !files.value.length)
    ) {
        return;
    }

    const projectId = selected.value.id;
    const text = draft.value;
    const attachments = [...files.value];

    sending.value = true;
    error.value = "";

    const data = new FormData();

    data.append("body", text);

    attachments.forEach((file) => {
        data.append("files[]", file);
    });

    try {
        await axios.post(`${endpoint(projectId)}/messages`, data);

        if (selected.value?.id !== projectId) return;

        draft.value = "";
        files.value = [];

        await load(true);
        await refreshGroups();
        scrollEnd();
    } catch (e) {
        const errors = e.response?.data?.errors;

        error.value = errors
            ? Object.values(errors).flat().join(" ")
            : e.response?.data?.message || "Échec de l'envoi.";
    } finally {
        sending.value = false;
    }
}

function handleVisibility() {
    if (document.visibilityState === "visible") {
        load(true);
        refreshGroups();
    }
}

function fileExtension(name) {
    return (name || "").split(".").pop()?.toUpperCase() || "FILE";
}

function isPreviewableImage(file) {
    return [
        "image/avif",
        "image/bmp",
        "image/gif",
        "image/jpeg",
        "image/png",
        "image/webp",
    ].includes(String(file?.mime_type || "").toLowerCase());
}

function attachmentUrl(file, preview = false) {
    const url = `/project-chat/attachments/${file.id}`;

    return preview ? `${url}?preview=1` : url;
}

onMounted(() => {
    const id = Number(
        new URLSearchParams(window.location.search).get("project"),
    );

    const first =
        props.projects.find((p) => Number(p.id) === id) || props.projects[0];

    chatProjects.value = sortProjects(chatProjects.value);
    refreshGroups().then(() => {
        const chosen =
            chatProjects.value.find((p) => Number(p.id) === id) ||
            chatProjects.value[0];
        if (chosen && !selected.value) openProject(chosen);
    });
    groupsTimer = setInterval(() => {
        if (document.visibilityState === "visible") refreshGroups();
    }, 5000);

    document.addEventListener("visibilitychange", handleVisibility);
});

onUnmounted(() => {
    clearInterval(timer);
    clearInterval(groupsTimer);
    sequence++;

    document.removeEventListener("visibilitychange", handleVisibility);
});
</script>

<template>
    <Head title="Discussions des projets - WorkPilot" />

    <AuthenticatedLayout>
        <div class="wp-chat-page">
            <div class="wp-chat-container">
                <!-- CHAT -->
                <div class="wp-chat-shell">
                    <!-- GROUPES -->
                    <aside
                        class="wp-chat-sidebar"
                        :class="{ 'mobile-hidden': mobileChat }"
                    >
                        <div class="wp-sidebar-header">
                            <div class="wp-sidebar-title">
                                <div class="wp-sidebar-heading">
                                    <span class="wp-sidebar-logo">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                            <path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4Z" />
                                            <path d="M8 9h8M8 13h5" />
                                        </svg>
                                    </span>

                                    <div>
                                        <h2>Groupes projets</h2>
                                        <p>Collaboration en temps réel</p>
                                    </div>
                                </div>

                                <span class="wp-total">
                                    {{ chatProjects.length }}
                                </span>
                            </div>

                            <div class="wp-search">
                                <svg
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                >
                                    <circle cx="11" cy="11" r="7" />
                                    <path d="m20 20-4-4" />
                                </svg>

                                <input
                                    v-model="search"
                                    type="search"
                                    placeholder="Rechercher une discussion..."
                                />
                            </div>
                        </div>

                        <div class="wp-group-list">
                            <button
                                v-for="project in filteredProjects"
                                :key="project.id"
                                type="button"
                                class="wp-group-item"
                                :class="{
                                    active: selected?.id === project.id,
                                    'has-unread':
                                        Number(project.unread_count || 0) > 0,
                                }"
                                :style="{
                                    '--group-accent':
                                        projectColor(project).accent,
                                }"
                                @click="openProject(project)"
                            >
                                <div
                                    class="wp-group-avatar"
                                    :style="avatarStyle(project)"
                                >
                                    {{ initials(project.name) }}
                                </div>

                                <div class="wp-group-info">
                                    <div class="wp-group-top">
                                        <strong>{{ project.name }}</strong>
                                        <time
                                            v-if="project.last_message_at"
                                            class="wp-group-time"
                                            >{{ groupTime(project) }}</time
                                        >
                                    </div>

                                    <p>
                                        {{ project.code || "Groupe du projet" }}
                                    </p>
                                </div>

                                <span
                                    v-if="Number(project.unread_count || 0) > 0"
                                    class="wp-unread-badge"
                                >
                                    {{
                                        Number(project.unread_count) > 99
                                            ? "99+"
                                            : project.unread_count
                                    }}
                                </span>
                            </button>

                            <div
                                v-if="!filteredProjects.length"
                                class="wp-no-groups"
                            >
                                Aucun groupe trouvé.
                            </div>
                        </div>
                    </aside>

                    <!-- CONVERSATION -->
                    <main
                        class="wp-chat-main"
                        :class="{ 'mobile-hidden': !mobileChat }"
                    >
                        <template v-if="selected">
                            <!-- CONVERSATION HEADER -->
                            <header class="wp-conversation-header">
                                <div class="wp-conversation-identity">
                                    <button
                                        type="button"
                                        class="wp-back-button"
                                        @click="mobileChat = false"
                                    >
                                        ←
                                    </button>

                                    <div
                                        class="wp-group-avatar wp-header-avatar"
                                        :style="avatarStyle(selected)"
                                    >
                                        {{ initials(selected.name) }}
                                    </div>

                                    <div class="wp-conversation-info">
                                        <h2>{{ selected.name }}</h2>

                                        <p>
                                            <span class="wp-online-dot"></span>
                                            {{ members.length }} participants
                                            <span v-if="selected.code"> · {{ selected.code }}</span>
                                        </p>
                                    </div>
                                </div>

                                <button
                                    type="button"
                                    class="wp-members-button"
                                    @click="showMembers = !showMembers"
                                >
                                    <svg
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.8"
                                    >
                                        <path
                                            d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"
                                        />
                                        <circle cx="9" cy="7" r="4" />
                                        <path
                                            d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"
                                        />
                                    </svg>

                                    <span>Membres</span>

                                    <span class="wp-members-count">
                                        {{ members.length }}
                                    </span>
                                </button>
                            </header>

                            <div class="wp-conversation-content">
                                <div class="wp-conversation-column">
                                    <!-- MESSAGES -->
                                    <div ref="messagePane" class="wp-messages">
                                        <div
                                            v-if="loading"
                                            class="wp-chat-placeholder"
                                        >
                                            Chargement des messages...
                                        </div>

                                        <div
                                            v-else-if="!messages.length"
                                            class="wp-chat-placeholder"
                                        >
                                            <div class="wp-empty-icon">
                                                <svg
                                                    viewBox="0 0 24 24"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    stroke-width="1.5"
                                                >
                                                    <path
                                                        d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8A8.5 8.5 0 0 1 12.5 3H13a8.48 8.48 0 0 1 8 8v.5z"
                                                    />
                                                </svg>
                                            </div>

                                            <h3>Bienvenue dans ce groupe</h3>

                                            <p>
                                                Envoyez votre premier message à
                                                l'équipe.
                                            </p>
                                        </div>

                                        <div
                                            v-for="(message, index) in messages"
                                            :key="message.id"
                                        >
                                            <!-- DATE -->
                                            <div
                                                v-if="
                                                    index === 0 ||
                                                    formatDate(
                                                        messages[index - 1]
                                                            .created_at,
                                                    ) !==
                                                        formatDate(
                                                            message.created_at,
                                                        )
                                                "
                                                class="wp-date-separator"
                                            >
                                                <span>
                                                    {{
                                                        formatDate(
                                                            message.created_at,
                                                        )
                                                    }}
                                                </span>
                                            </div>

                                            <!-- MESSAGE -->
                                            <div
                                                class="wp-message-row"
                                                :class="{
                                                    mine: isMine(message),
                                                }"
                                            >
                                                <div
                                                    class="wp-message-bubble"
                                                    :class="{
                                                        'wp-message-mine':
                                                            isMine(message),
                                                    }"
                                                >
                                                    <div
                                                        class="wp-message-author"
                                                    >
                                                        {{
                                                            message.user
                                                                ?.name ||
                                                            "Utilisateur"
                                                        }}
                                                    </div>

                                                    <div
                                                        v-if="message.body"
                                                        class="wp-message-text"
                                                    >
                                                        {{ message.body }}
                                                    </div>

                                                    <!-- ATTACHMENTS -->
                                                    <div
                                                        v-if="
                                                            message.attachments
                                                                ?.length
                                                        "
                                                        class="wp-attachments"
                                                    >
                                                        <a
                                                            v-for="file in message.attachments"
                                                            :key="file.id"
                                                            :href="attachmentUrl(file, isPreviewableImage(file))"
                                                            :class="isPreviewableImage(file) ? 'wp-image-attachment' : 'wp-attachment'"
                                                            :target="isPreviewableImage(file) ? '_blank' : undefined"
                                                            :download="isPreviewableImage(file) ? undefined : file.original_name"
                                                            rel="noopener"
                                                        >
                                                            <template v-if="isPreviewableImage(file)">
                                                                <img
                                                                    :src="attachmentUrl(file, true)"
                                                                    :alt="file.original_name"
                                                                    loading="lazy"
                                                                />
                                                                <span class="wp-image-caption">
                                                                    <span>{{ file.original_name }}</span>
                                                                    <small>{{ formatSize(file.size) }}</small>
                                                                </span>
                                                            </template>

                                                            <template v-else>
                                                            <div
                                                                class="wp-file-icon"
                                                            >
                                                                <svg
                                                                    viewBox="0 0 24 24"
                                                                    fill="none"
                                                                    stroke="currentColor"
                                                                    stroke-width="1.8"
                                                                >
                                                                    <path
                                                                        d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"
                                                                    />
                                                                    <path
                                                                        d="M14 2v6h6"
                                                                    />
                                                                    <path
                                                                        d="M8 13h8M8 17h6"
                                                                    />
                                                                </svg>
                                                            </div>

                                                            <div
                                                                class="wp-file-details"
                                                            >
                                                                <strong>
                                                                    {{
                                                                        file.original_name
                                                                    }}
                                                                </strong>

                                                                <small>
                                                                    {{
                                                                        fileExtension(
                                                                            file.original_name,
                                                                        )
                                                                    }}
                                                                    ·
                                                                    {{
                                                                        formatSize(
                                                                            file.size,
                                                                        )
                                                                    }}
                                                                </small>
                                                            </div>

                                                            <svg
                                                                class="wp-download-icon"
                                                                viewBox="0 0 24 24"
                                                                fill="none"
                                                                stroke="currentColor"
                                                                stroke-width="1.8"
                                                            >
                                                                <path
                                                                    d="M12 3v12"
                                                                />
                                                                <path
                                                                    d="m7 10 5 5 5-5"
                                                                />
                                                                <path
                                                                    d="M5 20h14"
                                                                />
                                                            </svg>
                                                            </template>
                                                        </a>
                                                    </div>

                                                    <div
                                                        class="wp-message-footer"
                                                    >
                                                        <span>
                                                            {{
                                                                formatTime(
                                                                    message.created_at,
                                                                )
                                                            }}
                                                        </span>

                                                        <button
                                                            v-if="
                                                                isMine(message)
                                                            "
                                                            type="button"
                                                            class="wp-read-button"
                                                            @click="
                                                                detailsId =
                                                                    detailsId ===
                                                                    message.id
                                                                        ? null
                                                                        : message.id
                                                            "
                                                        >
                                                            <span>✓✓</span>

                                                            Vu
                                                            {{
                                                                seen(message)
                                                                    .length
                                                            }}/{{
                                                                Math.max(
                                                                    0,
                                                                    members.length -
                                                                        1,
                                                                )
                                                            }}
                                                        </button>
                                                    </div>

                                                    <!-- READ DETAILS -->
                                                    <div
                                                        v-if="
                                                            detailsId ===
                                                                message.id &&
                                                            isMine(message)
                                                        "
                                                        class="wp-read-details"
                                                    >
                                                        <strong>Vu par</strong>

                                                        <p
                                                            v-for="person in seen(
                                                                message,
                                                            )"
                                                            :key="person.id"
                                                        >
                                                            ✓ {{ person.name }}
                                                        </p>

                                                        <p
                                                            v-if="
                                                                !seen(message)
                                                                    .length
                                                            "
                                                        >
                                                            Aucune lecture
                                                            confirmée
                                                        </p>

                                                        <strong
                                                            class="wp-unseen-title"
                                                        >
                                                            Non vu par
                                                        </strong>

                                                        <p
                                                            v-for="person in unseen(
                                                                message,
                                                            )"
                                                            :key="person.id"
                                                        >
                                                            ○ {{ person.name }}
                                                        </p>

                                                        <p
                                                            v-if="
                                                                !unseen(message)
                                                                    .length
                                                            "
                                                        >
                                                            Tous les membres ont
                                                            vu ce message.
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- COMPOSER -->
                                    <footer class="wp-composer">
                                        <!-- ERROR -->
                                        <div v-if="error" class="wp-error">
                                            {{ error }}
                                        </div>

                                        <!-- SELECTED FILES -->
                                        <div
                                            v-if="files.length"
                                            class="wp-selected-files"
                                        >
                                            <div
                                                v-for="(file, index) in files"
                                                :key="index"
                                                class="wp-selected-file"
                                            >
                                                <div
                                                    class="wp-selected-file-icon"
                                                >
                                                    <svg
                                                        viewBox="0 0 24 24"
                                                        fill="none"
                                                        stroke="currentColor"
                                                        stroke-width="1.8"
                                                    >
                                                        <path
                                                            d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"
                                                        />
                                                        <path d="M14 2v6h6" />
                                                    </svg>
                                                </div>

                                                <div
                                                    class="wp-selected-file-info"
                                                >
                                                    <strong>
                                                        {{ file.name }}
                                                    </strong>

                                                    <small>
                                                        {{
                                                            formatSize(
                                                                file.size,
                                                            )
                                                        }}
                                                    </small>
                                                </div>

                                                <button
                                                    type="button"
                                                    class="wp-remove-file"
                                                    aria-label="Supprimer le fichier"
                                                    @click="removeFile(index)"
                                                >
                                                    ×
                                                </button>
                                            </div>
                                        </div>

                                        <!-- COMPOSER CONTAINER -->
                                        <div class="wp-composer-container">
                                            <input
                                                ref="filePicker"
                                                type="file"
                                                multiple
                                                hidden
                                                @change="addFiles"
                                            />

                                            <!-- INPUT + ATTACHMENT -->
                                            <div class="wp-input-wrapper">
                                                <button
                                                    type="button"
                                                    class="wp-attach-btn"
                                                    aria-label="Joindre des fichiers"
                                                    @click="filePicker?.click()"
                                                >
                                                    <svg
                                                        viewBox="0 0 24 24"
                                                        fill="none"
                                                        stroke="currentColor"
                                                        stroke-width="1.8"
                                                        stroke-linecap="round"
                                                        stroke-linejoin="round"
                                                    >
                                                        <path
                                                            d="m21.4 11.6-8.5 8.5a6 6 0 0 1-8.5-8.5L13.6 2.4a4 4 0 0 1 5.7 5.7l-9.2 9.2a2 2 0 0 1-2.8-2.8l8.5-8.5"
                                                        />
                                                    </svg>
                                                </button>

                                                <textarea
                                                    v-model="draft"
                                                    rows="1"
                                                    placeholder="Écrivez votre message..."
                                                    class="wp-composer-input"
                                                    @keydown.enter.exact.prevent="
                                                        send
                                                    "
                                                ></textarea>
                                            </div>

                                            <!-- SEND BUTTON -->
                                            <button
                                                type="button"
                                                class="wp-send-btn"
                                                :disabled="
                                                    sending ||
                                                    (!draft.trim() &&
                                                        !files.length)
                                                "
                                                title="Envoyer le message"
                                                aria-label="Envoyer le message"
                                                @click="send"
                                            >
                                                <svg
                                                    v-if="!sending"
                                                    viewBox="0 0 24 24"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    stroke-width="1.9"
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                >
                                                    <path
                                                        d="m22 2-7 20-4-9-9-4Z"
                                                    />
                                                    <path d="M22 2 11 13" />
                                                </svg>

                                                <span
                                                    v-else
                                                    class="wp-send-spinner"
                                                ></span>
                                            </button>
                                        </div>

                                        <!-- HINT -->
                                        <div class="wp-composer-hint">
                                            <span>
                                                Entrée pour envoyer · Maj +
                                                Entrée pour une nouvelle ligne
                                            </span>

                                            <span> 20 fichiers max. </span>
                                        </div>
                                    </footer>
                                </div>

                                <!-- MEMBERS PANEL -->
                                <aside
                                    v-if="showMembers"
                                    class="wp-members-panel"
                                >
                                    <div class="wp-members-heading">
                                        <h3>Participants</h3>

                                        <button
                                            type="button"
                                            @click="showMembers = false"
                                        >
                                            ×
                                        </button>
                                    </div>

                                    <div
                                        v-for="person in members"
                                        :key="person.id"
                                        class="wp-member"
                                    >
                                        <div class="wp-member-avatar">
                                            {{ initials(person.name) }}
                                        </div>

                                        <div>
                                            <strong>{{ person.name }}</strong>
                                            <small>
                                                {{ person.role || "Membre" }}
                                            </small>
                                        </div>
                                    </div>
                                </aside>
                            </div>
                        </template>

                        <div v-else class="wp-chat-placeholder">
                            Sélectionnez un groupe pour commencer.
                        </div>
                    </main>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
/* =========================================
   PAGE
========================================= */

.wp-chat-page {
    height: calc(100dvh - 76px);
    min-height: 520px;
    background: #eef2f7;
    padding: 12px;
    color: #111827;
}

.wp-chat-container {
    max-width: 1700px;
    height: 100%;
    margin: 0 auto;
}

/* =========================================
   CHAT SHELL
========================================= */

.wp-chat-shell {
    display: grid;
    grid-template-columns: 350px minmax(0, 1fr);
    height: 100%;
    min-height: 0;
    background: white;
    border: 1px solid #d8e0eb;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 18px 50px rgba(15, 23, 42, 0.09);
}

/* =========================================
   SIDEBAR
========================================= */

.wp-chat-sidebar {
    display: flex;
    flex-direction: column;
    min-height: 0;
    background: #fff;
    border-right: 1px solid #e2e8f0;
}

.wp-sidebar-header {
    padding: 16px 14px 14px;
    border-bottom: 1px solid #edf0f4;
    background: linear-gradient(180deg, #ffffff, #fbfdff);
}

.wp-sidebar-title {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 14px;
}

.wp-sidebar-heading {
    display: flex;
    align-items: center;
    gap: 10px;
}

.wp-sidebar-logo {
    display: grid;
    width: 38px;
    height: 38px;
    flex-shrink: 0;
    place-items: center;
    border-radius: 12px;
    color: #ffffff;
    background: linear-gradient(145deg, #2563eb, #0ea5e9);
    box-shadow: 0 8px 18px rgba(37, 99, 235, 0.22);
}

.wp-sidebar-logo svg {
    width: 19px;
    height: 19px;
}

.wp-sidebar-title h2 {
    font-size: 15px;
    font-weight: 800;
    color: #0f172a;
}

.wp-sidebar-title p {
    margin-top: 3px;
    font-size: 11px;
    color: #94a3b8;
}

.wp-total {
    background: #eaf2ff;
    color: #2563eb;
    border-radius: 999px;
    padding: 5px 10px;
    font-size: 11px;
    font-weight: 700;
}

.wp-search {
    display: flex;
    align-items: center;
    gap: 10px;
    background: #f3f6fa;
    border: 1px solid #e5e9f0;
    border-radius: 12px;
    padding: 11px 12px;
    transition: border-color 160ms ease, background 160ms ease, box-shadow 160ms ease;
}

.wp-search:focus-within {
    border-color: #93c5fd;
    background: #ffffff;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.07);
}

.wp-search svg {
    width: 17px;
    height: 17px;
    color: #94a3b8;
    flex-shrink: 0;
}

.wp-search input {
    background: transparent;
    border: none;
    outline: none;
    box-shadow: none;
    width: 100%;
    min-width: 0;
    font-size: 12px;
    color: #334155;
    padding: 0;
}

.wp-search input:focus {
    outline: none;
    box-shadow: none;
}

.wp-group-list {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
}

.wp-group-item {
    width: 100%;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    border: none;
    border-bottom: 1px solid #f1f3f6;
    text-align: left;
    transition: background 0.15s, transform 0.15s;
    position: relative;
}

.wp-group-item:hover {
    background: #f7f9fc;
    transform: translateX(2px);
}

.wp-group-item.active {
    background: linear-gradient(90deg, #edf4ff, #f7faff);
}

.wp-group-item.active::before {
    content: "";
    position: absolute;
    left: 0;
    top: 7px;
    bottom: 7px;
    width: 4px;
    border-radius: 0 3px 3px 0;
    background: var(--group-accent);
}

.wp-group-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    flex-shrink: 0;
    display: grid;
    place-items: center;
    font-size: 12px;
    font-weight: 800;
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.65);
}

.wp-group-info {
    min-width: 0;
    flex: 1;
}

.wp-group-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}

.wp-group-top strong {
    display: block;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
    font-size: 12px;
    font-weight: 700;
    color: #1e293b;
}

.wp-group-info p {
    margin-top: 5px;
    color: #94a3b8;
    font-size: 10px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.wp-active-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #2563eb;
    flex-shrink: 0;
}

.wp-no-groups {
    padding: 35px 15px;
    text-align: center;
    color: #94a3b8;
    font-size: 12px;
}

/* =========================================
   CONVERSATION
========================================= */

.wp-chat-main {
    min-width: 0;
    min-height: 0;
    display: flex;
    flex-direction: column;
}

.wp-conversation-header {
    height: 72px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    padding: 11px 18px;
    background: rgba(255, 255, 255, 0.97);
    border-bottom: 1px solid #e2e8f0;
}

.wp-conversation-identity {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}

.wp-header-avatar {
    width: 45px;
    height: 45px;
}

.wp-conversation-info {
    min-width: 0;
}

.wp-conversation-info h2 {
    font-size: 13.5px;
    font-weight: 800;
    color: #0f172a;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.wp-conversation-info p {
    display: flex;
    align-items: center;
    font-size: 11px;
    color: #94a3b8;
    margin-top: 4px;
}

.wp-online-dot {
    width: 7px;
    height: 7px;
    margin-right: 6px;
    border-radius: 999px;
    background: #22c55e;
    box-shadow: 0 0 0 3px #dcfce7;
}

.wp-members-button {
    display: flex;
    align-items: center;
    gap: 8px;
    border: 1px solid #e2e8f0;
    border-radius: 11px;
    padding: 9px 11px;
    color: #475569;
    font-size: 11px;
    font-weight: 700;
    background: #f8fafc;
    white-space: nowrap;
}

.wp-members-button:hover {
    background: #eff6ff;
    color: #2563eb;
}

.wp-members-button svg {
    width: 16px;
    height: 16px;
}

.wp-members-count {
    background: #e2e8f0;
    border-radius: 5px;
    padding: 2px 6px;
}

.wp-back-button {
    display: none;
    font-size: 20px;
    color: #475569;
}

.wp-conversation-content {
    flex: 1;
    min-height: 0;
    display: flex;
}

.wp-conversation-column {
    flex: 1;
    min-width: 0;
    min-height: 0;
    display: flex;
    flex-direction: column;
}

/* =========================================
   MESSAGES
========================================= */

.wp-messages {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    padding: 24px clamp(18px, 3vw, 46px);
    background-color: #f1f5f4;
    background-image:
        radial-gradient(circle at 20% 20%, rgba(37, 99, 235, 0.045), transparent 28%),
        radial-gradient(#d5dfdc 0.65px, transparent 0.65px);
    background-size: auto, 19px 19px;
}

.wp-chat-placeholder {
    height: 100%;
    min-height: 200px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    color: #94a3b8;
    font-size: 12px;
    text-align: center;
}

.wp-empty-icon {
    width: 62px;
    height: 62px;
    border-radius: 50%;
    background: #e0ebff;
    display: grid;
    place-items: center;
    margin-bottom: 16px;
    color: #2563eb;
}

.wp-empty-icon svg {
    width: 28px;
    height: 28px;
}

.wp-chat-placeholder h3 {
    font-size: 15px;
    font-weight: 800;
    color: #334155;
    margin-bottom: 7px;
}

.wp-chat-placeholder p {
    max-width: 300px;
    line-height: 1.6;
}

.wp-date-separator {
    display: flex;
    justify-content: center;
    margin: 10px 0 22px;
}

.wp-date-separator span {
    background: rgba(255, 255, 255, 0.9);
    color: #64748b;
    font-size: 10px;
    font-weight: 700;
    padding: 7px 13px;
    border: 1px solid rgba(203, 213, 225, 0.7);
    border-radius: 999px;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.04);
}

.wp-message-row {
    display: flex;
    justify-content: flex-start;
    margin-bottom: 12px;
}

.wp-message-row.mine {
    justify-content: flex-end;
}

.wp-message-bubble {
    position: relative;
    max-width: min(75%, 540px);
    min-width: 130px;
    padding: 10px 13px 8px;
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 4px 14px 14px 14px;
    box-shadow: 0 3px 12px rgba(15, 23, 42, 0.055);
}

.wp-message-bubble::before {
    position: absolute;
    top: -1px;
    left: -7px;
    width: 10px;
    height: 12px;
    content: "";
    background: white;
    clip-path: polygon(100% 0, 100% 100%, 0 0);
}

.wp-message-mine {
    background: #dcecff;
    border-color: #c5dcfb;
    border-radius: 14px 4px 14px 14px;
}

.wp-message-mine::before {
    right: -7px;
    left: auto;
    background: #dcecff;
    clip-path: polygon(0 0, 100% 0, 0 100%);
}

.wp-message-author {
    font-size: 11px;
    font-weight: 800;
    color: #2563eb;
    margin-bottom: 6px;
}

.wp-message-text {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    font-size: 12px;
    line-height: 1.65;
    color: #243247;
}

.wp-message-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 7px;
    font-size: 9px;
    color: #94a3b8;
}

.wp-read-button {
    color: #2563eb;
    font-size: 9px;
    font-weight: 700;
}

.wp-read-button span {
    font-size: 12px;
    margin-right: 3px;
}

.wp-read-details {
    border-top: 1px solid #d6e3f5;
    margin-top: 10px;
    padding-top: 10px;
    display: grid;
    gap: 5px;
    font-size: 10px;
    color: #475569;
}

.wp-read-details strong {
    color: #059669;
    margin-bottom: 3px;
}

.wp-read-details .wp-unseen-title {
    color: #d97706;
    margin-top: 6px;
}

/* =========================================
   ATTACHMENTS IN MESSAGES
========================================= */

.wp-attachments {
    display: grid;
    gap: 7px;
    margin-top: 10px;
}

.wp-image-attachment {
    position: relative;
    display: block;
    max-width: 420px;
    overflow: hidden;
    border-radius: 11px;
    background: #e2e8f0;
    text-decoration: none;
}

.wp-image-attachment img {
    display: block;
    width: 100%;
    max-height: 360px;
    object-fit: cover;
    transition: transform 220ms ease;
}

.wp-image-attachment:hover img {
    transform: scale(1.015);
}

.wp-image-caption {
    position: absolute;
    right: 0;
    bottom: 0;
    left: 0;
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 12px;
    padding: 24px 11px 9px;
    color: #ffffff;
    background: linear-gradient(transparent, rgba(15, 23, 42, 0.78));
    font-size: 10px;
}

.wp-image-caption span {
    overflow: hidden;
    font-weight: 700;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.wp-image-caption small {
    flex-shrink: 0;
    color: #dbeafe;
    font-size: 9px;
}

.wp-attachment {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
    background: #ffffffd9;
    border: 1px solid #e2e8f0;
    border-radius: 7px;
    padding: 10px;
    text-decoration: none;
    transition: border-color 0.15s;
}

.wp-attachment:hover {
    border-color: #93c5fd;
}

.wp-file-icon {
    width: 36px;
    height: 36px;
    border-radius: 7px;
    background: #eff6ff;
    color: #2563eb;
    display: grid;
    place-items: center;
    flex-shrink: 0;
}

.wp-file-icon svg {
    width: 19px;
    height: 19px;
}

.wp-file-details {
    flex: 1;
    min-width: 0;
}

.wp-file-details strong {
    display: block;
    color: #334155;
    font-size: 11px;
    font-weight: 700;
    overflow-wrap: anywhere;
}

.wp-file-details small {
    display: block;
    color: #94a3b8;
    font-size: 9px;
    margin-top: 3px;
}

.wp-download-icon {
    width: 17px;
    height: 17px;
    color: #2563eb;
    flex-shrink: 0;
}

/* =========================================
   PREMIUM COMPOSER
========================================= */

.wp-composer {
    width: 100%;
    padding: 12px 18px 9px;
    background: #f8fafc;
    border-top: 1px solid #e7ebf2;
    flex-shrink: 0;
}

.wp-composer-container {
    display: flex;
    align-items: flex-end;
    gap: 10px;
    width: 100%;
}

/* INPUT WRAPPER */

.wp-input-wrapper {
    display: flex;
    align-items: center;
    flex: 1;
    min-width: 0;
    min-height: 46px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    overflow: hidden;
    transition: 0.2s ease;
}

.wp-input-wrapper:hover {
    border-color: #cbd5e1;
}

.wp-input-wrapper:focus-within {
    background: #ffffff;
    border-color: #93b4ff;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.07);
}

/* ATTACHMENT INSIDE INPUT */

.wp-input-wrapper .wp-attach-btn {
    width: 36px;
    height: 36px;
    min-width: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-left: 6px;
    border: 0 !important;
    border-radius: 10px;
    background: transparent;
    color: #64748b;
    outline: none !important;
    box-shadow: none !important;
    appearance: none;
    cursor: pointer;
    transition: 0.2s ease;
}

.wp-input-wrapper .wp-attach-btn:hover {
    background: #edf4ff;
    color: #2563eb;
}

.wp-input-wrapper .wp-attach-btn:focus,
.wp-input-wrapper .wp-attach-btn:focus-visible {
    outline: none !important;
    background: #edf4ff;
    color: #2563eb;
    box-shadow: inset 0 0 0 1px #bfdbfe !important;
}

.wp-input-wrapper .wp-attach-btn svg {
    width: 19px;
    height: 19px;
}

/* TEXTAREA */

.wp-input-wrapper .wp-composer-input {
    flex: 1;
    display: block;
    width: 100%;
    min-width: 0;
    min-height: 44px;
    max-height: 130px;
    padding: 12px 14px 12px 5px;
    margin: 0;
    border: none !important;
    border-radius: 0;
    outline: none !important;
    box-shadow: none !important;
    background: transparent !important;
    resize: none !important;
    overflow-y: auto;
    font-family: inherit;
    font-size: 12.5px;
    line-height: 20px;
    color: #1e293b;
    scrollbar-width: thin;
}

.wp-input-wrapper .wp-composer-input:focus {
    border: none !important;
    outline: none !important;
    box-shadow: none !important;
    --tw-ring-shadow: 0 0 #0000 !important;
}

.wp-input-wrapper .wp-composer-input::placeholder {
    color: #94a3b8;
    opacity: 1;
}

/* SEND BUTTON */

.wp-send-btn {
    width: 46px;
    height: 46px;
    min-width: 46px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: none;
    border-radius: 14px;
    background: linear-gradient(145deg, #2563eb, #1d4ed8);
    color: #ffffff;
    cursor: pointer;
    transition: all 0.2s ease;
    box-shadow: 0 8px 18px rgba(37, 99, 235, 0.2);
}

.wp-send-btn:hover:not(:disabled) {
    background: #1d4ed8;
    transform: translateY(-1px);
}

.wp-send-btn:disabled {
    background: #a5b4fc;
    cursor: not-allowed;
}

.wp-send-btn svg {
    width: 19px;
    height: 19px;
}

.wp-send-spinner {
    width: 18px;
    height: 18px;
    border: 2px solid rgba(255, 255, 255, 0.35);
    border-top-color: #ffffff;
    border-radius: 50%;
    animation: wp-spin 0.7s linear infinite;
}

@keyframes wp-spin {
    to {
        transform: rotate(360deg);
    }
}

/* =========================================
   SELECTED FILES
========================================= */

.wp-selected-files {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 12px;
}

.wp-selected-file {
    display: flex;
    align-items: center;
    gap: 9px;
    max-width: 250px;
    padding: 7px 9px;
    border: 1px solid #dbeafe;
    border-radius: 9px;
    background: #f0f6ff;
}

.wp-selected-file-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    min-width: 32px;
    border-radius: 7px;
    background: #dbeafe;
    color: #2563eb;
}

.wp-selected-file-icon svg {
    width: 16px;
    height: 16px;
}

.wp-selected-file-info {
    min-width: 0;
    flex: 1;
}

.wp-selected-file-info strong {
    display: block;
    font-size: 10px;
    font-weight: 700;
    color: #334155;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.wp-selected-file-info small {
    display: block;
    margin-top: 2px;
    color: #64748b;
    font-size: 9px;
}

.wp-remove-file {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 23px;
    height: 23px;
    min-width: 23px;
    border-radius: 6px;
    background: transparent;
    color: #64748b;
    font-size: 17px;
    cursor: pointer;
}

.wp-remove-file:hover {
    background: #fee2e2;
    color: #dc2626;
}

/* HINT */

.wp-composer-hint {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-top: 8px;
    padding: 0 2px;
    color: #94a3b8;
    font-size: 9.5px;
}

.wp-error {
    margin-bottom: 10px;
    padding: 9px 12px;
    border: 1px solid #fecdd3;
    border-radius: 8px;
    background: #fff1f2;
    color: #be123c;
    font-size: 11px;
}

/* =========================================
   MEMBERS PANEL
========================================= */

.wp-members-panel {
    width: 245px;
    flex-shrink: 0;
    overflow-y: auto;
    border-left: 1px solid #e2e8f0;
    background: #fbfdff;
    padding: 15px;
}

.wp-members-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 15px;
}

.wp-members-heading h3 {
    font-size: 13px;
    font-weight: 800;
}

.wp-members-heading button {
    font-size: 20px;
    color: #64748b;
}

.wp-member {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 0;
    border-bottom: 1px solid #f1f5f9;
}

.wp-member-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    display: grid;
    place-items: center;
    background: #e2e8f0;
    color: #334155;
    font-size: 11px;
    font-weight: 800;
    flex-shrink: 0;
}

.wp-member strong {
    display: block;
    font-size: 11px;
    color: #334155;
}

.wp-member small {
    display: block;
    font-size: 10px;
    color: #94a3b8;
    margin-top: 3px;
}

/* =========================================
   RESPONSIVE
========================================= */

@media (max-width: 1100px) {
    .wp-chat-shell {
        grid-template-columns: 290px minmax(0, 1fr);
    }

    .wp-members-panel {
        width: 200px;
    }
}

@media (max-width: 767px) {
    .wp-chat-page {
        height: calc(100dvh - 76px);
        min-height: 440px;
        padding: 7px;
    }

    .wp-chat-shell {
        display: block;
        height: 100%;
        min-height: 0;
        border-radius: 12px;
    }

    .wp-chat-sidebar,
    .wp-chat-main {
        height: 100%;
    }

    .mobile-hidden {
        display: none;
    }

    .wp-back-button {
        display: block;
    }

    .wp-conversation-header {
        padding: 10px 12px;
        height: 67px;
    }

    .wp-conversation-info h2 {
        max-width: 180px;
        font-size: 12px;
    }

    .wp-members-button {
        padding: 8px;
    }

    .wp-members-button span:not(.wp-members-count) {
        display: none;
    }

    .wp-messages {
        padding: 15px 12px;
    }

    .wp-message-bubble {
        max-width: 88%;
    }

    .wp-composer {
        padding: 10px 11px 8px;
    }

    .wp-composer-container {
        gap: 8px;
    }

    .wp-input-wrapper {
        min-height: 42px;
    }

    .wp-input-wrapper .wp-attach-btn {
        width: 34px;
        height: 34px;
        min-width: 34px;
    }

    .wp-input-wrapper .wp-composer-input {
        min-height: 40px;
        padding: 10px 12px 10px 4px;
        font-size: 12px;
    }

    .wp-send-btn {
        width: 42px;
        height: 42px;
        min-width: 42px;
    }

    .wp-composer-hint {
        font-size: 9px;
    }

    .wp-composer-hint span:last-child {
        display: none;
    }

    .wp-selected-file {
        max-width: 200px;
    }

    .wp-members-panel {
        position: absolute;
        right: 0;
        top: 0;
        bottom: 0;
        z-index: 10;
        width: min(280px, 85vw);
        box-shadow: -10px 0 30px #00000012;
    }

    .wp-conversation-content {
        position: relative;
    }
}

/* Améliorations : discussions récentes et messages non lus */
.wp-group-time {
    flex-shrink: 0;
    color: #94a3b8;
    font-size: 10px;
    font-weight: 600;
}
.wp-group-item.has-unread .wp-group-top strong {
    font-weight: 850;
    color: #111827;
}
.wp-group-item.has-unread .wp-group-time {
    color: #2563eb;
}
.wp-unread-badge {
    min-width: 22px;
    height: 22px;
    padding: 0 6px;
    border-radius: 999px;
    background: #2563eb;
    color: white;
    font-size: 10px;
    font-weight: 800;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.wp-group-item {
    cursor: pointer;
}
.wp-group-item:focus-visible,
.wp-members-button:focus-visible,
.wp-send-btn:focus-visible {
    outline: 2px solid #2563eb;
    outline-offset: -2px;
}
.wp-chat-shell {
    box-shadow: 0 18px 50px rgba(15, 23, 42, 0.09);
}
.wp-messages {
    background-color: #f1f5f4;
}
.wp-message-bubble {
    box-shadow: 0 3px 12px rgba(15, 23, 42, 0.055);
}
.wp-input-wrapper {
    border-radius: 14px;
}
.wp-send-btn {
    border-radius: 14px;
}
@media (max-width: 767px) {
    .wp-group-time {
        font-size: 9px;
    }
}
</style>
