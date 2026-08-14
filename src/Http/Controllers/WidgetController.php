<?php

declare(strict_types=1);

namespace Miran\SupportChat\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Miran\SupportChat\Http\Requests\SendChatMessageRequest;
use Miran\SupportChat\Http\Requests\StartChatRequest;
use Miran\SupportChat\Models\Message;
use Miran\SupportChat\Support\AttachmentService;
use Miran\SupportChat\Support\ChatService;
use Miran\SupportChat\Support\TypingService;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class WidgetController extends Controller
{
    public function __construct(
        private readonly ChatService $chat,
        private readonly AttachmentService $attachments,
        private readonly TypingService $typing,
    ) {}

    public function session(Request $request): JsonResponse
    {
        $raw = $this->rawToken($request);
        $conversation = $this->chat->findByRawToken($raw);

        if (! $conversation || $raw === null) {
            return response()->json([
                'conversation' => null,
            ]);
        }

        return $this->withCookie(
            response()->json([
                'conversation' => $this->chat->serializeConversation($conversation->load('messages')),
                'resumed' => true,
            ]),
            $raw
        );
    }

    public function start(StartChatRequest $request): JsonResponse
    {
        [$conversation, $raw, $created] = $this->chat->start(
            (string) $request->validated('name'),
            (string) $request->validated('email'),
            (string) $request->validated('phone'),
            $request->validated('greeting'),
            $request->validated('page_path'),
            $this->rawToken($request),
        );

        $conversation->load('messages');

        return $this->withCookie(
            response()->json([
                'conversation' => $this->chat->serializeConversation($conversation),
                'created' => $created,
                'resumed' => ! $created,
            ]),
            $raw
        );
    }

    public function messages(Request $request): JsonResponse
    {
        $conversation = $this->chat->findByRawToken($this->rawToken($request));
        if (! $conversation) {
            return response()->json(['message' => 'Chat session not found.'], 401);
        }

        $afterId = $request->integer('after_id', 0);

        return response()->json([
            'messages' => $this->chat->serializeMessages($conversation, $afterId > 0 ? $afterId : null),
            'typing' => [
                'agent' => $this->typing->isTyping($conversation, TypingService::SIDE_AGENT),
            ],
            'agent_read_message_id' => (int) ($conversation->fresh()?->agent_read_message_id ?? 0),
        ]);
    }

    public function typing(Request $request): JsonResponse
    {
        $conversation = $this->chat->findByRawToken($this->rawToken($request));
        if (! $conversation) {
            return response()->json(['message' => 'Chat session not found.'], 401);
        }

        if ($conversation->status !== 'open') {
            return response()->json(['ok' => false], 423);
        }

        $this->typing->markTyping($conversation, TypingService::SIDE_VISITOR);

        return response()->json(['ok' => true]);
    }

    public function read(Request $request): JsonResponse
    {
        $conversation = $this->chat->findByRawToken($this->rawToken($request));
        if (! $conversation) {
            return response()->json(['message' => 'Chat session not found.'], 401);
        }

        $max = (int) ($conversation->messages()->max('id') ?? 0);
        $requested = $request->integer('up_to_id', 0);
        $target = $requested > 0 ? min($requested, $max) : $max;

        if ($target > 0) {
            $conversation->markVisitorReadUpTo($target);
        }

        return response()->json([
            'ok' => true,
            'visitor_read_message_id' => (int) ($conversation->visitor_read_message_id ?? 0),
        ]);
    }

    public function send(SendChatMessageRequest $request): JsonResponse
    {
        $conversation = $this->chat->findByRawToken($this->rawToken($request));
        if (! $conversation) {
            return response()->json(['message' => 'Chat session not found.'], 401);
        }

        if ($conversation->status !== 'open') {
            return response()->json(['message' => 'This chat is closed.'], 423);
        }

        $body = trim((string) ($request->validated('body') ?? ''));
        $attachmentMeta = null;

        if ($request->hasFile('attachment')) {
            try {
                $attachmentMeta = $this->attachments->store($conversation, $request->file('attachment'));
            } catch (RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        try {
            $message = $this->chat->addMessage(
                $conversation,
                Message::SENDER_VISITOR,
                $body,
                $attachmentMeta,
                $request->integer('reply_to_id') ?: null,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => $this->chat->serializeMessage($message),
        ]);
    }

    public function download(Request $request, Message $message): StreamedResponse
    {
        $conversation = $this->chat->findByRawToken($this->rawToken($request));
        if (! $conversation || (int) $message->conversation_id !== (int) $conversation->id) {
            abort(404);
        }

        if (! $conversation->isResumable()) {
            abort(404);
        }

        return $this->attachments->download($message);
    }

    public function preview(Request $request, Message $message): StreamedResponse
    {
        $conversation = $this->chat->findByRawToken($this->rawToken($request));
        if (! $conversation || (int) $message->conversation_id !== (int) $conversation->id) {
            abort(404);
        }

        if (! $conversation->isResumable()) {
            abort(404);
        }

        return $this->attachments->preview($message);
    }

    private function rawToken(Request $request): ?string
    {
        $value = $request->cookie($this->chat->cookieName());

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function withCookie(JsonResponse $response, string $rawToken): JsonResponse
    {
        $minutes = $this->chat->resumeDays() * 24 * 60;

        return $response->withCookie(cookie(
            name: $this->chat->cookieName(),
            value: $rawToken,
            minutes: $minutes,
            path: '/',
            domain: null,
            secure: (bool) config('session.secure', false),
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_LAX,
        ));
    }
}
