<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantPayout;
use App\Models\MerchantSettlementAck;
use App\Services\MerchantSettlementService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class SettlementController extends Controller
{
    /**
     * Merchant views its own T+1 settlement (sub-orders delivered on the
     * given business day, excluding cancelled/refunded).
     * Route: GET /api/merchant/settlements
     */
    public function merchantIndex(Request $request)
    {
        $merchant = Merchant::where('user_id', $request->user()->id)->firstOrFail();

        $data = MerchantSettlementService::forMerchant($merchant, $request->get('date'));
        $data['confirmed'] = MerchantSettlementAck::where('merchant_id', $merchant->id)
            ->where('settle_date', $data['settle_date'])
            ->exists();

        return response()->json($data);
    }

    /**
     * Merchant confirms a T+1 settlement statement for a business day.
     * Route: POST /api/merchant/settlements/confirm
     */
    public function confirmMerchant(Request $request)
    {
        $merchant = Merchant::where('user_id', $request->user()->id)->firstOrFail();
        $settleDate = $request->input('settle_date', Carbon::yesterday()->toDateString());

        $ack = MerchantSettlementAck::updateOrCreate(
            ['merchant_id' => $merchant->id, 'settle_date' => $settleDate],
            ['status' => 'acknowledged', 'ack_at' => now(), 'period' => 'T+1']
        );

        return response()->json([
            'message'      => 'Đã xác nhận đối soát.',
            'settle_date'  => $ack->settle_date->toDateString(),
            'status'       => $ack->status,
        ]);
    }

    /**
     * Admin views the per-merchant T+1 payout breakdown.
     * Route: GET /api/admin/settlements/merchants
     */
    public function adminIndex(Request $request)
    {
        return response()->json(
            MerchantSettlementService::perMerchant($request->get('date'))
        );
    }

    /**
     * Admin disburses a T+1 settlement to a single merchant for a business day.
     * Idempotent per (merchant, settle_date). Also writes the merchant's
     * acknowledgement so the merchant sees the statement as confirmed.
     * Route: POST /api/admin/settlements/{merchant}/pay
     */
    public function adminPayout(Request $request, $merchant)
    {
        $merchant = Merchant::findOrFail($merchant);

        $data = $request->validate([
            'settle_date' => ['required', 'date'],
            'amount'      => ['nullable', 'numeric', 'min:0'],
            'method'      => ['sometimes', Rule::in(['bank', 'momo', 'zalopay', 'manual'])],
            'reference'   => ['nullable', 'string', 'max:120'],
            'note'        => ['nullable', 'string', 'max:500'],
        ]);

        // Default the disbursed amount to the computed payable for that day,
        // but allow an explicit admin override (e.g. partial / corrected payout).
        $computed = MerchantSettlementService::forMerchant($merchant, $data['settle_date']);
        $amount = $data['amount'] ?? $computed['payable'];

        $payout = MerchantPayout::updateOrCreate(
            ['merchant_id' => $merchant->id, 'settle_date' => $data['settle_date']],
            [
                'amount'   => $amount,
                'method'   => $data['method'] ?? 'bank',
                'reference'=> $data['reference'] ?? null,
                'note'     => $data['note'] ?? null,
                'status'   => 'paid',
                'admin_id' => $request->user()->id,
                'paid_at'  => now(),
            ]
        );

        // Mirror as a merchant acknowledgement for that business day.
        MerchantSettlementAck::updateOrCreate(
            ['merchant_id' => $merchant->id, 'settle_date' => $data['settle_date']],
            ['status' => 'acknowledged', 'ack_at' => now(), 'period' => 'T+1']
        );

        return response()->json([
            'message'      => 'Đã tạo lệnh chi.',
            'payout'       => $payout,
            'computed'     => $computed['payable'],
        ], 201);
    }

    /**
     * Admin lists merchant payout records (optional merchant / date / status
     * filters). Route: GET /api/admin/settlements/payouts
     */
    public function adminPayouts(Request $request)
    {
        $q = MerchantPayout::with('merchant:id,name')
            ->when($request->get('merchant_id'), fn ($q, $m) => $q->where('merchant_id', $m))
            ->when($request->get('settle_date'), fn ($q, $d) => $q->where('settle_date', $d))
            ->when($request->get('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('paid_at')
            ->get();

        return response()->json($q);
    }
}
