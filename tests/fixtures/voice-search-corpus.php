<?php

/**
 * Tập kiểm định cho tìm kiếm bằng giọng nói tiếng Việt.
 *
 * MỤC ĐÍCH
 * Khoá lại hành vi của App\Services\VoiceSearchNormalizer trên đúng những chuỗi mà
 * Chrome Web Speech `vi-VN` thật sự trả về — không phải trên chuỗi lý tưởng. Mỗi ca
 * là một cặp "máy nghe được" ↔ "khách thật sự muốn gì".
 *
 * THƯỚC ĐO — đọc trước khi viết assert
 * "storefrontSearch() trả > 0 sản phẩm" KHÔNG phải thước đo. Product::storefrontSearch()
 * khớp từng TỪ trong name_folded HOẶC ingredients_folded, nên "ba la bù la" ra 125 sản
 * phẩm và "ê a ê a" ra cả 3.146 sản phẩm. Thước đo dùng được:
 *
 *     A = tập MaSP của storefrontSearch(kết quả normalize)
 *     B = tập MaSP của storefrontSearch($case['intended'])
 *     ĐẠT  ⟺  |A ∩ B| >= |B| / 2
 *
 * Với ca 'nonsense' ($case['intended'] === null) thước đo đảo lại: normalize() phải
 * KHÔNG trả confident === true. Nhóm này chống dương tính giả và quan trọng ngang
 * nhóm chính — mọi chuỗi nonsense ở đây đều ra > 0 sản phẩm, đó chính là cái bẫy.
 *
 * VÌ SAO `intended` PHẢI KIỂM TRÊN CATALOG THẬT
 * Một ca kiểm mà đáp án không tồn tại trong catalog thì luôn đỏ vì lý do sai. Mọi
 * `intended` trong file này đã chạy qua:
 *
 *     Product::notExpired()->storefrontSearch($intended)->count() > 0
 *
 * trên catalog 3.146 sản phẩm / 50 danh mục (đo 2026-09-01). Tên thuốc, thương hiệu
 * và danh mục lấy từ catalog thật; chỉ cách đọc lệch (`said`) là soạn ra.
 *
 * DẤU CÂU
 * Chrome luôn chấm hết câu, nên trong sản xuất MỌI ca đều có thể mọc thêm '.'. Ở đây
 * dấu câu chỉ nằm trong `said` của nhóm 'punctuation' — nơi nó là đối tượng kiểm.
 * Harness muốn phủ rộng thì tự sinh biến thể: `$case['said'] . '.'`.
 *
 * THÊM CA MỚI
 * 1. Lấy tên/danh mục/thương hiệu THẬT từ catalog rồi bịa cách đọc lệch của nó.
 *    Đừng bịa cả hai đầu.
 * 2. Chạy `php artisan tinker --execute="echo App\Models\Product::notExpired()
 *    ->storefrontSearch('...')->count();"`. Ra 0 thì đổi `intended`, đừng thêm ca.
 * 3. `alternatives[0]` luôn === `said`. Web Speech trả tối đa 3, tốt nhất trước.
 * 4. `category` phải là một trong: exact, onset-merge, coda-merge, rime-merge,
 *    tone-loss, syllable-split, lead-phrase, punctuation, symptom, brand, dosage,
 *    nonsense, ambiguous.
 * 5. `dialect`: north | central | south | any. Giọng Trung khó nhất — đừng bỏ trống.
 *
 * @return array<int, array{said: string, alternatives: array<int, string>, intended: ?string, dialect: string, category: string, note: string}>
 */

