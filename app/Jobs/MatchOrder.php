<?php

namespace App\Jobs;

use App\Events\OrderMatched;
use App\Models\Asset;
use App\Models\Order;
use App\Models\Trade;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Type\Decimal;

class MatchOrder implements ShouldQueue
{
    use Queueable;

    public function __construct(public Order $order, public float $commission) {}

    public function handle(): void
    {
        $order = $this->order;

        // Find first valid counter order
        $match = $this->findMatch($order);

        if (!$match) return;

        DB::transaction(function () use ($order, $match) {
            $commission   = $this->commission; // 1.5%

            // Identify buyer and seller
            [$buyOrder, $sellOrder] = $order->side === 'buy'
                ? [$order, $match]
                : [$match, $order];

            // 🔒 Lock both orders for update to prevent race conditions
            $buyOrder  = Order::where('id', $buyOrder->id)->lockForUpdate()->first();
            $sellOrder = Order::where('id', $sellOrder->id)->lockForUpdate()->first();

            $buyer  = $buyOrder->user;
            $seller = $sellOrder->user;

            // ✅ Determine actual matched amount
            $matchAmount = $buyOrder->amount; // full match only for simplicity

            $matchPrice = $sellOrder->price; // Trade executes at the sell price

            $usdVolume = $matchAmount * $matchPrice;
            $fee = round($usdVolume * $commission, 8);

            // --- Settle buyer ---
            // Buyer locked USD at their order price, match happens at sell price
            $lockedCost = $buyOrder->locked_volume; // total locked for this order (cost + fee);
            $actualCost = $usdVolume;
            $refund     = $lockedCost - ($actualCost + $fee); // refund price difference if any

            // remove ALL locked funds for this order
            $buyer->decrement('locked_balance', $lockedCost);

            // Refund overpaid USD back to buyer (if buy price > match price)
            if ($refund > 0) {
                $buyer->increment('balance', $refund);
            }        

            // Credit buyer with the asset
            $buyerAsset = Asset::firstOrCreate(
                ['user_id' => $buyer->id, 'symbol' => $buyOrder->symbol],
                ['amount' => 0, 'locked_amount' => 0]
            );
            $buyerAsset->increment('amount', $matchAmount);

            // --- Settle seller ---
            // Release seller's locked asset
            $sellerAsset = Asset::where('user_id', $seller->id)
                ->where('symbol', $sellOrder->symbol)
                ->lockForUpdate()
                ->firstOrFail();

              // decrement ONLY matched portion
            $sellerAsset->decrement('locked_amount', $matchAmount);

            // Credit seller with USD (at matched price, no fee deducted from seller)
            $seller->increment('balance', $usdVolume);

            // ======================
            // 📉 ORDERS (FULL FILL)
            // ======================
            $buyOrder->update(['status' => 2]);
            $sellOrder->update(['status' => 2]);

            // --- Record trade ---
            $trade = Trade::create([
                'buy_order_id'  => $buyOrder->id,
                'sell_order_id' => $sellOrder->id,
                'symbol'        => $buyOrder->symbol,
                'price'         => $matchPrice,
                'amount'        => $matchAmount,
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
                ->where('amount', $order->amount) // full match only for simplicity
                ->orderBy('price', 'asc')
                ->orderBy('created_at', 'asc')
                ->first();
        } else {
            // Match with highest available buy at or above sell price
            return $query
                ->where('side', 'buy')
                ->where('price', '>=', $order->price)
                ->where('amount', $order->amount) // full match only for simplicity
                ->orderBy('price', 'desc')
                ->orderBy('created_at', 'asc')
                ->first();
        }
    }
}