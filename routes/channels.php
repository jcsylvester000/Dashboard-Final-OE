<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
| Private channels. Each member only ever listens on their own channel;
| notifications are pushed there (Reverb). Nothing workspace-wide is broadcast.
*/
Broadcast::channel('App.Models.User.{id}', fn (User $user, int $id): bool => $user->id === $id && $user->is_active);
