<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Giới hạn tải ảnh dùng chung
    |--------------------------------------------------------------------------
    |
    | Một nguồn duy nhất cho cả ảnh đại diện và ảnh gửi trong chatbot: hai chỗ
    | từng khai báo giới hạn riêng, nên chỉ cần sửa lệch một bên là client chặn
    | ở ngưỡng này còn server chặn ở ngưỡng khác.
    |
    */

    'images' => [
        // KB. Client dùng lại giá trị này qua config('uploads.images.max_size').
        'max_size' => env('UPLOAD_MAX_SIZE_KB', 5120),
        'allowed_types' => ['jpeg', 'jpg', 'png'],
    ],

    'avatars' => [
        'disk' => 'public',
        'path' => 'avatars',
        // Ảnh gốc từ điện thoại thường 3000px+; avatar lớn nhất hiển thị ở 96px,
        // nên thu về 512px là đủ cho màn hình 2x mà không giữ file vài MB.
        'max_dimension' => 512,
    ],

];
