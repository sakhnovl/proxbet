<?php

declare(strict_types=1);

function processUpdate(array $update, array $ctx): void
{
    if (isset($update['message']) && is_array($update['message'])) {
        handleMessage($update['message'], $ctx);
    }

    if (isset($update['callback_query']) && is_array($update['callback_query'])) {
        handleCallback($update['callback_query'], $ctx);
    }
}
