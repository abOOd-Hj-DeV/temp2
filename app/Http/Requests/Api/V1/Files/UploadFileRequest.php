<?php

namespace App\Http\Requests\Api\V1\Files;

use App\Services\Files\SecureFileService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:5120'],
            'purpose' => ['required', Rule::in(SecureFileService::PURPOSES)],
        ];
    }
}
