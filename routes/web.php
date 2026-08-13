<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Miran\SupportChat\Http\Controllers\AssetController;
use Miran\SupportChat\Http\Controllers\FilamentAttachmentController;
use Miran\SupportChat\Http\Controllers\WidgetController;

$prefix = trim((string) config('support-chat.route_prefix', 'support-chat'), '/');

Route::prefix($prefix)->group(function (): void {
    Route::get('assets/chat.css', [AssetController::class, 'css'])
        ->name('support-chat.assets.css');

    Route::get('assets/chat.js', [AssetController::class, 'js'])
        ->name('support-chat.assets.js');

    Route::get('session', [WidgetController::class, 'session'])
        ->middleware('throttle:sc-chat-read')
        ->name('support-chat.session');

    Route::post('start', [WidgetController::class, 'start'])
        ->middleware('throttle:sc-chat-write')
        ->name('support-chat.start');

    Route::get('messages', [WidgetController::class, 'messages'])
        ->middleware('throttle:sc-chat-read')
        ->name('support-chat.messages');

    Route::post('messages', [WidgetController::class, 'send'])
        ->middleware('throttle:sc-chat-write')
        ->name('support-chat.send');

    Route::post('typing', [WidgetController::class, 'typing'])
        ->middleware('throttle:sc-chat-typing')
        ->name('support-chat.typing');

    Route::get('attachments/{message}', [WidgetController::class, 'download'])
        ->middleware('throttle:sc-chat-read')
        ->whereNumber('message')
        ->name('support-chat.attachment');

    Route::get('attachments/{message}/preview', [WidgetController::class, 'preview'])
        ->middleware('throttle:sc-chat-read')
        ->whereNumber('message')
        ->name('support-chat.attachment.preview');
});

Route::middleware('auth')->prefix($prefix.'/admin/files')->group(function (): void {
    Route::get('messages/{message}/preview', [FilamentAttachmentController::class, 'preview'])
        ->whereNumber('message')
        ->name('support-chat.filament.attachment.preview');

    Route::get('messages/{message}/download', [FilamentAttachmentController::class, 'download'])
        ->whereNumber('message')
        ->name('support-chat.filament.attachment.download');
});
