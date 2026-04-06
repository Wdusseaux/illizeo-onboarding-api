<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Message;
use App\Models\User;
use App\Services\TeamsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    /**
     * List conversations for current user
     */
    public function conversations(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $conversations = Conversation::forUser($userId)
            ->with(['user1:id,name,email', 'user2:id,name,email', 'latestMessage'])
            ->withCount(['messages as unread_count' => function ($q) use ($userId) {
                $q->where('sender_id', '!=', $userId)
                  ->whereNull('read_at');
            }])
            ->orderByDesc('last_message_at')
            ->get()
            ->map(function ($conv) use ($userId) {
                $other = $conv->getOtherParticipant($userId);
                return [
                    'id' => $conv->id,
                    'other_user' => $other ? [
                        'id' => $other->id,
                        'name' => $other->name,
                        'email' => $other->email,
                        'initials' => collect(explode(' ', $other->name))->map(fn ($n) => mb_substr($n, 0, 1))->join(''),
                    ] : null,
                    'last_message' => $conv->latestMessage ? [
                        'content' => $conv->latestMessage->content,
                        'is_bot' => $conv->latestMessage->is_bot,
                        'created_at' => $conv->latestMessage->created_at,
                    ] : null,
                    'unread_count' => $conv->unread_count,
                    'last_message_at' => $conv->last_message_at,
                ];
            });

        return response()->json($conversations);
    }

    /**
     * Get messages for a conversation
     */
    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $userId = $request->user()->id;

        // Check user is participant
        if ($conversation->participant_1 !== $userId && $conversation->participant_2 !== $userId) {
            return response()->json(['error' => 'Non autorisé'], 403);
        }

        // Mark messages as read
        $conversation->messages()
            ->where('sender_id', '!=', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $messages = $conversation->messages()
            ->with('sender:id,name')
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'content' => $m->content,
                'sender_id' => $m->sender_id,
                'sender_name' => $m->is_bot ? 'IllizeoBot' : ($m->sender?->name ?? 'Système'),
                'is_bot' => $m->is_bot,
                'bot_type' => $m->bot_type,
                'read_at' => $m->read_at,
                'created_at' => $m->created_at,
            ]);

        return response()->json($messages);
    }

    /**
     * Send a message
     */
    public function send(Request $request): JsonResponse
    {
        $request->validate([
            'to_user_id' => 'required|exists:users,id',
            'content' => 'required|string|max:5000',
        ]);

        $userId = $request->user()->id;
        $conversation = Conversation::findOrCreateBetween($userId, $request->to_user_id);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $userId,
            'content' => $request->content,
        ]);

        $conversation->update(['last_message_at' => now()]);

        // Forward message to Teams if integration is active
        try {
            $teamsIntegration = Integration::where('provider', 'teams')->where('actif', true)->first();
            if ($teamsIntegration && !empty($teamsIntegration->config['webhook_url'])) {
                $senderName = $request->user()->name;
                $recipient = User::find($request->to_user_id);
                $recipientName = $recipient ? $recipient->name : 'Inconnu';

                $teamsService = TeamsService::fromIntegration($teamsIntegration);
                $teamsService->sendWebhookCard(
                    "💬 Message Illizeo",
                    "**{$senderName}** → **{$recipientName}**\n\n{$request->content}"
                );
            }
        } catch (\Exception $e) {
            \Log::warning("Teams message forward failed: " . $e->getMessage());
        }

        // Auto-award badge for first message
        \App\Services\BadgeAutoAwardService::checkAndAward($userId, 'premier_message');

        return response()->json([
            'id' => $message->id,
            'content' => $message->content,
            'sender_id' => $userId,
            'sender_name' => $request->user()->name,
            'is_bot' => false,
            'created_at' => $message->created_at,
            'conversation_id' => $conversation->id,
        ], 201);
    }

    /**
     * Get unread count for current user
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $count = Message::whereHas('conversation', function ($q) use ($userId) {
            $q->forUser($userId);
        })
            ->where('sender_id', '!=', $userId)
            ->whereNull('read_at')
            ->count();

        return response()->json(['unread_count' => $count]);
    }

    /**
     * List available users to chat with
     * Onboardees can only chat with admin_rh, manager, super_admin
     * Admin/Manager can chat with everyone
     */
    public function availableUsers(Request $request): JsonResponse
    {
        $currentUser = $request->user();
        $query = User::where('id', '!=', $currentUser->id);

        // If onboardee, restrict to staff only (no other onboardees)
        if ($currentUser->hasRole('onboardee')) {
            $query->whereHas('roles', function ($q) {
                $q->whereIn('name', ['super_admin', 'admin', 'admin_rh', 'manager']);
            });
        }

        $users = $query->select('id', 'name', 'email')
            ->get()
            ->map(function ($u) {
                $role = $u->roles->first()?->name ?? '';
                $roleLabel = match($role) {
                    'super_admin' => 'Super Admin',
                    'admin' => 'Admin',
                    'admin_rh' => 'Admin RH',
                    'manager' => 'Manager',
                    'onboardee' => 'Collaborateur',
                    default => '',
                };
                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'role' => $roleLabel,
                    'initials' => collect(explode(' ', $u->name))->map(fn ($n) => mb_substr($n, 0, 1))->join(''),
                ];
            });

        return response()->json($users);
    }
}
