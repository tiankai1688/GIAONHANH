<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Authorization for orders.
 *
 * A customer may act on an order they own; a merchant may act on orders
 * belonging to their shop. This rule previously lived as an inline
 * `authorizeOrder()` helper duplicated across Order & Payment controllers; it
 * now lives in ONE place (here) and is reached via the base controller's
 * `authorizeOrder()` -> `$this->authorize($ability, $order)` (Laravel policy
 * auto-discovery resolves App\Models\Order -> App\Policies\OrderPolicy).
 *
 * See docs/code-architecture-review-2026-08-01.md, P2-b.
 */
class OrderPolicy
{
    use HandlesAuthorization;

    /**
     * Customer owns the order, or the acting user's merchant owns the shop the
     * order belongs to. Shared by view / pay / cancel / status.
     */
    private function owns(User $user, Order $order): bool
    {
        return $user->id === $order->user_id
            || ($user->merchant && $user->merchant->id === $order->merchant_id);
    }

    public function view(User $user, Order $order): bool
    {
        return $this->owns($user, $order);
    }

    public function update(User $user, Order $order): bool
    {
        return $this->owns($user, $order);
    }

    public function cancel(User $user, Order $order): bool
    {
        return $this->owns($user, $order);
    }
}
