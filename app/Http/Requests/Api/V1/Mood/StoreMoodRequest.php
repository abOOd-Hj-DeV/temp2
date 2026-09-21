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
        $today = now($this->user()?->timezone() ?? config('app.timezone', 'UTC'))->startOfDay();

        return [
            'score' => ['required', 'integer', 'min:1', 'max:10', function (string $attribute, mixed $value, \Closure $fail) {
                if (! is_int($value)) {
                    $fail("The {$attribute} must be a JSON integer, not a string.");
                }
            }],
            'notes' => ['nullable', 'string', 'max:1000'],
            'log_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today, 'after_or_equal:'.$today->copy()->subDays(7)->toDateString()],
        ];
    }
}
