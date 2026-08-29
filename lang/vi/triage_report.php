<?php

return [
    'title' => 'Báo cáo sàng lọc y tế',
    'subtitle' => 'Tra soát các ca sàng lọc, phân bố kết quả và dấu hiệu nguy hiểm',
    'detail_title' => 'Chi tiết ca sàng lọc',
    'back_to_list' => 'Về danh sách báo cáo',

    'metrics' => [
        'total' => 'Tổng ca sàng lọc',
        'referral_rate' => 'Tỉ lệ chuyển tuyến',
        'pending_pharmacist' => 'Đang chờ dược sĩ',
        'average_wait' => 'Thời gian chờ trung bình',
        'average_wait_hint' => 'Tính trên :count ca dược sĩ đã xem',
        'minutes' => 'phút',
        'no_data' => 'Chưa có dữ liệu',
    ],

    'filters' => [
        'legend' => 'Bộ lọc báo cáo',
        'outcome' => 'Kết quả sàng lọc',
        'status' => 'Trạng thái',
        'entry_point' => 'Lối vào',
        'date_from' => 'Từ ngày',
        'date_to' => 'Đến ngày',
        'all' => 'Tất cả',
        'apply' => 'Áp dụng lọc',
        'reset' => 'Xóa lọc',
        'export' => 'Xuất Excel',
    ],

    'status' => [
        'in_progress' => 'Đang thực hiện',
        'completed' => 'Hoàn tất',
        'pending_pharmacist' => 'Chờ dược sĩ',
        'reviewed' => 'Dược sĩ đã xem',
    ],

    'entry_point' => [
        'chat' => 'Chatbot',
        'page' => 'Trang sàng lọc',
    ],

    'severity' => [
        'emergency' => 'Cấp cứu',
        'urgent' => 'Khẩn',
        'caution' => 'Cần lưu ý',
    ],

    'charts' => [
        'outcome_title' => 'Phân bố kết quả sàng lọc',
        'outcome_desc' => 'Bốn mức kết quả trên tổng số ca phù hợp bộ lọc',
        'outcome_aria' => 'Biểu đồ tròn phân bố kết quả sàng lọc theo bốn mức. Số liệu chi tiết có trong bảng bên dưới biểu đồ.',
        'trend_title' => 'Số ca theo ngày',
        'trend_desc' => 'Số ca sàng lọc mỗi ngày trong :days ngày gần nhất',
        'trend_aria' => 'Biểu đồ đường số ca sàng lọc mỗi ngày trong :days ngày gần nhất. Bảng số liệu kèm theo nằm ngay dưới biểu đồ.',
        'loading' => 'Đang tải biểu đồ...',
        'outcome_error' => 'Không thể tải phân bố kết quả.',
        'trend_error' => 'Không thể tải số ca theo ngày.',
        'sessions' => 'ca',
        'table_toggle' => 'Xem bảng số liệu',
    ],

    'table' => [
        'title' => 'Danh sách ca sàng lọc',
        'code' => 'Mã ca',
        'date' => 'Ngày',
        'customer' => 'Khách hàng',
        'symptoms' => 'Triệu chứng chính',
        'outcome' => 'Kết quả',
        'red_flags' => 'Dấu hiệu nguy hiểm',
        'status' => 'Trạng thái',
        'pharmacist' => 'Dược sĩ xử lý',
        'entry_point' => 'Lối vào',
        'action' => 'Thao tác',
        'view' => 'Xem chi tiết',
        'empty' => 'Chưa có ca sàng lọc nào phù hợp bộ lọc hiện tại.',
        'empty_hint' => 'Thử mở rộng khoảng ngày hoặc xóa lọc để xem toàn bộ dữ liệu.',
        'none' => 'Không',
        'unknown' => 'Không rõ',
        'no_outcome' => 'Chưa có kết quả',
        'count_column' => 'Số ca',
    ],

    'top_symptoms' => [
        'title' => 'Triệu chứng xuất hiện nhiều nhất',
        'empty' => 'Chưa ghi nhận triệu chứng nào.',
        'name' => 'Triệu chứng',
    ],

    'top_red_flags' => [
        'title' => 'Dấu hiệu nguy hiểm hay được bắt',
        'empty' => 'Chưa ghi nhận dấu hiệu nguy hiểm nào.',
        'name' => 'Dấu hiệu',
        'severity' => 'Mức độ',
    ],

    'entry_points' => [
        'title' => 'Lối vào sàng lọc',
    ],

    'answers' => [
        'title' => 'Thông tin khách hàng đã khai',
        'raw_symptom_text' => 'Mô tả triệu chứng',
        'symptoms' => 'Triệu chứng nhận diện',
        'duration_days' => 'Số ngày có triệu chứng',
        'medications_taken' => 'Thuốc đã dùng',
        'history' => 'Tiền sử liên quan',
        'other_symptoms' => 'Triệu chứng kèm theo',
        'yes' => 'Có',
        'no' => 'Không',
        'skipped' => 'Đã bỏ qua',
        'empty' => 'Không có dữ liệu khai báo.',
    ],

    'detail' => [
        'overview' => 'Tổng quan ca',
        'created_at' => 'Thời điểm tạo',
        'reviewed_at' => 'Thời điểm dược sĩ xem',
        'follow_up_at' => 'Hẹn theo dõi',
        'session_id' => 'Mã phiên',
        'matched_symptoms' => 'Triệu chứng đã khớp',
        'red_flags' => 'Dấu hiệu nguy hiểm đã bắt',
        'condition_groups' => 'Nhóm tình trạng có thể',
        'advice' => 'Lời khuyên đã đưa',
        'safety_net' => 'Cảnh báo an toàn',
        'pharmacist_note' => 'Ghi chú dược sĩ',
        'no_pharmacist_note' => 'Dược sĩ chưa để lại ghi chú.',
        'privacy_notice' => 'Trang này chỉ hiển thị dữ liệu do phiên sàng lọc ghi lại. Hồ sơ sức khoẻ của khách hàng thuộc quyền xem của dược sĩ phụ trách, quản trị viên không truy cập được.',
    ],
];
