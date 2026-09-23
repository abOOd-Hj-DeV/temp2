<?php

use App\Models\User;
use App\Services\Chat\ChatService;
use Illuminate\Support\Facades\Broadcast;

// Personal channel: unread badges, per-user pushes. Ids are UUID strings.
Broadcast::channel('App.Models.User.{id}', function (User $user, string $id): bool {
    return $user->is_active && $user->phone_verified_at !== null && hash_equals($user->id, $id);
}, ['guards' => ['api']]);

// Conversation channel: the two participants only.
Broadcast::channel('chat.conversation.{conversationId}', function (User $user, string $conversationId): bool {
    return app(ChatService::class)->canSubscribe($user, $conversationId);
}, ['guards' => ['api']]);
