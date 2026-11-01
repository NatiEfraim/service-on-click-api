<?php

namespace App\Http\Controllers;

use App\Support\AccessScope;
use Illuminate\Support\Facades\Auth;

abstract class Controller
{
    /** What the logged-in user may see and do (see AccessScope). */
    protected function accessScope(): AccessScope
    {
        return new AccessScope(Auth::user());
    }
}
