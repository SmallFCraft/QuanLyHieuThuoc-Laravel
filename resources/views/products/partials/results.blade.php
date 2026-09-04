{{-- Khối kết quả catalog. Trang đầy đủ và nhánh AJAX của
     ProductCatalogController::resultsFragment() render chung file này, nên lưới sản
     phẩm không thể lệch nhau giữa hai đường. --}}
<div class="d-flex justify-content-between align-items-center mb-4">
    <p class="text-gray-500 mb-0 small">
        {!! __('messages.showing_results', ['count' => '<strong class="text-gray-800">' . $products->count() . '</strong>', 'total' => '<strong class="text-gray-800">' . $products->total() . '</strong>']) !!}
    </p>
</div>

<div class="row g-4">
    @forelse($products as $product)
        <div class="col-sm-6 col-xl-4">
            <x-product-card
                :product="$product"
                card-class="card border-0 rounded-4 shadow-sm overflow-hidden catalog-product-card bg-white"
                media-class="catalog-product-media bg-gray-50"
                body-class="card-body p-4"
            />
        </div>
    @empty
        <div class="col-12">
            <div class="glass-card text-center py-5 rounded-4 border-0">
                <div class="bg-emerald-50 rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width:80px; height:80px;">
                    <i class="bi bi-search text-emerald-500 fs-1" aria-hidden="true"></i>
                </div>
                @if(filled($searchTerm ?? null))
                    <h5 class="fw-bold text-gray-700">{{ __('messages.no_result_for', ['term' => $searchTerm]) }}</h5>
                    <p class="text-muted mb-3">{{ __('messages.no_result_hint') }}</p>
                    <a href="{{ route('products.index') }}" class="btn btn-outline-success rounded-pill px-4">
                        {{ __('messages.all_products') }}
                    </a>
                @else
                    <h5 class="fw-bold text-gray-700">{{ __('messages.no_product_found') }}</h5>
                    <p class="text-muted mb-0">{{ __('messages.try_changing_filters') }}</p>
                @endif
            </div>
        </div>
    @endforelse
</div>

<div class="mt-5 d-flex justify-content-center">
    {{ $products->links() }}
</div>
