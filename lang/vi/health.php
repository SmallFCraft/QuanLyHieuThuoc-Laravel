<?php

return [
    // Navbar + page chrome
    'nav' => 'Sàng lọc',
    'nav_full' => 'Sàng lọc y tế',
    'page_title' => 'Sàng lọc triệu chứng',
    'page_subtitle' => 'Trả lời vài câu hỏi ngắn để em định hướng giúp anh/chị nên tự chăm sóc, gặp dược sĩ, hay đi khám.',

    // Scope notice — luôn hiện đầy đủ; popup nhắc lại đúng nội dung này mỗi ngày một lần
    'disclaimer_title' => 'Đây là công cụ sàng lọc, không phải chẩn đoán y khoa',
    'disclaimer_body' => 'Kết luận do hệ thống quy tắc y khoa quyết định; một số phần diễn giải có thể do AI viết lại nên có thể không chính xác. Kết quả chỉ mang tính định hướng, không thay thế việc gặp bác sĩ hoặc dược sĩ. Nếu triệu chứng nặng lên, anh/chị hãy đến cơ sở y tế ngay.',
    'disclaimer_ack' => 'Tôi đã đọc',
    'disclaimer_review' => 'Xem lại',

    // Wizard
    'progress_label' => 'Bước :current/:total',
    'progress_aria' => 'Tiến độ sàng lọc triệu chứng',
    'or_type' => 'hoặc mô tả cụ thể hơn',
    'answer_label' => 'Câu trả lời của anh/chị',
    'btn_back' => 'Quay lại',
    'btn_skip' => 'Bỏ qua câu này',
    'btn_next' => 'Tiếp tục',
    // Trạng thái của nút vừa bấm. Chữ này là thứ báo "đang xử lý" — vòng xoay bị
    // đứng hình khi người dùng bật giảm chuyển động, nên chữ phải tự đứng được.
    'btn_working' => 'Đang xử lý…',
    'btn_restart' => 'Bắt đầu lại',
    'btn_start' => 'Bắt đầu sàng lọc',
    'btn_history' => 'Lịch sử sàng lọc',
    'profile_hint' => 'Em đã dùng thông tin sức khoẻ anh/chị khai trong hồ sơ nên sẽ bỏ qua vài câu hỏi.',
    // Tiêu đề ngắn vì nó là một lời mời, không phải một câu. Phần giải thích dài nằm
    // ở dòng dưới (`profile_offer_hint`).
    'profile_cta' => 'Khai hồ sơ sức khoẻ',
    'profile_offer_hint' => 'Để em tư vấn chính xác hơn cho trường hợp của anh/chị.',

    // Steps
    'step_1' => 'Triệu chứng',
    'step_2' => 'Thời gian',
    'step_3' => 'Đã dùng thuốc',
    'step_4' => 'Tiền sử',
    'step_5' => 'Kèm theo',

    // Nhịp hành trình trên trang kết quả: năm câu trả lời dẫn tới kết luận, thay vì
    // nằm gọn trong một <details> phải bấm mới thấy.
    'journey_title' => 'Hành trình sàng lọc của anh/chị',
    'journey_aria' => 'Năm bước anh/chị đã trả lời để ra kết luận này',
    'journey_outcome' => 'Kết luận',

    // Trang kết quả gom phần diễn giải dài vào tab, để người đang lo lắng không phải
    // cuộn qua sáu màn hình mới tới được phần mình cần.
    'hub_aria' => 'Các phần chi tiết của kết quả sàng lọc',
    'hub_tab_care' => 'Thuốc & chăm sóc',
    'hub_tab_audit' => 'Căn cứ kết luận',

    // Outcome labels
    'outcome_emergency' => 'Cần được cấp cứu',
    'outcome_refer_doctor' => 'Nên đi khám',
    'outcome_pharmacist_review' => 'Chờ dược sĩ xem',
    'outcome_self_care' => 'Có thể tự chăm sóc',

    'outcome_emergency_lead' => 'Dấu hiệu anh/chị mô tả thuộc nhóm nguy cơ cao và cần được bác sĩ đánh giá trực tiếp ngay.',
    'outcome_refer_doctor_lead' => 'Em khuyên anh/chị nên đi khám để bác sĩ xác định nguyên nhân, thay vì tự dùng thuốc.',
    'outcome_pharmacist_review_lead' => 'Thông tin anh/chị cung cấp chưa đủ để em định hướng chắc chắn. Anh/chị đọc phần dưới, và nếu còn băn khoăn thì nhờ dược sĩ xem lại giúp em nhé.',
    'outcome_self_care_lead' => 'Với thông tin anh/chị cung cấp, tình trạng này thường có thể tự chăm sóc tại nhà.',

    // Result page blocks
    'call_emergency' => 'Gọi 115',
    'call_emergency_aria' => 'Gọi số cấp cứu 115',
    'red_flags_title' => 'Dấu hiệu em đã ghi nhận',
    'guidance_title' => 'Hướng dẫn',
    'advice_title' => 'Anh/chị nên làm gì',
    'advice_hint' => 'Dược sĩ nhà thuốc ghi cho trường hợp của anh/chị',
    'safety_net_title' => 'Đi khám ngay nếu có các dấu hiệu sau',
    'condition_group_title' => 'Nhóm nguyên nhân có thể gặp',
    'condition_group_note' => 'Đây là phỏng đoán dựa trên thông tin anh/chị cung cấp, không phải kết luận y khoa.',
    'confidence_label' => 'Mức phù hợp',
    'products_title' => 'Thuốc không cần kê đơn có thể phù hợp',
    'products_note' => 'Anh/chị vui lòng đọc kỹ hướng dẫn sử dụng trước khi dùng.',
    'products_empty' => 'Nhà thuốc hiện chưa có sản phẩm không kê đơn phù hợp với triệu chứng này. Anh/chị hỏi dược sĩ để được chọn đúng thuốc nhé.',
    'product_usage_label' => 'Cách dùng',
    // Nhãn trên <summary> của <details>. Trạng thái mở/đóng đã có mũi chevron
    // xoay bằng CSS, nên không cần nhãn riêng cho từng trạng thái.
    'product_usage_toggle_open' => 'Xem hướng dẫn & liều dùng chi tiết',
    'product_stock_in' => 'Còn hàng',
    'no_products' => 'Em không gợi ý thuốc cho tình huống này. Việc gặp bác sĩ hoặc dược sĩ là bước cần thiết.',
    // Tiêu đề khối khi không có thuốc nào phù hợp. Trước đây câu no_products nằm trơ
    // giữa hai card trắng nên đọc như chú thích lỗi; giờ nó là một kết luận có tiêu đề.
    'no_products_title' => 'Không có thuốc phù hợp để tự dùng',
    'no_products_lead' => 'Tình huống anh/chị mô tả nằm ngoài nhóm thuốc không kê đơn.',

    // Cơ sở của kết luận: chỉ hiện dữ liệu đã lưu, không sinh nội dung y khoa mới.
    'basis_title' => 'Em dựa vào đâu để kết luận',
    'basis_symptoms' => 'Triệu chứng khớp',
    'basis_duration' => 'Thời gian có triệu chứng',
    'basis_duration_days' => ':days ngày',
    'basis_duration_today' => 'Mới trong hôm nay',
    'basis_age' => 'Tuổi người bệnh',
    'basis_age_years' => ':age tuổi',
    'basis_clarify' => 'Số vòng em hỏi thêm',
    'basis_clarify_rounds' => ':count vòng',
    'basis_red_flags_found' => 'Dấu hiệu nguy hiểm đã ghi nhận',
    'basis_red_flags_cleared' => 'Em không thấy dấu hiệu nguy hiểm nào trong phần anh/chị mô tả.',
    'basis_note' => 'Kết luận đến từ bộ quy tắc y khoa của nhà thuốc, dựa trên đúng những thông tin trên. Thiếu thông tin nào thì kết luận sẽ dè dặt hơn.',

    // Khối phân tích số liệu trên trang kết quả: điểm nguy cơ theo thang quy tắc
    // (bị chặn trong dải của kết luận) + thanh đóng góp từng yếu tố. Số do app tính,
    // AI chỉ được đọc lại đúng các số đó.
    'analysis_title' => 'Phân tích số liệu ca sàng lọc',
    'analysis_source' => 'Dựa trên :count dữ kiện đã ghi nhận của ca',
    'analysis_score_aria' => 'Điểm nguy cơ :score trên 100 theo thang quy tắc, mức :level',
    'analysis_score_level' => 'Mức: :level',
    'analysis_band_note' => 'Dải điểm của mức này: :from–:to điểm',
    'analysis_contribution_title' => 'Yếu tố đóng góp vào điểm',
    'analysis_zero_factors' => 'Yếu tố chưa đóng góp: :factors',
    'analysis_all_zero' => 'Không có yếu tố nào làm tăng điểm: ca này nằm ở đáy dải của mức kết luận.',
    'analysis_factor_symptoms' => 'Số triệu chứng',
    'analysis_factor_duration' => 'Thời gian triệu chứng',
    'analysis_factor_redflags' => 'Cờ đỏ',
    'analysis_factor_age' => 'Tuổi dễ tổn thương',
    'analysis_reading_label' => 'AI đọc lại số liệu',
    'analysis_note' => 'Điểm theo thang quy tắc của nhà thuốc, không phải xác suất y khoa. Mức kết luận vẫn do bộ quy tắc quyết định.',

    // Kiểm tra an toàn thuốc theo hồ sơ sức khoẻ đã khai.
    'safety_check_title' => 'Lưu ý an toàn theo hồ sơ của anh/chị',
    'safety_check_intro' => 'Em đối chiếu thành phần các thuốc gợi ý ở trên với hồ sơ sức khoẻ anh/chị đã khai:',
    'safety_check_clear' => 'Em đã đối chiếu thuốc gợi ý với hồ sơ sức khoẻ anh/chị khai và chưa thấy điểm nào cần lưu ý.',
    'safety_check_no_profile' => 'Anh/chị khai hồ sơ sức khoẻ (dị ứng, bệnh nền, thuốc đang dùng) thì em kiểm tra được thuốc gợi ý có an toàn với anh/chị không.',
    'product_has_warning' => 'Thuốc này có lưu ý với hồ sơ của anh/chị',

    'follow_up_note' => 'Em sẽ hỏi lại sau 3 ngày xem anh/chị đỡ chưa.',
    'pharmacist_waiting' => 'Dược sĩ thường trả lời trong khoảng 15 phút trong giờ làm việc.',
    'pharmacist_answer_by' => 'Dược sĩ :name trả lời',

    // Luồng phụ: khách chủ động xin dược sĩ xem lại. Kết quả AI/quy tắc là chính.
    'pharmacist_request_title' => 'Muốn dược sĩ xem lại?',
    'pharmacist_request_hint' => 'Nếu anh/chị còn băn khoăn, em chuyển ca này cho dược sĩ đọc và trả lời riêng. Kết quả ở trên vẫn giữ nguyên.',
    'pharmacist_request_btn' => 'Nhờ dược sĩ xem lại',
    'pharmacist_requested' => 'Em đã chuyển ca này cho dược sĩ. Anh/chị quay lại trang này để xem câu trả lời nhé.',
    'pharmacist_request_unavailable' => 'Ca này không cần chuyển dược sĩ ạ. Nếu là tình huống cấp, anh/chị gọi 115 hoặc đến cơ sở y tế ngay.',
    'pharmacist_request_pending' => 'Em đã chuyển ca này cho dược sĩ, đang chờ trả lời.',
    'answers_title' => 'Thông tin anh/chị đã cung cấp',
    'answers_clarify_title' => 'Câu em hỏi thêm',
    'btn_print' => 'In kết quả',
    'btn_new_check' => 'Sàng lọc lần khác',

    // AI blocks. Nhãn luôn hiện kèm icon + chữ, không dùng màu đơn lẻ.
    'ai_badge' => 'Nội dung do AI diễn giải lại',
    'ai_notice' => 'Phần dưới đây do AI viết lại cho dễ đọc, dựa đúng trên lời khuyên của dược sĩ. Nội dung do AI tạo ra có thể không chính xác — anh/chị xem bản gốc nếu cần.',
    'ai_original_toggle' => 'Xem lời khuyên gốc của dược sĩ',
    'ai_pending' => 'Kết luận bên dưới đã xong. Em đang diễn giải lại lời khuyên cho dễ đọc, anh/chị chờ chút nhé.',

    // Phần diễn giải chi tiết do AI viết. Nhãn AI hiện ở đầu khối, không nhét vào
    // từng mục, và mọi mục đều là nội dung bổ sung — không thay kết luận quy tắc.
    'briefing_title' => 'Em giải thích kỹ hơn cho anh/chị',
    'briefing_notice' => 'Phần này do AI viết dựa trên thông tin anh/chị kể và kết luận của bộ quy tắc. Nội dung do AI tạo ra có thể không chính xác và không thay thế việc gặp bác sĩ hoặc dược sĩ.',
    'briefing_pending' => 'Kết luận ở trên đã xong. Em đang viết phần giải thích chi tiết, mất khoảng một phút — anh/chị cứ đọc phần trên trước nhé.',
    'briefing_why' => 'Vì sao em kết luận như vậy',
    'briefing_possible_causes' => 'Nhóm nguyên nhân thường gặp',
    'briefing_self_care' => 'Anh/chị có thể tự làm ngay',
    'briefing_watch_for' => 'Dấu hiệu cần theo dõi',
    'briefing_see_doctor_when' => 'Khi nào cần đi khám',
    'briefing_ask_pharmacist' => 'Nên hỏi dược sĩ hoặc bác sĩ',
    'briefing_products_title' => 'Thuốc nhà thuốc đang có, AI thấy phù hợp',
    'briefing_products_note' => 'Danh sách lấy từ kho nhà thuốc và đã đối chiếu hồ sơ sức khoẻ của anh/chị. Anh/chị vẫn nên hỏi dược sĩ trước khi dùng.',
    'briefing_product_reason' => 'Vì sao phù hợp',
    'briefing_unavailable' => 'Phần giải thích chi tiết chưa sẵn sàng lần này. Kết luận và lời khuyên ở trên vẫn đầy đủ ạ.',
    'briefing_toc' => 'Nội dung trang này',
    'clarify_title' => 'Em hỏi thêm một chút cho chính xác',
    'clarify_round' => 'vòng :round/:total',
    'clarify_hint' => 'Trả lời giúp em mấy câu này thì kết quả sàng lọc sẽ sát hơn. Anh/chị bỏ qua cũng được ạ.',
    'clarify_answer_label' => 'Câu trả lời cho: :question',
    'clarify_skip' => 'Bỏ qua, xem kết quả',
    'clarify_submit' => 'Gửi và tiếp tục',

    // Trạng thái chờ khi worker đang quyết định. Lời gọi AI mất 11-30s nên đây là
    // một trạng thái thật, không phải spinner cho có.
    'deciding_title' => 'Em đang xem lại thông tin của anh/chị',
    'deciding_body' => 'Em đối chiếu những gì anh/chị kể với bộ quy tắc y khoa của nhà thuốc. Việc này mất khoảng nửa phút, anh/chị đừng tắt trang nhé.',
    'deciding_step_reading' => 'Đọc lại phần anh/chị mô tả',
    'deciding_step_rules' => 'Đối chiếu quy tắc sàng lọc',
    'deciding_step_flags' => 'Kiểm tra dấu hiệu cần đi khám',
    'deciding_slow' => 'Hơi lâu hơn bình thường một chút. Em vẫn đang xử lý ạ.',
    'deciding_retry' => 'Tải lại trang',

    // Mạch câu trả lời phía trên câu đang hỏi.
    'trail_title' => 'Anh/chị đã kể với em',
    'trail_skipped' => 'đã bỏ qua',

    // History page
    'history_title' => 'Lịch sử sàng lọc',
    'history_subtitle' => 'Các lần sàng lọc triệu chứng anh/chị đã thực hiện.',
    'history_empty' => 'Anh/chị chưa có lần sàng lọc nào.',
    'history_symptoms' => 'Triệu chứng',
];