return [
    // === exact — nói đúng thì tuyệt đối không được "sửa" thành cái khác ===
    [
        'said' => 'paracetamol',
        'alternatives' => ['paracetamol'],
        'intended' => 'paracetamol',
        'dialect' => 'any',
        'category' => 'exact',
        'note' => 'Tên hoạt chất phổ biến nhất catalog (95 sản phẩm): phải khớp ở T0, không đi tới tầng phiên âm.',
    ],
    [
        'said' => 'thuốc hạ sốt',
        'alternatives' => ['thuốc hạ sốt'],
        'intended' => 'thuốc hạ sốt',
        'dialect' => 'north',
        'category' => 'exact',
        'note' => 'Cụm chuẩn của US-02 khi nghe đúng — mốc để so với biến thể "thuấc hạ sốc".',
    ],
    [
        'said' => 'khẩu trang',
        'alternatives' => ['khẩu trang'],
        'intended' => 'khẩu trang',
        'dialect' => 'north',
        'category' => 'exact',
        'note' => 'Trùng tên một danh mục thật; T0 phải nhận nguyên văn chứ không đổi sang tên sản phẩm nào.',
    ],
    [
        'said' => 'men vi sinh',
        'alternatives' => ['men vi sinh'],
        'intended' => 'men vi sinh',
        'dialect' => 'any',
        'category' => 'exact',
        'note' => 'Ba âm tiết ngắn, dễ bị tầng phiên âm kéo sang "men tiêu hóa" nếu T0 không chặn trước.',
    ],
    [
        'said' => 'nước muối sinh lý',
        'alternatives' => ['nước muối sinh lý'],
        'intended' => 'nước muối sinh lý',
        'dialect' => 'north',
        'category' => 'exact',
        'note' => 'Bốn âm tiết, chỉ 6 sản phẩm khớp — tập đích nhỏ nên mọi thay thế sai đều lộ ngay.',
    ],
    [
        'said' => 'vitamin C',
        'alternatives' => ['vitamin C', 'vitamin xê'],
        'intended' => 'vitamin c',
        'dialect' => 'any',
        'category' => 'exact',
        'note' => 'Âm tiết một ký tự: within() phải TỪ CHỐI mọi lệch ở đây, nếu không "vitamin C" thành "vitamin D".',
    ],
    [
        'said' => 'canxi',
        'alternatives' => ['canxi', 'can xi'],
        'intended' => 'canxi',
        'dialect' => 'any',
        'category' => 'exact',
        'note' => 'Hai alternative là một từ viết liền và viết rời; cả hai đều có trong catalog nên T0 phải thắng.',
    ],
    [
        'said' => 'dầu gió',
        'alternatives' => ['dầu gió'],
        'intended' => 'dầu gió',
        'dialect' => 'central',
        'category' => 'exact',
        'note' => 'Giọng Trung đọc "dầu gió" khá rõ; ca này chốt rằng phát âm đúng không bị kéo sang "dầu gấc" (4 sản phẩm).',
    ],
    [
        'said' => 'nhiệt kế',
        'alternatives' => ['nhiệt kế'],
        'intended' => 'nhiệt kế',
        'dialect' => 'any',
        'category' => 'exact',
        'note' => 'Dụng cụ y tế, không phải thuốc — chốt rằng T0 phủ cả nhóm thiết bị chứ không chỉ dược phẩm.',
    ],

    // === onset-merge — lệch phụ âm đầu: s/x, ch/tr, d/gi/v/r, n/l ===
    [
        'said' => 'khẩu chang',
        'alternatives' => ['khẩu chang', 'khẩu choang'],
        'intended' => 'khẩu trang',
        'dialect' => 'north',
        'category' => 'onset-merge',
        'note' => 'Giọng Bắc gộp tr/ch; cặp đo được trong §4.2 của spec, khoá trùng khít nên phải nhận ở T1.',
    ],
    [
        'said' => 'nước muối xinh lý',
        'alternatives' => ['nước muối xinh lý'],
        'intended' => 'nước muối sinh lý',
        'dialect' => 'south',
        'category' => 'onset-merge',
        'note' => 'Gộp s/x ở âm tiết thứ ba; ba âm tiết còn lại đúng nên đây là ca kiểm ONSETS sạch nhất.',
    ],
    [
        'said' => 'lước súc miệng',
        'alternatives' => ['lước súc miệng', 'nước súc miệng'],
        'intended' => 'nước súc miệng',
        'dialect' => 'north',
        'category' => 'onset-merge',
        'note' => 'Lẫn n/l vùng Bắc; alternative thứ hai đã đúng nên T0 phải bắt được nó trước khi tới T1.',
    ],
    [
        'said' => 'cô la gien',
        'alternatives' => ['cô la gien', 'cô la gen'],
        'intended' => 'collagen',
        'dialect' => 'any',
        'category' => 'onset-merge',
        'note' => 'gi/g cộng với đọc rời — spec §4.2 xếp cặp này NHẬN qua ngưỡng khác số âm tiết.',
    ],
    [
        'said' => 'thuốc chị nấm',
        'alternatives' => ['thuốc chị nấm', 'thuốc trị nấm'],
        'intended' => 'thuốc trị nấm',
        'dialect' => 'north',
        'category' => 'onset-merge',
        'note' => 'Giọng Bắc trả tr thành ch giữa câu; 41 sản phẩm đích, đủ lớn để giao tập có ý nghĩa.',
    ],
    [
        'said' => 'thuốc rị ứng',
        'alternatives' => ['thuốc rị ứng', 'thuốc dị ứng'],
        'intended' => 'thuốc dị ứng',
        'dialect' => 'south',
        'category' => 'onset-merge',
        'note' => 'Giọng Nam d/r: alternative tốt nhất lệch, alternative sau đúng — kiểm thứ tự xét alternative.',
    ],
    // === coda-merge — lệch âm cuối: t/c, n/ng, nh/n, m/n ===
    [
        'said' => 'thuốc hạ sốc',
        'alternatives' => ['thuốc hạ sốc', 'thuốc hạ sốt'],
        'intended' => 'thuốc hạ sốt',
        'dialect' => 'south',
        'category' => 'coda-merge',
        'note' => 'Giọng Nam gộp âm cuối t/c — ca gốc của US-02, phải khớp ở T1 mà không gọi AI.',
    ],
    [
        'said' => 'kem chóng nắng',
        'alternatives' => ['kem chóng nắng', 'kem chong nang'],
        'intended' => 'kem chống nắng',
        'dialect' => 'south',
        'category' => 'coda-merge',
        'note' => 'ng/n cuối cả hai âm tiết; §4.2 ghi khoá trùng khít KeN CoN LaN.',
    ],
    [
        'said' => 'mem vi sinh',
        'alternatives' => ['mem vi sinh'],
        'intended' => 'men vi sinh',
        'dialect' => 'south',
        'category' => 'coda-merge',
        'note' => 'm/n cuối ở âm tiết đầu, chỉ lệch một ký tự — kiểm CODAS gộp m về N.',
    ],
    [
        'said' => 'thuốc đau bụn',
        'alternatives' => ['thuốc đau bụn', 'thuốc đau bụng'],
        'intended' => 'thuốc đau bụng',
        'dialect' => 'south',
        'category' => 'coda-merge',
        'note' => 'Rụng phụ âm cuối ng — Chrome hay nghe rụng đúng chỗ này; đích chỉ 8 sản phẩm.',
    ],
    [
        'said' => 'thuốc xương khớt',
        'alternatives' => ['thuốc xương khớt'],
        'intended' => 'thuốc xương khớp',
        'dialect' => 'central',
        'category' => 'coda-merge',
        'note' => 'Giọng Trung gộp p/t cuối; ca khó vì "khớt" không là từ tiếng Việt nào nên T0 chắc chắn trượt.',
    ],
    [
        'said' => 'thuốc tiêu chản',
        'alternatives' => ['thuốc tiêu chản', 'thuốc tiêu chảy'],
        'intended' => 'thuốc tiêu chảy',
        'dialect' => 'central',
        'category' => 'coda-merge',
        'note' => 'Giọng Trung biến "chảy" thành âm đóng bằng n — kiểm rằng CODAS không kéo sang sản phẩm khác nghĩa.',
    ],
    [
        'said' => 'thuốc bổ mắc',
        'alternatives' => ['thuốc bổ mắc', 'thuốc bổ mắt'],
        'intended' => 'thuốc bổ mắt',
        'dialect' => 'south',
        'category' => 'coda-merge',
        'note' => 't/c cuối trên âm tiết 3 ký tự — vừa đủ dài để không bị luật ATOMIC_SYLLABLE chặn.',
    ],

    // === rime-merge — lệch nguyên âm đôi: uô/ua, iê/ia, oi/uôi ===
    [
        'said' => 'thuấc hạ sốt',
        'alternatives' => ['thuấc hạ sốt'],
        'intended' => 'thuốc hạ sốt',
        'dialect' => 'south',
        'category' => 'rime-merge',
        'note' => 'uô → uâ ở "thuốc", âm cuối vẫn đúng — tách riêng lệch nguyên âm khỏi lệch phụ âm cuối.',
    ],
    [
        'said' => 'thuấc nhỏ mắt',
        'alternatives' => ['thuấc nhỏ mắt', 'thuốc nhỏ mắt'],
        'intended' => 'thuốc nhỏ mắt',
        'dialect' => 'south',
        'category' => 'rime-merge',
        'note' => 'Cùng lệch uô nhưng đích khác (31 sản phẩm) — kiểm rằng T1 không luôn trả về "thuốc hạ sốt".',
    ],
    [
        'said' => 'thuốc vim mũi',
        'alternatives' => ['thuốc vim mũi', 'thuốc viêm mũi'],
        'intended' => 'thuốc viêm mũi',
        'dialect' => 'central',
        'category' => 'rime-merge',
        'note' => 'iê → i, đúng dòng "viêm ≡ vim" trong bảng §4.1; giọng Trung rút ngắn nguyên âm đôi.',
    ],
    [
        'said' => 'nước mói sinh lý',
        'alternatives' => ['nước mói sinh lý'],
        'intended' => 'nước muối sinh lý',
        'dialect' => 'central',
        'category' => 'rime-merge',
        'note' => 'uôi → oi, dòng "muối ≡ mói" của §4.1; ba âm tiết còn lại đúng.',
    ],
    [
        'said' => 'thuốc tiu hóa',
        'alternatives' => ['thuốc tiu hóa', 'men tiu hóa'],
        'intended' => 'men tiêu hóa',
        'dialect' => 'central',
        'category' => 'rime-merge',
        'note' => 'iê → iu cộng nhầm "thuốc"/"men": ca kiểm rằng cửa sổ 2 từ thay được đoạn lệch mà giữ phần còn lại.',
    ],
    [
        'said' => 'thuấc bổ gan',
        'alternatives' => ['thuấc bổ gan'],
        'intended' => 'thuốc bổ gan',
        'dialect' => 'south',
        'category' => 'rime-merge',
        'note' => 'Cùng dạng lệch uô nhưng cụm sau là tên bệnh lý — kiểm rằng phần đúng của câu không bị thay.',
    ],
    // === tone-loss — chỉ lệch dấu thanh, chữ cái đúng hết ===
    [
        'said' => 'thuoc ha sot',
        'alternatives' => ['thuoc ha sot'],
        'intended' => 'thuốc hạ sốt',
        'dialect' => 'any',
        'category' => 'tone-loss',
        'note' => 'Không dấu hoàn toàn: fold() bỏ dấu ở tầng đầu nên ca này phải khớp ngay ở T0.',
    ],
    [
        'said' => 'thuoc dau dau',
        'alternatives' => ['thuoc dau dau', 'thuốc đau đầu'],
        'intended' => 'thuốc đau đầu',
        'dialect' => 'any',
        'category' => 'tone-loss',
        'note' => 'Bỏ dấu làm "đau đầu" và "dau dau" trùng khoá — kiểm rằng đ/d không phá fold().',
    ],
    [
        'said' => 'thuốc hà sốt',
        'alternatives' => ['thuốc hà sốt'],
        'intended' => 'thuốc hạ sốt',
        'dialect' => 'central',
        'category' => 'tone-loss',
        'note' => 'Giọng Trung đổi thanh nặng thành thanh huyền — chỉ dấu lệch, chữ đúng hết.',
    ],
    [
        'said' => 'khâu trang',
        'alternatives' => ['khâu trang', 'khẩu trang'],
        'intended' => 'khẩu trang',
        'dialect' => 'central',
        'category' => 'tone-loss',
        'note' => 'Mất dấu hỏi và lệch nguyên âm ơ/â cùng lúc; fold() gánh phần dấu, VOWELS gánh phần còn lại.',
    ],
    [
        'said' => 'vitamin tong hop',
        'alternatives' => ['vitamin tong hop'],
        'intended' => 'vitamin tổng hợp',
        'dialect' => 'any',
        'category' => 'tone-loss',
        'note' => 'Chrome đôi khi trả về chữ không dấu cho từ ghép; 29 sản phẩm đích.',
    ],

    // === syllable-split — đọc rời tên thuốc nước ngoài ===
    [
        'said' => 'pa ra xe ta mon',
        'alternatives' => ['pa ra xe ta mon', 'pa ra ce ta mol'],
        'intended' => 'paracetamol',
        'dialect' => 'any',
        'category' => 'syllable-split',
        'note' => 'Năm âm tiết rời cho một từ liền: chỉ nhánh "khác số âm tiết" của within() xử lý được.',
    ],
    [
        'said' => 'i bu prô phen',
        'alternatives' => ['i bu prô phen'],
        'intended' => 'ibuprofen',
        'dialect' => 'any',
        'category' => 'syllable-split',
        'note' => 'ph/f cộng đọc rời; §4.2 đã đo NHẬN, nên ca này khoá lại một recall đã có.',
    ],
    [
        'said' => 'bơ bơ rin',
        'alternatives' => ['bơ bơ rin', 'bo bo rin'],
        'intended' => 'berberin',
        'dialect' => 'any',
        'category' => 'syllable-split',
        'note' => 'Cặp mà §4.2 đo TRƯỢT có chủ ý ở T1 và rơi xuống tầng AI — ca này khoá lại chỗ tầng AI phải gánh.',
    ],
    [
        'said' => 'ô mê ga ba',
        'alternatives' => ['ô mê ga ba', 'ô mê ga 3'],
        'intended' => 'omega 3',
        'dialect' => 'any',
        'category' => 'syllable-split',
        'note' => 'Chữ số đọc thành chữ ("ba" ↔ "3"): phần đọc rời và phần số phải cùng khớp về "omega 3".',
    ],
    [
        'said' => 'gờ lu cô sa min',
        'alternatives' => ['gờ lu cô sa min', 'glu cô sa min'],
        'intended' => 'glucosamin',
        'dialect' => 'any',
        'category' => 'syllable-split',
        'note' => 'Năm âm tiết rời, cụm gờ- đầu là cách người Việt phát âm phụ âm kép gl-.',
    ],
    [
        'said' => 'lô ra ta din',
        'alternatives' => ['lô ra ta din', 'lo ra ta đin'],
        'intended' => 'loratadin',
        'dialect' => 'any',
        'category' => 'syllable-split',
        'note' => 'Tên hoạt chất kháng histamin thật (33 sản phẩm); ONSETS gộp d/đ ở âm tiết cuối.',
    ],
    [
        'said' => 'côn la gien',
        'alternatives' => ['côn la gien'],
        'intended' => 'collagen',
        'dialect' => 'south',
        'category' => 'syllable-split',
        'note' => 'Đọc rời + thêm âm cuối n vào âm tiết đầu — hai loại lệch cùng lúc, sát ngưỡng lev/3.',
    ],
    // === lead-phrase — cụm mệnh lệnh mở đầu, phải bị bỏ chứ không bị tìm ===
    [
        'said' => 'Tìm thuốc hạ sốt',
        'alternatives' => ['Tìm thuốc hạ sốt', 'tìm thuốc hạ sốt'],
        'intended' => 'thuốc hạ sốt',
        'dialect' => 'any',
        'category' => 'lead-phrase',
        'note' => '"Tìm" là mệnh lệnh, không phải từ khoá; nếu nó vào truy vấn thì tập kết quả lệch hẳn.',
    ],
    [
        'said' => 'Cho tôi paracetamol',
        'alternatives' => ['Cho tôi paracetamol'],
        'intended' => 'paracetamol',
        'dialect' => 'any',
        'category' => 'lead-phrase',
        'note' => '"cho" là token thật trong 431 tên sản phẩm — để nguyên là kéo hẳn tập kết quả sai hướng.',
    ],
    [
        'said' => 'Tìm giúp tôi men vi sinh',
        'alternatives' => ['Tìm giúp tôi men vi sinh'],
        'intended' => 'men vi sinh',
        'dialect' => 'any',
        'category' => 'lead-phrase',
        'note' => 'Cụm mở đầu ba từ; "giúp" cũng là token thật (317 tên) nên nhiễu cộng dồn.',
    ],
    [
        'said' => 'Tôi muốn mua khẩu trang',
        'alternatives' => ['Tôi muốn mua khẩu trang'],
        'intended' => 'khẩu trang',
        'dialect' => 'any',
        'category' => 'lead-phrase',
        'note' => 'Câu đầy đủ chủ ngữ — dạng khách hay nói khi mới thử tính năng.',
    ],
    [
        'said' => 'Cho em hỏi thuốc đau đầu',
        'alternatives' => ['Cho em hỏi thuốc đau đầu', 'cho em hỏi thuốc đau đầu'],
        'intended' => 'thuốc đau đầu',
        'dialect' => 'south',
        'category' => 'lead-phrase',
        'note' => 'Cách mở lời của khách miền Nam; "hỏi" trùng token "hoi" (136 tên) sau khi fold.',
    ],
    [
        'said' => 'Tìm thúc đau đầu',
        'alternatives' => ['Tìm thúc đau đầu', 'tìm thuốc đau đầu'],
        'intended' => 'thuốc đau đầu',
        'dialect' => 'any',
        'category' => 'lead-phrase',
        'note' => 'Chuỗi Chrome thật sự trả về khi nghe "thuốc đau đầu": mất cả cụm mở đầu và âm cuối cùng lúc.',
    ],

    // === punctuation — Chrome luôn chấm hết câu ===
    [
        'said' => 'Tìm thúc đau đầu.',
        'alternatives' => ['Tìm thúc đau đầu.', 'Tìm thuốc đau đầu.'],
        'intended' => 'thuốc đau đầu',
        'dialect' => 'any',
        'category' => 'punctuation',
        'note' => 'Chuỗi đo được nguyên văn từ Chrome vi-VN, gồm dấu chấm cuối — ca mốc của cả nhóm.',
    ],
    [
        'said' => 'thuốc hạ sốt.',
        'alternatives' => ['thuốc hạ sốt.'],
        'intended' => 'thuốc hạ sốt',
        'dialect' => 'any',
        'category' => 'punctuation',
        'note' => 'Dấu chấm dính vào token cuối: "sốt." fold thành "sot." và không LIKE khớp nếu không bị loại.',
    ],
    [
        'said' => 'Có thuốc ho không?',
        'alternatives' => ['Có thuốc ho không?', 'có thuốc ho không'],
        'intended' => 'thuốc ho',
        'dialect' => 'any',
        'category' => 'punctuation',
        'note' => 'Dấu hỏi cuối câu nghi vấn; "không" cũng là token thật (96 tên) nên phải bị cụm mở đầu loại đi.',
    ],
    [
        'said' => 'thuốc ho, siro ho.',
        'alternatives' => ['thuốc ho, siro ho.'],
        'intended' => 'siro ho',
        'dialect' => 'any',
        'category' => 'punctuation',
        'note' => 'Dấu phẩy giữa câu tách hai cụm; cụm sau hẹp hơn (105 vs 482) nên là đích đúng.',
    ],
    [
        'said' => 'Vitamin C, canxi.',
        'alternatives' => ['Vitamin C, canxi.'],
        'intended' => 'canxi',
        'dialect' => 'any',
        'category' => 'punctuation',
        'note' => 'Dấu phẩy + chữ hoa + âm tiết một ký tự trong cùng một câu; "vitamin c" ra 473 sản phẩm nên đích hẹp hơn là canxi.',
    ],
    [
        'said' => 'khẩu trang y tế.',
        'alternatives' => ['khẩu trang y tế.', 'khẩu trang y tế'],
        'intended' => 'khẩu trang y tế',
        'dialect' => 'north',
        'category' => 'punctuation',
        'note' => 'Cùng một câu có và không có dấu chấm trong hai alternative — chốt rằng cả hai về cùng kết quả.',
    ],
    [
        'said' => 'Thuốc nhỏ mắt?',
        'alternatives' => ['Thuốc nhỏ mắt?'],
        'intended' => 'thuốc nhỏ mắt',
        'dialect' => 'any',
        'category' => 'punctuation',
        'note' => 'Dấu hỏi mà không có từ nghi vấn nào — Chrome tự thêm khi ngữ điệu lên cao.',
    ],
    // === symptom — tả triệu chứng, không nói tên thuốc (US-03) ===
    [
        'said' => 'con tôi bị sốt',
        'alternatives' => ['con tôi bị sốt', 'con tôi bị sốc'],
        'intended' => 'thuốc hạ sốt',
        'dialect' => 'any',
        'category' => 'symptom',
        'note' => 'Không có tên thuốc nào trong câu: T1 không có gì để thay, đây là ca tầng AI phải gánh.',
    ],
    [
        'said' => 'thuấc hạ sốc cho em bé',
        'alternatives' => ['thuấc hạ sốc cho em bé'],
        'intended' => 'thuốc hạ sốt',
        'dialect' => 'south',
        'category' => 'symptom',
        'note' => 'Ca nguyên văn của US-03: cụm đích lệch âm, phần "cho em bé" là bối cảnh phải giữ hoặc bỏ, không được tìm.',
    ],
    [
        'said' => 'tôi bị ho khan mấy ngày nay',
        'alternatives' => ['tôi bị ho khan mấy ngày nay'],
        'intended' => 'ho khan',
        'dialect' => 'any',
        'category' => 'symptom',
        'note' => 'Câu dài 7 từ: cửa sổ tối đa 3 từ nên phải tìm đúng cụm mang thông tin giữa câu.',
    ],
    [
        'said' => 'bị đau bụng đi ngoài',
        'alternatives' => ['bị đau bụng đi ngoài'],
        'intended' => 'thuốc tiêu chảy',
        'dialect' => 'any',
        'category' => 'symptom',
        'note' => 'Triệu chứng phải suy ra tên nhóm thuốc — không có từ nào trong câu trùng đích, chỉ AI làm được.',
    ],
    [
        'said' => 'mất ngủ cả đêm',
        'alternatives' => ['mất ngủ cả đêm', 'mất ngủ cả điêm'],
        'intended' => 'mất ngủ',
        'dialect' => 'central',
        'category' => 'symptom',
        'note' => '"mất ngủ" có thật trong 59 tên/công dụng nên đích hẹp lại đúng cụm đó, không cần suy ra tên thuốc.',
    ],
    [
        'said' => 'da tôi bị nổi mề đay',
        'alternatives' => ['da tôi bị nổi mề đay'],
        'intended' => 'mề đay',
        'dialect' => 'any',
        'category' => 'symptom',
        'note' => 'Tên bệnh nằm trong ingredients_folded/usage của 93 sản phẩm — kiểm rằng khớp qua công dụng vẫn tính là đúng.',
    ],
    [
        'said' => 'đau lưng mỏi vai',
        'alternatives' => ['đau lưng mỏi vai'],
        'intended' => 'đau lưng',
        'dialect' => 'any',
        'category' => 'symptom',
        'note' => 'Hai triệu chứng trong một câu: giao tập phải tính trên cụm chính, "mỏi vai" không có trong catalog.',
    ],

    // === brand — tên thương hiệu, hay bị nghe thành từ tiếng Việt ===
    [
        'said' => 'pa na đôn',
        'alternatives' => ['pa na đôn', 'panadol'],
        'intended' => 'panadol',
        'dialect' => 'any',
        'category' => 'brand',
        'note' => 'Thương hiệu thật, chỉ 5 sản phẩm — tập đích rất hẹp nên thay sai là lộ ngay.',
    ],
    [
        'said' => 'ha pa côn',
        'alternatives' => ['ha pa côn', 'hapacol'],
        'intended' => 'hapacol',
        'dialect' => 'any',
        'category' => 'brand',
        'note' => 'Thương hiệu paracetamol nội địa (18 sản phẩm); đọc rời ba âm tiết theo lối Việt.',
    ],
    [
        'said' => 'ê phê ran gan',
        'alternatives' => ['ê phê ran gan', 'ê phê ran găng'],
        'intended' => 'efferalgan',
        'dialect' => 'south',
        'category' => 'brand',
        'note' => '"gan" trùng một token thật trong 164 tên — nguy cơ T0 nhận sai vì "gan" tự nó tìm ra kết quả.',
    ],
    [
        'said' => 'sa lon pát',
        'alternatives' => ['sa lon pát', 'salonpas'],
        'intended' => 'salonpas',
        'dialect' => 'any',
        'category' => 'brand',
        'note' => 'Miếng dán giảm đau, 10 sản phẩm; s/x và p/t cuối đều nằm trong bảng gộp.',
    ],
    [
        'said' => 'bê tha đin',
        'alternatives' => ['bê tha đin', 'betadine', 'bê ta đin'],
        'intended' => 'betadine',
        'dialect' => 'any',
        'category' => 'brand',
        'note' => 'Ba alternative gần giống nhau — đúng dạng Web Speech trả về cho tên nước ngoài ngắn.',
    ],
    [
        'said' => 'tra pha cô',
        'alternatives' => ['tra pha cô', 'traphaco'],
        'intended' => 'traphaco',
        'dialect' => 'any',
        'category' => 'brand',
        'note' => 'Tên nhà sản xuất, không phải tên thuốc (48 sản phẩm) — kiểm rằng thương hiệu cũng tìm được.',
    ],
    [
        'said' => 'ni giô ran',
        'alternatives' => ['ni giô ran', 'nizoral'],
        'intended' => 'nizoral',
        'dialect' => 'north',
        'category' => 'brand',
        'note' => 'gi/z: bảng ONSETS không có z nên đây là ca dò xem D-marker có kéo nổi "giô" về "zo".',
    ],
    // === dosage — có hàm lượng hoặc số, số không được gộp như chữ ===
    [
        'said' => 'paracetamol 500mg',
        'alternatives' => ['paracetamol 500mg', 'paracetamol 500 mg'],
        'intended' => 'paracetamol 500mg',
        'dialect' => 'any',
        'category' => 'dosage',
        'note' => 'Ca của US-01; 38 sản phẩm. Chữ số không được thu gọn: "500" và "50" phải khác khoá.',
    ],
    [
        'said' => 'pa ra xe ta mon năm trăm',
        'alternatives' => ['pa ra xe ta mon năm trăm', 'paracetamol 500'],
        'intended' => 'paracetamol 500mg',
        'dialect' => 'any',
        'category' => 'dosage',
        'note' => 'Hàm lượng đọc thành chữ: "năm trăm" phải về "500", nếu không thì mất hết phần lọc hàm lượng.',
    ],
    [
        'said' => 'i bu prô phen bốn trăm',
        'alternatives' => ['i bu prô phen bốn trăm', 'ibuprofen 400'],
        'intended' => 'ibuprofen 400mg',
        'dialect' => 'any',
        'category' => 'dosage',
        'note' => 'Chỉ 5 sản phẩm đích — tập nhỏ nên nếu "400" bị rơi thì giao tập tụt ngay dưới một nửa.',
    ],
    [
        'said' => 'vitamin xê một ngàn',
        'alternatives' => ['vitamin xê một ngàn', 'vitamin C 1000'],
        'intended' => 'vitamin c 1000mg',
        'dialect' => 'south',
        'category' => 'dosage',
        'note' => 's/x ở "xê" + số đọc thành chữ; "vitamin c" trơ trọi ra 473 sản phẩm nên hàm lượng là phần thu hẹp.',
    ],
    [
        'said' => 'canxi năm trăm mg',
        'alternatives' => ['canxi năm trăm mg', 'canxi 500mg'],
        'intended' => 'canxi 500mg',
        'dialect' => 'any',
        'category' => 'dosage',
        'note' => 'Nửa chữ nửa ký hiệu — dạng Chrome hay trả khi người nói đọc "năm trăm mi li gam".',
    ],
    [
        'said' => 'lô ra ta din mười mg',
        'alternatives' => ['lô ra ta din mười mg', 'loratadin 10mg'],
        'intended' => 'loratadin 10mg',
        'dialect' => 'any',
        'category' => 'dosage',
        'note' => 'Đọc rời tên + hàm lượng bằng chữ trong một câu: hai loại chuẩn hoá phải cùng chạy.',
    ],
    [
        'said' => 'siro sáu mươi ml',
        'alternatives' => ['siro sáu mươi ml', 'siro 60ml'],
        'intended' => 'siro 60ml',
        'dialect' => 'any',
        'category' => 'dosage',
        'note' => 'Thể tích chứ không phải khối lượng (16 sản phẩm) — kiểm rằng ml cũng được xử như mg.',
    ],
    // === ambiguous — nghe được nhưng nhập nhèm giữa hai sản phẩm khác nhau ===
    [
        'said' => 'vitamin đê',
        'alternatives' => ['vitamin đê', 'vitamin D', 'vitamin dê'],
        'intended' => 'vitamin d',
        'dialect' => 'any',
        'category' => 'ambiguous',
        'note' => 'Trục chệch nguy hiểm nhất của spec: "đê" không được thành "xê". Âm tiết 1 ký tự phải bị TỪ CHỐI mọi lệch.',
    ],
    [
        'said' => 'vitamin bê sáu',
        'alternatives' => ['vitamin bê sáu', 'vitamin B6', 'vitamin bê sáo'],
        'intended' => 'vitamin b6',
        'dialect' => 'south',
        'category' => 'ambiguous',
        'note' => 'b6 và b12 là hai sản phẩm khác nhau (152 vs 105); chữ số là phần duy nhất phân biệt.',
    ],
    [
        'said' => 'kem',
        'alternatives' => ['kem', 'kẽm'],
        'intended' => 'kẽm',
        'dialect' => 'south',
        'category' => 'ambiguous',
        'note' => 'Cùng khoá phiên âm KeN nhưng là khoáng chất vs dạng bào chế; giọng Nam bỏ dấu ngã nên không thể phân biệt bằng âm.',
    ],
    [
        'said' => 'sắt',
        'alternatives' => ['sắt', 'sát'],
        'intended' => 'sắt',
        'dialect' => 'central',
        'category' => 'ambiguous',
        'note' => '"sắt" (khoáng chất, 192 sản phẩm) vs "sát khuẩn" (18) — chỉ dấu thanh phân biệt, mà fold() bỏ dấu.',
    ],
    [
        'said' => 'thuốc ngứa',
        'alternatives' => ['thuốc ngứa', 'thuốc ngủ'],
        'intended' => 'thuốc ngứa',
        'dialect' => 'south',
        'category' => 'ambiguous',
        'note' => 'Hai chỉ định hoàn toàn khác (45 vs 92 sản phẩm) chỉ khác nguyên âm — không được tự chọn cái phổ biến hơn.',
    ],
    [
        'said' => 'bổ phế',
        'alternatives' => ['bổ phế', 'bổ phổi'],
        'intended' => 'bổ phế',
        'dialect' => 'central',
        'category' => 'ambiguous',
        'note' => '209 vs 9 sản phẩm: lệch quy mô 20 lần nên nếu chọn sai thì giao tập gần như rỗng.',
    ],
    [
        'said' => 'trị nám',
        'alternatives' => ['trị nám', 'trị nấm'],
        'intended' => 'trị nám',
        'dialect' => 'south',
        'category' => 'ambiguous',
        'note' => 'Cùng khoá sau khi gộp âm cuối m/n; da liễu vs kháng nấm — hai kệ hàng khác nhau.',
    ],
    [
        'said' => 'cao dán',
        'alternatives' => ['cao dán', 'cao xoa'],
        'intended' => 'cao dán',
        'dialect' => 'any',
        'category' => 'ambiguous',
        'note' => 'Dán ngoài vs xoa bóp (35 vs 9); không cùng khoá nhưng cùng ngữ cảnh nên tầng AI dễ đổi bừa.',
    ],

    // === nonsense — phải KHÔNG trả kết quả tự tin ===
    // Mọi chuỗi dưới đây đã đo trên catalog thật và ĐỀU ra > 0 sản phẩm.
    // Đó chính là cái bẫy: "có kết quả" không chứng minh gì cả.
    [
        'said' => 'ba la bù la',
        'alternatives' => ['ba la bù la'],
        'intended' => null,
        'dialect' => 'any',
        'category' => 'nonsense',
        'note' => 'Ra 125 sản phẩm dù vô nghĩa — ca chứng minh vì sao "count > 0" không dùng làm thước đo.',
    ],
    [
        'said' => 'ê a ê a',
        'alternatives' => ['ê a ê a'],
        'intended' => null,
        'dialect' => 'any',
        'category' => 'nonsense',
        'note' => 'Ra ĐỦ 3.146 sản phẩm, tức toàn bộ catalog: trường hợp xấu nhất của khớp theo từng từ.',
    ],
    [
        'said' => 'lá la la la',
        'alternatives' => ['lá la la la'],
        'intended' => null,
        'dialect' => 'any',
        'category' => 'nonsense',
        'note' => 'Ra 1.271 sản phẩm; nếu normalize() trả confident thì panel gợi ý mở ra với nửa catalog.',
    ],
    [
        'said' => 'ố lê ô lê',
        'alternatives' => ['ố lê ô lê', 'ô lê ô lê'],
        'intended' => null,
        'dialect' => 'any',
        'category' => 'nonsense',
        'note' => 'Ra 846 sản phẩm. Tiếng cổ động thể thao — dạng tiếng ồn thật lọt vào micro.',
    ],
    [
        'said' => 'lô ca ca',
        'alternatives' => ['lô ca ca'],
        'intended' => null,
        'dialect' => 'any',
        'category' => 'nonsense',
        'note' => 'Ra 799 sản phẩm vì "ca" và "lo" đều là token thật sau khi fold.',
    ],
    [
        'said' => 'lô tô lô tô',
        'alternatives' => ['lô tô lô tô'],
        'intended' => null,
        'dialect' => 'south',
        'category' => 'nonsense',
        'note' => 'Ra 491 sản phẩm; âm tiết lặp là dạng Web Speech hay bịa ra khi nghe tiếng rao ngoài đường.',
    ],
    [
        'said' => 'ước gì',
        'alternatives' => ['ước gì', 'ướt gì'],
        'intended' => null,
        'dialect' => 'any',
        'category' => 'nonsense',
        'note' => 'Ra 468 sản phẩm chỉ với hai âm tiết — câu ngắn cũng làm ngập kết quả, không cần câu dài.',
    ],
    [
        'said' => 'ăng lê ăng lê',
        'alternatives' => ['ăng lê ăng lê'],
        'intended' => null,
        'dialect' => 'central',
        'category' => 'nonsense',
        'note' => 'Ra 351 sản phẩm; giọng Trung nói nhanh khiến Chrome trả ra chuỗi âm tiết vô nghĩa như thế này.',
    ],
    [
        'said' => 'bờ ao bờ ao',
        'alternatives' => ['bờ ao bờ ao'],
        'intended' => null,
        'dialect' => 'north',
        'category' => 'nonsense',
        'note' => 'Ra 319 sản phẩm. Hai từ tiếng Việt thật ghép thành câu vô nghĩa — khó hơn chuỗi âm bịa.',
    ],
    [
        'said' => 'a bờ cờ dờ',
        'alternatives' => ['a bờ cờ dờ', 'a bê xê dê'],
        'intended' => null,
        'dialect' => 'any',
        'category' => 'nonsense',
        'note' => 'Ra 213 sản phẩm; đọc bảng chữ cái — dạng người dùng thử nghiệm tính năng.',
    ],
    [
        'said' => 'nam mô a di đà',
        'alternatives' => ['nam mô a di đà'],
        'intended' => null,
        'dialect' => 'any',
        'category' => 'nonsense',
        'note' => 'Ra 58 sản phẩm vì "nam", "da" là token thật; tiếng radio nền lọt vào micro.',
    ],
    [
        'said' => 'không có gì',
        'alternatives' => ['không có gì', 'không có gì.'],
        'intended' => null,
        'dialect' => 'any',
        'category' => 'nonsense',
        'note' => 'Ra 40 sản phẩm dù là câu phủ định thuần: khách bấm mic rồi đổi ý và nói câu này.',
    ],
];
