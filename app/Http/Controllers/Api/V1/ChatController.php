<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Chat\SendMessageRequest;
use App\Services\Chat\ChatService;
use App\Services\Files\SecureFileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    public function __construct(
        private ChatService $chat,
        private SecureFileService $files,
    ) {}

    /** My conversations, most recently active first. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $paginator = $this->chat->conversationsFor($user, $this->perPage($request));

        return response()->json([
            'data' => collect($paginator->items())->map(fn ($c) => $this->chat->conversationToArray($c, $user)),
            'pagination' => $this->pagination($paginator),
        ]);
    }

    /**
     * Messages exchanged with {userId}, newest first. A pair that is allowed
     * to chat but has not started yet gets an empty thread.
     */
    public function show(Request $request, string $userId): JsonResponse
    {
        $user = $request->user();
        $conversation = $this->chat->find($user, $userId);

        if ($conversation === null) {
            return response()->json(['conversation' => null, 'data' => [], 'pagination' => null]);
        }

        $paginator = $this->chat->messages($user, $conversation, $this->perPage($request, 30));

        return response()->json([
            'conversation' => $this->chat->conversationToArray($conversation, $user),
            'data' => collect($paginator->items())->map(fn ($m) => $this->chat->toArray($m)),
            'pagination' => $this->pagination($paginator),
        ]);
    }

    /** Send text and/or one attachment (image, audio or document) to {userId}. */
    public function send(SendMessageRequest $request, string $userId): JsonResponse
    {
        $message = $this->chat->send($request->user(), $userId, $request->input('content'), $request->file('attachment'));

        return response()->json([
            'message' => 'Message sent.',
            'conversation_id' => $message->conversation_id,
            'data' => $this->chat->toArray($message),
        ], 201);
    }

    /** Mark everything {userId} sent me as read. */
    public function markRead(Request $request, string $userId): JsonResponse
    {
        $user = $request->user();
        $conversation = $this->chat->find($user, $userId);

        $updated = $conversation === null ? 0 : $this->chat->markRead($user, $conversation);

        return response()->json(['message' => 'Messages marked as read.', 'updated' => $updated]);
    }

    /** Stream an attachment to one of the two participants. */
    public function attachment(Request $request, string $message): StreamedResponse
    {
        $found = $this->chat->attachment($request->user(), $message);

        return Storage::disk($this->files->disk())->download($found->file_path, $found->attachment_name ?? basename($found->file_path), [
            'Content-Type' => $found->attachment_mime ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }

    private function pagination($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}
