<?php

namespace App\Services;

use App\Models\GmailMessage;
use Illuminate\Support\Collection;

class GlpiTicketAnalysisService
{
    public function __construct(
        private readonly GlpiTicketClassifier $classifier
    ) {}

    public function analyze(int $gmailIntegrationId): array
    {
        $messages = GmailMessage::query()
            ->where('gmail_integration_id', $gmailIntegrationId)
            ->orderBy('received_at')
            ->orderBy('id')
            ->get([
                'id',
                'ticket_number',
                'subject',
                'event_type',
                'received_at',
            ]);

        // Plusieurs emails peuvent concerner le même ticket.
        $tickets = $messages
            ->groupBy(function ($message) {
                $number = trim((string) $message->ticket_number);

                return $number !== ''
                    ? 'ticket:'.$number
                    : 'message:'.$message->id;
            })
            ->map(function (Collection $events) {
                $latest = $events->last();

                // Préférer un sujet de création si disponible.
                $subject = $events
                    ->first(fn ($event) => $event->event_type === 'new'
                        && filled($event->subject)
                    )?->subject;

                $subject ??= $events
                    ->first(fn ($event) => filled($event->subject))
                    ?->subject;

                $subject ??= '';

                $classification = $this->classifier
                    ->classify($subject);

                // "updated" n'est pas un statut GLPI.
                $status = match ($latest->event_type) {
                    'closed' => 'closed',
                    'solved' => 'solved',
                    'new' => 'new',
                    default => 'unknown',
                };

                return [
                    'ticket_number' => $latest->ticket_number,
                    'subject' => $subject,
                    'domain' => $classification['domain'],
                    'category' => $classification['category'],
                    'confidence' => $classification['confidence'],
                    'status' => $status,
                    'last_event' => $latest->event_type,
                    'received_at' => $latest->received_at
                        ?->toIso8601String(),
                ];
            })
            ->values();

        $domains = [
            'infrastructure' => $this->summarize(
                $tickets->where('domain', 'infrastructure')
            ),
            'solutions' => $this->summarize(
                $tickets->where('domain', 'solutions')
            ),
            'unknown' => $this->summarize(
                $tickets->where('domain', 'unknown')
            ),
        ];

        return [
            'total' => $tickets->count(),
            'domains' => $domains,
            'tickets' => $tickets->all(),
        ];
    }

    private function summarize(Collection $tickets): array
    {
        $categories = $tickets
            ->groupBy('category')
            ->map(function (Collection $items, string $category) {
                return [
                    'name' => $category,
                    'total' => $items->count(),
                    'statuses' => $this->statuses($items),
                ];
            })
            ->sortByDesc('total')
            ->values()
            ->all();

        return [
            'total' => $tickets->count(),
            'statuses' => $this->statuses($tickets),
            'categories' => $categories,
        ];
    }

    private function statuses(Collection $tickets): array
    {
        return [
            'new' => $tickets->where('status', 'new')->count(),
            'solved' => $tickets->where('status', 'solved')->count(),
            'closed' => $tickets->where('status', 'closed')->count(),
            'unknown' => $tickets->where('status', 'unknown')->count(),
        ];
    }
}
