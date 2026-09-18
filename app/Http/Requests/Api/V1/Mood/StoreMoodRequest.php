<?php

namespace App\Http\Requests\Api\V1\Mood;

use Illuminate\Foundation\Http\FormRequest;

class StoreMoodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'score' => ['required', 'integer', 'min:1', 'max:10'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'log_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:'.now()->subDays(7)->toDateString()],
        ];
    }
}
