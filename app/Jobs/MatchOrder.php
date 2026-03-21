<?php

namespace App\Jobs;

use App\Events\OrderMatched;
use App\Models\Asset;
use App\Models\Order;
use App\Models\Trade;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class MatchOrder implements ShouldQueue
{
    use Queueable;

    public function __construct(public Order $order) {}

    public function handle(): void
    {
        $order = $this->order;

        // Find first valid counter order
        $match = $this->findMatch($order);

        if (!$match) return;

        DB::transaction(function () use ($order, $match) {
            $commission   = 0.015; // 1.5%
            $usdVolume    = $order->amount * $order->price;
            $fee          = round($usdVolume * $commission, 8);

            // Identify buyer and seller
            [$buyOrder, $sellOrder] = $order->side === 'buy'
                ? [$order, $match]
                : [$match, $order];

            $buyer  = $buyOrder->user;
            $seller = $sellOrder->user;

            // --- Settle buyer ---
            // Buyer locked USD at their order price, match happens at sell price
            $actualCost   = $order->amount * $match->price;
            $lockedCost   = $buyOrder->amount * $buyOrder->price;
            $refund       = $lockedCost - $actualCost; // refund price difference if any

            // Deduct fee from buyer's perspective (from the USD they locked)
            // Refund overpaid USD minus the fee
            $buyer->increment('balance', max(0, $refund - $fee));

            // Credit buyer with the asset
            $buyerAsset = Asset::firstOrCreate(
                ['user_id' => $buyer->id, 'symbol' => $buyOrder->symbol],
                ['amount' => 0, 'locked_amount' => 0]
            );
            $buyerAsset->increment('amount', $buyOrder->amount);

            // --- Settle seller ---
            // Release seller's locked asset
            $sellerAsset = Asset::where('user_id', $seller->id)
                ->where('symbol', $sellOrder->symbol)
                ->lockForUpdate()
                ->firstOrFail();

            $sellerAsset->decrement('locked_amount', $sellOrder->amount);

            // Credit seller with USD (at matched price, no fee deducted from seller)
            $sellerUsd = $sellOrder->amount * $match->price;
            $seller->increment('balance', $sellerUsd);

            // --- Mark both orders filled ---
            $buyOrder->update(['status'  => 2]);
            $sellOrder->update(['status' => 2]);

            // --- Record trade ---
            $trade = Trade::create([
                'buy_order_id'  => $buyOrder->id,
                'sell_order_id' => $sellOrder->id,
                'symbol'        => $buyOrder->symbol,
                'price'         => $match->price,
                'amount'        => $buyOrder->amount,
            ]);

            // --- Broadcast to both parties ---
            event(new OrderMatched($buyer, $seller, $trade, $fee));
        });
    }

    private function findMatch(Order $order): ?Order
    {
        $query = Order::where('symbol', $order->symbol)
            ->where('status', 1)
            ->where('user_id', '!=', $order->user_id); // can't match yourself

        if ($order->side === 'buy') {
            // Match with cheapest available sell at or below buy price
            return $query
                ->where('side', 'sell')
                ->where('price', '<=', $order->price)
                ->orderBy('price', 'asc')
                ->orderBy('created_at', 'asc')
                ->lockForUpdate()
                ->first();
        } else {
            // Match with highest available buy at or above sell price
            return $query
                ->where('side', 'buy')
                ->where('price', '>=', $order->price)
                ->orderBy('price', 'desc')
                ->orderBy('created_at', 'asc')
                ->lockForUpdate()
                ->first();
        }
    }
}