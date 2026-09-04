@php
    // Mức hoàn thiện hồ sơ tính trên các trường người dùng thực sự điền được.
    // role/status do hệ thống đặt nên không tính vào đây.
    $profileFields = [
        __('messages.profile.name') => filled($user->name),
        __('messages.profile.email') => filled($user->email),
        __('messages.profile.phone') => filled($user->phone),
        __('messages.profile.address') => filled($user->address),
        __('messages.profile.birth_date') => filled($user->birth_date),
        __('messages.profile.gender') => filled($user->gender),
        __('messages.profile.avatar_title') => filled($user->avatar),
    ];
    $filledCount = count(array_filter($profileFields));
    $profilePercent = (int) round($filledCount / max(1, count($profileFields)) * 100);
    $missingFields = array_keys(array_filter($profileFields, fn ($ok) => ! $ok));

    $healthProfile = $user->healthProfile;
    $healthFields = [
        filled($healthProfile?->allergies),
        filled($healthProfile?->chronic_conditions),
        filled($healthProfile?->current_medications),
        filled($healthProfile?->weight_kg),
    ];
    $healthPercent = $healthProfile?->hasConsented()
        ? (int) round(count(array_filter($healthFields)) / count($healthFields) * 100)
        : 0;
@endphp

<div class="profile-card">
    <div class="profile-card__head">
        <span class="profile-card__icon"><i class="bi bi-speedometer2"></i></span>
        <div>
            <h2 class="profile-card__title">{{ __('messages.profile.overview_title') }}</h2>
            <p class="profile-card__subtitle">{{ __('messages.profile.overview_desc') }}</p>
        </div>
    </div>

    <div class="mb-4">
        <div class="d-flex justify-content-between align-items-center mb-2 gap-2">
            <span class="fw-semibold text-gray-800 small">{{ __('messages.profile.overview_profile_complete') }}</span>
            <span class="fw-bold text-emerald-700" style="font-variant-numeric: tabular-nums;">{{ $profilePercent }}%</span>
        </div>
        <div class="profile-progress"
             role="progressbar"
             aria-valuenow="{{ $profilePercent }}"
             aria-valuemin="0"
             aria-valuemax="100"
             aria-label="{{ __('messages.profile.overview_profile_complete') }}">
            <div class="profile-progress__bar" style="width: {{ $profilePercent }}%"></div>
        </div>
        @if($missingFields !== [])
            <p class="profile-field__hint mt-2 mb-0">
                {{ __('messages.profile.overview_profile_missing', ['fields' => implode(', ', $missingFields)]) }}
            </p>
        @endif
    </div>

    <div class="profile-stat-grid">
        <div class="profile-stat">
            <span class="profile-stat__icon"><i class="bi bi-bag-check"></i></span>
            <span class="profile-stat__value">{{ $orderCounts->sum() }}</span>
            <span class="profile-stat__label">{{ __('messages.profile.total_orders') }}</span>
        </div>
        <div class="profile-stat profile-stat--warning">
            <span class="profile-stat__icon"><i class="bi bi-clock-history"></i></span>
            <span class="profile-stat__value">{{ $orderCounts['Pending'] ?? 0 }}</span>
            <span class="profile-stat__label">{{ __('messages.profile.processing') }}</span>
        </div>
        <div class="profile-stat profile-stat--info">
            <span class="profile-stat__icon"><i class="bi bi-truck"></i></span>
            <span class="profile-stat__value">{{ $orderCounts['Delivering'] ?? 0 }}</span>
            <span class="profile-stat__label">{{ __('messages.profile.delivering') }}</span>
        </div>
        <div class="profile-stat profile-stat--success">
            <span class="profile-stat__icon"><i class="bi bi-check-circle"></i></span>
            <span class="profile-stat__value">{{ $orderCounts['Completed'] ?? 0 }}</span>
            <span class="profile-stat__label">{{ __('messages.profile.orders_completed') }}</span>
        </div>
    </div>
</div>

