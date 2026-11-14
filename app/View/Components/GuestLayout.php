<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

class GuestLayout extends Component
{
    /**
     * Lay view / noi dung dai dien cho component.
     */
    public function render(): View
    {
        return view('layouts.guest');
    }
}
