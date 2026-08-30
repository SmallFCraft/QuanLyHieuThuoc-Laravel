{{-- Product photo for the cards on the result page. Shared by both lists so the two
     never drift apart. Not a link: the card already ends in "Xem chi tiết", and a
     second link to the same page would give a screen reader two names for one target.
     Hidden in print, where the photo only costs ink. --}}
<div class="health-product__media health-no-print" data-product-image-container>
    <x-product-image
        :src="$product['image_url'] ?? null"
        :alt="$product['name'] ?? ''"
        class="w-100 h-100 object-fit-contain p-2"
        icon-class="text-secondary opacity-25"
        icon-size="2.25rem"
    />
</div>
