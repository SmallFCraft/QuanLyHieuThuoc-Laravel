{{-- Thẻ người dùng: ảnh đại diện, danh tính, số liệu nhanh. --}}
<div class="profile-card text-center mb-3" data-profile-user-card>
    <div class="profile-avatar-editor mb-3">
        <x-user-avatar :user="$user" :size="96" ring class="profile-avatar--lg" />

        <button type="button"
                class="profile-avatar-editor__button"
                data-avatar-trigger
                aria-label="{{ __('messages.profile.avatar_change') }}"
                title="{{ __('messages.profile.avatar_change') }}">
            <i class="bi bi-camera-fill" aria-hidden="true"></i>
        </button>
    </div>

    <h2 class="h6 fw-bold text-gray-900 mb-1">{{ $user->name }}</h2>
    <p class="text-gray-500 small mb-2 text-break">{{ $user->email }}</p>
    <span class="badge bg-emerald-100 text-emerald-800 rounded-pill px-3 py-2">
        <i class="bi bi-shield-check me-1" aria-hidden="true"></i>{{ role_text($user->role) }}
    </span>

    {{-- Form ẩn: input file nằm ngoài luồng tab, nút camera ở trên mở nó.
         enctype bắt buộc, thiếu thì file không bao giờ tới server. --}}
    <form method="POST"
          action="{{ route('profile.avatar.update') }}"
          enctype="multipart/form-data"
          class="d-none"
          data-avatar-form>
        @csrf
        <input type="file"
               name="avatar"
               accept="image/jpeg,image/jpg,image/png"
               data-avatar-input
               data-max-size-kb="{{ (int) config('uploads.images.max_size', 5120) }}"
               data-too-large-message="{{ __('messages.profile.avatar_too_large', ['size' => round((int) config('uploads.images.max_size', 5120) / 1024, 1)]) }}"
               data-bad-type-message="{{ __('messages.profile.avatar_bad_type') }}">
    </form>

    <p class="profile-field__hint mt-2 mb-0">
        {{ __('messages.profile.avatar_hint', ['size' => round((int) config('uploads.images.max_size', 5120) / 1024, 1)]) }}
    </p>

    @error('avatar')
        <p class="invalid-feedback d-block text-center mb-0">{{ $message }}</p>
    @enderror

    @if($user->avatar)
        <form method="POST" action="{{ route('profile.avatar.destroy') }}" class="mt-2" data-avatar-remove-form>
            @csrf
            @method('delete')
            <button type="submit"
                    class="btn btn-sm profile-btn-danger"
                    data-confirm="{{ __('messages.profile.avatar_remove_confirm') }}">
                <i class="bi bi-trash3 me-1" aria-hidden="true"></i>{{ __('messages.profile.avatar_remove') }}
            </button>
        </form>
    @endif

    <div class="row g-2 mt-3 pt-3 border-top">
        <div class="col-4">
            <div class="profile-stat__value" style="font-size: 1.1rem;">{{ $orderCounts->sum() }}</div>
            <small class="text-gray-500">{{ __('messages.profile.orders_count') }}</small>
        </div>
        <div class="col-4">
            <div class="profile-stat__value" style="font-size: 1.1rem;">{{ $orderCounts['Completed'] ?? 0 }}</div>
            <small class="text-gray-500">{{ __('messages.profile.orders_completed') }}</small>
        </div>
        <div class="col-4">
            <div class="profile-stat__value text-rose-600" style="font-size: 1.1rem;" data-favorites-count>{{ $favoriteItems->total() }}</div>
            <small class="text-gray-500">{{ __('messages.profile.favorites_count') }}</small>
        </div>
    </div>
</div>
