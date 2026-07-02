@props([
    'src' => null,
    'alt' => '',
    'fallbackClass' => '',
    'iconClass' => 'text-gray-300',
    'iconSize' => '2.5rem',
    'loading' => 'lazy',
])

@if($src)
    <img
        src="{{ $src }}"
        alt="{{ $alt }}"
        @if($loading) loading="{{ $loading }}" @endif
        onerror="this.classList.add('d-none'); this.nextElementSibling.classList.remove('d-none'); this.closest('[data-product-image-container]')?.querySelectorAll('[data-product-image-action]').forEach((element) => element.classList.add('d-none'));"
        {{ $attributes->class(['position-absolute top-0 start-0']) }}
        style="{{ trim(($attributes->get('style') ? rtrim($attributes->get('style'), ';') . '; ' : '') . 'inset: 0;') }}"
    >
@endif

<div
    class="position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center {{ $src ? 'd-none' : '' }} {{ $fallbackClass }}"
    style="inset: 0;"
    role="img"
    aria-label="{{ $alt ? 'Không có hình ảnh cho ' . $alt : 'Không có hình ảnh sản phẩm' }}"
>
    <i class="bi bi-capsule {{ $iconClass }}" style="font-size: {{ $iconSize }};" aria-hidden="true"></i>
</div>
