@props([
    'warnings' => [],
    'compact' => false,
    'title' => null,
    'intro' => null,
])

@php
    $warnings = collect($warnings)->filter()->values();
    $headingId = 'drug-warnings-heading-' . uniqid();

    $levels = [
        'major' => ['icon' => 'bi-exclamation-octagon-fill', 'label' => __('messages.drug_safety.severity_major')],
        'moderate' => ['icon' => 'bi-exclamation-triangle-fill', 'label' => __('messages.drug_safety.severity_moderate')],
        'minor' => ['icon' => 'bi-info-circle-fill', 'label' => __('messages.drug_safety.severity_minor')],
    ];
@endphp

@if($warnings->isNotEmpty())
    {{-- Colour is paired with an icon and a text label on every row: severity must
         stay readable for colour-blind users and in printed/greyscale output.
         Styles live in resources/css/app.css so the add-to-cart confirmation dialog
         can reuse them on pages where this component never rendered. --}}
    <div {{ $attributes->merge(['class' => 'drug-warnings' . ($compact ? ' drug-warnings--compact' : '')]) }}
         role="alert"
         aria-labelledby="{{ $headingId }}">
        <h2 id="{{ $headingId }}" class="drug-warnings__heading mb-1">
            <i class="bi bi-shield-exclamation me-2" aria-hidden="true"></i>{{ $title ?? __('messages.drug_safety.block_title') }}
        </h2>

        @if($intro !== false)
            <p class="drug-warnings__intro mb-3">{{ $intro ?? __('messages.drug_safety.block_intro') }}</p>
        @endif

        <div class="d-flex flex-column gap-2">
            @foreach($warnings as $warning)
                @php
                    $severity = in_array($warning['severity'] ?? 'minor', ['major', 'moderate', 'minor'], true)
                        ? $warning['severity']
                        : 'minor';
                    $level = $levels[$severity];
                @endphp
                <div class="drug-warning drug-warning--{{ $severity }}">
                    <span class="drug-warning__badge">
                        <i class="bi {{ $level['icon'] }}" aria-hidden="true"></i>
                        <span class="visually-hidden">{{ __('messages.drug_safety.severity_label') }}:</span>{{ $level['label'] }}
                    </span>
                    <p class="drug-warning__title mb-1 mt-1">{{ $warning['title'] ?? '' }}</p>
                    @if(!empty($warning['message']))
                        <p class="drug-warning__message mb-1">{{ $warning['message'] }}</p>
                    @endif
                    @if(!empty($warning['action']))
                        <p class="drug-warning__action mb-0">
                            <i class="bi bi-arrow-right-short" aria-hidden="true"></i>
                            <strong>{{ __('messages.drug_safety.action_label') }}:</strong> {{ $warning['action'] }}
                        </p>
                    @endif
                </div>
            @endforeach
        </div>

        <p class="drug-warnings__disclaimer mb-0 mt-3">{{ __('messages.drug_safety.disclaimer') }}</p>

        {{ $slot }}
    </div>
@endif
