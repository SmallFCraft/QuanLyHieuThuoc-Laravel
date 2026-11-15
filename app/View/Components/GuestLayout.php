<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

class GuestLayout extends Component
{
    /**
     * Lấy view / nội dung đại diện cho component.
     */
    public function render(): View
    {
        return view('layouts.guest');
    }
}
