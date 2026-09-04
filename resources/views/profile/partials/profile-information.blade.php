<div class="profile-card">
    <div class="profile-card__head">
        <span class="profile-card__icon"><i class="bi bi-person-fill"></i></span>
        <div class="flex-grow-1">
            <h2 class="profile-card__title">{{ __('messages.profile.info_title') }}</h2>
            <p class="profile-card__subtitle">{{ __('messages.profile.info_desc') }}</p>
        </div>
        @if($user->email_verified_at)
            <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-3 py-2 d-none d-md-inline">
                <i class="bi bi-shield-check me-1" aria-hidden="true"></i>{{ __('messages.profile.verified') }}
            </span>
        @endif
    </div>

    @if (session('status') === 'profile-updated')
        <div class="profile-flash mb-4" role="status">
            <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
            <span>{{ __('messages.profile.update_success') }}</span>
        </div>
    @endif

    {{-- data-profile-form: profile.js gắn kiểm tra khi blur và tự lưu nháp vào
         localStorage, xoá nháp sau khi submit thành công. --}}
    <form method="POST"
          action="{{ route('profile.update') }}"
          id="profileForm"
          data-profile-form
          data-draft-key="pharmacy.profileDraft.{{ $user->MaKH }}"
          novalidate>
        @csrf
        @method('patch')

        <div class="row g-3">
            <div class="col-md-6">
                <label for="name" class="form-label form-label--required">
                    <i class="bi bi-person" aria-hidden="true"></i>{{ __('messages.profile.name') }}
                </label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-person-badge" aria-hidden="true"></i></span>
                    <input type="text"
                           class="form-control @error('name') is-invalid @enderror"
                           id="name"
                           name="name"
                           value="{{ old('name', $user->name) }}"
                           placeholder="{{ __('messages.profile.name_placeholder') }}"
                           autocomplete="name"
                           data-validate="required"
                           data-message="{{ __('messages.profile.validation_name_required') }}"
                           required>
                </div>
                <div class="invalid-feedback" data-error-for="name">@error('name'){{ $message }}@enderror</div>
            </div>

            <div class="col-md-6">
                <label for="email" class="form-label form-label--required">
                    <i class="bi bi-envelope" aria-hidden="true"></i>{{ __('messages.profile.email') }}
                </label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-envelope-at" aria-hidden="true"></i></span>
                    <input type="email"
                           class="form-control @error('email') is-invalid @enderror"
                           id="email"
                           name="email"
                           value="{{ old('email', $user->email) }}"
                           placeholder="email@example.com"
                           autocomplete="email"
                           inputmode="email"
                           data-validate="email"
                           data-message="{{ __('messages.profile.validation_email_invalid') }}"
                           required>
                </div>
                <div class="invalid-feedback" data-error-for="email">@error('email'){{ $message }}@enderror</div>

                @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                    <div class="alert alert-warning border-0 mt-2 py-2 px-3" style="background: rgba(251,191,36,0.12);">
                        <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
                        <small>{{ __('messages.profile.email_unverified') }}
                            <a href="{{ route('verification.send') }}" class="text-emerald-600 fw-semibold text-decoration-none">{{ __('messages.profile.resend_email') }}</a>
                        </small>
                    </div>
                @endif
            </div>

            <div class="col-md-6">
                <label for="birth_date" class="form-label">
                    <i class="bi bi-calendar-event" aria-hidden="true"></i>{{ __('messages.profile.birth_date') }}
                </label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-cake2" aria-hidden="true"></i></span>
                    <input type="date"
                           class="form-control @error('birth_date') is-invalid @enderror"
                           id="birth_date"
                           name="birth_date"
                           value="{{ old('birth_date', $user->birth_date?->format('Y-m-d')) }}"
                           max="{{ now()->format('Y-m-d') }}"
                           data-validate="past-date"
                           data-message="{{ __('messages.profile.validation_birth_date_future') }}">
                </div>
                <div class="invalid-feedback" data-error-for="birth_date">@error('birth_date'){{ $message }}@enderror</div>
            </div>

            <div class="col-md-6">
                <label for="gender" class="form-label">
                    <i class="bi bi-gender-ambiguous" aria-hidden="true"></i>{{ __('messages.profile.gender') }}
                </label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-person-standing" aria-hidden="true"></i></span>
                    <select class="form-select @error('gender') is-invalid @enderror" id="gender" name="gender">
                        <option value="">{{ __('messages.profile.select') }}</option>
                        <option value="male" @selected(old('gender', $user->gender) === 'male')>{{ __('messages.profile.male') }}</option>
                        <option value="female" @selected(old('gender', $user->gender) === 'female')>{{ __('messages.profile.female') }}</option>
                        <option value="other" @selected(old('gender', $user->gender) === 'other')>{{ __('messages.profile.other') }}</option>
                    </select>
                </div>
                <div class="invalid-feedback" data-error-for="gender">@error('gender'){{ $message }}@enderror</div>
            </div>

            <div class="col-md-6">
                <label for="phone" class="form-label">
                    <i class="bi bi-telephone" aria-hidden="true"></i>{{ __('messages.profile.phone') }}
                </label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-phone" aria-hidden="true"></i></span>
                    <input type="tel"
                           class="form-control @error('phone') is-invalid @enderror"
                           id="phone"
                           name="phone"
                           value="{{ old('phone', $user->phone) }}"
                           placeholder="0xxxxxxxxx"
                           inputmode="numeric"
                           maxlength="10"
                           autocomplete="tel"
                           data-validate="phone-vn"
                           data-message="{{ __('messages.profile.validation_phone_invalid') }}">
                </div>
                <div class="invalid-feedback" data-error-for="phone">@error('phone'){{ $message }}@enderror</div>
                <span class="profile-field__hint">{{ __('messages.profile.phone_hint') }}</span>
            </div>

            <div class="col-md-6">
                <label for="address" class="form-label">
                    <i class="bi bi-geo-alt" aria-hidden="true"></i>{{ __('messages.profile.address') }}
                </label>
                <textarea class="form-control @error('address') is-invalid @enderror"
                          id="address"
                          name="address"
                          rows="3"
                          maxlength="500"
                          autocomplete="street-address"
                          placeholder="{{ __('messages.profile.address_placeholder') }}">{{ old('address', $user->address) }}</textarea>
                <div class="invalid-feedback" data-error-for="address">@error('address'){{ $message }}@enderror</div>
            </div>
        </div>

        <div class="d-flex flex-column flex-sm-row align-items-sm-center gap-2 mt-4 pt-3 border-top">
            <button type="submit" class="btn profile-btn-primary" data-profile-submit>
                <i class="bi bi-check-lg me-1" aria-hidden="true"></i>{{ __('messages.profile.save_changes') }}
            </button>
            <button type="button" class="btn profile-btn-ghost d-none" data-draft-discard>
                <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>{{ __('messages.profile.draft_discard') }}
            </button>
        </div>
    </form>
