<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Collaborateur;
use App\Models\Integration;
use App\Models\MeetingInstance;
use App\Models\RecurringMeeting;
use App\Services\TeamsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RecurringMeetingController extends Controller
{
    // ─── CRUD ──────────────────────────────────────────────

    public function index(): JsonResponse
    {
        return response()->json(RecurringMeeting::with('parcours:id,nom')->orderBy('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);
        $rm = RecurringMeeting::create($data);
        return response()->json($rm->load('parcours:id,nom'), 201);
    }

    public function update(Request $request, RecurringMeeting $recurringMeeting): JsonResponse
    {
        $data = $this->validatePayload($request);
        $recurringMeeting->update($data);
        return response()->json($recurringMeeting->load('parcours:id,nom'));
    }

    public function destroy(RecurringMeeting $recurringMeeting): JsonResponse
    {
        $recurringMeeting->delete();
        return response()->json(['ok' => true]);
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'titre' => 'required|string|max:255',
            'description' => 'nullable|string',
            'frequence' => 'required|in:weekly,biweekly,monthly,milestone',
            'jour_semaine' => 'nullable|integer|min:1|max:7',
            'milestones' => 'nullable|array',
            'milestones.*' => 'integer|min:1|max:365',
            'heure' => 'nullable|string|max:5',
            'duree_min' => 'nullable|integer|min:5|max:480',
            'lieu' => 'nullable|string|max:255',
            'participants_roles' => 'nullable|array',
            'parcours_id' => 'nullable|exists:parcours,id',
            'auto_sync_calendar' => 'boolean',
            'actif' => 'boolean',
            'translations' => 'nullable|array',
        ]);
    }

    // ─── Instances generator ───────────────────────────────

    /**
     * Compute virtual instances for a collaborateur (no DB writes).
     * Used by the employee Mes RDV page to render the schedule.
     */
    public function instancesForCollaborateur(Request $request, Collaborateur $collaborateur): JsonResponse
    {
        if (!$collaborateur->date_debut) {
            return response()->json(['instances' => [], 'message' => 'No start date for this collaborateur']);
        }
        $start = Carbon::parse($collaborateur->date_debut);
        $today = now();
        $horizon = $start->copy()->addDays(120); // 4 months window

        $rms = RecurringMeeting::where('actif', true)
            ->where(function ($q) use ($collaborateur) {
                $q->whereNull('parcours_id')->orWhere('parcours_id', $collaborateur->parcours_id);
            })
            ->get();

        $instances = [];
        $persisted = MeetingInstance::where('collaborateur_id', $collaborateur->id)
            ->get()
            ->keyBy(fn ($mi) => $mi->recurring_meeting_id . '_' . $mi->scheduled_at->toIso8601String());

        foreach ($rms as $rm) {
            foreach ($this->expandSchedule($rm, $start, $horizon) as $occurrence) {
                $key = $rm->id . '_' . $occurrence->toIso8601String();
                $persistedInstance = $persisted->get($key);
                $instances[] = [
                    'recurring_meeting_id' => $rm->id,
                    'collaborateur_id' => $collaborateur->id,
                    'titre' => $rm->titre,
                    'description' => $rm->description,
                    'frequence' => $rm->frequence,
                    'lieu' => $rm->lieu,
                    'duree_min' => $rm->duree_min,
                    'participants_roles' => $rm->participants_roles ?? [],
                    'scheduled_at' => $occurrence->toIso8601String(),
                    'past' => $occurrence->lt($today),
                    'instance_id' => $persistedInstance?->id,
                    'external_provider' => $persistedInstance?->external_provider,
                    'external_event_id' => $persistedInstance?->external_event_id,
                    'external_join_url' => $persistedInstance?->external_join_url,
                    'synced_at' => $persistedInstance?->synced_at?->toIso8601String(),
                ];
            }
        }

        usort($instances, fn ($a, $b) => strcmp($a['scheduled_at'], $b['scheduled_at']));

        return response()->json(['instances' => $instances]);
    }

    /**
     * Compute the schedule occurrences within [start, horizon] for a recurring meeting.
     */
    private function expandSchedule(RecurringMeeting $rm, Carbon $start, Carbon $horizon): array
    {
        $time = explode(':', $rm->heure ?: '09:00');
        $h = (int) ($time[0] ?? 9);
        $m = (int) ($time[1] ?? 0);
        $occurrences = [];

        if ($rm->frequence === 'milestone') {
            foreach ((array) ($rm->milestones ?? []) as $offset) {
                $d = $start->copy()->addDays((int) $offset)->setTime($h, $m);
                if ($d->between($start, $horizon)) $occurrences[] = $d;
            }
            return $occurrences;
        }

        $stepDays = match ($rm->frequence) {
            'weekly' => 7,
            'biweekly' => 14,
            'monthly' => 30, // simplified
            default => 7,
        };

        // Find the first occurrence on or after start matching jour_semaine (ISO 1..7 = Mon..Sun)
        $cursor = $start->copy()->setTime($h, $m);
        if ($rm->jour_semaine) {
            $targetDow = (int) $rm->jour_semaine;
            // Carbon dayOfWeekIso: 1..7
            while ($cursor->dayOfWeekIso !== $targetDow) {
                $cursor->addDay();
            }
        }
        while ($cursor->lte($horizon)) {
            $occurrences[] = $cursor->copy();
            $cursor->addDays($stepDays);
        }
        return $occurrences;
    }

    // ─── Calendar sync ─────────────────────────────────────

    /**
     * Sync a single instance to a connected calendar (Microsoft Graph / Google).
     * Creates a persisted MeetingInstance row and stores the external event id.
     */
    public function syncInstance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'recurring_meeting_id' => 'required|exists:recurring_meetings,id',
            'collaborateur_id' => 'required|exists:collaborateurs,id',
            'scheduled_at' => 'required|date',
            'provider' => 'required|in:microsoft,google',
        ]);

        $rm = RecurringMeeting::findOrFail($data['recurring_meeting_id']);
        $collab = Collaborateur::with(['user:id,email', 'manager.user:id,email', 'hrManager.user:id,email'])
            ->findOrFail($data['collaborateur_id']);
        $start = Carbon::parse($data['scheduled_at']);
        $end = $start->copy()->addMinutes($rm->duree_min ?? 30);

        $attendees = $this->resolveAttendees($collab, $rm->participants_roles ?? []);

        if ($data['provider'] === 'microsoft') {
            $result = $this->syncMicrosoft($collab, $rm, $start, $end, $attendees);
        } else {
            $result = $this->syncGoogle($collab, $rm, $start, $end, $attendees);
        }

        if (!$result['ok']) {
            return response()->json(['ok' => false, 'message' => $result['message']], 422);
        }

        $instance = MeetingInstance::updateOrCreate(
            [
                'recurring_meeting_id' => $rm->id,
                'collaborateur_id' => $collab->id,
                'scheduled_at' => $start,
            ],
            [
                'duree_min' => $rm->duree_min ?? 30,
                'external_provider' => $data['provider'],
                'external_event_id' => $result['event_id'] ?? null,
                'external_join_url' => $result['join_url'] ?? null,
                'synced_at' => now(),
                'status' => 'scheduled',
            ]
        );

        return response()->json([
            'ok' => true,
            'instance' => $instance,
            'join_url' => $result['join_url'] ?? null,
        ]);
    }

    private function resolveAttendees(Collaborateur $collab, array $roles): array
    {
        $emails = [];
        if ($collab->user?->email) $emails[] = $collab->user->email;
        elseif ($collab->email) $emails[] = $collab->email;

        if (in_array('manager', $roles) && $collab->manager?->user?->email) {
            $emails[] = $collab->manager->user->email;
        }
        if (in_array('rh', $roles) && $collab->hrManager?->user?->email) {
            $emails[] = $collab->hrManager->user->email;
        }
        // Buddy via accompagnants is more complex — skipped for now
        return array_values(array_unique(array_filter($emails)));
    }

    private function syncMicrosoft(Collaborateur $collab, RecurringMeeting $rm, Carbon $start, Carbon $end, array $attendees): array
    {
        try {
            $integration = Integration::where('provider', 'teams')->where('connecte', true)->first();
            if (!$integration) {
                return ['ok' => false, 'message' => 'Microsoft Teams non connecté. Connectez-le dans Intégrations.'];
            }
            $config = $integration->config ?? [];
            $accessToken = $config['access_token'] ?? null;
            if (!$accessToken) {
                return ['ok' => false, 'message' => 'Token Graph API manquant. Reconnectez Microsoft via Azure AD.'];
            }
            $organizer = $config['organizer_email'] ?? ($attendees[0] ?? null);
            if (!$organizer) {
                return ['ok' => false, 'message' => 'Aucun organisateur disponible. Vérifiez l\'email du collaborateur.'];
            }
            $service = new TeamsService(null, $accessToken);
            $meeting = $service->createMeeting(
                $organizer,
                $rm->titre,
                $start->toIso8601String(),
                $end->toIso8601String(),
                $attendees,
                $rm->description
            );
            return [
                'ok' => true,
                'event_id' => $meeting['id'] ?? null,
                'join_url' => $meeting['join_url'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::warning('Microsoft sync failed: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Échec Microsoft: ' . $e->getMessage()];
        }
    }

    private function syncGoogle(Collaborateur $collab, RecurringMeeting $rm, Carbon $start, Carbon $end, array $attendees): array
    {
        try {
            $integration = Integration::where('provider', 'google')
                ->orWhere('provider', 'google_calendar')
                ->where('connecte', true)
                ->first();
            if (!$integration) {
                return ['ok' => false, 'message' => 'Google Calendar non connecté. Connectez-le dans Intégrations.'];
            }
            $config = $integration->config ?? [];
            $accessToken = $config['access_token'] ?? null;
            if (!$accessToken) {
                return ['ok' => false, 'message' => 'Token Google manquant. Reconnectez Google.'];
            }
            $calendarId = $config['calendar_id'] ?? 'primary';

            $event = [
                'summary' => $rm->titre,
                'description' => $rm->description ?? '',
                'location' => $rm->lieu ?? '',
                'start' => ['dateTime' => $start->toIso8601String(), 'timeZone' => 'Europe/Paris'],
                'end' => ['dateTime' => $end->toIso8601String(), 'timeZone' => 'Europe/Paris'],
                'attendees' => array_map(fn ($e) => ['email' => $e], $attendees),
                'conferenceData' => [
                    'createRequest' => [
                        'requestId' => uniqid('illizeo_'),
                        'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                    ],
                ],
            ];

            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->post("https://www.googleapis.com/calendar/v3/calendars/{$calendarId}/events?conferenceDataVersion=1&sendUpdates=all", $event);

            if (!$response->successful()) {
                return ['ok' => false, 'message' => 'Google API: ' . $response->body()];
            }
            $data = $response->json();
            return [
                'ok' => true,
                'event_id' => $data['id'] ?? null,
                'join_url' => $data['hangoutLink'] ?? ($data['conferenceData']['entryPoints'][0]['uri'] ?? null),
            ];
        } catch (\Throwable $e) {
            Log::warning('Google sync failed: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Échec Google: ' . $e->getMessage()];
        }
    }
}
