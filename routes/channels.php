<?php

use Illuminate\Support\Facades\Broadcast;

// routes/channels.php
Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});