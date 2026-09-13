@php
    $healthProfile = $user->healthProfile;
    $tagValue = fn (string $field) => old($field, $healthProfile?->{$field} ?? []);
    $hasConsent = (bool) $healthProfile?->hasConsented();

    // After a health-form validation redirect the ticket is in `_old_input`, and a missing
    // checkbox key there means the user left it unticked; falling back to the stored
    // profile would silently re-tick a box the user just cleared.
    //
    // The page hosts the profile-info, password and avatar forms too, and any of their
    // errors flashes `_old_input` as well. Keying off `_old_input` alone would untick both
    // consent boxes after a mistyped phone number — and the next health save would then
    // post neither box and revoke consent the user never touched.
    $hasHealthOldInput = session()->hasAny([
        '_old_input.consent',
        '_old_input.ai_consent',
        '_old_input.allergies',
        '_old_input.chronic_conditions',
        '_old_input.current_medications',
        '_old_input.is_pregnant',
        '_old_input.is_breastfeeding',
        '_old_input.weight_kg',
        '_old_input.notes',
    ]);

    $consentChecked = old('consent', $hasConsent && ! $hasHealthOldInput);
    $aiConsentChecked = old('ai_consent', (bool) $healthProfile?->hasAiConsent() && ! $hasHealthOldInput);
@endphp

