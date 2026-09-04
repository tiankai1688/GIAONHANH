<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMerchantRequest;
use App\Http\Resources\MerchantResource;
use App\Http\Resources\ProductResource;
use App\Models\Merchant;
use App\Models\Product;
use App\Services\PaymentSplitService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MerchantController extends Controller
{
    /**
     * List approved & open merchants, with optional category / search / distance.
     */
    public function index(Request $request)
    {
        $q   = $request->query('q');
        $cat = $request->query('category_id');
        $lat = $request->query('lat');
        $lng = $request->query('lng');

        $merchants = Merchant::approved()
            ->with('category')
            ->when($cat, function ($query) use ($cat) {
                // match the top-level category OR any of its children
                $childIds = \App\Models\Category::where('parent_id', $cat)->pluck('id')->push($cat);
                $query->whereIn('category_id', $childIds);
            })
            ->when($q, function ($query) use ($q) {
                $query->where(fn ($qb) => $qb
                    ->where('name', 'like', "%{$q}%")
                    ->orWhere('address', 'like', "%{$q}%"));
            })
            ->get();

        if ($lat !== null && $lng !== null) {
            $merchants->each(function (Merchant $m) use ($lat, $lng) {
                if ($m->lat && $m->lng) {
                    $m->distance_km = round(PaymentSplitService::distance((float)$lat, (float)$lng, (float)$m->lat, (float)$m->lng), 2);
                }
            });
            $merchants = $merchants->sortBy('distance_km')->values();
        }

        return MerchantResource::collection($merchants);
    }

    public function show(Merchant $merchant)
    {
        $merchant->load('category');
        return new MerchantResource($merchant);
    }

    public function products(Merchant $merchant, Request $request)
    {
        $sub = $request->query('subcategory_id');
        $products = $merchant->products()
            ->onSale()
            ->when($sub, fn ($q) => $q->where('category_id', $sub))
            ->orderBy('sort')
            ->get();

        return ProductResource::collection($products);
    }

    /**
     * Flash-sale products across nearby merchants.
     */
    public function flashSales(Request $request)
    {
        $lat = $request->query('lat');
        $lng = $request->query('lng');

        $items = Product::flash()
            ->with('merchant')
            ->inRandomOrder()
            ->limit(20)
            ->get();

        return $items->map(function (Product $p) use ($lat, $lng) {
            return [
                'id'          => $p->id,
                'name_vi'     => $p->name_vi,
                'name_zh'     => $p->name_zh,
                'image'       => $p->image,
                'price'       => (float) $p->price,
                'flash_price' => (float) $p->flash_price,
                'flash_stock' => $p->flash_stock,
                'merchant'    => $p->merchant ? ['id' => $p->merchant->id, 'name' => $p->merchant->name] : null,
            ];
        });
    }

    /**
     * Merchant onboarding -> status pending (one-to-one帮扶 review).
     */
    public function onboard(StoreMerchantRequest $request)
    {
        $user = $request->user();
        if ($user && $user->merchant) {
            return response()->json(['message' => 'Bạn đã có hồ sơ merchant.'], 409);
        }

        $merchant = Merchant::create([
            'user_id'      => $user?->id,
            'category_id'  => $request->input('category_id'),
            'name'         => $request->input('name'),
            'contact_name' => $request->input('contact_name'),
            'phone'        => $request->input('phone'),
            'email'        => $request->input('email'),
            'address'      => $request->input('address'),
            'lat'          => $request->input('lat'),
            'lng'          => $request->input('lng'),
            'business_hours' => $request->input('business_hours'),
            'status'       => 'pending',
            'commission_rate' => 0,
            'delivery_subsidy' => true,
        ]);

        return response()->json([
            'message' => 'Đã nhận hồ sơ. Chuyên viên sẽ liên hệ 1-1 trong 24h.',
            'merchant_id' => $merchant->id,
            'status' => $merchant->status,
        ], 201);
    }

    public function profile(Request $request)
    {
        $merchant = $request->user()->merchant;
        if (! $merchant) {
            return response()->json(['message' => 'Chưa có hồ sơ merchant.'], 404);
        }
        $merchant->load('category');
        return new MerchantResource($merchant);
    }

    public function orders(Request $request)
    {
        $merchant = $request->user()->merchant;
        $orders = $merchant->orders()->with('items', 'user')->latest()->paginate(20);
        return \App\Http\Resources\OrderResource::collection($orders);
    }

    public function acceptOrder(Request $request, \App\Models\Order $order)
    {
        $merchant = $request->user()->merchant;
        if ($order->merchant_id !== $merchant->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ($order->status !== 'paid') {
            return response()->json(['message' => 'Đơn chưa thanh toán.'], 422);
        }
        $order->update(['status' => 'accepted', 'accepted_at' => now()]);
        return new \App\Http\Resources\OrderResource($order->load('items', 'user', 'merchant'));
    }

    public function readyOrder(Request $request, \App\Models\Order $order)
    {
        $merchant = $request->user()->merchant;
        if ($order->merchant_id !== $merchant->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if ($order->status !== 'accepted') {
            return response()->json(['message' => 'Đơn chưa được xác nhận.'], 422);
        }
        // Grab model: clear any rider so the order becomes available for nearby
        // riders to claim, then broadcast it on the public orders.grab channel.
        $order->update(['status' => 'picked', 'picked_at' => now(), 'rider_id' => null]);
        event(new \App\Events\OrderReadyForGrab($order));
        return new \App\Http\Resources\OrderResource($order->load('items', 'user', 'merchant'));
    }

    /**
     * All products of the logged-in merchant (incl. off-shelf), for management.
     */
    public function myProducts(Request $request)
    {
        $merchant = $request->user()->merchant;
        if (! $merchant) {
            return response()->json(['message' => 'Chưa có hồ sơ merchant.'], 404);
        }
        return ProductResource::collection(
            $merchant->products()->orderBy('sort')->get()
        );
    }

    /**
     * Update a product's price / on-shelf status / stock.
     */
    public function updateProduct(Request $request, Product $product)
    {
        $merchant = $request->user()->merchant;
        if (! $merchant || $product->merchant_id !== $merchant->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $data = $request->validate([
            'price'  => ['sometimes', 'numeric', 'min:0'],
            'status' => ['sometimes', Rule::in(['on', 'off'])],
            'stock'  => ['sometimes', 'integer', 'min:0'],
        ]);
        $product->update($data);
        return new ProductResource($product);
    }

    /**
     * Create a new product for the logged-in merchant.
     * Closes the M-Web "新增商品" gap (previously demo-only fallback).
     */
    public function storeProduct(Request $request)
    {
        $merchant = $request->user()->merchant;
        if (! $merchant) {
            return response()->json(['message' => 'Chưa có hồ sơ merchant.'], 404);
        }
        $data = $request->validate([
            'name_vi'        => ['required', 'string', 'max:120'],
            'name_zh'        => ['required', 'string', 'max:120'],
            'category_id'    => ['nullable', 'exists:categories,id'],
            'description'    => ['nullable', 'string', 'max:1000'],
            'price'          => ['required', 'numeric', 'min:0'],
            'original_price' => ['nullable', 'numeric', 'min:0'],
            'image'          => ['nullable', 'string', 'max:512'],
            'stock'          => ['nullable', 'integer', 'min:0'],
            'status'         => ['sometimes', Rule::in(['on', 'off'])],
        ]);
        $data['merchant_id'] = $merchant->id;
        $data['status']      = $data['status'] ?? 'on';

        $product = Product::create($data);
        return new ProductResource($product);
    }

    /**
     * Toggle shop open/closed, delivery subsidy, business hours.
     */
    public function updateProfile(Request $request)
    {
        $merchant = $request->user()->merchant;
        if (! $merchant) {
            return response()->json(['message' => 'Chưa có hồ sơ merchant.'], 404);
        }
        $data = $request->validate([
            'is_open'          => ['sometimes', 'boolean'],
            'delivery_subsidy' => ['sometimes', 'boolean'],
            'business_hours'  => ['sometimes', 'string', 'max:60'],
            'business_license' => ['sometimes', 'string', 'max:255'],
            'bank_account'     => ['sometimes', 'string', 'max:255'],
        ]);
        $merchant->update($data);
        $merchant->load('category');
        return new MerchantResource($merchant);
    }
}
