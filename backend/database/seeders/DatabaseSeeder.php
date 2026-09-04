<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\Rider;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CategorySeeder::class);

        // Demo accounts
        // SECURITY (2026-08-01): NO hardcoded password anywhere. The seeded admin
        // password MUST come from ADMIN_SEED_PASSWORD; if unset we generate a
        // random 24-char password and log it (info) so local operators can
        // retrieve it. A staging/dev box (APP_ENV != production) can no longer be
        // logged into with a committed guessable password.
        $adminPassword = env('ADMIN_SEED_PASSWORD', \Illuminate\Support\Str::random(24));
        if (! env('ADMIN_SEED_PASSWORD')) {
            info('[DatabaseSeeder] ADMIN_SEED_PASSWORD not set — generated random admin password: ' . $adminPassword);
        }
        $admin = User::create(['name' => 'Admin', 'phone' => '0900000001', 'role' => 'admin', 'password' => Hash::make($adminPassword)]);
        $customer = User::create(['name' => 'Khách Hàng', 'phone' => '0900000002', 'role' => 'customer']);
        User::create(['name' => 'Merchant Demo', 'phone' => '0900000003', 'role' => 'merchant']);
        User::create(['name' => 'Shipper A', 'phone' => '0900000004', 'role' => 'rider']);

        Rider::create(['user_id' => User::where('phone', '0900000004')->first()->id, 'name' => 'Shipper A', 'phone' => '0900000004', 'vehicle' => 'bike', 'lat' => 21.0295, 'lng' => 105.8522, 'status' => 'online']);
        Rider::create(['name' => 'Shipper B', 'phone' => '0900000005', 'vehicle' => 'bike', 'lat' => 21.0260, 'lng' => 105.8570, 'status' => 'online']);

        $cat = fn (string $zh) => Category::where('name_zh', $zh)->first();

        $this->makeMerchant('GREEN MART', 'Cửa hàng tạp hóa', $cat('生鲜商超'), 21.0278, 105.8532, [
            ['Táo nhập khẩu', '进口苹果', 45000, 60000, '🍎', false],
            ['Sữa tươi', '鲜牛奶', 32000, 38000, '🥛', false],
            ['Nước khoáng', '矿泉水', 12000, 15000, '💧', true, 8000],
        ], User::where('phone', '0900000003')->first()->id);

        $this->makeMerchant('PHỞ 24H', 'Phở & mì', $cat('餐饮外卖'), 21.0289, 105.8551, [
            ['Phở bò', '牛肉粉', 40000, 50000, '🍜', false],
            ['Gà rán', '炸鸡', 55000, 70000, '🍗', true, 39000],
            ['Trà sữa', '奶茶', 30000, 35000, '🧋', false],
        ]);

        $this->makeMerchant('BEAUTY HUB', 'Mỹ phẩm', $cat('美妆个护'), 21.0255, 105.8519, [
            ['Mặt nạ', '面膜', 25000, 30000, '🧖', false],
            ['Dầu gội', '洗发水', 90000, 120000, '🧴', false],
        ]);

        // Order book across the full lifecycle (paid → … → delivered), with
        // items + payments, so the three-terminal prototype is testable.
        $this->call(OrderSeeder::class);
    }

    private function makeMerchant(string $name, string $contact, ?Category $category, float $lat, float $lng, array $products, ?int $userId = null): void
    {
        $merchant = Merchant::create([
            'user_id'        => $userId,
            'name'            => $name,
            'contact_name'    => $contact,
            'phone'           => '0' . rand(900000006, 909999999),
            'address'         => '123 Đường Cầu Giấy, Hà Nội',
            'category_id'     => $category?->id,
            'status'          => 'approved',
            'commission_rate' => 0,
            'delivery_subsidy'=> true,
            'lat'             => $lat,
            'lng'             => $lng,
            'rating'          => 4.8,
            'avg_delivery_min'=> 32,
            'min_order'       => 20000,
            'delivery_fee'    => 15000,
            'is_open'         => true,
            'business_hours'  => '08:00-22:00',
            'monthly_sales'   => rand(800, 4000),
        ]);

        foreach ($products as $p) {
            Product::create([
                'merchant_id'    => $merchant->id,
                'category_id'    => $category?->id,
                'name_vi'        => $p[0],
                'name_zh'        => $p[1],
                'price'          => $p[2],
                'original_price' => $p[3],
                'image'          => $p[4],
                'is_flash'       => $p[5] ?? false,
                'flash_price'    => $p[6] ?? null,
                'flash_stock'    => $p[6] ?? null ? 50 : null,
                'flash_start'    => $p[6] ?? null ? now()->subHour() : null,
                'flash_end'      => $p[6] ?? null ? now()->addHours(3) : null,
                'stock'          => 200,
                'sales'          => rand(50, 800),
                'status'         => 'on',
            ]);
        }
    }
}
