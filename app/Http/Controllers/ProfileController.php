<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user()->load('assets');

        return response()->json([
            'balance' => $user->balance,
            'assets'  => $user->assets->map(fn($a) => [
                'symbol'        => $a->symbol,
                'amount'        => $a->amount,
                'locked_amount' => $a->locked_amount,
            ]),
        ]);
    }
}