{{-- Anh dai dien. Truoc day goi ui-avatars.com tren moi lan tai trang: mot phu
     thuoc ben ngoai chi de ve mot chu cai, dong thoi gui ten nguoi dung ra ngoai.
     Gio dung anh tu tai len, khong co thi ve chu cai dau bang CSS. --}}
@props(['user', 'size' => 40, 'ring' => false])

@php
    $url = $user->avatarUrl();
    $px = (int) $size;
@endphp

@if($url)
    <img src="{{ $url }}"
         alt="{{ $user->ho_ten }}"
         width="{{ $px }}" height="{{ $px }}"
         loading="lazy"
         {{ $attributes->class(['profile-avatar', 'profile-avatar--ring' => $ring]) }}
         style="width: {{ $px }}px; height: {{ $px }}px;">
@else
    <span {{ $attributes->class(['profile-avatar', 'profile-avatar--initial', 'profile-avatar--ring' => $ring]) }}
          style="width: {{ $px }}px; height: {{ $px }}px; font-size: {{ round($px * 0.42) }}px;"
          aria-hidden="true">{{ $user->initial() }}</span>
@endif
