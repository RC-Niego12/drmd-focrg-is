<?php

namespace App\Http\Controllers;

use App\Models\SystemMessage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SystemMessageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $messages = SystemMessage::query()
            ->with([
                'sender:id,name,email,office,avatar',
                'recipient:id,name,email,office,avatar',
            ])
            ->where(function ($query) use ($user): void {
                $query->where('recipient_id', $user->id)
                    ->orWhere('sender_id', $user->id);
            })
            ->latest()
            ->limit(40)
            ->get()
            ->map(fn (SystemMessage $message): array => $this->serializeMessage($message, $user->id));

        $contacts = User::query()
            ->whereKeyNot($user->id)
            ->where('is_active', true)
            ->where('access_status', 'approved')
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'email', 'office'])
            ->map(fn (User $contact): array => [
                'id' => $contact->id,
                'name' => $contact->name,
                'email' => $contact->email,
                'office' => $contact->office,
            ])
            ->values();

        return response()->json([
            'unread_count' => SystemMessage::query()
                ->where('recipient_id', $user->id)
                ->whereNull('read_at')
                ->count(),
            'messages' => $messages,
            'contacts' => $contacts,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'recipient_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->where('is_active', true)
                    ->where('access_status', 'approved')
                    ->whereNull('deleted_at')),
                Rule::notIn([$user->id]),
            ],
            'subject' => ['nullable', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:3000'],
        ]);

        $message = SystemMessage::create([
            'sender_id' => $user->id,
            'recipient_id' => $validated['recipient_id'],
            'subject' => $validated['subject'] ?? null,
            'body' => $validated['body'],
        ])->load([
            'sender:id,name,email,office,avatar',
            'recipient:id,name,email,office,avatar',
        ]);

        return response()->json([
            'message' => 'Message sent.',
            'item' => $this->serializeMessage($message, $user->id),
        ], 201);
    }

    public function read(Request $request, SystemMessage $message): JsonResponse
    {
        abort_unless((int) $message->recipient_id === (int) $request->user()->id, 403);

        if (! $message->read_at) {
            $message->update(['read_at' => now()]);
        }

        return response()->json(['message' => 'Message marked as read.']);
    }

    private function serializeMessage(SystemMessage $message, int $currentUserId): array
    {
        $isMine = (int) $message->sender_id === $currentUserId;
        $other = $isMine ? $message->recipient : $message->sender;

        return [
            'id' => $message->id,
            'subject' => $message->subject ?: 'DROMIS message',
            'body' => $message->body,
            'read_at' => $message->read_at?->toDateTimeString(),
            'created_at' => $message->created_at?->toDateTimeString(),
            'is_mine' => $isMine,
            'other_user' => [
                'id' => $other?->id,
                'name' => $other?->name ?? 'DROMIS User',
                'email' => $other?->email,
                'office' => $other?->office,
                'avatar' => $other?->avatar,
            ],
            'sender' => [
                'id' => $message->sender?->id,
                'name' => $message->sender?->name,
                'office' => $message->sender?->office,
            ],
            'recipient' => [
                'id' => $message->recipient?->id,
                'name' => $message->recipient?->name,
                'office' => $message->recipient?->office,
            ],
        ];
    }
}
