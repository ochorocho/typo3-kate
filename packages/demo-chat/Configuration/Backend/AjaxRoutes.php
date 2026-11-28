<?php

use Ochorocho\KateDemoChat\Controller\ChatController;

return [
    'kate_demo_chat_subscribe' => [
        'path' => '/kate-demo-chat/subscribe',
        'methods' => ['GET'],
        'target' => ChatController::class . '::subscribeAction',
    ],
    'kate_demo_chat_send' => [
        'path' => '/kate-demo-chat/send',
        'methods' => ['POST'],
        'target' => ChatController::class . '::sendAction',
    ],
];
