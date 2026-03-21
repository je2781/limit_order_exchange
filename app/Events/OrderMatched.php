<?php

namespace App\Events;

use App\Models\Trade;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderMatched implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public User  $buyer,
        public User  $seller,
        public Trade $trade,
        public float $fee,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("user.{$this->buyer->id}"),
            new PrivateChannel("user.{$this->seller->id}"),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'trade'  => $this->trade,
            'fee'    => $this->fee,
        ];
    }
}