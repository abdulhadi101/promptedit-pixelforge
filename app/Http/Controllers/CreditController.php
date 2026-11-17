<?php

namespace App\Http\Controllers;

use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Fake purchase: no real payments, one fixed $10.00 bundle per click. */
class CreditController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $request->user()->increment('balance_nano', Money::parseDecimal('10.00', 9));

        return back();
    }
}
