<?php

declare(strict_types=1);

namespace Miran\SupportChat\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class AssetController extends Controller
{
    public function css(): BinaryFileResponse|Response
    {
        return $this->file('chat.css', 'text/css; charset=UTF-8');
    }

    public function js(): BinaryFileResponse|Response
    {
        return $this->file('chat.js', 'application/javascript; charset=UTF-8');
    }

    private function file(string $name, string $contentType): BinaryFileResponse|Response
    {
        $path = dirname(__DIR__, 3).'/resources/dist/'.$name;
        if (! is_file($path)) {
            abort(404);
        }

        return response()->file($path, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
