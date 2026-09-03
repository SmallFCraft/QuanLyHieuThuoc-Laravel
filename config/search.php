<?php

return [
    /*
    |--------------------------------------------------------------------------
    | TÌM KIẾM — XU HƯỚNG & PLACEHOLDER
    |--------------------------------------------------------------------------
    */

    'trending' => [
        // Số pill hiện ở panel. 8 vừa hai hàng trên điện thoại.
        'limit' => 8,

        // Chỉ tính từ khoá được tìm trong bao nhiêu ngày gần đây.
        'window_days' => 30,

        // Từ khoá tối thiểu phải được tìm bao nhiêu lần mới lên panel. 2 để một
        // lượt tìm lẻ của chính admin không thành "xu hướng".
        'min_hits' => 2,

        // Bù khi log còn ít: shop mới mở thì bảng search_queries rỗng, mà một panel
        // trống trông như tính năng lỗi. Thật xếp trước, mồi bù vào sau.
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
    | Chuỗi placeholder gõ từng chữ, hai bộ theo trạng thái header.
    |
    | Ngưỡng là chiều rộng chứ không phải số ký tự: ô tìm kiếm ở 375px chỉ còn 122px
    | lòng trong khi header đầy đủ (194px trừ 24px padding trái và 48px chỗ icon), và
    | ~212px sau khi logo nhường chỗ. Dài hơn là bị text-overflow cắt mất chữ, nên
    | resources/js/search-placeholder.js chọn bộ theo data-header-scrolled.
    */
    'placeholder_rotation' => [
        'Tên thuốc...',
        'Triệu chứng...',
        'Thành phần...',
        'Danh mục...',
    ],

    // Đo được ở 375px sau khi cuộn: chuỗi dài nhất dưới đây là 171px, vừa 212px.
    'placeholder_rotation_wide' => [
        'Tìm theo tên thuốc...',
        'Tìm theo triệu chứng...',
        'Tìm theo thành phần...',
        'Tìm theo danh mục...',
    ],

    /*
    |--------------------------------------------------------------------------
    | TÌM KIẾM BẰNG GIỌNG NÓI
    |--------------------------------------------------------------------------
    |
    | Nhận diện chạy trong browser (Web Speech API) — server chỉ nhận text. Đo
    | 2026-09-01: cả hai AI provider của repo đều không có STT tiếng Việt dùng
    | được, xem docs/superpowers/specs/2026-09-01-voice-search-design.md §1.1.
    */
    'voice' => [
        // Tắt thì nút mic không render và route trả 404.
        'enabled' => env('VOICE_SEARCH_ENABLED', true),

        // Tầng AI tắt riêng được: hai tầng đầu vẫn chạy, câu lạ về nguyên văn.
        'ai' => env('VOICE_SEARCH_AI', true),

        // Thấp hơn AI_TIMEOUT nhiều: lời gọi này nằm trong một request đồng bộ
        // mà khách đang chờ với ô tìm kiếm đang mở, nên phải fail fast.
        'ai_timeout' => env('VOICE_SEARCH_AI_TIMEOUT', 8),

        'cache_ttl' => env('VOICE_SEARCH_CACHE_TTL', 86400),

        // Đủ số lỗi này trong cửa sổ thì bỏ tầng AI trong đúng khoảng đó.
        'breaker_failures' => 5,
        'breaker_window' => 300,

        // Token phải xuất hiện đủ số lần trong tên sản phẩm mới vào index. Đo
        // trên catalog hiện tại: 3.998 token khác nhau, 1.489 token đạt ngưỡng 3.
        // Token 1-2 lần phần lớn là mã lô hoặc số đo bao bì.
        'phonetic_min_hits' => 3,

        // Cửa sổ dài nhất xét tới. 3 là quá hẹp cho ca dùng chính: "pa ra xê ta môn"
        // (paracetamol) là 5 âm tiết, và đo 2026-09-02 cho thấy dòng `Paracetamol` CÓ
        // trong index, within() nhận nó, nhưng không cửa sổ nào chứa nổi cả cụm.
        // Nhóm syllable-split của corpus: 2/7 khớp ở 3 từ, 4/7 ở 5 từ.
        'max_window_words' => 5,

        // Số cửa sổ tối đa lấy từ mỗi alternative. Phải nới cùng max_window_words:
        // vòng lặp đi dài-trước nên với cap 6 nó tiêu hết ngân sách ngay ở tầng dài
        // nhất và các cụm ngắn không bao giờ được sinh. Đo trên corpus 94 ca với cổng
        // đồng-xuất-hiện: cap 6 cho 55/94 và 466 truy vấn, cap 14 cho 56/94 và 928.
        // lookup() vẫn là MỘT truy vấn, phần tăng là nameHasWord() theo từ.
        'max_windows' => 14,
    ],
];
