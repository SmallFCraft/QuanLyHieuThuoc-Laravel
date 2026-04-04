@extends('layouts.app')

@section('title', __('messages.product_page_title'))

@section('content')
<!-- Page Header -->
<section class="py-4" style="background: linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 50%, #f8fafc 100%);">
    <div class="container">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">
            <div>
                <h1 class="fw-bold text-gray-900 mb-1">{{ __('messages.products') }}</h1>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-0 small">
                        <li class="breadcrumb-item"><a href="{{ route('home') }}" class="text-emerald-600 text-decoration-none">{{ __('messages.home') }}</a></li>
                        <li class="breadcrumb-item active" aria-current="page">{{ __('messages.products') }}</li>
                    </ol>
                </nav>
            </div>
            <!-- Search -->
            <form action="{{ route('products.search') }}" method="GET" class="d-flex gap-2" style="max-width: 400px; width: 100%;">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0 rounded-start-pill text-gray-400"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" placeholder="{{ __('messages.search_product_placeholder') }}" 
                           value="{{ $searchTerm ?? '' }}"
                           class="form-control border-start-0 rounded-end-pill shadow-none ps-0"
                           autocomplete="off"
                           style="border-color: #e5e7eb;">
                </div>
                <button type="submit" class="btn btn-glass-success rounded-pill px-4 shadow-sm fw-medium border-0">
                    {{ __('messages.search') }}
                </button>
            </form>
        </div>
    </div>
</section>

<section class="py-5">
    <div class="container">
        <div class="row g-4">
            <!-- Sidebar Filters -->
            <div class="col-lg-3">
                <div class="glass-card rounded-4 p-4 border-0 mb-4 position-sticky" style="top: 100px;">
                    <h5 class="fw-bold mb-3 d-flex align-items-center gap-2">
                        <i class="bi bi-funnel text-emerald-600"></i> {{ __('messages.categories') }}
                    </h5>
                    <div class="d-flex flex-column gap-2">
                        <a href="{{ route('products.index') }}" 
                           class="btn btn-sm text-start rounded-3 px-3 py-2 {{ !request('category') ? 'bg-emerald-500 text-white shadow-sm' : 'btn-light text-gray-700' }}">
                            <i class="bi bi-grid-3x3-gap me-2"></i>{{ __('messages.all') }}
                        </a>
                        @foreach($categories as $cat)
                            <a href="{{ route('products.index', ['category' => $cat->id]) }}" 
                               class="btn btn-sm text-start rounded-3 px-3 py-2 {{ request('category') == $cat->id ? 'bg-emerald-500 text-white shadow-sm' : 'btn-light text-gray-700' }}">
                                <i class="bi bi-tag me-2"></i>{{ $cat->name }}
                                <span class="badge bg-gray-100 text-gray-600 rounded-pill float-end">{{ $cat->products_count }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>

            <!-- Products Grid -->
            <div class="col-lg-9">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <p class="text-gray-500 mb-0 small">
                        {!! __('messages.showing_results', ['count' => '<strong class="text-gray-800">' . $products->count() . '</strong>', 'total' => '<strong class="text-gray-800">' . $products->total() . '</strong>']) !!}
                    </p>
                </div>

                <div class="row g-4">
                    @forelse($products as $product)
                            <div class="col-sm-6 col-xl-4">
                            <div class="card border-0 rounded-4 shadow-sm h-100 overflow-hidden product-card bg-white">
                                <div class="position-relative overflow-hidden bg-gray-50" style="aspect-ratio: 1/1;">
                                    @if($product->image)
                                        <img src="{{ asset('storage/' . $product->image) }}" 
                                             alt="{{ $product->name }}" 
                                             class="w-100 h-100 object-fit-contain p-2" 
                                             loading="lazy">
                                    @else
                                        <div class="w-100 h-100 d-flex align-items-center justify-content-center">
                                            <i class="bi bi-capsule text-gray-300" style="font-size: 4rem;"></i>
                                        </div>
                                    @endif
                                    
                                    @if($product->stock_quantity == 0)
                                        <span class="badge bg-danger bg-opacity-10 text-danger border border-danger-subtle position-absolute top-0 start-0 m-3 rounded-pill px-2 py-1">
                                            <i class="bi bi-x-circle me-1"></i>{{ __('messages.out_of_stock') }}
                                        </span>
                                    @endif

                                    <div class="position-absolute top-0 end-0 m-3">
                                        <a href="{{ route('products.show', $product->id) }}" class="btn btn-light btn-sm rounded-circle shadow-sm" style="width:36px; height:36px; display:flex; align-items:center; justify-content:center;" title="{{ __('messages.view_details') }}">
                                            <i class="bi bi-eye text-emerald-600"></i>
                                        </a>
                                    </div>
                                </div>
                                
                                <div class="card-body p-4 d-flex flex-column">
                                    <a href="{{ route('products.show', $product->id) }}" class="text-decoration-none">
                                        <h6 class="fw-bold text-gray-800 mb-1 text-truncate" title="{{ $product->name }}">{{ $product->name }}</h6>
                                    </a>
                                    <p class="text-emerald-600 small mb-3 fw-medium">{{ $product->category->name }}</p>
                                    
                                    <div class="mt-auto d-flex justify-content-between align-items-end">
                                        <span class="h5 mb-0 text-emerald-700 fw-bold">{{ $product->formatted_price }}</span>
                                        
                                        @if($product->stock_quantity > 0)
                                            <form action="{{ route('cart.add') }}" method="POST" class="d-inline js-add-to-cart-form">
                                                @csrf
                                                <input type="hidden" name="product_id" value="{{ $product->id }}">
                                                <input type="hidden" name="quantity" value="1">
                                                <button type="submit" class="btn text-white rounded-circle shadow-sm" style="width: 42px; height: 42px; background: #10b981;" title="{{ __('messages.add_to_cart') }}">
                                                    <i class="bi bi-cart-plus"></i>
                                                </button>
                                            </form>
                                        @else
                                            <span class="badge bg-danger bg-opacity-10 text-danger rounded-pill px-3 py-2">{{ __('messages.out_of_stock') }}</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="col-12">
                            <div class="glass-card text-center py-5 rounded-4 border-0">
                                <div class="bg-emerald-50 rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width:80px; height:80px;">
                                    <i class="bi bi-search text-emerald-500 fs-1"></i>
                                </div>
                                <h5 class="fw-bold text-gray-700">{{ __('messages.no_product_found') }}</h5>
                                <p class="text-muted mb-0">{{ __('messages.try_changing_filters') }}</p>
                            </div>
                        </div>
                    @endforelse
                </div>

                <!-- Pagination -->
                <div class="mt-5 d-flex justify-content-center">
                    {{ $products->links() }}
                </div>
            </div>
        </div>
    </div>
</section>
@endsection
