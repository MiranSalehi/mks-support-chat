<?php

declare(strict_types=1);

namespace Miran\SupportChat\Http\Controllers;

use Illuminate\Routing\Controller;
use Miran\SupportChat\Models\Message;
use Miran\SupportChat\Support\AttachmentService;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class FilamentAttachmentController extends Controller
{
    public function __construct(
        private readonly AttachmentService $attachments,
    ) {}

    public function preview(Message $message): StreamedResponse
    {
        return $this->attachments->preview($message);
    }

    public function download(Message $message): StreamedResponse
    {
        return $this->attachments->download($message);
    }
}
