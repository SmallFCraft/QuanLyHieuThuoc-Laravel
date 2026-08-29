<?php

return [
    // Trang danh sách nhắc thuốc
    'page_title' => 'Nhắc uống thuốc',
    'page_subtitle' => 'Các lịch nhắc uống thuốc đang hoạt động của anh/chị.',
    'empty' => 'Anh/chị chưa có lịch nhắc uống thuốc nào.',
    'col_product' => 'Thuốc',
    'col_dosage' => 'Cách dùng',
    'col_times' => 'Số lần/ngày',
    'col_next' => 'Lần nhắc tiếp theo',
    'col_action' => 'Thao tác',
    'times_per_day' => ':count lần/ngày',
    'duration' => 'Trong :days ngày',
    'no_next' => 'Chưa hẹn',
    'btn_cancel' => 'Huỷ nhắc',
    'btn_cancel_aria' => 'Huỷ nhắc uống thuốc :name',
    'cancelled' => 'Đã huỷ lịch nhắc uống thuốc.',
    'not_found' => 'Không tìm thấy lịch nhắc này.',
    'disclaimer' => 'Nhắc uống thuốc chỉ để hỗ trợ ghi nhớ, không thay thế chỉ định của bác sĩ hoặc dược sĩ.',

    // Email nhắc uống thuốc
    'mail_subject' => 'Nhắc uống thuốc: :name',
    'mail_greeting' => 'Chào anh/chị :name,',
    'mail_lead' => 'Đã đến giờ uống thuốc theo lịch anh/chị đã đặt.',
    'mail_dosage_label' => 'Cách dùng',
    'mail_times_label' => 'Số lần mỗi ngày',
    'mail_cancel_lead' => 'Nếu anh/chị không muốn nhận nhắc thuốc này nữa:',
    'mail_cancel_link' => 'Quản lý nhắc thuốc',
    'mail_footer' => 'Email này được gửi tự động từ Medicare Pharmacy. Vui lòng dùng thuốc đúng theo hướng dẫn.',

    // Email theo dõi sau tư vấn (safety-netting)
    'follow_up_subject' => 'Medicare Pharmacy hỏi thăm: triệu chứng của anh/chị đỡ chưa?',
    'follow_up_greeting' => 'Chào anh/chị :name,',
    'follow_up_question' => 'Đã 3 ngày kể từ lần sàng lọc triệu chứng của anh/chị. Triệu chứng đã đỡ hơn chưa ạ?',
    'follow_up_advice_label' => 'Hướng dẫn em đã gửi anh/chị lần trước',
    'follow_up_btn_improved' => 'Đã đỡ',
    'follow_up_btn_not_improved' => 'Chưa đỡ',
    'follow_up_footer' => 'Anh/chị chỉ cần bấm một trong hai lựa chọn ở trên. Nếu triệu chứng nặng lên, hãy đến cơ sở y tế ngay.',

    // Phản hồi khi khách trả lời
    'response_improved' => 'Em rất mừng khi anh/chị đã đỡ hơn. Anh/chị dùng đủ liệu trình theo hướng dẫn rồi hãy dừng, đừng bỏ giữa dở để tránh tái lại.',
    'response_not_improved' => 'Triệu chứng kéo dài quá 3 ngày mà không đỡ là dấu hiệu cần bác sĩ đánh giá trực tiếp. Em khuyên anh/chị đi khám tại cơ sở y tế để được xác định nguyên nhân. Em không gợi ý thêm thuốc cho tình huống này.',
    'response_already_answered' => 'Em đã ghi nhận phản hồi này trước đó rồi ạ.',
    'response_forbidden' => 'Anh/chị không có quyền trả lời ca sàng lọc này.',
    'response_confirm_title' => 'Xác nhận phản hồi theo dõi',
    'response_confirm_improved' => 'Anh/chị xác nhận triệu chứng đã đỡ hơn?',
    'response_confirm_not_improved' => 'Anh/chị xác nhận triệu chứng chưa đỡ?',
    'response_confirm_button' => 'Xác nhận',
    'response_confirm_hint' => 'Em chỉ ghi nhận sau khi anh/chị bấm xác nhận, để tránh phần mềm đọc email tự bấm giúp.',
];
