<?php

declare(strict_types=1);

namespace Miran\SupportChat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Miran\SupportChat\Support\ChatService;

class StartChatRequest extends FormRequest
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
        $max = app(ChatService::class)->maxMessageLength();

        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['required', 'string', 'max:32'],
            'greeting' => ['nullable', 'string', 'max:'.$max],
            'page_path' => ['nullable', 'string', 'max:255', 'starts_with:/'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $digits = app(ChatService::class)->normalizePhone((string) $this->input('phone', ''));
            if (strlen($digits) < 8 || strlen($digits) > 15) {
                $validator->errors()->add('phone', 'Enter a valid mobile number.');
            }
        });
    }
}
