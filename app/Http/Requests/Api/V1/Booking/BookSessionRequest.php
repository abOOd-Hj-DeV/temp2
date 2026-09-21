<?php

namespace App\Http\Requests\Api\V1\Booking;

use Illuminate\Foundation\Http\FormRequest;

class BookSessionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'therapist_id' => 'required|uuid|exists:therapists,user_id',
            'session_date' => 'required|date_format:Y-m-d',
            'session_time' => 'required|date_format:H:i',
            'medium' => 'required|in:zoom,meet,whatsapp',
        ];
    }
}
