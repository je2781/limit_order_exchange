<?php

namespace App\Http\Controllers;

use App\Jobs\MatchOrder;
use App\Models\Asset;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    // GET /api/orders?symbol=BTC
    public function index(Request $request)
    {
        $request->validate(['symbol' => 'required|string']);

        $orders = Order::where('symbol', $request->symbol)
            ->where('status', 1) // open only
            ->select('id', 'user_id', 'side', 'price', 'amount')
            ->get()
            ->groupBy('side');

         return response()->json([
        'buy'  => $orders->get('buy', collect())->values(),
        'sell' => $orders->get('sell', collect())->values(),
        ]);
    }

    // POST /api/orders
    public function store(Request $request)
    {
        $data = $request->validate([
            'symbol' => 'required|string',
            'side'   => 'required|in:buy,sell',
            'price'  => 'required|numeric|min:0.00000001',
            'amount' => 'required|numeric|min:0.00000001',
        ]);

        $user   = $request->user();
        $symbol = strtoupper($data['symbol']);
        $side   = $data['side'];
        $price  = $data['price'];
        $amount = $data['amount'];
        $cost   = $amount * $price; // USD cost of the order

        $order = DB::transaction(function () use ($user, $symbol, $side, $price, $amount, $cost) {
            if ($side === 'buy') {
                if ($user->balance < $cost) {
                    abort(422, 'Insufficient USD balance.');
                }
                // Lock USD
                $user->decrement('balance', $cost);

            } else {
                $asset = Asset::where('user_id', $user->id)
                    ->where('symbol', $symbol)
                    ->lockForUpdate()
                    ->first();

                if (!$asset || $asset->amount < $amount) {
                    abort(422, 'Insufficient asset balance.');
                }
                // Move to locked
                $asset->decrement('amount', $amount);
                $asset->increment('locked_amount', $amount);
            }

            return Order::create([
                'user_id' => $user->id,
                'symbol'  => $symbol,
                'side'    => $side,
                'price'   => $price,
                'amount'  => $amount,
                'status'  => 1,
            ]);
        });

        // Attempt match after order is committed
        MatchOrder::dispatch($order);

        return response()->json($order, 201);
    }

    // POST /api/orders/{id}/cancel
    public function cancel(Request $request, int $id)
    {
        $user  = $request->user();
        $order = Order::where('id', $id)
            ->where('user_id', $user->id)
            ->where('status', 1)
            ->firstOrFail();

        DB::transaction(function () use ($order, $user) {
            if ($order->side === 'buy') {
                // Refund locked USD
                $user->increment('balance', $order->amount * $order->price);
            } else {
                // Unlock asset
                $asset = Asset::where('user_id', $user->id)
                    ->where('symbol', $order->symbol)
                    ->lockForUpdate()
                    ->firstOrFail();

                $asset->increment('amount', $order->amount);
                $asset->decrement('locked_amount', $order->amount);
            }

            $order->update(['status' => 3]); // cancelled
        });

        return response()->json(['message' => 'Order cancelled.']);
    }
}