</div>

<div class="profile-card">
    <div class="row g-3">
        <div class="col-md-4">
            <div class="d-flex align-items-center gap-2">
                <span class="profile-overview-item__icon"><i class="bi bi-calendar-check"></i></span>
                <div>
                    <small class="text-gray-500 d-block" style="font-size: 0.75rem;">{{ __('messages.profile.joined_date') }}</small>
                    <p class="fw-semibold text-gray-900 mb-0 small">{{ $user->created_at->format('d/m/Y') }}</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="d-flex align-items-center gap-2">
                <span class="profile-overview-item__icon"><i class="bi bi-clock-history"></i></span>
                <div>
                    <small class="text-gray-500 d-block" style="font-size: 0.75rem;">{{ __('messages.profile.last_updated') }}</small>
                    <p class="fw-semibold text-gray-900 mb-0 small">{{ $user->updated_at->diffForHumans() }}</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="d-flex align-items-center gap-2">
                <span class="profile-overview-item__icon"><i class="bi bi-shield-check"></i></span>
                <div>
                    <small class="text-gray-500 d-block" style="font-size: 0.75rem;">{{ __('messages.profile.status') }}</small>
                    <p class="fw-semibold {{ $user->status === 'active' ? 'text-success' : 'text-secondary' }} mb-0 small">
                        <i class="bi bi-circle-fill me-1" style="font-size: 0.5rem;" aria-hidden="true"></i>{{ $user->status === 'active' ? __('messages.auth.status_active') : __('messages.auth.status_inactive') }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
