<?php

declare(strict_types=1);

namespace Miran\SupportChat\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Miran\SupportChat\Models\Conversation;
use Miran\SupportChat\Models\Message;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AttachmentService
{
    /**
     * @return array<string, list<string>>
     */
    public function allowedMap(): array
    {
        return [
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword'],
            'docx' => [
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/zip',
            ],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'webp' => ['image/webp'],
        ];
    }

    /**
     * @return list<string>
     */
    public function allowedExtensions(): array
    {
        $configured = config('support-chat.attachment_extensions');
        if (is_array($configured) && $configured !== []) {
            return array_values(array_map(
                static fn ($ext): string => strtolower(ltrim((string) $ext, '.')),
                $configured
            ));
        }

        return array_keys($this->allowedMap());
    }

    public function maxKilobytes(): int
    {
        return max(1, (int) config('support-chat.attachment_max_kb', 5120));
    }

    public function disk(): string
    {
        return (string) config('support-chat.attachment_disk', 'local');
    }

    public function basePath(): string
    {
        return trim((string) config('support-chat.attachment_path', 'support-chat'), '/');
    }

    /**
     * @return array{disk: string, path: string, name: string, mime: string, size: int}
     */
    public function store(Conversation $conversation, UploadedFile $file): array
    {
        $this->assertSafeUpload($file);

        $extension = strtolower($file->getClientOriginalExtension());
        $safeName = $this->sanitizeOriginalName($file->getClientOriginalName(), $extension);
        $storedName = Str::uuid()->toString().'.'.$extension;
        $directory = $this->basePath().'/'.$conversation->uuid;
        $disk = $this->disk();

        $path = $file->storeAs($directory, $storedName, [
            'disk' => $disk,
            'visibility' => 'private',
        ]);

        if (! is_string($path) || $path === '' || ! $this->pathIsInsideBase($path)) {
            if (is_string($path) && $path !== '') {
                Storage::disk($disk)->delete($path);
            }
            throw new RuntimeException('Failed to store chat attachment.');
        }

        $absolute = Storage::disk($disk)->path($path);
        $detectedMime = $this->detectMime($absolute) ?? (string) $file->getMimeType();

        if (! $this->mimeAllowedForExtension($extension, $detectedMime)) {
            Storage::disk($disk)->delete($path);
            throw new RuntimeException('Attachment type mismatch.');
        }

        if ($this->looksLikeExecutableContent($absolute, $detectedMime)) {
            Storage::disk($disk)->delete($path);
            throw new RuntimeException('Attachment content rejected.');
        }

        if (! $this->matchesMagicBytes($absolute, $extension, $detectedMime)) {
            Storage::disk($disk)->delete($path);
            throw new RuntimeException('Attachment signature mismatch.');
        }

        return [
            'disk' => $disk,
            'path' => $path,
            'name' => $safeName,
            'mime' => $detectedMime,
            'size' => (int) $file->getSize(),
        ];
    }

    public function assertSafeUpload(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw new RuntimeException('Invalid upload.');
        }

        $maxBytes = $this->maxKilobytes() * 1024;
        if ($file->getSize() <= 0 || $file->getSize() > $maxBytes) {
            throw new RuntimeException('Attachment exceeds size limit.');
        }

        $original = $file->getClientOriginalName();
        if ($original === '' || str_contains($original, "\0")) {
            throw new RuntimeException('Invalid attachment name.');
        }

        if (preg_match('/[\\\\\\/]/', $original) === 1) {
            throw new RuntimeException('Invalid attachment name.');
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if ($extension === '' || ! in_array($extension, $this->allowedExtensions(), true)) {
            throw new RuntimeException('Attachment type not allowed.');
        }

        $lower = strtolower($original);
        if (! str_ends_with($lower, '.'.$extension)) {
            throw new RuntimeException('Attachment extension mismatch.');
        }

        if (preg_match('/\.(php|phtml|phar|exe|sh|bat|cmd|js|html|htm|svg|shtml)(\.|$)/i', $original) === 1) {
            throw new RuntimeException('Attachment type not allowed.');
        }
    }

    public function sanitizeOriginalName(string $original, string $extension): string
    {
        $base = pathinfo($original, PATHINFO_FILENAME);
        $base = Str::of($base)
            ->replaceMatches('/[^\pL\pN\.\-_ ]+/u', '')
            ->trim(' .-_')
            ->limit(80, '')
            ->toString();

        if ($base === '') {
            $base = 'file';
        }

        return $base.'.'.$extension;
    }

    public function detectMime(string $absolutePath): ?string
    {
        if (! is_file($absolutePath)) {
            return null;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($absolutePath);

        return is_string($mime) && $mime !== '' ? $mime : null;
    }

    public function mimeAllowedForExtension(string $extension, string $mime): bool
    {
        $map = $this->allowedMap();
        $allowed = $map[$extension] ?? [];

        return in_array(strtolower($mime), array_map('strtolower', $allowed), true);
    }

    public function looksLikeExecutableContent(string $absolutePath, string $mime): bool
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            return true;
        }

        $handle = fopen($absolutePath, 'rb');
        if ($handle === false) {
            return true;
        }

        $chunk = fread($handle, 512) ?: '';
        fclose($handle);

        $lower = strtolower($chunk);
        if (str_contains($lower, '<?php') || str_contains($lower, '<script') || str_starts_with(ltrim($chunk), '#!')) {
            return true;
        }

        if (str_contains($lower, '<svg') || str_contains($mime, 'svg')) {
            return true;
        }

        return false;
    }

    public function matchesMagicBytes(string $absolutePath, string $extension, string $mime): bool
    {
        $handle = fopen($absolutePath, 'rb');
        if ($handle === false) {
            return false;
        }

        $bytes = fread($handle, 12) ?: '';
        fclose($handle);

        return match ($extension) {
            'pdf' => str_starts_with($bytes, '%PDF'),
            'png' => str_starts_with($bytes, "\x89PNG\r\n\x1a\n"),
            'jpg', 'jpeg' => str_starts_with($bytes, "\xFF\xD8\xFF"),
            'webp' => str_starts_with($bytes, 'RIFF') && str_contains(substr($bytes, 0, 12), 'WEBP'),
            'doc' => str_starts_with($bytes, "\xD0\xCF\x11\xE0"),
            'docx' => str_starts_with($bytes, "PK\x03\x04") || str_starts_with($bytes, "PK\x05\x06"),
            default => $mime !== '',
        };
    }

    public function download(Message $message): StreamedResponse
    {
        if (! $message->hasAttachment()) {
            abort(404);
        }

        $disk = Storage::disk((string) $message->attachment_disk);
        $path = (string) $message->attachment_path;

        if (! $this->pathIsInsideBase($path) || ! $disk->exists($path)) {
            abort(404);
        }

        return $disk->download(
            $path,
            (string) $message->attachment_name,
            [
                'Content-Type' => (string) $message->attachment_mime,
                'X-Content-Type-Options' => 'nosniff',
                'Content-Disposition' => 'attachment; filename="'.$this->headerFilename((string) $message->attachment_name).'"',
            ]
        );
    }

    public function preview(Message $message): StreamedResponse
    {
        if (! $message->attachmentIsImage()) {
            abort(404);
        }

        $disk = Storage::disk((string) $message->attachment_disk);
        $path = (string) $message->attachment_path;

        if (! $this->pathIsInsideBase($path) || ! $disk->exists($path)) {
            abort(404);
        }

        $mime = (string) $message->attachment_mime;
        $filename = $this->headerFilename((string) $message->attachment_name);

        return $disk->response(
            $path,
            (string) $message->attachment_name,
            [
                'Content-Type' => $mime,
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, max-age=3600',
                'Content-Disposition' => 'inline; filename="'.$filename.'"',
            ]
        );
    }

    private function pathIsInsideBase(string $path): bool
    {
        $normalized = str_replace('\\', '/', ltrim($path, '/'));
        $base = $this->basePath().'/';

        if ($normalized === '' || str_contains($normalized, '..')) {
            return false;
        }

        return str_starts_with($normalized, $base) || $normalized === rtrim($base, '/');
    }

    private function headerFilename(string $name): string
    {
        return str_replace(['"', "\r", "\n"], '', $name);
    }
}
