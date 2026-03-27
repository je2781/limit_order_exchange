<?php

namespace App\Http\Controllers;

use App\Jobs\MatchOrder;
use App\Models\Asset;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    // GET /api/orders?symbol=BTC&all=true
    public function index(Request $request)
    {
        $request->validate(['symbol' => 'required|string', 'all' => 'string|nullable']);

        $orders = Order::where('symbol', $request->symbol)
            ->when(!$request->boolean('all'), function ($query) {
                $query->where('status', 1); // open only
            })
            ->orderBy('created_at', 'desc')
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

        $commission = 0.015; // 1.5% commission fee
        
        $order = DB::transaction(function () use ($user, $symbol, $side, $price, $amount, $cost, $commission) {
            if ($side === 'buy') {
                if ($user->balance < $cost) {
                    abort(422, 'Insufficient USD balance.');
                }
                // Lock buyer's funds (cost + fee) for this order
                $locked = $cost * (1 + $commission);
         
                $user->decrement('balance', $locked);
                $user->increment('locked_balance', $locked);

                return Order::create([
                    'user_id' => $user->id,
                    'symbol'  => $symbol,
                    'side'    => $side,
                    'price'   => $price,
                    'amount'  => $amount,
                    'status'  => 1,
                    'locked_volume' => $locked,
                ]);

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

                return Order::create([
                    'user_id' => $user->id,
                    'symbol'  => $symbol,
                    'side'    => $side,
                    'price'   => $price,
                    'amount'  => $amount,
                    'status'  => 1,
                ]);
            }

           
        });

        // Attempt match after order is committed
        MatchOrder::dispatch($order, $commission);

        return response()->json($order, 201);
    }

    // POST /api/orders/{id}/cancel
    public function cancel(Request $request, int $id)
    {
        $user  = $request->user();

        $order = Order::where('id', $id)
            ->where('status', 1)
            ->firstOrFail(); // find the order first

        // Explicit ownership check — 403 is more accurate than 404 here
        if ($order->user_id !== $user->id) {
            return response()->json(['message' => 'You are not authorised to cancel this order.'], 403);
        }


        DB::transaction(function () use ($order, $user) {
            if ($order->side === 'buy') {
                // Unlock buyer's funds (cost + fee)
                $locked = $order->locked_volume; // total locked for this order (cost + fee);

                $user->increment('balance', $locked);
                $user->decrement('locked_balance', $locked);
            } else {
                $asset = Asset::where('user_id', $user->id)
                    ->where('symbol', $order->symbol)
                    ->lockForUpdate()
                    ->firstOrFail();

                $asset->increment('amount', $order->amount);
                $asset->decrement('locked_amount', $order->amount);
            }

            $order->update(['status' => 3]);
        });

        return response()->json(['message' => 'Order cancelled.']);
    }
}