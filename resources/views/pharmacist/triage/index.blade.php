@extends('pharmacist.layout')

@section('title', __('messages.pharmacist.queue_title'))

@section('content')
@php
    // Match the spec's "waiting too long" cue. Behaviour constant, not config.
    $waitLongMinutes = 15;
@endphp

<div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
    <div>
        <h1 class="h3 fw-bold text-gray-900 mb-1">{{ __('messages.pharmacist.queue_title') }}</h1>
        <p class="text-gray-600 mb-0">{{ __('messages.pharmacist.queue_subtitle') }}</p>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-md-4">
        <div class="rx-card p-4 h-100 d-flex justify-content-between align-items-start gap-3">
            <div>
                <p class="rx-metric-label mb-1">{{ __('messages.pharmacist.metric_pending') }}</p>
                <p class="rx-metric-value mb-0">{{ number_format($pendingCount) }}</p>
            </div>
            <i class="bi bi-inboxes-fill fs-3" style="color: var(--health-pharmacist);" aria-hidden="true"></i>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="rx-card p-4 h-100 d-flex justify-content-between align-items-start gap-3">
            <div>
                <p class="rx-metric-label mb-1">{{ __('messages.pharmacist.metric_longest_wait') }}</p>
                <p class="rx-metric-value mb-0">
                    {{ $oldestPendingAt ? $oldestPendingAt->locale('vi')->diffForHumans(['parts' => 1]) : __('messages.pharmacist.metric_none') }}
                </p>
            </div>
            <i class="bi bi-hourglass-split fs-3" style="color: var(--health-refer);" aria-hidden="true"></i>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="rx-card p-4 h-100 d-flex justify-content-between align-items-start gap-3">
            <div>
                <p class="rx-metric-label mb-1">{{ __('messages.pharmacist.metric_handled_today') }}</p>
                <p class="rx-metric-value mb-0">{{ number_format($handledToday) }}</p>
            </div>
            <i class="bi bi-check2-circle fs-3" style="color: var(--health-selfcare);" aria-hidden="true"></i>
        </div>
    </div>
</div>

@if ($sessions->isEmpty())
    <div class="rx-card p-5 text-center">
        <i class="bi bi-clipboard2-check text-gray-300 d-block mb-3" style="font-size: 3rem;" aria-hidden="true"></i>
        <p class="text-gray-600 mb-0">{{ __('messages.pharmacist.queue_empty') }}</p>
    </div>
@else
    <div class="rx-card p-0 overflow-hidden">
        <div class="table-responsive">
            <table class="table rx-table mb-0 align-middle">
                <caption class="visually-hidden">{{ __('messages.pharmacist.queue_subtitle') }}</caption>
                <thead class="table-light">
                    <tr>
                        <th scope="col">{{ __('messages.pharmacist.col_waiting') }}</th>
                        <th scope="col">{{ __('messages.pharmacist.col_symptom') }}</th>
                        <th scope="col">{{ __('messages.pharmacist.col_age') }}</th>
                        <th scope="col">{{ __('messages.pharmacist.col_flags') }}</th>
                        <th scope="col" class="text-end">{{ __('messages.pharmacist.col_actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sessions as $item)
                        @php
                            $symptomText = $item->collected_data['raw_symptom_text']
                                ?? implode(', ', (array) ($item->collected_data['symptoms'] ?? []));
                            $flags = (array) ($item->triggered_red_flags ?? []);
                            $severities = collect($flags)->pluck('severity');
                            $topSeverity = $severities->contains('emergency')
                                ? 'emergency'
                                : ($severities->contains('urgent') ? 'urgent' : 'caution');
                            $age = $item->user?->ageInYears();
                            $waited = $item->created_at->locale('vi')->diffForHumans(['parts' => 1]);
                            $isOverdue = $item->created_at->lt(now()->subMinutes($waitLongMinutes));
                            // Emergency first in the row's own colour, so the eye lands on it
                            // before reading anything else on the line.
                            $rowAccent = ['emergency' => 'emergency', 'urgent' => 'refer', 'caution' => 'pharmacist'][$topSeverity];
                        @endphp
                        <tr>
                            <td style="border-left: 4px solid var(--health-{{ $rowAccent }});">
                                <time datetime="{{ $item->created_at->toIso8601String() }}" class="fs-5 fw-bold text-gray-900 d-block">
                                    {{ $waited }}
                                </time>
                                <div class="small text-gray-600">{{ $item->created_at->format('d/m/Y H:i') }}</div>
                                @if ($isOverdue)
                                    {{-- Icon + text, not colour alone. --}}
                                    <span class="rx-badge rx-badge--{{ $topSeverity === 'emergency' ? 'emergency' : 'caution' }} mt-1">
                                        <i class="bi bi-hourglass-bottom" aria-hidden="true"></i>{{ __('messages.pharmacist.wait_long') }}
                                    </span>
                                @endif
                            </td>
                            <td>
                                <div class="fw-medium text-gray-900">
                                    {{ $symptomText !== '' ? \Illuminate\Support\Str::limit($symptomText, 70) : __('messages.pharmacist.no_symptom') }}
                                </div>
                                <div class="small text-gray-600">{{ $item->user?->name ?? __('messages.pharmacist.customer_unknown') }}</div>
                            </td>
                            <td>
                                {{ $age !== null ? __('messages.pharmacist.age_years', ['age' => $age]) : __('messages.pharmacist.age_unknown') }}
                            </td>
                            <td>
                                @if ($flags === [])
                                    <span class="rx-badge rx-badge--calm">
                                        <i class="bi bi-house-heart-fill" aria-hidden="true"></i>{{ __('messages.pharmacist.no_flags') }}
                                    </span>
                                @else
                                    <span class="rx-badge rx-badge--{{ $topSeverity }} fw-bold fs-6">
                                        <i class="bi {{ $topSeverity === 'emergency' ? 'bi-exclamation-octagon-fill' : ($topSeverity === 'urgent' ? 'bi-hospital-fill' : 'bi-person-badge-fill') }}" aria-hidden="true"></i>
                                        {{ __('messages.pharmacist.severity_' . $topSeverity) }} ({{ count($flags) }})
                                    </span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('pharmacist.triage.show', $item->MaSL) }}"
                                   class="rx-btn rx-btn-primary"
                                   aria-label="{{ __('messages.pharmacist.btn_view_aria', ['id' => $item->MaSL]) }}">
                                    <i class="bi bi-eye" aria-hidden="true"></i>{{ __('messages.pharmacist.btn_view') }}
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $sessions->links() }}
    </div>
@endif
@endsection
