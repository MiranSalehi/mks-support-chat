<?php

declare(strict_types=1);

namespace Miran\SupportChat;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Miran\SupportChat\Events\MessageCreated;
use Miran\SupportChat\Listeners\NotifyPanelUsers;
use Miran\SupportChat\Support\ChatService;
use Miran\SupportChat\View\Components\Widget;

class SupportChatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/support-chat.php', 'support-chat');

        $this->app->singleton(ChatService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'support-chat');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'support-chat');

        $this->app->booted(function (): void {
            Route::middleware('web')->group(__DIR__.'/../routes/web.php');
        });

        Blade::component('support-chat::widget', Widget::class);

        Event::listen(MessageCreated::class, NotifyPanelUsers::class);

        $this->registerRateLimiters();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/support-chat.php' => config_path('support-chat.php'),
            ], 'support-chat-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/support-chat'),
            ], 'support-chat-views');
        }
    }

    private function registerRateLimiters(): void
    {
        $key = static function (Request $request): string {
            $raw = $request->cookie((string) config('support-chat.cookie', ChatService::COOKIE));

            if (is_string($raw) && $raw !== '') {
                return 'sc-chat:token:'.hash('sha256', $raw);
            }

            return 'sc-chat:ip:'.$request->ip();
        };

        RateLimiter::for('sc-chat-read', static fn (Request $request): Limit => Limit::perMinute(180)->by($key($request)));
        RateLimiter::for('sc-chat-write', static fn (Request $request): Limit => Limit::perMinute(30)->by($key($request)));
        RateLimiter::for('sc-chat-typing', static fn (Request $request): Limit => Limit::perMinute(60)->by($key($request)));
    }
}
