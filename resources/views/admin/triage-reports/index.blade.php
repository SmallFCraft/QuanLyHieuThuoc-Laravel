@extends('layouts.admin')

@section('title', __('triage_report.title'))
@section('page-title', __('triage_report.title'))
@section('page-subtitle', __('triage_report.subtitle'))

@push('styles')
<style>
    .triage-badge {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        padding: .3rem .6rem;
        border-radius: 999px;
        font-size: .8125rem;
        font-weight: 600;
        line-height: 1.2;
        border: 1px solid currentColor;
    }
    .triage-filter-actions .btn { min-height: 44px; }
    .triage-data-table { font-size: .875rem; }
    .triage-data-table th { white-space: nowrap; }
</style>
@endpush

@section('content')
@php
    $outcomeLabels = [
        'emergency' => __('messages.health.outcome_emergency'),
        'refer_doctor' => __('messages.health.outcome_refer_doctor'),
        'pharmacist_review' => __('messages.health.outcome_pharmacist_review'),
        'self_care' => __('messages.health.outcome_self_care'),
    ];
    $activeFilters = array_filter($filters);
@endphp

<div class="container-fluid px-0">
    <div class="row g-3 g-xl-4 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="metric-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="label">{{ __('triage_report.metrics.total') }}</div>
                        <div class="value">{{ number_format($summary['total']) }}</div>
                    </div>
                    <div class="icon" style="background:#ECFDF5;color:#047857;"><i class="bi bi-clipboard2-pulse-fill" aria-hidden="true"></i></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="metric-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="label">{{ __('triage_report.metrics.referral_rate') }}</div>
                        <div class="value">{{ number_format($summary['referral_rate'], 1) }}%</div>
                    </div>
                    <div class="icon" style="background:#FFFBEB;color:#B45309;"><i class="bi bi-hospital-fill" aria-hidden="true"></i></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="metric-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="label">{{ __('triage_report.metrics.pending_pharmacist') }}</div>
                        <div class="value">{{ number_format($summary['pending_pharmacist']) }}</div>
                    </div>
                    <div class="icon" style="background:#ECFEFF;color:#0E7490;"><i class="bi bi-person-badge-fill" aria-hidden="true"></i></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="metric-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="label">{{ __('triage_report.metrics.average_wait') }}</div>
                        <div class="value">
                            @if($summary['average_wait_minutes'] === null)
                                <span class="fs-6 text-secondary">{{ __('triage_report.metrics.no_data') }}</span>
                            @else
                                {{ number_format($summary['average_wait_minutes'], 1) }}
                                <span class="fs-6 fw-normal">{{ __('triage_report.metrics.minutes') }}</span>
                            @endif
                        </div>
                        @if($summary['average_wait_minutes'] !== null)
                            <div class="small text-secondary mt-1">{{ __('triage_report.metrics.average_wait_hint', ['count' => $summary['reviewed_count']]) }}</div>
                        @endif
                    </div>
                    <div class="icon" style="background:#eff6ff;color:#1d4ed8;"><i class="bi bi-hourglass-split" aria-hidden="true"></i></div>
                </div>
            </div>
        </div>
    </div>

    <section class="admin-card mb-4">
        <form method="GET" action="{{ route('admin.triageReports.index') }}" class="row g-3 align-items-end">
            <fieldset class="row g-3 align-items-end border-0 p-0 m-0">
                <legend class="visually-hidden">{{ __('triage_report.filters.legend') }}</legend>

                <div class="col-12 col-md-6 col-xl-3">
                    <label class="form-label" for="filterOutcome">{{ __('triage_report.filters.outcome') }}</label>
                    <select class="form-select" id="filterOutcome" name="outcome">
                        <option value="">{{ __('triage_report.filters.all') }}</option>
                        @foreach($outcomeLabels as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['outcome'] ?? null) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-12 col-md-6 col-xl-3">
                    <label class="form-label" for="filterStatus">{{ __('triage_report.filters.status') }}</label>
                    <select class="form-select" id="filterStatus" name="status">
                        <option value="">{{ __('triage_report.filters.all') }}</option>
                        @foreach(['in_progress', 'completed', 'pending_pharmacist', 'reviewed'] as $value)
                            <option value="{{ $value }}" @selected(($filters['status'] ?? null) === $value)>{{ __('triage_report.status.' . $value) }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label" for="filterEntryPoint">{{ __('triage_report.filters.entry_point') }}</label>
                    <select class="form-select" id="filterEntryPoint" name="entry_point">
                        <option value="">{{ __('triage_report.filters.all') }}</option>
                        @foreach(['chat', 'page'] as $value)
                            <option value="{{ $value }}" @selected(($filters['entry_point'] ?? null) === $value)>{{ __('triage_report.entry_point.' . $value) }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label" for="filterDateFrom">{{ __('triage_report.filters.date_from') }}</label>
                    <input type="date" class="form-control" id="filterDateFrom" name="date_from" value="{{ $filters['date_from'] }}">
                </div>

                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label" for="filterDateTo">{{ __('triage_report.filters.date_to') }}</label>
                    <input type="date" class="form-control" id="filterDateTo" name="date_to" value="{{ $filters['date_to'] }}">
                </div>
            </fieldset>

            <div class="col-12 d-flex flex-wrap gap-2 triage-filter-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-funnel me-1" aria-hidden="true"></i>{{ __('triage_report.filters.apply') }}
                </button>
                @if($activeFilters !== [])
                    <a class="btn btn-outline-secondary" href="{{ route('admin.triageReports.index') }}">
                        <i class="bi bi-x-circle me-1" aria-hidden="true"></i>{{ __('triage_report.filters.reset') }}
                    </a>
                @endif
                <a class="btn btn-success ms-auto" href="{{ route('admin.triageReports.export', request()->query()) }}">
                    <i class="bi bi-file-earmark-excel me-1" aria-hidden="true"></i>{{ __('triage_report.filters.export') }}
                </a>
            </div>
        </form>
    </section>

    <div class="row g-3 g-xl-4 mb-4">
        <div class="col-xl-5">
            <section class="chart-card h-100" aria-labelledby="triageOutcomeChartTitle">
                <div class="admin-card__header">
                    <div>
                        <h2 class="admin-card__title" id="triageOutcomeChartTitle">
                            <i class="bi bi-pie-chart text-success me-2" aria-hidden="true"></i>{{ __('triage_report.charts.outcome_title') }}
                        </h2>
                        <p class="admin-card__description">{{ __('triage_report.charts.outcome_desc') }}</p>
                    </div>
                </div>
                <div class="chart-shell">
                    <div class="chart-status" data-chart-status="triageOutcome">{{ __('triage_report.charts.loading') }}</div>
                    <canvas id="triageOutcomeChart" role="img" aria-label="{{ __('triage_report.charts.outcome_aria') }}"></canvas>
                </div>

                <table class="table table-sm triage-data-table mt-3 mb-0">
                    <caption class="visually-hidden">{{ __('triage_report.charts.outcome_title') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('triage_report.table.outcome') }}</th>
                            <th scope="col" class="text-end">{{ __('triage_report.table.count_column') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($distribution['rows'] as $row)
                            <tr>
                                <td>
                                    <span class="triage-badge" style="color:{{ $row['color'] }};background:{{ $outcomeStyles[$row['outcome']]['bg'] }};">
                                        <i class="bi {{ $row['icon'] }}" aria-hidden="true"></i>{{ $row['label'] }}
                                    </span>
                                </td>
                                <td class="text-end fw-semibold">{{ number_format($row['count']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        </div>

        <div class="col-xl-7">
            <section class="chart-card h-100" aria-labelledby="triageTrendChartTitle">
                <div class="admin-card__header">
                    <div>
                        <h2 class="admin-card__title" id="triageTrendChartTitle">
                            <i class="bi bi-graph-up text-success me-2" aria-hidden="true"></i>{{ __('triage_report.charts.trend_title') }}
                        </h2>
                        <p class="admin-card__description" id="triageTrendSummary">{{ __('triage_report.charts.trend_desc', ['days' => $trendDays]) }}</p>
                    </div>
                </div>
                <div class="chart-shell">
                    <div class="chart-status" data-chart-status="triageTrend">{{ __('triage_report.charts.loading') }}</div>
                    <canvas id="triageTrendChart" height="130" role="img" aria-label="{{ __('triage_report.charts.trend_aria', ['days' => $trendDays]) }}"></canvas>
                </div>

                <details class="mt-3">
                    <summary class="fw-semibold">{{ __('triage_report.charts.table_toggle') }}</summary>
                    <div class="admin-table-wrap mt-2">
                        <table class="table table-sm triage-data-table mb-0" id="triageTrendTable">
                            <caption class="visually-hidden">{{ __('triage_report.charts.trend_title') }}</caption>
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('triage_report.table.date') }}</th>
                                    <th scope="col" class="text-end">{{ __('triage_report.table.count_column') }}</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </details>
            </section>
        </div>
    </div>

    <div class="row g-3 g-xl-4 mb-4">
        <div class="col-xl-4">
            <section class="admin-card h-100">
                <div class="admin-card__header">
                    <h2 class="admin-card__title">
                        <i class="bi bi-list-ol text-success me-2" aria-hidden="true"></i>{{ __('triage_report.top_symptoms.title') }}
                    </h2>
                </div>
                @if(count($topSymptoms) === 0)
                    <div class="admin-empty-state">{{ __('triage_report.top_symptoms.empty') }}</div>
                @else
                    <table class="table table-sm triage-data-table mb-0">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('triage_report.top_symptoms.name') }}</th>
                                <th scope="col" class="text-end">{{ __('triage_report.table.count_column') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($topSymptoms as $symptom)
                                <tr>
                                    <td>{{ $symptom['name_vi'] }}</td>
                                    <td class="text-end fw-semibold">{{ number_format($symptom['count']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </section>
        </div>

        <div class="col-xl-5">
            <section class="admin-card h-100">
                <div class="admin-card__header">
                    <h2 class="admin-card__title">
                        <i class="bi bi-exclamation-triangle-fill me-2" style="color:#B45309;" aria-hidden="true"></i>{{ __('triage_report.top_red_flags.title') }}
                    </h2>
                </div>
                @if(count($topRedFlags) === 0)
                    <div class="admin-empty-state">{{ __('triage_report.top_red_flags.empty') }}</div>
                @else
                    <table class="table table-sm triage-data-table mb-0">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('triage_report.top_red_flags.name') }}</th>
                                <th scope="col">{{ __('triage_report.top_red_flags.severity') }}</th>
                                <th scope="col" class="text-end">{{ __('triage_report.table.count_column') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($topRedFlags as $flag)
                                @php
                                    $severityStyle = match($flag['severity']) {
                                        'emergency' => ['color' => '#B91C1C', 'bg' => '#FEF2F2', 'icon' => 'bi-exclamation-octagon-fill'],
                                        'urgent' => ['color' => '#B45309', 'bg' => '#FFFBEB', 'icon' => 'bi-exclamation-triangle-fill'],
                                        default => ['color' => '#0E7490', 'bg' => '#ECFEFF', 'icon' => 'bi-info-circle-fill'],
                                    };
                                @endphp
                                <tr>
                                    <td>{{ $flag['name_vi'] }}</td>
                                    <td>
                                        <span class="triage-badge" style="color:{{ $severityStyle['color'] }};background:{{ $severityStyle['bg'] }};">
                                            <i class="bi {{ $severityStyle['icon'] }}" aria-hidden="true"></i>{{ __('triage_report.severity.' . $flag['severity']) }}
                                        </span>
                                    </td>
                                    <td class="text-end fw-semibold">{{ number_format($flag['count']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </section>
        </div>

        <div class="col-xl-3">
            <section class="admin-card h-100">
                <div class="admin-card__header">
                    <h2 class="admin-card__title">
                        <i class="bi bi-signpost-split text-success me-2" aria-hidden="true"></i>{{ __('triage_report.entry_points.title') }}
                    </h2>
                </div>
                <table class="table table-sm triage-data-table mb-0">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('triage_report.table.entry_point') }}</th>
                            <th scope="col" class="text-end">{{ __('triage_report.table.count_column') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><i class="bi bi-chat-dots-fill me-1" aria-hidden="true"></i>{{ __('triage_report.entry_point.chat') }}</td>
                            <td class="text-end fw-semibold">{{ number_format($entryPoints['chat']) }}</td>
                        </tr>
                        <tr>
                            <td><i class="bi bi-window-stack me-1" aria-hidden="true"></i>{{ __('triage_report.entry_point.page') }}</td>
                            <td class="text-end fw-semibold">{{ number_format($entryPoints['page']) }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </div>

    <section class="admin-card">
        <div class="admin-card__header">
            <div>
                <h2 class="admin-card__title">
                    <i class="bi bi-table text-success me-2" aria-hidden="true"></i>{{ __('triage_report.table.title') }}
                </h2>
                <p class="admin-card__description">{{ number_format($sessions->total()) }} {{ __('triage_report.charts.sessions') }}</p>
            </div>
        </div>

        @if($sessions->total() === 0)
            <div class="admin-empty-state">
                <p class="mb-1 fw-semibold">{{ __('triage_report.table.empty') }}</p>
                <p class="mb-0 small text-secondary">{{ __('triage_report.table.empty_hint') }}</p>
            </div>
        @else
            {{-- 10 columns overflow below ~1100px, and a scroll container that is not
                 focusable cannot be panned by keyboard alone. tabindex makes the
                 region reachable; role+label tell a screen reader what it is. --}}
            <div class="admin-table-wrap"
                 tabindex="0"
                 role="region"
                 aria-label="{{ __('triage_report.table.title') }}">
                <table class="table table-hover align-middle w-100">
                    <caption class="visually-hidden">{{ __('triage_report.table.title') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('triage_report.table.code') }}</th>
                            <th scope="col">{{ __('triage_report.table.date') }}</th>
                            <th scope="col">{{ __('triage_report.table.customer') }}</th>
                            <th scope="col">{{ __('triage_report.table.symptoms') }}</th>
                            <th scope="col">{{ __('triage_report.table.outcome') }}</th>
                            <th scope="col">{{ __('triage_report.table.red_flags') }}</th>
                            <th scope="col">{{ __('triage_report.table.status') }}</th>
                            <th scope="col">{{ __('triage_report.table.pharmacist') }}</th>
                            <th scope="col">{{ __('triage_report.table.entry_point') }}</th>
                            <th scope="col" class="text-center">{{ __('triage_report.table.action') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($sessions as $session)
                            @php
                                $symptomList = collect((array) ($session->matched_symptoms ?? []))
                                    ->filter(fn ($code) => is_string($code) && $code !== '')
                                    ->map(fn ($code) => $symptomNames[$code] ?? $code);
                                $redFlagList = collect((array) ($session->triggered_red_flags ?? []))
                                    ->map(fn ($flag) => is_array($flag) ? ($flag['name_vi'] ?? $flag['code'] ?? null) : $flag)
                                    ->filter(fn ($name) => is_string($name) && $name !== '');
                                $style = $outcomeStyles[$session->outcome] ?? null;
                            @endphp
                            <tr>
                                <td class="fw-semibold">SL-{{ $session->MaSL }}</td>
                                <td><time datetime="{{ $session->created_at->toIso8601String() }}">{{ $session->created_at->format('d/m/Y H:i') }}</time></td>
                                <td>
                                    <div class="fw-semibold">{{ $session->user->name ?? __('triage_report.table.unknown') }}</div>
                                    <div class="small text-secondary">{{ $session->user->email ?? '#' . $session->MaKH }}</div>
                                </td>
                                <td>{{ $symptomList->isEmpty() ? '—' : $symptomList->implode(', ') }}</td>
                                <td>
                                    @if($style)
                                        <span class="triage-badge" style="color:{{ $style['color'] }};background:{{ $style['bg'] }};">
                                            <i class="bi {{ $style['icon'] }}" aria-hidden="true"></i>{{ $outcomeLabels[$session->outcome] }}
                                        </span>
                                    @else
                                        <span class="text-secondary">{{ __('triage_report.table.no_outcome') }}</span>
                                    @endif
                                </td>
                                <td>{{ $redFlagList->isEmpty() ? __('triage_report.table.none') : $redFlagList->implode(', ') }}</td>
                                <td>{{ __('triage_report.status.' . $session->status) }}</td>
                                <td>{{ $session->pharmacist->name ?? '—' }}</td>
                                <td>{{ $session->entry_point ? __('triage_report.entry_point.' . $session->entry_point) : '—' }}</td>
                                <td class="text-center">
                                    <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.triageReports.show', $session->MaSL) }}"
                                       aria-label="{{ __('triage_report.table.view') }} SL-{{ $session->MaSL }}">
                                        <i class="bi bi-eye me-1" aria-hidden="true"></i>{{ __('triage_report.table.view') }}
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-3">{{ $sessions->links() }}</div>
        @endif
    </section>
</div>
@endsection

@push('scripts')
<script type="module">
document.addEventListener('DOMContentLoaded', function () {
    const hideStatus = (name) => {
        const status = document.querySelector(`[data-chart-status="${name}"]`);
        if (status) status.classList.add('d-none');
    };

    const showError = (name, message) => {
        const status = document.querySelector(`[data-chart-status="${name}"]`);
        if (status) {
            status.classList.remove('d-none');
            status.textContent = message;
        }
    };

    const query = @json(array_filter(request()->query()));
    const withQuery = (url) => {
        const target = new URL(url, window.location.origin);
        Object.entries(query).forEach(([key, value]) => target.searchParams.set(key, value));
        return target.toString();
    };

    fetch(withQuery(@json(route('admin.triageReports.outcomeChart'))))
        .then(response => {
            if (!response.ok) throw new Error('outcome chart request failed');
            return response.json();
        })
        .then(data => {
            hideStatus('triageOutcome');
            new Chart(document.getElementById('triageOutcomeChart').getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: data.labels,
                    datasets: [{
                        data: data.data,
                        backgroundColor: data.colors,
                        borderColor: '#fff',
                        borderWidth: 3,
                        hoverOffset: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    cutout: '58%',
                    plugins: { legend: { position: 'bottom' } }
                }
            });
        })
        .catch(() => showError('triageOutcome', @json(__('triage_report.charts.outcome_error'))));

    fetch(withQuery(@json(route('admin.triageReports.trendChart', ['days' => $trendDays]))))
        .then(response => {
            if (!response.ok) throw new Error('trend chart request failed');
            return response.json();
        })
        .then(data => {
            hideStatus('triageTrend');

            const body = document.querySelector('#triageTrendTable tbody');
            if (body) {
                body.innerHTML = '';
                data.labels.forEach((label, index) => {
                    const row = document.createElement('tr');
                    const dateCell = document.createElement('td');
                    dateCell.textContent = label;
                    const countCell = document.createElement('td');
                    countCell.className = 'text-end fw-semibold';
                    countCell.textContent = data.data[index];
                    row.append(dateCell, countCell);
                    body.append(row);
                });
            }

            new Chart(document.getElementById('triageTrendChart').getContext('2d'), {
                type: 'line',
                data: {
                    labels: data.labels,
                    datasets: [{
                        label: @json(__('triage_report.charts.sessions')),
                        data: data.data,
                        borderColor: '#047857',
                        backgroundColor: 'rgba(4, 120, 87, 0.14)',
                        tension: 0.34,
                        fill: true,
                        borderWidth: 2.5,
                        pointRadius: 0,
                        pointHoverRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    interaction: { intersect: false, mode: 'index' },
                    plugins: { legend: { position: 'bottom' } },
                    scales: {
                        y: { beginAtZero: true, ticks: { precision: 0 } },
                        x: { ticks: { maxRotation: 0, autoSkip: true } }
                    }
                }
            });
        })
        .catch(() => showError('triageTrend', @json(__('triage_report.charts.trend_error'))));
});
</script>
@endpush
