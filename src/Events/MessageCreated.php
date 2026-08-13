<?php

declare(strict_types=1);

namespace Miran\SupportChat\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Miran\SupportChat\Models\Message;

class MessageCreated
{
    use Dispatchable;

    public function __construct(
        public readonly Message $message,
    ) {}
}
