<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Files\UploadFileRequest;
use App\Services\Files\SecureFileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController extends Controller
{
    public function __construct(private SecureFileService $files) {}

    public function upload(UploadFileRequest $request): JsonResponse
    {
        $meta = $this->files->upload($request->user(), $request->file('file'), $request->input('purpose'));

        return response()->json([
            'message' => 'File uploaded.',
            'file' => $meta + ['download_url' => url('/api/v1/files/download/'.$meta['path'])],
        ], 201);
    }

    /**
     * Streams a private file after authorization. Accepts GET and POST so
     * clients that cannot set Authorization headers on GET can use POST.
     */
    public function download(Request $request, string $path): StreamedResponse
    {
        $safe = $this->files->authorizeDownload($request->user(), $path);

        return Storage::disk($this->files->disk())->download($safe, basename($safe), [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }
}
