<?php

/**
 * Push-notification configuration.
 *
 * Read via config('notification.*') — never env() in runtime code.
 */

return [
    // FCM legacy server key. Empty => NotificationService degrades to a log line
    // (no error in local/dev where push is not configured).
    'fcm_server_key' => env('FCM_SERVER_KEY'),
];
