<?php

return [
    // Section chrome
    'nav' => 'Hồ sơ sức khoẻ',
    'mobile_nav' => 'Sức khoẻ',
    'title' => 'Hồ sơ sức khoẻ',
    'desc' => 'Khai dị ứng, bệnh nền và thuốc đang dùng để hệ thống cảnh báo chống chỉ định và tương tác thuốc khi anh/chị mua hàng.',
    'encrypted_badge' => 'Đã mã hoá',

    // Consent
    'consent_label' => 'Tôi đồng ý cho hệ thống dùng thông tin sức khoẻ này để cảnh báo chống chỉ định và tương tác thuốc. Thông tin được mã hoá. Tôi có thể xoá bất kỳ lúc nào.',
    'consent_title' => 'Đồng ý sử dụng thông tin sức khoẻ',
    'no_consent_title' => 'Chưa bật cảnh báo an toàn thuốc',
    'no_consent_body' => 'Hệ thống sẽ KHÔNG dùng thông tin trong hồ sơ này để cảnh báo chống chỉ định hay tương tác thuốc cho tới khi anh/chị tích ô đồng ý bên dưới và lưu lại.',
    'consent_active_title' => 'Cảnh báo an toàn thuốc đang bật',
    'consent_active_body' => 'Hệ thống đang dùng hồ sơ này để kiểm tra khi anh/chị thêm thuốc vào giỏ. Đồng ý lúc: :time',

    // AI Consent
    'ai_consent_title' => 'Đồng ý sử dụng thông tin cho trợ lý AI',
    'ai_consent_label' => 'Tôi đồng ý cho hệ thống dùng thông tin sức khoẻ này để hỗ trợ trí tuệ nhân tạo (AI) soạn gợi ý tư vấn cho dược sĩ. Thông tin được mã hoá.',
    'ai_consent_hint' => 'Tuỳ chọn. Không tích ô này thì trợ lý AI sẽ không đọc hồ sơ sức khoẻ; hệ thống vẫn cảnh báo an toàn thuốc bình thường nếu ô phía trên đã bật.',

    // Tag inputs
    'allergies_label' => 'Dị ứng thuốc hoặc thành phần',
    'allergies_hint' => 'Gõ tên rồi nhấn Enter hoặc dấu phẩy để thêm. Mỗi mục là một chất riêng.',
    'allergies_input_label' => 'Thêm một chất gây dị ứng',
    'chronic_label' => 'Bệnh nền',
    'chronic_hint' => 'Gõ tên bệnh rồi nhấn Enter hoặc dấu phẩy để thêm.',
    'chronic_input_label' => 'Thêm một bệnh nền',
    'medications_label' => 'Thuốc đang dùng',
    'medications_hint' => 'Ghi tên thuốc hoặc hoạt chất đang dùng thường xuyên, mỗi loại một mục.',
    'medications_input_label' => 'Thêm một thuốc đang dùng',
    'tag_remove' => 'Xoá mục :item',
    'suggestions_label' => 'Gợi ý nhanh',
    'max_items_reached' => 'Đã đạt tối đa 30 mục.',

    // Other fields
    'is_pregnant' => 'Đang mang thai',
    'is_pregnant_hint' => 'Một số thuốc chống chỉ định khi mang thai.',
    'is_breastfeeding' => 'Đang cho con bú',
    'is_breastfeeding_hint' => 'Một số thuốc đi vào sữa mẹ.',
    'weight_kg' => 'Cân nặng (kg)',
    'weight_kg_hint' => 'Dùng để kiểm tra liều dùng theo cân nặng. Không bắt buộc.',
    'notes' => 'Ghi chú thêm',
    'notes_hint' => 'Ví dụ: đã từng phản ứng nặng với thuốc nào, hoặc lưu ý cần dược sĩ biết.',

    // Actions
    'save' => 'Lưu hồ sơ sức khoẻ',
    'update_success' => 'Đã lưu hồ sơ sức khoẻ.',
    'delete' => 'Xoá hồ sơ sức khoẻ',
    'delete_title' => 'Xoá hồ sơ sức khoẻ?',
    'delete_body' => 'Toàn bộ dị ứng, bệnh nền, thuốc đang dùng và ghi chú sẽ bị xoá vĩnh viễn. Hệ thống sẽ ngừng cảnh báo chống chỉ định dựa trên thông tin cá nhân. Anh/chị có thể khai lại bất kỳ lúc nào.',
    'delete_confirm' => 'Xoá vĩnh viễn',
    'delete_cancel' => 'Giữ lại',
    'delete_success' => 'Đã xoá hồ sơ sức khoẻ.',
    'delete_open' => 'Mở hộp thoại xác nhận xoá hồ sơ sức khoẻ',

    // Privacy note
    'privacy_title' => 'Thông tin này được bảo vệ thế nào',
    'privacy_body' => 'Dữ liệu sức khoẻ được mã hoá trong cơ sở dữ liệu và không bao giờ được ghi vào lịch sử hệ thống dưới dạng thô. Chỉ anh/chị và quy trình kiểm tra an toàn thuốc đọc được.',
];
