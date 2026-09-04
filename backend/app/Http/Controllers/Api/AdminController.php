<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    /**
     * One-to-one帮扶 review: approve a pending merchant.
     */
    public function approveMerchant(Request $request, Merchant $merchant)
    {
        if ($merchant->status !== 'pending') {
            return response()->json(['message' => 'Chỉ duyệt merchant chờ xét.'], 422);
        }
        $merchant->update([
            'status'           => 'approved',
            'commission_rate'  => 0,           // guarantee 0 commission
            'delivery_subsidy' => true,        // guarantee free-delivery subsidy
        ]);
        return response()->json([
            'ok'     => true,
            'status' => $merchant->status,
            'note'   => '0% hoa hồng, miễn phí giao hàng (nền tảng hỗ trợ).',
        ]);
    }

    public function rejectMerchant(Request $request, Merchant $merchant)
    {
        $reason = $request->input('reason', 'Không đủ điều kiện.');
        $merchant->update(['status' => 'rejected', 'reject_reason' => $reason]);
        return response()->json(['ok' => true, 'status' => $merchant->status]);
    }

    /**
     * Finance / reconciliation summary (0% commission + platform subsidy model).
     */
    public function settlement(Request $request)
    {
        return response()->json(app(\App\Services\SettlementService::class)->summary());
    }

    public function payouts(Request $request)
    {
        return response()->json(app(\App\Services\SettlementService::class)->merchantPayouts());
    }

    public function orders(Request $request)
    {
        $status = $request->query('status');
        $query = \App\Models\Order::with('merchant', 'user', 'rider')->latest();
        if ($status) {
            $query->where('status', $status);
        }
        return \App\Http\Resources\OrderResource::collection($query->paginate(30));
    }

    /**
     * Aggregated dashboard KPIs for the L console.
     * GET /api/admin/dashboard
     * Frontend (app/admin.html) consumes the DASH contract:
     *   orders, ordersTrend, gmv, gmvTrend, merchants, merchantsNew,
     *   riders, ridersNew, pending, pendingNew, subsidy, commission, gmv7, chart[]
     */
    public function dashboard(Request $request)
    {
        $today = today();
        $yest  = today()->subDay();

        $todayOrders = \App\Models\Order::whereDate('created_at', $today)->count();
        $yestOrders  = \App\Models\Order::whereDate('created_at', $yest)->count();
        $todayGmv    = (float) \App\Models\Order::whereDate('created_at', $today)->sum('amount');
        $yestGmv    = (float) \App\Models\Order::whereDate('created_at', $yest)->sum('amount');

        $ordersTrend = $yestOrders ? round(($todayOrders - $yestOrders) / $yestOrders * 100) : ($todayOrders ? 100 : 0);
        $gmvTrend    = $yestGmv   ? round(($todayGmv   - $yestGmv)   / $yestGmv   * 100) : ($todayGmv   ? 100 : 0);

        $merchants    = \App\Models\Merchant::count();
        $merchantsNew = \App\Models\Merchant::whereDate('created_at', $today)->count();
        $riders       = \App\Models\Rider::count();
        $ridersNew    = \App\Models\Rider::whereDate('created_at', $today)->count();
        $pending      = \App\Models\Merchant::where('status', 'pending')->count();
        $pendingNew   = \App\Models\Merchant::where('status', 'pending')->whereDate('created_at', $today)->count();

        $chart = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = today()->subDays($i);
            $chart[] = [
                'd' => $d->format('m-d'),
                'v' => \App\Models\Order::whereDate('created_at', $d)->count(),
            ];
        }

        $gmv7 = (float) \App\Models\Order::where('created_at', '>=', today()->subDays(6)->startOfDay())->sum('amount');

        return response()->json([
            'orders'       => $todayOrders,
            'ordersTrend'  => $ordersTrend,
            'gmv'          => $todayGmv,
            'gmvTrend'     => $gmvTrend,
            'merchants'    => $merchants,
            'merchantsNew' => $merchantsNew,
            'riders'       => $riders,
            'ridersNew'    => $ridersNew,
            'pending'      => $pending,
            'pendingNew'   => $pendingNew,
            'subsidy'      => (float) \App\Models\Order::sum('platform_subsidy'),
            'commission'   => (float) \App\Models\Order::sum('commission'),
            'gmv7'         => $gmv7,
            'chart'        => $chart,
        ]);
    }

    /**
     * Merchant list for the L console (supports status / kyc / search filters).
     * GET /api/admin/merchants?status=pending&kyc=pending&q=foo
     * Returns a paginator; frontend reads mr.data (array).
     */
    public function merchants(Request $request)
    {
        $query = \App\Models\Merchant::with('category')->latest();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($kyc = $request->query('kyc')) {
            $query->where('kyc_status', $kyc);
        }
        if ($q = $request->query('q')) {
            $query->where(function ($qb) use ($q) {
                $qb->where('name', 'like', "%{$q}%")
                   ->orWhere('phone', 'like', "%{$q}%")
                   ->orWhere('contact_name', 'like', "%{$q}%");
            });
        }

        return $query->paginate(30);
    }

    /**
     * KYC verdicts for merchant business-license / bank-account verification.
     */
    public function approveKyc(Request $request, Merchant $merchant)
    {
        $merchant->update(['kyc_status' => 'approved', 'kyc_reject_reason' => null]);
        return response()->json(['ok' => true, 'kyc_status' => $merchant->kyc_status]);
    }

    public function rejectKyc(Request $request, Merchant $merchant)
    {
        $reason = $request->input('reason', 'Không đủ điều kiện KYC.');
        $merchant->update(['kyc_status' => 'rejected', 'kyc_reject_reason' => $reason]);
        return response()->json(['ok' => true, 'kyc_status' => $merchant->kyc_status]);
    }
}
