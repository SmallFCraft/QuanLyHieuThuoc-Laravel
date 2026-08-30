@extends('layouts.admin')

@section('title', __('triage_report.detail_title') . ' SL-' . $session->MaSL)
@section('page-title', __('triage_report.detail_title') . ' SL-' . $session->MaSL)
@section('page-subtitle', __('triage_report.subtitle'))

@push('styles')
<style>
    .triage-badge {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        padding: .35rem .7rem;
        border-radius: 999px;
        font-size: .875rem;
        font-weight: 600;
        line-height: 1.2;
        border: 1px solid currentColor;
    }
    .triage-detail-list dt { color: #475569; font-weight: 600; font-size: .875rem; }
    .triage-detail-list dd { margin-bottom: .85rem; }
    .triage-back-btn { min-height: 44px; display: inline-flex; align-items: center; }
</style>
@endpush

@section('content')
@php
    $style = $outcomeStyles[$session->outcome] ?? null;
    $outcomeLabel = $session->outcome ? __('messages.health.outcome_' . $session->outcome) : __('triage_report.table.no_outcome');
    $symptomList = collect((array) ($session->matched_symptoms ?? []))
        ->filter(fn ($code) => is_string($code) && $code !== '')
        ->map(fn ($code) => $symptomNames[$code] ?? $code);
    $redFlags = collect((array) ($session->triggered_red_flags ?? []))->filter(fn ($flag) => is_array($flag) || is_string($flag));
    $conditionGroups = collect((array) ($session->possible_condition_groups ?? []))
        ->map(fn ($group) => is_array($group) ? ($group['group'] ?? $group['name'] ?? json_encode($group, JSON_UNESCAPED_UNICODE)) : (string) $group)
        ->filter(fn ($group) => trim($group) !== '');
@endphp

<div class="container-fluid px-0">
    <div class="mb-3">
        <a class="btn btn-outline-secondary triage-back-btn" href="{{ route('admin.triageReports.index') }}">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>{{ __('triage_report.back_to_list') }}
        </a>
    </div>

    <div class="alert alert-info d-flex align-items-start gap-2" role="note">
        <i class="bi bi-shield-lock-fill mt-1" aria-hidden="true"></i>
        <div>{{ __('triage_report.detail.privacy_notice') }}</div>
    </div>

    <div class="row g-3 g-xl-4">
        <div class="col-xl-5">
            <section class="admin-card h-100">
                <div class="admin-card__header">
                    <h2 class="admin-card__title">
                        <i class="bi bi-info-circle text-success me-2" aria-hidden="true"></i>{{ __('triage_report.detail.overview') }}
                    </h2>
                </div>

                <div class="mb-3">
                    @if($style)
                        <span class="triage-badge" style="color:{{ $style['color'] }};background:{{ $style['bg'] }};">
                            <i class="bi {{ $style['icon'] }}" aria-hidden="true"></i>{{ $outcomeLabel }}
                        </span>
                    @else
                        <span class="text-secondary">{{ $outcomeLabel }}</span>
                    @endif
                </div>

                <dl class="triage-detail-list mb-0">
                    <dt>{{ __('triage_report.table.customer') }}</dt>
                    <dd>
                        {{ $session->user->name ?? __('triage_report.table.unknown') }}
                        <div class="small text-secondary">{{ $session->user->email ?? '#' . $session->MaKH }}</div>
                    </dd>

                    <dt>{{ __('triage_report.table.status') }}</dt>
                    <dd>{{ __('triage_report.status.' . $session->status) }}</dd>

                    <dt>{{ __('triage_report.table.entry_point') }}</dt>
                    <dd>{{ $session->entry_point ? __('triage_report.entry_point.' . $session->entry_point) : '—' }}</dd>

                    <dt>{{ __('triage_report.detail.created_at') }}</dt>
                    <dd><time datetime="{{ $session->created_at->toIso8601String() }}">{{ $session->created_at->format('d/m/Y H:i') }}</time></dd>

                    <dt>{{ __('triage_report.table.pharmacist') }}</dt>
                    <dd>{{ $session->pharmacist->name ?? '—' }}</dd>

                    <dt>{{ __('triage_report.detail.reviewed_at') }}</dt>
                    <dd>{{ $session->reviewed_at ? $session->reviewed_at->format('d/m/Y H:i') : '—' }}</dd>

                    <dt>{{ __('triage_report.detail.follow_up_at') }}</dt>
                    <dd>{{ $session->follow_up_at ? $session->follow_up_at->format('d/m/Y H:i') : '—' }}</dd>

                    @if($session->session_id)
                        <dt>{{ __('triage_report.detail.session_id') }}</dt>
                        <dd><code class="text-break">{{ $session->session_id }}</code></dd>
                    @endif
                </dl>
            </section>
        </div>

        <div class="col-xl-7">
            <section class="admin-card mb-3 mb-xl-4">
                <div class="admin-card__header">
                    <h2 class="admin-card__title">
                        <i class="bi bi-chat-left-text text-success me-2" aria-hidden="true"></i>{{ __('triage_report.answers.title') }}
                    </h2>
                </div>

                @if(count($answers) === 0)
                    <div class="admin-empty-state">{{ __('triage_report.answers.empty') }}</div>
                @else
                    <dl class="triage-detail-list mb-0">
                        @foreach($answers as $answer)
                            <dt>{{ $answer['label'] }}</dt>
                            <dd>
                                @if($answer['skipped'] && $answer['value'] === '')
                                    <span class="text-secondary">{{ __('triage_report.answers.skipped') }}</span>
                                @else
                                    {{ $answer['value'] !== '' ? $answer['value'] : '—' }}
                                @endif
                            </dd>
                        @endforeach
                    </dl>
                @endif
            </section>

            <section class="admin-card mb-3 mb-xl-4">
                <div class="admin-card__header">
                    <h2 class="admin-card__title">
                        <i class="bi bi-activity text-success me-2" aria-hidden="true"></i>{{ __('triage_report.detail.matched_symptoms') }}
                    </h2>
                </div>
                @if($symptomList->isEmpty())
                    <div class="admin-empty-state">{{ __('triage_report.top_symptoms.empty') }}</div>
                @else
                    <ul class="list-inline mb-0">
                        @foreach($symptomList as $name)
                            <li class="list-inline-item"><span class="badge text-bg-light border">{{ $name }}</span></li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="admin-card mb-3 mb-xl-4">
                <div class="admin-card__header">
                    <h2 class="admin-card__title">
                        <i class="bi bi-exclamation-triangle-fill me-2" style="color:#B45309;" aria-hidden="true"></i>{{ __('triage_report.detail.red_flags') }}
                    </h2>
                </div>
                @if($redFlags->isEmpty())
                    <div class="admin-empty-state">{{ __('triage_report.top_red_flags.empty') }}</div>
                @else
                    <ul class="list-unstyled mb-0 d-flex flex-column gap-2">
                        @foreach($redFlags as $flag)
                            @php
                                $flagName = is_array($flag) ? ($flag['name_vi'] ?? $flag['code'] ?? '') : (string) $flag;
                                $severity = is_array($flag) ? ($flag['severity'] ?? 'caution') : 'caution';
                                $severityStyle = match($severity) {
                                    'emergency' => ['color' => '#B91C1C', 'bg' => '#FEF2F2', 'icon' => 'bi-exclamation-octagon-fill'],
                                    'urgent' => ['color' => '#B45309', 'bg' => '#FFFBEB', 'icon' => 'bi-exclamation-triangle-fill'],
                                    default => ['color' => '#0E7490', 'bg' => '#ECFEFF', 'icon' => 'bi-info-circle-fill'],
                                };
                            @endphp
                            <li class="d-flex flex-wrap align-items-center gap-2">
                                <span class="triage-badge" style="color:{{ $severityStyle['color'] }};background:{{ $severityStyle['bg'] }};">
                                    <i class="bi {{ $severityStyle['icon'] }}" aria-hidden="true"></i>{{ __('triage_report.severity.' . $severity) }}
                                </span>
                                <span>{{ $flagName }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            @if($conditionGroups->isNotEmpty())
                <section class="admin-card mb-3 mb-xl-4">
                    <div class="admin-card__header">
                        <h2 class="admin-card__title">
                            <i class="bi bi-diagram-3 text-success me-2" aria-hidden="true"></i>{{ __('triage_report.detail.condition_groups') }}
                        </h2>
                    </div>
                    <ul class="mb-0">
                        @foreach($conditionGroups as $group)
                            <li>{{ $group }}</li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if($session->advice_given)
                <section class="admin-card mb-3 mb-xl-4">
                    <div class="admin-card__header">
                        <h2 class="admin-card__title">
                            <i class="bi bi-lightbulb text-success me-2" aria-hidden="true"></i>{{ __('triage_report.detail.advice') }}
                        </h2>
                    </div>
                    <p class="mb-0">{{ $session->advice_given }}</p>
                </section>
            @endif

            @if($session->safety_net)
                <section class="admin-card mb-3 mb-xl-4">
                    <div class="admin-card__header">
                        <h2 class="admin-card__title">
                            <i class="bi bi-shield-check me-2" style="color:#B45309;" aria-hidden="true"></i>{{ __('triage_report.detail.safety_net') }}
                        </h2>
                    </div>
                    <p class="mb-0">{{ $session->safety_net }}</p>
                </section>
            @endif

            <section class="admin-card">
                <div class="admin-card__header">
                    <h2 class="admin-card__title">
                        <i class="bi bi-person-badge-fill me-2" style="color:#0E7490;" aria-hidden="true"></i>{{ __('triage_report.detail.pharmacist_note') }}
                    </h2>
                </div>
                @if($session->pharmacist_note)
                    <p class="mb-0">{{ $session->pharmacist_note }}</p>
                @else
                    <div class="admin-empty-state">{{ __('triage_report.detail.no_pharmacist_note') }}</div>
                @endif
            </section>
        </div>
    </div>
</div>
@endsection
