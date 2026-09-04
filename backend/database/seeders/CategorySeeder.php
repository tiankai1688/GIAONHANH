<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * The 11 recruitment categories from the GIAONHANH招商 spec.
     */
    private array $catalog = [
        ['生鲜商超', 'Tạp hóa tươi sống', '🛒', 'goods', [
            '蔬菜水果', 'Rau củ quả', '🥬',
            '肉禽水产', 'Thịt hải sản', '🥩',
            '蛋奶粮油', 'Trứng sữa gạo', '🥚',
            '休闲零食', 'Đồ ăn vặt', '🍪',
            '酒水饮料', 'Nước giải khát', '🥤',
            '母婴奶粉', 'Sữa bột', '🍼',
        ]],
        ['美妆个护', 'Mỹ phẩm & cá nhân', '💄', 'goods', [
            '面部护理', 'Chăm sóc da', '🧴',
            '身体护理', 'Chăm sóc body', '🧼',
            '美发护发', 'Tóc', '💇',
            '口腔护理', 'Răng miệng', '🦷',
        ]],
        ['3C数码&家电', 'Điện tử & gia dụng', '📱', 'goods', [
            '手机配件', 'Phụ kiện điện thoại', '🔌',
            '电脑办公', 'Máy tính văn phòng', '💻',
            '大家电', 'Đồ gia dụng lớn', '🧊',
            '小家电', 'Đồ gia dụng nhỏ', '☕',
        ]],
        ['家居日用', 'Nhà cửa & dụng cụ', '🏠', 'goods', [
            '床上用品', 'Đồ giường', '🛏️',
            '厨房用品', 'Nhà bếp', '🍳',
            '清洁洗护', 'Vệ sinh', '🧽',
            '家居装饰', 'Trang trí', '🪴',
        ]],
        ['时尚服饰', 'Thời trang', '👗', 'goods', [
            '女装', 'Nữ', '👚',
            '男装', 'Nam', '👔',
            '童装', 'Trẻ em', '🧒',
            '鞋包配饰', 'Giày túi', '👜',
        ]],
        ['母婴用品', 'Mẹ & bé', '🍼', 'goods', [
            '婴儿洗护', 'Chăm sóc bé', '🧴',
            '纸尿裤湿巾', 'Tã ướt', '🧷',
            '推车床品', 'Xe đẩy', '🛞',
            '玩具', 'Đồ chơi', '🧸',
        ]],
        ['运动户外汽车', 'Thể thao & xe', '⚽', 'goods', [
            '运动装备', 'Dụng cụ thể thao', '🏀',
            '户外用品', 'Ngoài trời', '🏕️',
            '汽车用品', 'Phụ kiện ô tô', '🚗',
        ]],
        ['宠物用品', 'Thú cưng', '🐾', 'goods', [
            '主粮零食', 'Thức ăn', '🦴',
            '日用窝具', 'Chuồng nệm', '🛏️',
        ]],
        ['休闲娱乐', 'Giải trí', '🎤', 'service', [
            'KTV', 'Karaoke', '🎶',
            '按摩SPA', 'Massage SPA', '💆',
            '酒店住宿', 'Khách sạn', '🏨',
            '旅游出行', 'Du lịch', '✈️',
            '私人影院', 'Rạp phim', '🎬',
        ]],
        ['餐饮外卖', 'Đồ ăn', '🍔', 'service', [
            '甜品饮品', 'Trà & tráng miệng', '🧋',
            '盖饭简餐', 'Cơm hộp', '🍱',
            '西式快餐', 'Fast food', '🍟',
            '烧烤炸串', 'Nướng', '🍢',
            '米粉面食', 'Phở mì', '🍜',
        ]],
        ['出行服务', 'Di chuyển', '🚕', 'service', [
            '网约车打车', 'Gọi xe', '🚖',
            '专业代驾', 'Lái hộ', '🚘',
        ]],
    ];

    public function run(): void
    {
        foreach ($this->catalog as $i => $cat) {
            [$zh, $vi, $icon, $type, $children] = $cat;
            $parent = Category::create([
                'name_vi' => $vi,
                'name_zh' => $zh,
                'icon'    => $icon,
                'type'    => $type,
                'sort'    => $i,
            ]);
            for ($j = 0; $j < count($children); $j += 3) {
                Category::create([
                    'parent_id' => $parent->id,
                    'name_vi'   => $children[$j + 1],
                    'name_zh'   => $children[$j],
                    'icon'      => $children[$j + 2],
                    'type'      => $type,
                    'sort'      => $j,
                ]);
            }
        }
    }
}
