@extends('layouts.app')

@section('title', 'Liên hệ - Nhà Thuốc Sinh Thái')

@section('content')
<!-- Page Header -->
<section class="py-5 position-relative overflow-hidden" style="background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);">
    <div class="position-absolute top-0 end-0 rounded-circle bg-emerald-200" style="width: 300px; height: 300px; filter: blur(80px); opacity: 0.5; z-index: 0;"></div>
    <div class="container position-relative z-1">
        <div class="text-center">
            <span class="badge bg-emerald-100 text-emerald-800 px-3 py-2 rounded-pill mb-3 fw-medium">
                <i class="bi bi-headset me-1"></i> Hỗ trợ 24/7
            </span>
            <h1 class="display-5 fw-bold text-gray-900 mb-2">Liên Hệ Với Chúng Tôi</h1>
            <p class="lead text-gray-600 mb-0">Đội ngũ dược sĩ sẵn sàng giải đáp mọi thắc mắc của bạn</p>
        </div>
    </div>
</section>

<section class="py-5">
    <div class="container">
        <div class="row g-5">
            <!-- Contact Form -->
            <div class="col-lg-7">
                <div class="glass-card rounded-4 border-0 p-4 p-md-5">
                    <h3 class="fw-bold mb-1 text-gray-900">Gửi tin nhắn</h3>
                    <p class="text-gray-500 mb-4">Chúng tôi sẽ phản hồi trong vòng 24 giờ</p>
                    
                    <form action="{{ route('contact.submit') }}" method="POST">
                        @csrf
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="name" class="form-label fw-medium text-gray-700">
                                    Họ tên <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-gray-50 border-end-0 text-gray-400"><i class="bi bi-person"></i></span>
                                    <input type="text" 
                                           class="form-control bg-gray-50 border-start-0 shadow-none @error('name') is-invalid @enderror" 
                                           id="name" name="name" 
                                           value="{{ old('name') }}" required
                                           placeholder="Nguyễn Văn A">
                                    @error('name')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="email" class="form-label fw-medium text-gray-700">
                                    Email <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-gray-50 border-end-0 text-gray-400"><i class="bi bi-envelope"></i></span>
                                    <input type="email" 
                                           class="form-control bg-gray-50 border-start-0 shadow-none @error('email') is-invalid @enderror" 
                                           id="email" name="email" 
                                           value="{{ old('email') }}" required
                                           placeholder="email@example.com">
                                    @error('email')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                            
                            <div class="col-12">
                                <label for="phone" class="form-label fw-medium text-gray-700">
                                    Số điện thoại <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-gray-50 border-end-0 text-gray-400"><i class="bi bi-telephone"></i></span>
                                    <input type="tel" 
                                           class="form-control bg-gray-50 border-start-0 shadow-none @error('phone') is-invalid @enderror" 
                                           id="phone" name="phone" 
                                           value="{{ old('phone') }}" required
                                           placeholder="0901234567">
                                    @error('phone')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <small class="text-gray-400 mt-1 d-block">Định dạng: 0xxxxxxxxx hoặc +84xxxxxxxxx</small>
                            </div>
                            
                            <div class="col-12">
                                <label for="message" class="form-label fw-medium text-gray-700">
                                    Nội dung <span class="text-danger">*</span>
                                </label>
                                <textarea class="form-control bg-gray-50 shadow-none @error('message') is-invalid @enderror" 
                                          id="message" name="message" 
                                          rows="5" required
                                          placeholder="Mô tả câu hỏi hoặc yêu cầu của bạn...">{{ old('message') }}</textarea>
                                @error('message')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            
                            <div class="col-12">
                                <button type="submit" class="btn btn-lg w-100 text-white fw-bold rounded-pill shadow" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                                    <i class="bi bi-send me-2"></i>Gửi tin nhắn
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Contact Information -->
            <div class="col-lg-5">
                <!-- Info Cards -->
                <div class="d-flex flex-column gap-4 mb-4">
                    <div class="glass-card rounded-4 border-0 d-flex align-items-center gap-4 p-4">
                        <div class="bg-emerald-100 text-emerald-600 rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 56px; height: 56px;">
                            <i class="bi bi-geo-alt-fill fs-4"></i>
                        </div>
                        <div>
                            <h6 class="fw-bold text-gray-800 mb-1">Địa chỉ</h6>
                            <p class="text-gray-500 mb-0 small">123 Tôn Đức Thắng, Phường Bến Nghé, Quận 1, TP.HCM</p>
                        </div>
                    </div>
                    
                    <div class="glass-card rounded-4 border-0 d-flex align-items-center gap-4 p-4">
                        <div class="bg-blue-100 text-blue-600 rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 56px; height: 56px;">
                            <i class="bi bi-telephone-fill fs-4"></i>
                        </div>
                        <div>
                            <h6 class="fw-bold text-gray-800 mb-1">Hotline</h6>
                            <p class="text-gray-500 mb-0 small">
                                <a href="tel:19008888" class="text-decoration-none text-gray-500">1900 8888</a> (Miễn phí)
                            </p>
                        </div>
                    </div>
                    
                    <div class="glass-card rounded-4 border-0 d-flex align-items-center gap-4 p-4">
                        <div class="bg-amber-100 text-amber-600 rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 56px; height: 56px;">
                            <i class="bi bi-envelope-fill fs-4"></i>
                        </div>
                        <div>
                            <h6 class="fw-bold text-gray-800 mb-1">Email</h6>
                            <p class="text-gray-500 mb-0 small">
                                <a href="mailto:cskh@nhathuocsinhthai.vn" class="text-decoration-none text-gray-500">cskh@nhathuocsinhthai.vn</a>
                            </p>
                        </div>
                    </div>
                    
                    <div class="glass-card rounded-4 border-0 d-flex align-items-center gap-4 p-4">
                        <div class="bg-rose-100 text-rose-600 rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 56px; height: 56px;">
                            <i class="bi bi-clock-fill fs-4"></i>
                        </div>
                        <div>
                            <h6 class="fw-bold text-gray-800 mb-1">Giờ làm việc</h6>
                            <p class="text-gray-500 mb-0 small">
                                T2 - T7: 8:00 - 20:00 · CN: 8:00 - 18:00
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Google Maps -->
                <div class="glass-card rounded-4 border-0 overflow-hidden p-0">
                    <div class="ratio ratio-4x3">
                        <iframe 
                            src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3919.4967814570754!2d106.69522631533417!3d10.776530192320445!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x31752f4b3330bcc9%3A0xb3ff69197b10ec4f!2zTmd1eeG7hW4gSHXhu4csIFF14bqtbiAxLCBUaMOgbmggcGjhu5EgSOG7kyBDaMOtIE1pbmgsIFZp4buHdCBOYW0!5e0!3m2!1svi!2s!4v1234567890123!5m2!1svi!2s" 
                            style="border:0;" 
                            allowfullscreen="" 
                            loading="lazy" 
                            referrerpolicy="no-referrer-when-downgrade">
                        </iframe>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection
