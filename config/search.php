<?php

return [
    /*
    |--------------------------------------------------------------------------
    | TIM KIEM — XU HUONG & PLACEHOLDER
    |--------------------------------------------------------------------------
    */

    'trending' => [
        // So pill hien o panel. 8 vua hai hang tren dien thoai.
        'limit' => 8,

        // Chi tinh tu khoa duoc tim trong bao nhieu ngay gan day.
        'window_days' => 30,

        // Tu khoa toi thieu phai duoc tim bao nhieu lan moi len panel. 2 de mot
        // luot tim le cua chinh admin khong thanh "xu huong".
        'min_hits' => 2,

        // Bu khi log con it: shop moi mo thi bang search_queries rong, ma mot panel
        // trong trong nhu tinh nang loi. That xep truoc, moi bu vao sau.
        'seed' => [
            'vitamin c',
            'tăng đề kháng',
            'khẩu trang',
            'men vi sinh',
            'omega 3',
            'canxi',
            'dầu gió',
            'kem chống nắng',
        ],
    ],

    /*
    | Chuoi placeholder go tung chu, hai bo theo trang thai header.
    |
    | Nguong la chieu rong chu khong phai so ky tu: o tim kiem o 375px chi con 122px
    | long trong khi header day du (194px tru 24px padding trai va 48px cho icon), va
    | ~212px sau khi logo nhuong cho. Dai hon la bi text-overflow cat mat chu, nen
    | resources/js/search-placeholder.js chon bo theo data-header-scrolled.
    */
    'placeholder_rotation' => [
        'Tên thuốc...',
        'Triệu chứng...',
        'Thành phần...',
        'Danh mục...',
    ],

    // Do duoc o 375px sau khi cuon: chuoi dai nhat duoi day la 171px, vua 212px.
    'placeholder_rotation_wide' => [
        'Tìm theo tên thuốc...',
        'Tìm theo triệu chứng...',
        'Tìm theo thành phần...',
        'Tìm theo danh mục...',
    ],

    /*
    |--------------------------------------------------------------------------
    | TIM KIEM BANG GIONG NOI
    |--------------------------------------------------------------------------
    |
    | Nhan dien chay trong browser (Web Speech API) — server chi nhan text. Do
    | 2026-09-01: ca hai AI provider cua repo deu khong co STT tieng Viet dung
    | duoc, nen moi truy van dieu khien bang giong noi chi gui van ban len.
    */
    'voice' => [
        // Tat thi nut mic khong render va route tra 404.
        'enabled' => env('VOICE_SEARCH_ENABLED', true),

        // Tang AI tat rieng duoc: hai tang dau van chay, cau la ve nguyen van.
        'ai' => env('VOICE_SEARCH_AI', true),

        // Thap hon AI_TIMEOUT nhieu: loi goi nay nam trong mot request dong bo
        // ma khach dang cho voi o tim kiem dang mo, nen phai fail fast.
        'ai_timeout' => env('VOICE_SEARCH_AI_TIMEOUT', 8),

        'cache_ttl' => env('VOICE_SEARCH_CACHE_TTL', 86400),

        // Du so loi nay trong cua so thi bo tang AI trong dung khoang do.
        'breaker_failures' => 5,
        'breaker_window' => 300,

        // Token phai xuat hien du so lan trong ten san pham moi vao index. Do
        // tren catalog hien tai: 3.998 token khac nhau, 1.489 token dat nguong 3.
        // Token 1-2 lan phan lon la ma lo hoac so do bao bi.
        'phonetic_min_hits' => 3,

        // So tu T0 chiu kiem. Moi tu la mot truy van 4 nhanh LIKE, hai nhanh trong do
        // khong dung duoc index name_folded. Do 2026-09-02: 3 alternative x 50 tu
        // THAT trong catalog ton 100 truy van / 284ms. Mot truy van tim kiem that
        // khong dai toi 12 tu; dai hon thi de T1 va AI xu ly.
        't0_max_words' => 12,

        // Cua so dai nhat xet toi. 3 la qua hep cho ca dung chinh: "pa ra xe ta mon"
        // (paracetamol) la 5 am tiet, va do 2026-09-02 cho thay dong `Paracetamol` CO
        // trong index, within() nhan no, nhung khong cua so nao chua noi ca cum.
        // Nhom syllable-split cua corpus: 2/7 khop o 3 tu, 4/7 o 5 tu.
        'max_window_words' => 5,

        // So cua so toi da lay tu moi alternative. Phai noi cung max_window_words:
        // vong lap di dai-truoc nen voi cap 6 no tieu het ngan sach ngay o tang dai
        // nhat va cac cum ngan khong bao gio duoc sinh. Do tren corpus 94 ca voi cong
        // dong-xuat-hien: cap 6 cho 55/94 va 466 truy van, cap 14 cho 56/94 va 928.
        // lookup() van la MOT truy van, phan tang la nameHasWord() theo tu.
        'max_windows' => 14,
    ],
];
