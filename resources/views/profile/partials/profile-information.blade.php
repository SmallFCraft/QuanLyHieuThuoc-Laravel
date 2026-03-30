<!-- Profile Information Section -->
<div class="glass-card rounded-4 border-0 p-4 p-md-5 mb-4">
    <div class="d-flex flex-column flex-md-row align-items-md-center gap-3 mb-4">
        <div class="bg-emerald-100 text-emerald-600 rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 icon-circle-md" >
            <i class="bi bi-person-fill fs-3"></i>
        </div>
        <div class="flex-grow-1">
            <h5 class="fw-bold text-gray-900 mb-1">Thông Tin Cá Nhân</h5>
            <p class="text-gray-500 small mb-0">Cập nhật thông tin tài khoản của bạn</p>
        </div>
        <div class="d-none d-md-block">
            <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-3 py-2">
                <i class="bi bi-shield-check me-1"></i>Đã xác thực
            </span>
        </div>
    </div>

    <form method="POST" action="{{ route('profile.update') }}" id="profileForm">
        @csrf
        @method('patch')

        <div class="row g-3">
            <div class="col-md-6">
                <label for="name" class="form-label fw-medium text-gray-700 mb-2">
                    <i class="bi bi-person text-emerald-600 me-1"></i>Họ và tên
                </label>
                <div class="input-group">
                    <span class="input-group-text bg-gray-50 border-end-0">
                        <i class="bi bi-person-badge text-gray-400"></i>
                    </span>
                    <input type="text" 
                           class="form-control bg-gray-50 border-start-0 rounded-end-3 shadow-none @error('name') is-invalid @enderror"
                           id="name" 
                           name="name" 
                           value="{{ old('name', $user->name) }}" 
                           placeholder="Nhập họ và tên"
                           required>
                    @error('name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="col-md-6">
                <label for="email" class="form-label fw-medium text-gray-700 mb-2">
                    <i class="bi bi-envelope text-emerald-600 me-1"></i>Địa chỉ Email
                </label>
                <div class="input-group">
                    <span class="input-group-text bg-gray-50 border-end-0">
                        <i class="bi bi-envelope-at text-gray-400"></i>
                    </span>
                    <input type="email" 
                           class="form-control bg-gray-50 border-start-0 rounded-end-3 shadow-none @error('email') is-invalid @enderror"
                           id="email" 
                           name="email" 
                           value="{{ old('email', $user->email) }}" 
                           placeholder="email@example.com"
                           required>
                    @error('email')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                    <div class="alert alert-warning border-0 mt-2 py-2 px-3" style="background: rgba(251,191,36,0.1);">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        <small>Email chưa được xác thực. 
                            <a href="{{ route('verification.send') }}" class="text-emerald-600 fw-semibold text-decoration-none">Gửi lại email xác thực</a>
                        </small>
                    </div>
                @endif
            </div>

            <div class="col-md-6">
                <label for="birth_date" class="form-label fw-medium text-gray-700 mb-2">
                    <i class="bi bi-calendar-event text-emerald-600 me-1"></i>Ngày sinh
                </label>
                <div class="input-group">
                    <span class="input-group-text bg-gray-50 border-end-0">
                        <i class="bi bi-cake2 text-gray-400"></i>
                    </span>
                    <input type="date"
                           class="form-control bg-gray-50 border-start-0 rounded-end-3 shadow-none @error('birth_date') is-invalid @enderror"
                           id="birth_date"
                           name="birth_date"
                           value="{{ old('birth_date', $user->birth_date?->format('Y-m-d')) }}"
                           max="{{ now()->format('Y-m-d') }}">
                    @error('birth_date')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="col-md-6">
                <label for="gender" class="form-label fw-medium text-gray-700 mb-2">
                    <i class="bi bi-gender-ambiguous text-emerald-600 me-1"></i>Giới tính
                </label>
                <div class="input-group">
                    <span class="input-group-text bg-gray-50 border-end-0">
                        <i class="bi bi-person-standing text-gray-400"></i>
                    </span>
                    <select class="form-select bg-gray-50 border-start-0 rounded-end-3 shadow-none @error('gender') is-invalid @enderror"
                            id="gender"
                            name="gender">
                        <option value="">— Chọn —</option>
                        <option value="male" @selected(old('gender', $user->gender) === 'male')>Nam</option>
                        <option value="female" @selected(old('gender', $user->gender) === 'female')>Nữ</option>
                        <option value="other" @selected(old('gender', $user->gender) === 'other')>Khác</option>
                    </select>
                    @error('gender')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="col-md-6">
                <label for="phone" class="form-label fw-medium text-gray-700 mb-2">
                    <i class="bi bi-telephone text-emerald-600 me-1"></i>Số điện thoại
                </label>
                <div class="input-group">
                    <span class="input-group-text bg-gray-50 border-end-0">
                        <i class="bi bi-phone text-gray-400"></i>
                    </span>
                    <input type="tel"
                           class="form-control bg-gray-50 border-start-0 rounded-end-3 shadow-none @error('phone') is-invalid @enderror"
                           id="phone"
                           name="phone"
                           value="{{ old('phone', $user->phone) }}"
                           placeholder="0xxxxxxxxx"
                           inputmode="numeric"
                           maxlength="10"
                           pattern="0[0-9]{9}"
                           autocomplete="tel">
                    @error('phone')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <small class="text-muted">10 chữ số, bắt đầu bằng 0</small>
            </div>

            <div class="col-12">
                <label for="address" class="form-label fw-medium text-gray-700 mb-2">
                    <i class="bi bi-geo-alt text-emerald-600 me-1"></i>Địa chỉ
                </label>
                <div class="input-group align-items-start">
                    <span class="input-group-text bg-gray-50 border-end-0 align-self-stretch">
                        <i class="bi bi-house text-gray-400 mt-1"></i>
                    </span>
                    <textarea class="form-control bg-gray-50 border-start-0 rounded-end-3 shadow-none @error('address') is-invalid @enderror"
                              id="address"
                              name="address"
                              rows="3"
                              placeholder="Số nhà, đường, phường/xã, quận/huyện, tỉnh/thành phố">{{ old('address', $user->address) }}</textarea>
                    @error('address')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="col-12">
                <div class="p-3 rounded-3 bg-emerald-50">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="d-flex align-items-center gap-2">
                                <div class="bg-white rounded-2 p-2">
                                    <i class="bi bi-calendar-check text-emerald-600"></i>
                                </div>
                                <div>
                                    <small class="text-gray-500 d-block" style="font-size: 0.75rem;">Ngày tham gia</small>
                                    <p class="fw-semibold text-gray-900 mb-0 small">{{ $user->created_at->format('d/m/Y') }}</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="d-flex align-items-center gap-2">
                                <div class="bg-white rounded-2 p-2">
                                    <i class="bi bi-clock-history text-emerald-600"></i>
                                </div>
                                <div>
                                    <small class="text-gray-500 d-block" style="font-size: 0.75rem;">Cập nhật lần cuối</small>
                                    <p class="fw-semibold text-gray-900 mb-0 small">{{ $user->updated_at->diffForHumans() }}</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="d-flex align-items-center gap-2">
                                <div class="bg-white rounded-2 p-2">
                                    <i class="bi bi-shield-check text-emerald-600"></i>
                                </div>
                                <div>
                                    <small class="text-gray-500 d-block" style="font-size: 0.75rem;">Trạng thái</small>
                                    <p class="fw-semibold text-success mb-0 small">
                                        <i class="bi bi-circle-fill me-1" style="font-size: 0.5rem;"></i>{{ ucfirst($user->status) }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex flex-column flex-sm-row align-items-sm-center gap-3 mt-4 pt-3 border-top">
            <button type="submit" class="btn btn-glass-primary rounded-pill px-4 py-2 fw-medium order-sm-1">
                <i class="bi bi-check-lg me-1"></i>Lưu thay đổi
            </button>
            
            @if (session('status') === 'profile-updated')
                <div class="alert alert-glass-success border-0 mb-0 py-2 px-3 rounded-pill animate-fade-in order-sm-2">
                    <i class="bi bi-check-circle-fill me-1"></i>
                    <span class="fw-medium small">Đã cập nhật thành công!</span>
                </div>
            @endif
        </div>
    </form>
</div>

<!-- Account Stats -->
<div class="row g-3 g-md-4">
    <div class="col-6 col-lg-3">
        <div class="metric-card text-center h-100">
            <div class="metric-icon bg-emerald-100 text-emerald-600 mx-auto">
                <i class="bi bi-bag-check"></i>
            </div>
            <div class="metric-value text-emerald-600">{{ auth()->user()->orders()->count() }}</div>
            <div class="metric-label">Tổng đơn hàng</div>
        </div>
    </div>
    
    <div class="col-6 col-lg-3">
        <div class="metric-card text-center h-100">
            <div class="metric-icon bg-warning bg-opacity-10 text-warning mx-auto">
                <i class="bi bi-clock-history"></i>
            </div>
            <div class="metric-value text-warning">{{ auth()->user()->orders()->where('status', 'Pending')->count() }}</div>
            <div class="metric-label">Đang xử lý</div>
        </div>
    </div>
    
    <div class="col-6 col-lg-3">
        <div class="metric-card text-center h-100">
            <div class="metric-icon bg-info bg-opacity-10 text-info mx-auto">
                <i class="bi bi-truck"></i>
            </div>
            <div class="metric-value text-info">{{ auth()->user()->orders()->where('status', 'Delivering')->count() }}</div>
            <div class="metric-label">Đang giao</div>
        </div>
    </div>
    
    <div class="col-6 col-lg-3">
        <div class="metric-card text-center h-100">
            <div class="metric-icon bg-success bg-opacity-10 text-success mx-auto">
                <i class="bi bi-check-circle"></i>
            </div>
            <div class="metric-value text-success">{{ auth()->user()->orders()->where('status', 'Completed')->count() }}</div>
            <div class="metric-label">Hoàn thành</div>
        </div>
    </div>
</div>

<style>
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}
.animate-fade-in {
    animation: fadeIn 0.3s ease-in;
}
.input-group-text {
    transition: all 0.3s ease;
}
.form-control:focus + .input-group-text,
.input-group-text + .form-control:focus {
    border-color: #10b981;
}
</style>
