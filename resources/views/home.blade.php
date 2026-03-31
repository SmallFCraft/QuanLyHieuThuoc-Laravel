@extends('layouts.app')

@section('title', 'Nhà Thuốc Sinh Thái - Chăm sóc sức khỏe gia đình bạn')

@section('content')
<div class="home-page">
    <!-- Hero -->
    <section class="home-hero-wrap mb-4 mb-md-5">
        <div class="position-absolute top-0 start-0 w-100 h-100 z-n1" style="background: linear-gradient(165deg, rgba(240,253,244,0.88) 0%, rgba(255,255,255,0.72) 42%, rgba(236,253,245,0.85) 100%);"></div>
        <div class="home-hero-blob home-hero-blob--1" aria-hidden="true"></div>
        <div class="home-hero-blob home-hero-blob--2" aria-hidden="true"></div>
        <div class="home-hero-blob home-hero-blob--3 d-none d-md-block" aria-hidden="true"></div>

        <div class="container position-relative py-4 py-md-5 my-lg-4">
            <div class="row align-items-center g-4 g-lg-5">
                <div class="col-lg-6 text-center text-lg-start fade-in">
                    <span class="badge rounded-pill px-3 py-2 fw-medium border mb-3" style="background: rgba(255,255,255,0.65); backdrop-filter: blur(8px); color: #047857; border-color: rgba(16,185,129,0.25) !important;">
                        <i class="bi bi-patch-check-fill text-success me-1"></i> Đối tác sức khỏe tin cậy
                    </span>
                    <h1 class="home-title fw-bold mb-3 text-dark">
                        Chăm sóc sức khỏe<br>
                        <span class="text-transparent bg-clip-text d-inline-block" style="background-image: linear-gradient(90deg, #10b981, #34d399); -webkit-background-clip: text; background-clip: text;">gia đình bạn</span>
                    </h1>
                    <p class="home-lead mb-4 text-secondary mx-auto mx-lg-0" style="max-width: 32rem;">
                        Thuốc và sản phẩm chăm sóc sức khỏe chất lượng, minh bạch nguồn gốc. Đội ngũ dược sĩ sẵn sàng tư vấn khi bạn cần.
                    </p>

                    <div class="d-flex flex-column flex-sm-row gap-2 gap-sm-3 justify-content-center justify-content-lg-start mb-4">
                        <a href="{{ route('products.index') }}" class="btn btn-glass-success btn-lg px-4 px-sm-5 py-2 py-sm-3 rounded-pill fw-semibold shadow-sm d-inline-flex align-items-center justify-content-center">
                            <i class="bi bi-bag-heart me-2"></i>Mua sắm ngay
                        </a>
                        <a href="{{ route('contact') }}" class="btn btn-lg px-4 px-sm-5 py-2 py-sm-3 rounded-pill fw-semibold d-inline-flex align-items-center justify-content-center home-glass-panel text-success border-0">
                            <i class="bi bi-chat-dots me-2"></i>Nhận tư vấn
                        </a>
                    </div>

                    <div class="d-flex flex-wrap justify-content-center justify-content-lg-start gap-3 gap-md-4 pt-3 home-trust-strip border-top" style="border-color: rgba(16,185,129,0.15) !important;">
                        <div class="d-flex align-items-center gap-2 text-secondary">
                            <i class="bi bi-truck text-success fs-5"></i>
                            <span>Giao nhanh nội thành</span>
                        </div>
                        <div class="d-flex align-items-center gap-2 text-secondary">
                            <i class="bi bi-shield-check text-success fs-5"></i>
                            <span>Chính hãng, hóa đơn đầy đủ</span>
                        </div>
                        <div class="d-flex align-items-center gap-2 text-secondary">
                            <i class="bi bi-headset text-success fs-5"></i>
                            <span>Hỗ trợ 24/7</span>
                        </div>
                    </div>

                    <div class="row g-3 mt-2 mt-md-3 text-center text-lg-start">
                        <div class="col-6 col-sm-4 col-lg-auto">
                            <div class="home-glass-panel px-3 py-2 rounded-4">
                                <span class="d-block fw-bold text-success fs-4 mb-0">10k+</span>
                                <span class="small text-muted">Sản phẩm</span>
                            </div>
                        </div>
                        <div class="col-6 col-sm-4 col-lg-auto">
                            <div class="home-glass-panel px-3 py-2 rounded-4">
                                <span class="d-block fw-bold text-success fs-4 mb-0">24/7</span>
                                <span class="small text-muted">Tư vấn</span>
                            </div>
                        </div>
                        <div class="col-12 col-sm-4 col-lg-auto">
                            <div class="home-glass-panel px-3 py-2 rounded-4">
                                <span class="d-block fw-bold text-success fs-4 mb-0">FEFO</span>
                                <span class="small text-muted">Ưu tiên hạn dùng</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6 fade-in" style="animation-delay: 0.12s;">
                    <div class="glass-card border-0 p-3 p-md-4 rounded-4 shadow-sm home-hero-visual">
                        <img src="https://images.unsplash.com/photo-1576602976047-174e57a47881?auto=format&fit=crop&q=80&w=800&h=600" alt="Đội ngũ nhà thuốc" class="img-fluid rounded-4 w-100 object-fit-cover shadow-sm" style="min-height: 200px; max-height: min(52vh, 440px);">
                        <div class="d-flex justify-content-center justify-content-lg-start mt-3">
                            <div class="home-glass-panel p-3 rounded-4 d-flex align-items-center gap-3 shadow-sm w-100" style="max-width: 320px;">
                                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 48px; height: 48px; background: rgba(16,185,129,0.15); color: #059669;">
                                    <i class="bi bi-shield-check fs-4"></i>
                                </div>
                                <div class="text-start">
                                    <p class="mb-0 fw-semibold text-dark">100% kiểm soát chất lượng</p>
                                    <p class="mb-0 small text-muted">Lưu kho theo chuẩn, hạn dùng rõ ràng</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Tìm kiếm nhanh -->
    <section class="pb-4 pb-md-5 position-relative overflow-hidden" id="tim-kiem">
        <div class="home-section-mesh home-section-mesh--subtle" aria-hidden="true"></div>
        <div class="container position-relative">
            <form action="{{ route('products.search') }}" method="GET" class="home-glass-panel p-2 p-md-3 rounded-pill mx-auto shadow-sm" style="max-width: 800px; background: rgba(255,255,255,0.85); backdrop-filter: blur(16px);" role="search" aria-label="Tìm sản phẩm">
                <div class="input-group input-group-lg align-items-center">
                    <span class="input-group-text border-0 bg-transparent ps-3 ps-md-4">
                        <i class="bi bi-search text-success"></i>
                    </span>
                    <input type="text" id="home-search-q" name="q" value="{{ request('q') }}" class="form-control border-0 bg-transparent py-2 py-md-3 fs-6 fs-md-5 shadow-none" placeholder="Tìm thuốc, sản phẩm sức khỏe..." autocomplete="off" style="box-shadow: none !important; outline: none !important;">
                    <button type="submit" class="btn btn-success rounded-pill px-4 px-md-5 fw-semibold shadow-sm ms-2 me-1" style="background: #10b981; border-color: #10b981; height: 100%; min-height: 48px;">
                        <span class="d-none d-sm-inline">Tìm kiếm</span>
                        <i class="bi bi-search d-sm-none"></i>
                    </button>
                </div>
            </form>
        </div>
    </section>

    <!-- Danh mục -->
    @if($categories->isNotEmpty())
    <section class="pb-4 pb-md-5 position-relative overflow-hidden" id="danh-muc">
        <div class="home-section-mesh home-section-mesh--subtle" aria-hidden="true"></div>
        <div class="container position-relative">
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-2 mb-3 mb-md-4">
                <div>
                    <span class="text-success fw-bold text-uppercase small" style="letter-spacing: 0.06em;">Khám phá</span>
                    <h2 class="h4 fw-bold text-dark mb-0 mt-1">Danh mục sản phẩm</h2>
                </div>
                <a href="{{ route('products.index') }}" class="btn btn-sm btn-outline-success rounded-pill px-3 flex-shrink-0">
                    Tất cả <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>
            <div class="row g-3">
                @foreach($categories as $category)
                    <div class="col-6 col-md-4 col-lg-3 col-xl-2-4">
                        <a href="{{ route('products.category', $category->id) }}" class="home-glass-panel p-3 rounded-4 text-center d-block text-decoration-none transition-all hover-scale-105" style="border: 1px solid rgba(16,185,129,0.15);">
                            <div class="rounded-circle d-inline-flex mx-auto align-items-center justify-content-center mb-2" style="width: 50px; height: 50px; background: linear-gradient(135deg, rgba(16,185,129,0.15) 0%, rgba(16,185,129,0.08) 100%); color: #059669;">
                                <i class="bi bi-{{ $category->icon ?? 'grid-fill' }}" style="font-size: 1.5rem;"></i>
                            </div>
                            <span class="fw-semibold text-truncate d-block text-dark mb-1 small" title="{{ $category->name }}">{{ $category->name }}</span>
                            <span class="small text-success" style="font-size: 0.75rem;">{{ $category->available_products_count }} sản phẩm</span>
                        </a>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
    @endif

    <!-- Sản phẩm nổi bật -->
    <section class="py-4 py-md-5 position-relative overflow-hidden" id="noi-bat">
        <div class="home-section-mesh" aria-hidden="true"></div>
        <div class="container position-relative">
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center mb-4 gap-3">
                <div class="text-center text-sm-start w-100 w-sm-auto">
                    <span class="text-success fw-bold text-uppercase small">Gợi ý hôm nay</span>
                    <h2 class="h3 fw-bold text-dark mt-1 mb-0">Sản phẩm nổi bật</h2>
                    <p class="text-muted small mb-0 mt-1 d-none d-md-block">Cập nhật mới, còn hạn dùng và đủ tồn kho.</p>
                </div>
                <a href="{{ route('products.index') }}" class="btn btn-sm btn-outline-success rounded-pill px-3 flex-shrink-0">
                    Tất cả <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>

            <div class="row g-3 g-md-4">
                @forelse($featuredProducts as $product)
                    <div class="col-12 col-sm-6 col-lg-4 col-xl-3">
                        <div class="product-card h-100 border-0">
                            <div class="position-relative overflow-hidden home-product-media" style="border-radius: 16px 16px 0 0; aspect-ratio: 1/1;">
                                @if($product->image)
                                    <img src="{{ asset('storage/' . $product->image) }}"
                                         class="w-100 h-100 object-fit-contain p-2"
                                         alt="{{ $product->name }}"
                                         loading="lazy">
                                @else
                                    <div class="w-100 h-100 d-flex align-items-center justify-content-center">
                                        <i class="bi bi-capsule text-secondary opacity-25" style="font-size: 4rem;"></i>
                                    </div>
                                @endif
                                <div class="position-absolute top-0 start-0 m-2 m-md-3 d-flex flex-column gap-2">
                                    @if($product->stock_quantity == 0)
                                        <span class="badge bg-danger bg-opacity-10 text-danger border border-danger-subtle px-2 py-1 rounded-pill">
                                            <i class="bi bi-x-circle me-1"></i>Hết hàng
                                        </span>
                                    @elseif($product->stock_quantity <= $product->reorder_point)
                                        <span class="badge bg-warning bg-opacity-10 text-warning-emphasis border border-warning-subtle px-2 py-1 rounded-pill">
                                            <i class="bi bi-exclamation-triangle me-1"></i>Sắp hết
                                        </span>
                                    @endif
                                </div>
                                <div class="position-absolute top-0 end-0 m-2 m-md-3">
                                    <a href="{{ route('products.show', $product->id) }}" class="btn btn-light btn-sm rounded-circle shadow-sm text-success d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;" title="Xem chi tiết">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                </div>
                            </div>
                            <div class="p-3 p-md-4 d-flex flex-column flex-grow-1">
                                <a href="{{ route('products.show', $product->id) }}" class="text-decoration-none">
                                    <h3 class="h6 fw-bold mb-1 text-dark text-truncate" title="{{ $product->name }}">{{ $product->name }}</h3>
                                </a>
                                <p class="text-success small mb-2 fw-medium mb-0">{{ $product->category->name }}</p>
                                <div class="mt-auto d-flex justify-content-between align-items-end pt-3 gap-2">
                                    <div class="min-w-0">
                                        <span class="text-muted small text-decoration-line-through d-block mb-1">
                                            {{ format_currency_vi($product->price * 1.1) }}
                                        </span>
                                        <span class="h5 mb-0 text-success fw-bold">
                                            {{ format_currency_vi($product->price) }}
                                        </span>
                                    </div>
                                    <form action="{{ route('cart.add') }}" method="POST" class="d-inline flex-shrink-0 js-add-to-cart-form">
                                        @csrf
                                        <input type="hidden" name="product_id" value="{{ $product->id }}">
                                        <input type="hidden" name="quantity" value="1">
                                        <button type="submit" class="btn text-white rounded-circle shadow-sm d-flex align-items-center justify-content-center" style="width: 46px; height: 46px; background: #10b981;" {{ $product->stock_quantity == 0 ? 'disabled' : '' }} title="Thêm vào giỏ">
                                            <i class="bi bi-cart-plus fs-5"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="col-12">
                        <div class="text-center py-5 glass-card rounded-4">
                            <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 80px; height: 80px; background: rgba(16,185,129,0.12);">
                                <i class="bi bi-box2 text-success fs-1"></i>
                            </div>
                            <h3 class="h5 fw-bold text-dark">Chưa có sản phẩm</h3>
                            <p class="text-muted mb-0 px-3">Hệ thống đang cập nhật. Vui lòng quay lại sau hoặc xem danh mục.</p>
                            <a href="{{ route('products.index') }}" class="btn btn-success rounded-pill mt-3 px-4" style="background: #10b981; border-color: #10b981;">Đến cửa hàng</a>
                        </div>
                    </div>
                @endforelse
            </div>
        </div>
    </section>

    <!-- Vì sao chọn chúng tôi -->
    <section class="py-4 py-md-5 position-relative overflow-hidden">
        <div class="home-section-mesh" aria-hidden="true"></div>
        <div class="container position-relative py-2">
            <div class="text-center mb-4 mb-md-5">
                <span class="text-success fw-bold text-uppercase small">Vì sao chọn chúng tôi</span>
                <h2 class="h3 fw-bold text-dark mt-1">Dịch vụ rõ ràng, an tâm mua dùng</h2>
            </div>
            <div class="row g-3 g-md-4">
                <div class="col-md-4">
                    <div class="glass-card text-center h-100 p-4 p-md-5 rounded-4 border-0 hover-lift">
                        <div class="rounded-circle mx-auto mb-3 d-flex align-items-center justify-content-center" style="width: 72px; height: 72px; background: rgba(16,185,129,0.15); color: #059669;">
                            <i class="bi bi-shield-check fs-2"></i>
                        </div>
                        <h3 class="h5 fw-bold mb-2 text-dark">Chính hãng, minh bạch</h3>
                        <p class="text-secondary mb-0 small">Nguồn nhập rõ ràng, hóa đơn VAT khi cần, lưu vết theo lô và hạn dùng.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="glass-card text-center h-100 p-4 p-md-5 rounded-4 border-0 hover-lift">
                        <div class="rounded-circle mx-auto mb-3 d-flex align-items-center justify-content-center" style="width: 72px; height: 72px; background: rgba(16,185,129,0.12); color: #059669;">
                            <i class="bi bi-truck fs-2"></i>
                        </div>
                        <h3 class="h5 fw-bold mb-2 text-dark">Giao hàng linh hoạt</h3>
                        <p class="text-secondary mb-0 small">Ưu tiên đơn gấp nội thành, đóng gói kín đáo, bảo quản phù hợp.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="glass-card text-center h-100 p-4 p-md-5 rounded-4 border-0 hover-lift">
                        <div class="rounded-circle mx-auto mb-3 d-flex align-items-center justify-content-center" style="width: 72px; height: 72px; background: rgba(16,185,129,0.1); color: #059669;">
                            <i class="bi bi-heart-pulse fs-2"></i>
                        </div>
                        <h3 class="h5 fw-bold mb-2 text-dark">Tư vấn tận tâm</h3>
                        <p class="text-secondary mb-0 small">Dược sĩ hỗ trợ chọn sản phẩm phù hợp và nhắc lịch dùng thuận tiện.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Quy trình -->
    <section class="py-4 py-md-5 position-relative overflow-hidden">
        <div class="home-section-mesh home-section-mesh--subtle" aria-hidden="true"></div>
        <div class="container position-relative">
            <div class="text-center mb-4 mb-md-5">
                <span class="text-success fw-bold text-uppercase small">Chỉ vài bước</span>
                <h2 class="h3 fw-bold text-dark mt-1">Mua hàng đơn giản</h2>
            </div>
            <div class="row g-3 g-md-4">
                <div class="col-md-4">
                    <div class="home-glass-panel home-step-card h-100 rounded-4">
                        <span class="home-step-num">1</span>
                        <h3 class="h6 fw-bold text-dark">Tìm &amp; chọn sản phẩm</h3>
                        <p class="small text-secondary mb-0">Dùng ô tìm kiếm hoặc lọc theo danh mục; xem chi tiết và hạn dùng trước khi thêm giỏ.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="home-glass-panel home-step-card h-100 rounded-4">
                        <span class="home-step-num">2</span>
                        <h3 class="h6 fw-bold text-dark">Giỏ hàng &amp; thanh toán</h3>
                        <p class="small text-secondary mb-0">Kiểm tra số lượng, đăng nhập và hoàn tất đơn — hệ thống trừ tồn theo chuẩn an toàn.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="home-glass-panel home-step-card h-100 rounded-4">
                        <span class="home-step-num">3</span>
                        <h3 class="h6 fw-bold text-dark">Nhận hàng &amp; hỗ trợ</h3>
                        <p class="small text-secondary mb-0">Theo dõi trạng thái đơn trong tài khoản; cần tư vấn thêm cứ liên hệ nhà thuốc.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- FAQ (details/summary: hiển thị nội dung ổn định + glass rõ nét) -->
    <section class="home-faq-section py-4 py-md-5 position-relative overflow-hidden">
        <div class="home-faq-section__mesh" aria-hidden="true"></div>
        <div class="container position-relative" style="z-index: 1;">
            <div class="row justify-content-center">
                <div class="col-lg-9 col-xl-8">
                    <div class="text-center mb-4">
                        <span class="text-success fw-bold text-uppercase small" style="letter-spacing: 0.06em;">Câu hỏi thường gặp</span>
                        <h2 class="h3 fw-bold text-dark mt-1">Thông tin hữu ích</h2>
                    </div>
                    <div class="home-faq-stack d-flex flex-column gap-3">
                        <details class="home-faq-item">
                            <summary class="home-faq-summary">Làm sao biết sản phẩm còn hạn và đủ tồn?</summary>
                            <div class="home-faq-body">
                                Trên trang chi tiết sản phẩm hiển thị tồn kho; chúng tôi ưu tiên xuất theo FEFO (hết hạn trước — xuất trước). Sản phẩm hết hạn không được bán.
                            </div>
                        </details>
                        <details class="home-faq-item">
                            <summary class="home-faq-summary">Tôi có thể đổi ý sau khi đặt hàng?</summary>
                            <div class="home-faq-body">
                                Đơn ở trạng thái chờ xử lý có thể được hủy theo quy định; sau khi giao hàng, vui lòng liên hệ hotline hoặc form liên hệ để được hỗ trợ từng trường hợp.
                            </div>
                        </details>
                        <details class="home-faq-item">
                            <summary class="home-faq-summary">Ai tư vấn khi tôi không chắc nên dùng thuốc nào?</summary>
                            <div class="home-faq-body">
                                Bạn có thể gửi câu hỏi qua trang <a href="{{ route('contact') }}" class="text-success fw-semibold text-decoration-none">liên hệ</a> hoặc kênh hỗ trợ trực tuyến. Dược sĩ chỉ tư vấn thông tin chung; với bệnh lý cần khám, hãy đến cơ sở y tế.
                            </div>
                        </details>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA -->
    <section class="py-4 py-md-5 mb-4 mb-md-5 position-relative overflow-hidden">
        <div class="home-section-mesh home-section-mesh--subtle" aria-hidden="true"></div>
        <div class="container position-relative">
            <div class="home-cta-gradient home-cta-glass rounded-4 p-4 p-md-5 position-relative overflow-hidden">
                <div class="position-absolute top-0 end-0 rounded-circle bg-white opacity-25" style="width: 200px; height: 200px; transform: translate(35%, -35%);" aria-hidden="true"></div>
                <div class="position-absolute bottom-0 start-0 rounded-circle bg-white opacity-20" style="width: 140px; height: 140px; transform: translate(-30%, 30%);" aria-hidden="true"></div>
                <div class="row align-items-center position-relative gy-4">
                    <div class="col-lg-8 text-center text-lg-start">
                        <h2 class="home-cta-heading fw-bold text-white mb-2" style="text-shadow: 0 1px 2px rgba(0,0,0,0.08);">Sức khỏe của bạn là ưu tiên</h2>
                        <p class="text-white mb-0 opacity-90 pe-lg-4">
                            Đăng ký tài khoản để theo dõi đơn hàng, lưu địa chỉ và nhận thông tin khuyến mãi hợp lệ.
                        </p>
                    </div>
                    <div class="col-lg-4 text-center text-lg-end">
                        @guest
                            <a href="{{ route('register') }}" class="btn btn-light btn-lg text-success fw-bold px-4 px-lg-5 py-3 rounded-pill shadow">
                                Tạo tài khoản
                            </a>
                        @else
                            <a href="{{ route('products.index') }}" class="btn btn-light btn-lg text-success fw-bold px-4 px-lg-5 py-3 rounded-pill shadow">
                                Tiếp tục mua sắm
                            </a>
                        @endguest
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
@endsection
