<?php

namespace App;

use Ratchet\ConnectionInterface;
use Ratchet\RFC6455\Messaging\MessageInterface;
use Ratchet\WebSocket\MessageComponentInterface;
use BeyondCode\LaravelWebSockets\WebSockets\WebSocketHandler;

class MyCustomWebSocketHandler extends WebSocketHandler
{

    public function onMessage(ConnectionInterface $connection, MessageInterface $msg)
    {
        // TODO: Implement onMessage() method.
        \Illuminate\Support\Facades\Log::info('onMessage');
    }
    public function onOpen(ConnectionInterface $connection)
    {
        // TODO: Implement onOpen() method.
        \Illuminate\Support\Facades\Log::info("onOpen");

    }
    
    public function onClose(ConnectionInterface $connection)
    {
        // TODO: Implement onClose() method.
        \Illuminate\Support\Facades\Log::info("onClose");

    }

    public function onError(ConnectionInterface $connection, \Exception $e)
    {
        // TODO: Implement onError() method.
        \Illuminate\Support\Facades\Log::info("onError");

    }
}