<div class="profile-card health-profile-card mb-3" id="health-profile">
    <div class="profile-card__head">
        <span class="profile-card__icon"><i class="bi bi-heart-pulse-fill"></i></span>
        <div class="flex-grow-1">
            <h2 class="profile-card__title">{{ __('messages.health_profile.title') }}</h2>
            <p class="profile-card__subtitle">{{ __('messages.health_profile.desc') }}</p>
        </div>
        {{-- text-success on the 10%-opacity tint measures 3.97:1; hp-badge-secure
             darkens the text so the label clears 4.5:1. --}}
        <span class="badge bg-success bg-opacity-10 rounded-pill px-3 py-2 hp-badge-secure flex-shrink-0">
            <i class="bi bi-lock-fill me-1" aria-hidden="true"></i>{{ __('messages.health_profile.encrypted_badge') }}
        </span>
    </div>

    @if (session('status') === 'health-profile-updated')
        <div class="profile-flash mb-4" role="status">
            <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
            <span>{{ __('messages.health_profile.update_success') }}</span>
        </div>
    @endif

    @if (session('status') === 'health-profile-deleted')
        <div class="profile-flash mb-4" role="status">
            <i class="bi bi-trash3-fill" aria-hidden="true"></i>
            <span>{{ __('messages.health_profile.delete_success') }}</span>
        </div>
    @endif

    @if ($hasConsent)
        <div class="hp-banner hp-banner--ok d-flex gap-3 mb-4" role="status">
            <i class="bi bi-shield-check fs-4 flex-shrink-0"></i>
            <div>
                <p class="fw-semibold mb-1">{{ __('messages.health_profile.consent_active_title') }}</p>
                <p class="mb-0 small">{{ __('messages.health_profile.consent_active_body', ['time' => $healthProfile->consented_at->format('d/m/Y H:i')]) }}</p>
            </div>
        </div>
    @else
        <div class="hp-banner hp-banner--warn d-flex gap-3 mb-4" role="alert">
            <i class="bi bi-exclamation-triangle-fill fs-4 flex-shrink-0"></i>
            <div>
                <p class="fw-semibold mb-1">{{ __('messages.health_profile.no_consent_title') }}</p>
                <p class="mb-0 small">{{ __('messages.health_profile.no_consent_body') }}</p>
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('profile.health.update') }}" id="healthProfileForm">
        @csrf
        @method('put')

        <div class="row g-4">
            <div class="col-12">
                @include('profile.partials.health-profile-tags', [
                    'field' => 'allergies',
                    'label' => __('messages.health_profile.allergies_label'),
                    'inputLabel' => __('messages.health_profile.allergies_input_label'),
                    'hint' => __('messages.health_profile.allergies_hint'),
                    'icon' => 'bi-exclamation-octagon',
                    'items' => $tagValue('allergies'),
                    'suggestions' => ['Paracetamol', 'Ibuprofen', 'Aspirin', 'Penicillin', 'Amoxicillin'],
                ])
            </div>

            <div class="col-12">
                @include('profile.partials.health-profile-tags', [
                    'field' => 'chronic_conditions',
                    'label' => __('messages.health_profile.chronic_label'),
                    'inputLabel' => __('messages.health_profile.chronic_input_label'),
                    'hint' => __('messages.health_profile.chronic_hint'),
                    'icon' => 'bi-clipboard2-pulse',
                    'items' => $tagValue('chronic_conditions'),
                    'suggestions' => ['loét dạ dày', 'suy thận', 'suy gan', 'hen suyễn', 'tiểu đường', 'huyết áp cao', 'bệnh tim'],
                ])
            </div>

            <div class="col-12">
                @include('profile.partials.health-profile-tags', [
                    'field' => 'current_medications',
                    'label' => __('messages.health_profile.medications_label'),
                    'inputLabel' => __('messages.health_profile.medications_input_label'),
                    'hint' => __('messages.health_profile.medications_hint'),
                    'icon' => 'bi-capsule',
                    'items' => $tagValue('current_medications'),
                    'suggestions' => [],
                ])
            </div>

            <div class="col-md-6">
                <fieldset class="h-100">
                    <legend class="form-label fw-medium text-gray-700 mb-2 fs-6">
                        <i class="bi bi-person-hearts text-emerald-600 me-1"></i>{{ __('messages.health_profile.is_pregnant') }} / {{ __('messages.health_profile.is_breastfeeding') }}
                    </legend>
                    <div class="hp-check-row">
                        <input type="hidden" name="is_pregnant" value="0">
                        <input class="form-check-input hp-check" type="checkbox" id="is_pregnant" name="is_pregnant" value="1"
                               @checked(old('is_pregnant', $healthProfile?->is_pregnant))>
                        <label class="form-check-label" for="is_pregnant">
                            <span class="fw-medium text-gray-900 d-block">{{ __('messages.health_profile.is_pregnant') }}</span>
                            <small class="text-gray-500">{{ __('messages.health_profile.is_pregnant_hint') }}</small>
                        </label>
                    </div>
                    <div class="hp-check-row">
                        <input type="hidden" name="is_breastfeeding" value="0">
                        <input class="form-check-input hp-check" type="checkbox" id="is_breastfeeding" name="is_breastfeeding" value="1"
                               @checked(old('is_breastfeeding', $healthProfile?->is_breastfeeding))>
                        <label class="form-check-label" for="is_breastfeeding">
                            <span class="fw-medium text-gray-900 d-block">{{ __('messages.health_profile.is_breastfeeding') }}</span>
                            <small class="text-gray-500">{{ __('messages.health_profile.is_breastfeeding_hint') }}</small>
                        </label>
                    </div>
                </fieldset>
            </div>

            <div class="col-md-6">
                <label for="weight_kg" class="form-label fw-medium text-gray-700 mb-2">
                    <i class="bi bi-speedometer2 text-emerald-600 me-1"></i>{{ __('messages.health_profile.weight_kg') }}
                </label>
                <input type="number"
                       class="form-control hp-input @error('weight_kg') is-invalid @enderror"
                       id="weight_kg"
                       name="weight_kg"
                       value="{{ old('weight_kg', $healthProfile?->weight_kg) }}"
                       min="1"
                       max="500"
                       step="0.1"
                       inputmode="decimal"
                       aria-describedby="weight_kg_hint">
                <small id="weight_kg_hint" class="text-gray-500">{{ __('messages.health_profile.weight_kg_hint') }}</small>
                @error('weight_kg')
                    <div class="invalid-feedback d-block" role="alert">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-12">
                <label for="health_notes" class="form-label fw-medium text-gray-700 mb-2">
                    <i class="bi bi-journal-text text-emerald-600 me-1"></i>{{ __('messages.health_profile.notes') }}
                </label>
                <textarea class="form-control hp-input @error('notes') is-invalid @enderror"
                          id="health_notes"
                          name="notes"
                          rows="3"
                          maxlength="1000"
                          aria-describedby="health_notes_hint">{{ old('notes', $healthProfile?->notes) }}</textarea>
                <small id="health_notes_hint" class="text-gray-500">{{ __('messages.health_profile.notes_hint') }}</small>
                @error('notes')
                    <div class="invalid-feedback d-block" role="alert">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-12">
                <div class="hp-consent-box">
                    <div class="hp-check-row mb-0">
                        {{-- Consent is never pre-ticked: it must be an explicit act each time it is granted. --}}
                        <input class="form-check-input hp-check @error('consent') is-invalid @enderror"
                               type="checkbox"
                               id="health_consent"
                               name="consent"
                               value="1"
                               @checked($consentChecked)
                               aria-describedby="health_consent_desc">
                        <label class="form-check-label" for="health_consent">
                            <span class="fw-semibold text-gray-900 d-block mb-1">{{ __('messages.health_profile.consent_title') }}</span>
                            <span id="health_consent_desc" class="text-gray-700 small">{{ __('messages.health_profile.consent_label') }}</span>
                        </label>
                    </div>
                    @error('consent')
                        <div class="invalid-feedback d-block mt-2" role="alert">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="col-12">
                <div class="hp-consent-box">
                    <div class="hp-check-row mb-0">
                        {{-- Independent of `consent`: unticking this withdraws AI consent only. --}}
                        <input class="form-check-input hp-check @error('ai_consent') is-invalid @enderror"
                               type="checkbox"
                               id="health_ai_consent"
                               name="ai_consent"
                               value="1"
                               @checked($aiConsentChecked)
                               aria-describedby="health_ai_consent_desc health_ai_consent_hint">
                        <label class="form-check-label" for="health_ai_consent">
                            <span class="fw-semibold text-gray-900 d-block mb-1">{{ __('messages.health_profile.ai_consent_title') }}</span>
                            <span id="health_ai_consent_desc" class="text-gray-700 small">{{ __('messages.health_profile.ai_consent_label') }}</span>
                        </label>
                    </div>
                    <small id="health_ai_consent_hint" class="text-gray-600 d-block ms-4 ps-1">{{ __('messages.health_profile.ai_consent_hint') }}</small>
                    @error('ai_consent')
                        <div class="invalid-feedback d-block mt-2" role="alert">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="col-12">
                <div class="p-3 rounded-3 bg-emerald-50 d-flex gap-2">
                    <i class="bi bi-shield-lock-fill text-emerald-600 flex-shrink-0"></i>
                    <div>
                        <p class="fw-semibold text-gray-900 mb-1 small">{{ __('messages.health_profile.privacy_title') }}</p>
                        <p class="text-gray-600 mb-0 small">{{ __('messages.health_profile.privacy_body') }}</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex flex-column flex-sm-row gap-2 mt-4 pt-3 border-top">
            <button type="submit" class="btn profile-btn-primary">
                <i class="bi bi-check-lg me-1" aria-hidden="true"></i>{{ __('messages.health_profile.save') }}
            </button>

            @if ($healthProfile)
                <button type="button"
                        class="btn profile-btn-danger"
                        data-bs-toggle="modal"
                        data-bs-target="#deleteHealthProfileModal"
                        aria-label="{{ __('messages.health_profile.delete_open') }}">
                    <i class="bi bi-trash3 me-1" aria-hidden="true"></i>{{ __('messages.health_profile.delete') }}
                </button>
            @endif
        </div>
    </form>
