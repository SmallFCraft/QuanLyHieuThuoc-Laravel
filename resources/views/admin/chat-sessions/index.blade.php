@extends('layouts.admin')

@section('title', 'Phiên chat AI')
@section('page-title', 'Phiên chat AI')
@section('page-subtitle', 'Tra cứu hội thoại chatbot theo mã phiên, người dùng hoặc email')

@section('content')
@php
    $latestAt = $summary['latest_at'] ? \Carbon\Carbon::parse($summary['latest_at']) : null;
@endphp

<div class="container-fluid px-0">
    <div class="row g-3 g-xl-4 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="metric-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="label">Phiên hiển thị</div>
                        <div class="value">{{ number_format($summary['total_sessions']) }}</div>
                    </div>
                    <div class="icon"><i class="bi bi-chat-dots" aria-hidden="true"></i></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="metric-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="label">Tổng tin nhắn</div>
                        <div class="value">{{ number_format($summary['total_messages']) }}</div>
                    </div>
                    <div class="icon" style="background:#eff6ff;color:#0369a1;"><i class="bi bi-chat-left-text" aria-hidden="true"></i></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="metric-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="label">Người dùng</div>
                        <div class="value">{{ number_format($summary['identified_users']) }}</div>
                    </div>
                    <div class="icon" style="background:#f0fdf4;color:#15803d;"><i class="bi bi-person-check" aria-hidden="true"></i></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="metric-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="label">Mới cập nhật</div>
                        <div class="value admin-metric-date">{{ $latestAt ? $latestAt->format('d/m H:i') : '--' }}</div>
                    </div>
                    <div class="icon" style="background:#fff7ed;color:#b45309;"><i class="bi bi-clock-history" aria-hidden="true"></i></div>
                </div>
            </div>
        </div>
    </div>

    <section class="admin-card">
        <div class="admin-card__header">
            <div>
                <h2 class="admin-card__title">
                    <i class="bi bi-chat-square-text me-2 text-success" aria-hidden="true"></i>Danh sách phiên chat
                </h2>
                <p class="admin-card__description">DataTables hỗ trợ tìm kiếm nhanh, sắp xếp và phân trang giống các màn hình quản lý khác.</p>
            </div>
        </div>

        <div class="admin-toolbar">
            <form method="GET" action="{{ route('admin.chatSessions.index') }}" class="row g-2 flex-grow-1 align-items-end">
                <div class="col-12 col-lg-8 col-xl-9">
                    <label class="form-label" for="chatSessionSearch">
                        <i class="bi bi-search me-1" aria-hidden="true"></i>Tìm theo mã phiên, tên, email hoặc mã user
                    </label>
                    <input
                        type="search"
                        name="q"
                        id="chatSessionSearch"
                        class="form-control"
                        value="{{ $search }}"
                        placeholder="VD: CHAT-1A2B3C4D, email@example.com, Nguyễn Văn A hoặc #12"
                    >
                </div>
                <div class="col-12 col-lg-4 col-xl-3 d-flex gap-2">
                    <button class="btn btn-primary flex-fill" type="submit">
                        <i class="bi bi-search me-1" aria-hidden="true"></i>Tìm kiếm
                    </button>
                    @if($search !== '')
                        <a class="btn btn-outline-secondary flex-fill" href="{{ route('admin.chatSessions.index') }}">
                            <i class="bi bi-x-circle me-1" aria-hidden="true"></i>Xóa lọc
                        </a>
                    @endif
                </div>
            </form>
        </div>

        @if($search !== '')
            <div class="alert alert-info d-flex align-items-start gap-2 py-2" role="status">
                <i class="bi bi-info-circle mt-1" aria-hidden="true"></i>
                <div>
                    Đang lọc theo <strong>{{ $search }}</strong>. Có {{ number_format($summary['total_sessions']) }} phiên phù hợp.
                </div>
            </div>
        @endif

        <div class="admin-table-wrap admin-desktop-table">
            <table id="chatSessionsTable" class="table table-hover align-middle w-100">
                <thead>
                    <tr>
                        <th>Mã phiên</th>
                        <th>Người dùng</th>
                        <th>Số tin</th>
                        <th>Bắt đầu</th>
                        <th>Cập nhật cuối</th>
                        <th class="text-center">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($sessions as $session)
                        @php
                            $displayId = 'CHAT-' . strtoupper(substr($session->session_id, 0, 8));
                            $startedAt = \Carbon\Carbon::parse($session->started_at);
                            $lastMessageAt = \Carbon\Carbon::parse($session->last_message_at);
                            $userName = $session->user?->name ?? 'Không rõ';
                            $userMeta = $session->user?->email ?? 'User #' . $session->MaKH;
                        @endphp
                        <tr>
                            <td>
                                <div class="admin-session-code admin-session-code--compact">
                                    <span>{{ $displayId }}</span>
                                    <code>{{ $session->session_id }}</code>
                                </div>
                            </td>
                            <td>
                                <div class="fw-semibold">{{ $userName }}</div>
                                <div class="small text-secondary">{{ $userMeta }}</div>
                            </td>
                            <td data-order="{{ (int) $session->messages_count }}">
                                <span class="badge text-bg-light">{{ number_format((int) $session->messages_count) }} tin</span>
                            </td>
                            <td data-order="{{ $startedAt->timestamp }}">
                                <time datetime="{{ $startedAt->toIso8601String() }}">{{ $startedAt->format('d/m/Y H:i') }}</time>
                            </td>
                            <td data-order="{{ $lastMessageAt->timestamp }}">
                                <time datetime="{{ $lastMessageAt->toIso8601String() }}">{{ $lastMessageAt->format('d/m/Y H:i') }}</time>
                            </td>
                            <td class="text-center">
                                <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.chatSessions.show', $session->session_id) }}" aria-label="Xem hội thoại {{ $displayId }}">
                                    <i class="bi bi-eye me-1" aria-hidden="true"></i>Xem
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="admin-mobile-list" aria-live="polite">
            @forelse($sessions as $session)
                @php
                    $displayId = 'CHAT-' . strtoupper(substr($session->session_id, 0, 8));
                    $lastMessageAt = \Carbon\Carbon::parse($session->last_message_at);
                    $userName = $session->user?->name ?? 'Không rõ';
                    $userMeta = $session->user?->email ?? 'User #' . $session->MaKH;
                @endphp
                <article class="admin-mobile-item">
                    <div class="d-flex justify-content-between gap-3">
                        <div class="min-w-0">
                            <strong>{{ $displayId }}</strong>
                            <div class="small text-secondary text-break">{{ $session->session_id }}</div>
                            <div class="small mt-2">{{ $userName }}</div>
                            <div class="small text-secondary">{{ $userMeta }}</div>
                        </div>
                        <span class="badge text-bg-light align-self-start">{{ number_format((int) $session->messages_count) }} tin</span>
                    </div>
                    <div class="small text-secondary mt-2">Cập nhật: {{ $lastMessageAt->format('d/m/Y H:i') }}</div>
                    <a class="btn btn-sm btn-outline-primary w-100 mt-3" href="{{ route('admin.chatSessions.show', $session->session_id) }}">
                        <i class="bi bi-eye me-1" aria-hidden="true"></i>Xem hội thoại
                    </a>
                </article>
            @empty
                <div class="admin-empty-state">Chưa có phiên chat phù hợp.</div>
            @endforelse
        </div>
    </section>
</div>
@endsection

@push('scripts')
<script type="module">
document.addEventListener('DOMContentLoaded', function() {
    const table = $('#chatSessionsTable').DataTable({
        language: window.AdminUI.dtLanguage,
        order: [[4, 'desc']],
        pageLength: 25,
        responsive: true,
        columnDefs: [
            { targets: 5, orderable: false, searchable: false },
        ],
        dom: '<"row align-items-center mb-3 g-2"<"col-md-6"l><"col-md-6"f>>rt<"row align-items-center mt-3 g-2"<"col-md-6"i><"col-md-6"p>>',
    });

    const initialSearch = @json($search);
    if (initialSearch) {
        table.search(initialSearch).draw();
    }
});
</script>
@endpush