<div class="profile-overview-grid mt-3">
    <div class="profile-card">
        <div class="profile-card__head">
            <span class="profile-card__icon"><i class="bi bi-receipt"></i></span>
            <div>
                <h3 class="profile-card__title">{{ __('messages.profile.overview_recent_orders') }}</h3>
            </div>
        </div>

        @if($recentOrders->isNotEmpty())
            <ul class="profile-overview-list">
                @foreach($recentOrders as $order)
                    <li>
                        <a class="profile-overview-item" href="#order-{{ $order->MaDH }}" data-profile-goto-order="{{ $order->MaDH }}">
                            <span class="profile-overview-item__icon"><i class="bi bi-box-seam"></i></span>
                            <span class="profile-overview-item__body">
                                <span class="profile-overview-item__title">{{ __('messages.profile.order_prefix') }}{{ $order->MaDH }}</span>
                                <span class="profile-overview-item__meta">
                                    {{ $order->created_at->format('d/m/Y') }} · {{ order_status_text($order->status) }}
                                </span>
                            </span>
                            <span class="profile-overview-item__value">{{ format_currency_vi($order->total_amount) }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="profile-overview-empty">{{ __('messages.profile.overview_no_orders') }}</p>
        @endif
    </div>

    <div class="profile-card">
        <div class="profile-card__head">
            <span class="profile-card__icon"><i class="bi bi-alarm"></i></span>
            <div>
                <h3 class="profile-card__title">{{ __('messages.profile.overview_reminders') }}</h3>
            </div>
        </div>

        @if($upcomingReminders->isNotEmpty())
            <ul class="profile-overview-list">
                @foreach($upcomingReminders as $reminder)
                    <li class="profile-overview-item">
                        <span class="profile-overview-item__icon"><i class="bi bi-capsule"></i></span>
                        <span class="profile-overview-item__body">
                            <span class="profile-overview-item__title">{{ $reminder->product?->name ?? __('messages.profile.product_not_exist') }}</span>
                            <span class="profile-overview-item__meta">
                                {{ __('messages.profile.overview_reminder_next') }}:
                                {{ $reminder->next_remind_at?->format('d/m/Y H:i') ?? '—' }}
                            </span>
                        </span>
                    </li>
                @endforeach
            </ul>
            <a class="btn profile-btn-ghost mt-3" href="{{ route('reminders.index') }}">
                <i class="bi bi-arrow-right me-1" aria-hidden="true"></i>{{ __('messages.profile.overview_view_reminders') }}
            </a>
        @else
            <p class="profile-overview-empty">{{ __('messages.profile.overview_no_reminders') }}</p>
        @endif
    </div>

    <div class="profile-card">
        <div class="profile-card__head">
            <span class="profile-card__icon"><i class="bi bi-heart-pulse"></i></span>
            <div>
                <h3 class="profile-card__title">{{ __('messages.profile.overview_health') }}</h3>
            </div>
        </div>

        @if($healthProfile?->hasConsented())
            <div class="d-flex justify-content-between align-items-center mb-2 gap-2">
                <span class="text-gray-700 small">{{ __('messages.profile.overview_health_complete', ['percent' => $healthPercent]) }}</span>
                <span class="fw-bold text-emerald-700" style="font-variant-numeric: tabular-nums;">{{ $healthPercent }}%</span>
            </div>
            <div class="profile-progress"
                 role="progressbar"
                 aria-valuenow="{{ $healthPercent }}"
                 aria-valuemin="0"
                 aria-valuemax="100"
                 aria-label="{{ __('messages.profile.overview_health') }}">
                <div class="profile-progress__bar" style="width: {{ $healthPercent }}%"></div>
            </div>
        @else
            <p class="profile-overview-empty mb-3">{{ __('messages.profile.overview_health_empty') }}</p>
        @endif

        <a class="btn profile-btn-ghost mt-3" href="#health-profile" data-profile-goto-tab="#health-profile">
            <i class="bi bi-pencil-square me-1" aria-hidden="true"></i>{{ __('messages.profile.overview_health_fill') }}
        </a>
    </div>

    <div class="profile-card">
        <div class="profile-card__head">
            <span class="profile-card__icon profile-card__icon--rose"><i class="bi bi-heart-fill"></i></span>
            <div class="flex-grow-1">
                <h3 class="profile-card__title">{{ __('messages.profile.overview_favorites') }}</h3>
                <p class="profile-card__subtitle">
                    <span data-favorites-count>{{ $favoriteItems->total() }}</span> {{ mb_strtolower(__('messages.products')) }}
                </p>
            </div>
        </div>

        @php
            // Chỉ 3 dòng: thẻ này nằm cạnh "đơn hàng gần đây" trong lưới 2 cột, dài
            // hơn thì hai thẻ lệch chiều cao. Danh sách đầy đủ ở tab Yêu thích.
            $previewFavorites = $favoriteItems->getCollection()
                ->map(fn ($favorite) => $favorite->product)
                ->filter()
                ->take(3);
        @endphp

        @if($previewFavorites->isNotEmpty())
            <ul class="profile-overview-list">
                @foreach($previewFavorites as $product)
                    <li>
                        <a class="profile-overview-item" href="{{ route('products.show', $product->MaSP) }}">
                            <span class="profile-overview-thumb" data-product-image-container>
                                <x-product-image
                                    :src="$product->image_url"
                                    :alt="$product->name"
                                    class="w-100 h-100 object-fit-cover"
                                    icon-class="text-secondary opacity-25"
                                    icon-size="1.25rem"
                                />
                            </span>
                            <span class="profile-overview-item__body">
                                <span class="profile-overview-item__title">{{ $product->name }}</span>
                                <span class="profile-overview-item__meta">
                                    {{ $product->category?->name ?? __('messages.product_category') }}
                                    @if($product->stock_quantity < 1)
                                        · <span class="text-danger">{{ __('messages.out_of_stock') }}</span>
                                    @endif
                                </span>
                            </span>
                            <span class="profile-overview-item__value">{{ $product->formatted_price }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>

            @if($favoriteItems->total() > $previewFavorites->count())
                <p class="profile-field__hint mt-2 mb-0">
                    {{ __('messages.profile.overview_favorites_more', ['count' => $favoriteItems->total() - $previewFavorites->count()]) }}
                </p>
            @endif

            <a class="btn profile-btn-ghost mt-3" href="#favorites" data-profile-goto-tab="#favorites">
                <i class="bi bi-arrow-right me-1" aria-hidden="true"></i>{{ __('messages.profile.overview_view_all') }}
            </a>
        @else
            <p class="profile-overview-empty">{{ __('messages.profile.favorites_empty_desc') }}</p>
            <a class="btn profile-btn-ghost" href="{{ route('products.index') }}">
                <i class="bi bi-search me-1" aria-hidden="true"></i>{{ __('messages.profile.shop_now') }}
            </a>
        @endif
    </div>
</div>
