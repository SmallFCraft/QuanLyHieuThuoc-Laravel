<?php

return [
    // Layout
    'area_name' => 'Khu vực dược sĩ',
    'nav_queue' => 'Hàng đợi',
    'nav_home' => 'Về trang chủ',
    'nav_logout' => 'Đăng xuất',
    'signed_in_as' => 'Đang đăng nhập',

    // Queue page
    'queue_title' => 'Hàng đợi sàng lọc',
    'queue_subtitle' => 'Các ca sàng lọc đang chờ dược sĩ xem, ca chờ lâu nhất xếp trước.',
    'metric_pending' => 'Ca đang chờ',
    'metric_longest_wait' => 'Chờ lâu nhất',
    'metric_handled_today' => 'Anh/chị đã xử lý hôm nay',
    'metric_none' => 'Không có',
    'queue_empty' => 'Hiện không có ca nào chờ. Anh/chị có thể quay lại sau.',
    'col_waiting' => 'Thời gian chờ',
    'col_symptom' => 'Triệu chứng chính',
    'col_age' => 'Tuổi',
    'col_flags' => 'Dấu hiệu cảnh báo',
    'col_actions' => 'Thao tác',
    'btn_view' => 'Xem',
    'btn_view_aria' => 'Xem chi tiết ca sàng lọc #:id',
    'age_unknown' => 'Chưa rõ',
    'age_years' => ':age tuổi',
    'no_flags' => 'Không có',
    'no_symptom' => 'Khách chưa mô tả rõ',

    // Detail page
    'detail_title' => 'Ca sàng lọc #:id',
    'detail_subtitle' => 'Xem thông tin khách cung cấp trước khi trả lời.',
    'btn_back_queue' => 'Quay lại hàng đợi',
    'customer_title' => 'Khách hàng',
    'customer_unknown' => 'Không rõ',
    'created_at_label' => 'Thời điểm sàng lọc',
    'waiting_label' => 'Đã chờ',
    'answers_title' => 'Thông tin khách đã cung cấp',
    'answer_raw_symptom_text' => 'Khách tự mô tả',
    'answer_symptoms' => 'Triệu chứng',
    'answer_duration_days' => 'Thời gian bị (số ngày)',
    'answer_medications_taken' => 'Đã dùng thuốc gì',
    'answer_history' => 'Tiền sử, bệnh nền, dị ứng',
    'answer_other_symptoms' => 'Triệu chứng kèm theo',
    'answer_skipped' => 'Câu khách bỏ qua',
    'answer_empty' => 'Không có thông tin',
    'matched_symptoms_title' => 'Triệu chứng hệ thống khớp được',
    'red_flags_title' => 'Dấu hiệu cảnh báo đã bắt',
    'advice_title' => 'Lời khuyên hệ thống đề xuất',
    'safety_net_title' => 'Ngưỡng cần đi khám ngay',
    'system_outcome_title' => 'Kết luận hệ thống',

    // Severity labels for red flags
    'severity_emergency' => 'Cấp cứu',
    'severity_urgent' => 'Cần khám sớm',
    'severity_caution' => 'Cần lưu ý',

    // Health profile
    'profile_title' => 'Hồ sơ sức khoẻ',
    'profile_no_consent' => 'Khách chưa đồng ý chia sẻ hồ sơ sức khoẻ.',
    'profile_allergies' => 'Dị ứng',
    'profile_chronic' => 'Bệnh nền',
    'profile_medications' => 'Thuốc đang dùng',
    'profile_pregnant' => 'Đang mang thai',
    'profile_breastfeeding' => 'Đang cho con bú',
    'profile_weight' => 'Cân nặng (kg)',
    'profile_notes' => 'Ghi chú của khách',
    'profile_yes' => 'Có',
    'profile_no' => 'Không',
    'profile_accessed_note' => 'Mỗi lần mở hồ sơ sức khoẻ đều được ghi vết.',

    // Reply form
    'reply_title' => 'Trả lời khách',
    'reply_hint' => 'Nội dung này hiện trực tiếp cho khách, anh/chị viết rõ ràng và dặn ngưỡng cần đi khám.',
    'reply_label' => 'Nội dung trả lời',
    'reply_placeholder' => 'Ví dụ: Trường hợp của anh/chị có thể dùng...',
    'btn_reply' => 'Gửi câu trả lời',
    'btn_claim' => 'Nhận ca này',
    'claimed_by' => 'Ca này do dược sĩ :name nhận.',
    'claimed_by_you' => 'Anh/chị đang nhận ca này.',

    // AI draft (soạn nháp). Nháp không bao giờ được lưu hay gửi cho khách khi
    // dược sĩ chưa đọc và bấm gửi.
    'btn_ai_draft' => 'Soạn nháp bằng AI',
    'ai_draft_hint' => 'AI soạn sẵn một bản nháp từ dữ liệu ca này. Anh/chị đọc lại, sửa nếu cần rồi mới gửi cho khách.',
    'ai_draft_badge' => 'Bản nháp do AI soạn — anh/chị vui lòng kiểm tra trước khi gửi',
    'ai_draft_success' => 'Đã soạn nháp. Anh/chị đọc lại và sửa trước khi gửi nhé.',
    'ai_draft_failed' => 'Chưa soạn được nháp lúc này. Anh/chị viết tay giúp em nhé.',
    'ai_draft_disabled' => 'Tính năng soạn nháp bằng AI đang tắt.',

    // Escalate form
    'escalate_title' => 'Chuyển tuyến đi khám',
    'escalate_hint' => 'Chọn mục này khi ca vượt phạm vi tư vấn tại nhà thuốc. Hệ thống sẽ bỏ toàn bộ thuốc gợi ý.',
    'escalate_label' => 'Lý do chuyển tuyến',
    'escalate_placeholder' => 'Ví dụ: Triệu chứng kéo dài kèm sụt cân, cần bác sĩ khám trực tiếp.',
    'btn_escalate' => 'Chuyển khách đi khám',

    // Feedback
    'reply_success' => 'Đã gửi câu trả lời cho khách.',
    'escalate_success' => 'Đã chuyển ca này sang hướng đi khám bác sĩ.',
    'claim_success' => 'Anh/chị đã nhận ca này.',
    'error_already_reviewed' => 'Ca này đã được xử lý, không thể ghi đè.',
    'error_claimed_by_other' => 'Ca này đã có dược sĩ khác nhận.',
    'note_required' => 'Anh/chị vui lòng nhập nội dung trả lời.',
    'note_max' => 'Nội dung không được vượt quá 2000 ký tự.',
];
