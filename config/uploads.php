<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Gioi han tai anh dung chung
    |--------------------------------------------------------------------------
    |
    | Mot nguon duy nhat cho ca anh dai dien va anh gui trong chatbot: hai cho
    | tung khai bao gioi han rieng, nen chi can sua lech mot ben la client chan
    | o nguong nay con server chan o nguong khac.
    |
    */

    'images' => [
        // KB. Client dung lai gia tri nay qua config('uploads.images.max_size').
        'max_size' => env('UPLOAD_MAX_SIZE_KB', 5120),
        'allowed_types' => ['jpeg', 'jpg', 'png'],
    ],

    'avatars' => [
        'disk' => 'public',
        'path' => 'avatars',
        // Anh goc tu dien thoai thuong 3000px+; avatar lon nhat hien thi o 96px,
        // nen thu ve 512px la du cho man hinh 2x ma khong giu file vai MB.
        'max_dimension' => 512,
    ],

];
