<?php

namespace App\Http\Requests\Api\V1\Chat;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape-only validation; the sniffed MIME type, the per-kind size ceiling
 * and the text/attachment requirement are enforced by ChatService.
 */
class SendMessageRequest extends FormRequest
{
    public function rules(): array
    {
        $maxChars = (int) config('sakina.chat.max_message_chars', 4000);
        $maxKb = max((array) config('sakina.chat.attachment_max_kb', [5120]));

        return [
            'content' => ['nullable', 'string', "max:{$maxChars}"],
            'attachment' => ['nullable', 'file', "max:{$maxKb}"],
        ];
    }
}
