<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes (scheduled commands)
|--------------------------------------------------------------------------
| Loaded by bootstrap/app.php (withRouting -> commands). Register scheduled
| tasks here. The reconciliation command self-heals stuck payments/orders so
| a lost gateway IPN cannot strand an order forever.
*/
// withoutOverlapping() uses the cache (Redis) mutex so that, when the API is
// scaled to multiple replicas (or a dedicated scheduler service is added — see
// docker-compose.yml), only ONE process runs the reconcile per cycle. This
// prevents double-expiry / double-recovery of the same order across replicas.
Schedule::command('orders:reconcile')->everyFiveMinutes()->withoutOverlapping(10);
