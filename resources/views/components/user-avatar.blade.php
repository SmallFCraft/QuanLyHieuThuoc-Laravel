{{-- Ảnh đại diện. Trước đây gọi ui-avatars.com trên mỗi lần tải trang: một phụ
     thuộc bên ngoài chỉ để vẽ một chữ cái, đồng thời gửi tên người dùng ra ngoài.
     Giờ dùng ảnh tự tải lên, không có thì vẽ chữ cái đầu bằng CSS. --}}
@props(['user', 'size' => 40, 'ring' => false])

@php
    $url = $user->avatarUrl();
    $px = (int) $size;
@endphp

@if($url)
    <img src="{{ $url }}"
         alt="{{ $user->name }}"
         width="{{ $px }}" height="{{ $px }}"
         loading="lazy"
         {{ $attributes->class(['profile-avatar', 'profile-avatar--ring' => $ring]) }}
         style="width: {{ $px }}px; height: {{ $px }}px;">
@else
    <span {{ $attributes->class(['profile-avatar', 'profile-avatar--initial', 'profile-avatar--ring' => $ring]) }}
          style="width: {{ $px }}px; height: {{ $px }}px; font-size: {{ round($px * 0.42) }}px;"
          aria-hidden="true">{{ $user->initial() }}</span>
@endif
