<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\PaymentGatewayInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Self-healing reconciliation (red-team hacker #5 / user #1 — "stuck orders").
 *
 * When a wallet gateway IPN is lost (network blip, PSP retry exhaustion) an
 * order can sit in `pending_payment` forever with the customer charged but no
 * goods dispatched. Without this command the order NEVER recovers.
 *
 * Behavior (CONSERVATIVE, fail-closed):
 *  1. For each stale pending_payment order, try to reconcile against the REAL
 *     PSP via PaymentGatewayInterface::queryStatus (source of truth):
 *       - 'paid'           → mark the order (and merged sub-orders) paid.
 *       - 'failed'/'expired'→ cancel + restore stock + fail the payment.
 *       - null / 'pending' / no gateway configured → CONSERVATIVE EXPIRE
 *         (cancel + restore stock). A null (unreachable / sandbox / unknown)
 *         NEVER means "paid" — the self-heal stays safe and the customer can
 *         retry. This closes the gap where a genuinely-paid-but-stuck order is
 *         now recovered, while a lost IPN still expires rather than hanging.
 *  2. Payments stuck in `pending` past TTL whose ORDER is STILL pending_payment
 *     → failed (N3 guard: never flag an already-paid order's lagging payment).
 */
class ReconcileOrders extends Command
{
    protected $signature = 'orders:reconcile';
    protected $description = 'Reconcile stuck pending_payment orders against the PSP and expire the rest.';

    public function __construct(private PaymentGatewayInterface $gateway)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $ttl = (int) config('payment.pending_ttl_minutes', 30);
        $cutoff = now()->subMinutes($ttl);

        // 1) Stale pending_payment orders → reconcile against PSP, then branch.
        $stale = Order::where('status', 'pending_payment')
            ->where('created_at', '<=', $cutoff)
            ->with('items')
            ->get();

        $recovered = 0;
        $gatewayFailed = 0;
        $expired = 0;

        foreach ($stale as $order) {
            // Determine the gateway used for this order (from its latest payment).
            // Query the payments table directly (an order may accumulate retry
            // rows); we use the most recent one to decide the reconciliation path.
            $payment = Payment::where('order_id', $order->id)->latest()->first();
            $gateway = $payment?->gateway ?? $payment?->method;

            // queryStatus returns null when the gateway is unreachable / sandbox /
            // unconfigured — treat that as "unknown" and fall through to expire.
            $status = $gateway ? $this->gateway->queryStatus($order->order_no, $gateway) : null;

            if ($status === 'paid') {
                // Genuinely paid but the IPN was lost → fulfill it.
                DB::transaction(function () use ($order) {
                    $this->markPaid($order);
                    Payment::where('order_id', $order->id)
                        ->where('status', 'pending')
                        ->update(['status' => 'success', 'paid_at' => now()]);
                });
                $recovered++;
                continue;
            }

            if ($status === 'failed' || $status === 'expired') {
                // Gateway says it never succeeded → cancel + free inventory.
                DB::transaction(function () use ($order) {
                    $this->restoreStock($order);
                    $order->update(['status' => 'cancelled']);
                    Payment::where('order_id', $order->id)
                        ->where('status', 'pending')
                        ->update(['status' => 'failed']);
                });
                $gatewayFailed++;
                continue;
            }

            // Conservative default (null / 'pending' / no gateway): expire + free stock.
            DB::transaction(function () use ($order) {
                $this->restoreStock($order);
                $order->update(['status' => 'cancelled']);
                Payment::where('order_id', $order->id)
                    ->where('status', 'pending')
                    ->update(['status' => 'failed']);
            });
            $expired++;
        }

        if ($recovered) {
            Log::info("Reconcile: gateway recovered {$recovered} genuinely-paid orders.", ['ttl_minutes' => $ttl]);
        }
        if ($gatewayFailed) {
            Log::warning("Reconcile: gateway confirmed {$gatewayFailed} failed orders cancelled.");
        }
        if ($expired) {
            Log::warning("Reconcile: expired {$expired} stale pending_payment orders (gateway unknown).", ['ttl_minutes' => $ttl]);
        }
        $this->info("Reconcile: recovered={$recovered} gatewayFailed={$gatewayFailed} expired={$expired} (TTL {$ttl}m).");

        // 2) Payments stuck in `pending` past TTL (no gateway confirmation) → failed.
        // N3 guard: only mark payments whose ORDER is STILL pending_payment. A
        // payment row can briefly lag its order (IPN wrote the order `paid` but
        // the payment row update is in flight); marking it `failed` there would
        // wrongly flag an already-paid order as failed and corrupt reconciliation.
        $stalePay = Payment::where('status', 'pending')
            ->where('created_at', '<=', $cutoff)
            ->whereHas('order', fn ($o) => $o->where('status', 'pending_payment'))
            ->get();
        $failed = 0;
        foreach ($stalePay as $pay) {
            $pay->update(['status' => 'failed']);
            $failed++;
        }
        if ($failed) {
            Log::warning("Reconcile: marked {$failed} stale pending payments as failed.");
        }
        $this->info("Marked {$failed} stale pending payments as failed.");

        return self::SUCCESS;
    }

    /**
     * Mark an order paid, cascading to merged sub-orders (mirrors the IPN
     * handlers in PaymentController so recovery is consistent with live payment).
     */
    private function markPaid(Order $order): void
    {
        $order->update(['status' => 'paid', 'paid_at' => now()]);
        if ($order->type === 'merged') {
            $order->subOrders()
                ->where('status', 'pending_payment')
                ->update(['status' => 'paid', 'paid_at' => now()]);
        }
    }

    /**
     * Atomically restore the stock reserved by an order's items.
     */
    private function restoreStock(Order $order): void
    {
        foreach ($order->items as $item) {
            Product::where('id', $item->product_id)->increment('stock', $item->qty);
        }
    }
}
