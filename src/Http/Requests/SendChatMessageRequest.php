<?php

declare(strict_types=1);

namespace Miran\SupportChat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Miran\SupportChat\Support\AttachmentService;
use Miran\SupportChat\Support\ChatService;

class SendChatMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $chat = app(ChatService::class);
        $attachments = app(AttachmentService::class);
        $extensions = implode(',', $attachments->allowedExtensions());

        return [
            'body' => ['nullable', 'string', 'max:'.$chat->maxMessageLength()],
            'reply_to_id' => ['nullable', 'integer', 'min:1'],
            'attachment' => [
                'nullable',
                'file',
                'max:'.$attachments->maxKilobytes(),
                'mimes:'.$extensions,
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $body = trim((string) $this->input('body', ''));
            $hasFile = $this->hasFile('attachment');

            if ($body === '' && ! $hasFile) {
                $validator->errors()->add('body', 'Message text or an attachment is required.');
            }

            if ($hasFile) {
                try {
                    app(AttachmentService::class)->assertSafeUpload($this->file('attachment'));
                } catch (\Throwable $e) {
                    $validator->errors()->add('attachment', $e->getMessage());
                }
            }
        });
    }
}