</div>

@if ($healthProfile)
    @push('modals')
    <div class="modal fade" id="deleteHealthProfileModal" tabindex="-1" aria-labelledby="deleteHealthProfileLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content glass-card border-0 rounded-4">
                <div class="modal-header border-bottom-0">
                    <h5 class="modal-title fw-bold text-gray-900" id="deleteHealthProfileLabel">
                        <i class="bi bi-exclamation-triangle-fill text-danger me-2"></i>{{ __('messages.health_profile.delete_title') }}
                    </h5>
                    <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal" aria-label="{{ __('messages.profile.close') }}"></button>
                </div>
                <div class="modal-body pt-0">
                    <p class="text-gray-700 mb-0">{{ __('messages.health_profile.delete_body') }}</p>
                </div>
                <div class="modal-footer border-top-0 d-flex flex-column flex-sm-row gap-2">
                    <button type="button" class="btn btn-light rounded-pill px-4 hp-btn m-0" data-bs-dismiss="modal">
                        {{ __('messages.health_profile.delete_cancel') }}
                    </button>
                    <form method="POST" action="{{ route('profile.health.destroy') }}" class="m-0">
                        @csrf
                        @method('delete')
                        <button type="submit" class="btn btn-danger rounded-pill px-4 hp-btn w-100">
                            <i class="bi bi-trash3 me-1"></i>{{ __('messages.health_profile.delete_confirm') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    @endpush
@endif

@push('styles')
<style>
.health-profile-card {
    --hp-focus-ring: 0 0 0 3px rgba(16, 185, 129, 0.35);
}
/* hp-input dùng chung kích thước với .profile-page .form-control để tab này không
   lệch khỏi các tab còn lại. 16px giữ iOS Safari khỏi phóng viewport khi focus. */
.hp-input,
.hp-tag-input {
    min-height: var(--profile-field-height, 46px);
    font-size: 1rem;
    border-radius: var(--profile-radius-sm, 10px);
    border: 1px solid rgba(21, 128, 61, 0.18);
    padding: 0.55rem 0.75rem;
    width: 100%;
    background: rgba(255, 255, 255, 0.92);
}
.hp-input:focus,
.hp-tag-input:focus,
.hp-btn:focus-visible,
.hp-check:focus-visible,
.hp-chip:focus-visible,
.hp-tag-remove:focus-visible {
    outline: none;
    box-shadow: var(--hp-focus-ring);
    border-color: #10b981;
}
.hp-btn {
    min-height: 44px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.hp-badge-secure { color: #065F46; }
.hp-tag-field {
    border: 1px solid rgba(21, 128, 61, 0.18);
    border-radius: var(--profile-radius-sm, 10px);
    background: rgba(255, 255, 255, 0.92);
    padding: 10px;
}
.hp-tag-field:focus-within {
    border-color: #10b981;
    box-shadow: var(--hp-focus-ring);
}
.hp-tag-field .hp-tag-input {
    border: 0;
    background: transparent;
    box-shadow: none;
    padding: 8px 6px;
}
.hp-tag-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    list-style: none;
    margin: 0;
    padding: 0;
}
.hp-tag {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    min-height: 44px;
    padding: 6px 8px 6px 14px;
    border-radius: 999px;
    background: #d1fae5;
    color: #065f46;
    font-size: 0.9375rem;
    font-weight: 500;
}
.hp-tag-remove {
    position: relative;
    width: 32px;
    height: 32px;
    border: 0;
    border-radius: 999px;
    background: rgba(6, 95, 70, 0.12);
    color: #065f46;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    line-height: 1;
}
/* Visual dot stays small; the touch target is padded out to 44px. */
.hp-tag-remove::after {
    content: '';
    position: absolute;
    inset: -6px;
}
.hp-tag-remove:hover { background: rgba(6, 95, 70, 0.25); }
.hp-chip {
    min-height: 44px;
    padding: 8px 16px;
    border-radius: 999px;
    border: 1.5px solid #d1d5db;
    background: #fff;
    color: #1f2937;
    font-size: 0.9375rem;
    cursor: pointer;
    transition: background-color 180ms ease, border-color 180ms ease, color 180ms ease;
}
.hp-chip:hover {
    background: #ecfdf5;
    border-color: #6ee7b7;
    color: #047857;
}
.hp-chip[aria-pressed="true"] {
    background: #d1fae5;
    border-color: #059669;
    color: #065f46;
}
.hp-check-row {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 12px;
    border-radius: 12px;
    background: #f9fafb;
    margin-bottom: 12px;
}
.hp-check {
    width: 22px;
    height: 22px;
    margin-top: 2px;
    flex-shrink: 0;
}
.hp-check-row label { cursor: pointer; }
.hp-consent-box {
    border: 1.5px solid #a7f3d0;
    background: #ecfdf5;
    border-radius: 12px;
    padding: 8px;
}
.hp-consent-box .hp-check-row { background: transparent; }
.hp-banner {
    border-radius: 12px;
    padding: 16px;
    border: 1.5px solid transparent;
}
.hp-banner--warn {
    background: #fffbeb;
    border-color: #fde68a;
    color: #78350f;
}
.hp-banner--ok {
    background: #ecfdf5;
    border-color: #a7f3d0;
    color: #065f46;
}
@media (prefers-reduced-motion: reduce) {
    .hp-chip { transition: none; }
}
</style>
@endpush
