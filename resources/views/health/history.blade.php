@extends('layouts.app')

@section('title', __('messages.health.history_title') . ' - ' . __('messages.app_name'))

@push('styles')
    @include('health.partials.styles')
@endpush

@section('content')
@php
    $badges = [
        'emergency' => ['class' => 'health-outcome__title--emergency', 'bg' => '#FEF2F2', 'icon' => 'bi-exclamation-octagon-fill', 'label' => __('messages.health.outcome_emergency')],
        'refer_doctor' => ['class' => 'health-outcome__title--refer_doctor', 'bg' => '#FFFBEB', 'icon' => 'bi-hospital-fill', 'label' => __('messages.health.outcome_refer_doctor')],
        'pharmacist_review' => ['class' => 'health-outcome__title--pharmacist_review', 'bg' => '#ECFEFF', 'icon' => 'bi-person-badge-fill', 'label' => __('messages.health.outcome_pharmacist_review')],
        'self_care' => ['class' => 'health-outcome__title--self_care', 'bg' => '#ECFDF5', 'icon' => 'bi-house-heart-fill', 'label' => __('messages.health.outcome_self_care')],
    ];
@endphp

<div class="health-page py-4 py-md-5" style="background: linear-gradient(180deg, #f9fafb 0%, #ffffff 240px);">
    <div class="container">
        <div class="health-shell">

            <div class="text-center mb-4">
                <h1 class="h3 fw-bold text-gray-900 mb-2">{{ __('messages.health.history_title') }}</h1>
                <p class="text-gray-600 mb-0">{{ __('messages.health.history_subtitle') }}</p>
            </div>

            @if ($sessions->isEmpty())
                <div class="health-card p-5 text-center">
                    <i class="bi bi-clipboard2-x text-gray-300 d-block mb-3" style="font-size: 3rem;" aria-hidden="true"></i>
                    <p class="text-gray-600 mb-4">{{ __('messages.health.history_empty') }}</p>
                    <a href="{{ route('health.check') }}" class="health-btn health-btn-primary text-decoration-none">
                        <i class="bi bi-clipboard2-pulse me-1" aria-hidden="true"></i>{{ __('messages.health.btn_start') }}
                    </a>
                </div>
            @else
                <div class="d-flex flex-column gap-3">
                    @foreach ($sessions as $item)
                        @php
                            $badge = $badges[$item->outcome] ?? $badges['pharmacist_review'];
                            $symptomText = $item->collected_data['raw_symptom_text']
                                ?? implode(', ', (array) ($item->collected_data['symptoms'] ?? []));
                        @endphp
                        <a href="{{ route('health.result', $item->MaSL) }}"
                           class="health-card p-4 text-decoration-none d-block">
                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                                <div class="flex-grow-1" style="min-width: 200px;">
                                    <p class="fw-semibold text-gray-900 mb-1">
                                        {{ $symptomText !== '' ? \Illuminate\Support\Str::limit($symptomText, 70) : __('messages.health.history_symptoms') }}
                                    </p>
                                    <p class="small text-gray-500 mb-0">
                                        <i class="bi bi-clock me-1" aria-hidden="true"></i>{{ $item->created_at->format('d/m/Y H:i') }}
                                    </p>
                                </div>

                                {{-- Icon + text label, so the outcome reads without relying on colour --}}
                                <span class="badge fw-medium px-3 py-2 rounded-pill {{ $badge['class'] }}"
                                      style="background: {{ $badge['bg'] }};">
                                    <i class="bi {{ $badge['icon'] }} me-1" aria-hidden="true"></i>{{ $badge['label'] }}
                                </span>
                            </div>
                        </a>
                    @endforeach
                </div>

                <div class="mt-4">
                    {{ $sessions->links() }}
                </div>
            @endif

            <div class="text-center mt-4">
                <a href="{{ route('health.check') }}" class="health-link health-link--accent small">
                    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>{{ __('messages.health.page_title') }}
                </a>
            </div>
        </div>
    </div>
</div>
@endsection
