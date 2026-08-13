<?php

declare(strict_types=1);

namespace Miran\SupportChat\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class Widget extends Component
{
    public function render(): View
    {
        return view('support-chat::components.widget');
    }
}
