<?php

namespace App\Notifications;

use App\Models\DocumentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class DocumentRequestNotification extends Notification
{
    use Queueable;

    /** @param string $kind document_requested | document_submitted | document_reviewed */
    public function __construct(
        private DocumentRequest $request,
        private string $kind,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'document_request_id' => $this->request->id,
            'doc_type' => $this->request->doc_type,
            'status' => $this->request->status,
            'reason' => $this->request->reason,
            'review_note' => $this->request->review_note,
        ];
    }
}
