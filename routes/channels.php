<?php

use Illuminate\Support\Facades\Broadcast;
// use JWTAuth;
/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

// Broadcast::channel('App.User.{id}', function ($user, $id) {
//     return (int) $user->id === (int) $id;
// });

Broadcast::channel('messenger.{sender}.{receiver}', function ($user) {
    return !is_null($user);
});


Broadcast::channel('group_chat.{roomId}', function ($user, $roomId) {
    if(true){
        return ['id'=>$user->id, 'name'=> $user->nama];
    }
});
Broadcast::channel('comment.{roomId}', function ($user, $roomId) {
    if(true){
        return ['id'=>$user->id, 'name'=> $user->nama];
    }
});