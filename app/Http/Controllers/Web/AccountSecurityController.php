<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

final class AccountSecurityController extends Controller
{
    public function show(): View
    {
        return view('account.security');
    }
}
