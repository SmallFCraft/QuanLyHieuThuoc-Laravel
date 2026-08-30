@extends('layouts.app')

@section('title', __('messages.reminder.page_title') . ' - ' . __('messages.app_name'))

@section('content')
<div class="py-4 py-md-5" style="background: linear-gradient(180deg, #f9fafb 0%, #ffffff 240px);">
    <div class="container" style="max-width: 860px;">

        <div class="text-center mb-4">
            <h1 class="h3 fw-bold text-gray-900 mb-2">{{ __('messages.reminder.page_title') }}</h1>
            <p class="text-gray-600 mb-0">{{ __('messages.reminder.page_subtitle') }}</p>
        </div>

        <div class="alert d-flex align-items-start gap-2 border-0 rounded-4 mb-4"
             style="background:#ECFDF5; color:#065F46;" role="note">
            <i class="bi bi-info-circle-fill mt-1" aria-hidden="true"></i>
            <span class="small">{{ __('messages.reminder.disclaimer') }}</span>
        </div>

        @if ($reminders->isEmpty())
            <div class="card border-0 rounded-4 shadow-sm p-5 text-center">
                <i class="bi bi-alarm text-gray-300 d-block mb-3" style="font-size: 3rem;" aria-hidden="true"></i>
                <p class="text-gray-600 mb-0">{{ __('messages.reminder.empty') }}</p>
            </div>
        @else
            <div class="card border-0 rounded-4 shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <caption class="visually-hidden">{{ __('messages.reminder.page_title') }}</caption>
                        <thead class="table-light">
                            <tr>
                                <th scope="col">{{ __('messages.reminder.col_product') }}</th>
                                <th scope="col">{{ __('messages.reminder.col_dosage') }}</th>
                                <th scope="col">{{ __('messages.reminder.col_times') }}</th>
                                <th scope="col">{{ __('messages.reminder.col_next') }}</th>
                                <th scope="col" class="text-end">{{ __('messages.reminder.col_action') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($reminders as $reminder)
                                @php $productName = $reminder->product->name ?? $reminder->dosage_text; @endphp
                                <tr>
                                    <td class="fw-semibold text-gray-900">{{ $productName }}</td>
                                    <td class="small text-gray-600">{{ $reminder->dosage_text }}</td>
                                    <td class="small text-gray-600">
                                        {{ __('messages.reminder.times_per_day', ['count' => $reminder->times_per_day]) }}
                                        <span class="d-block text-gray-500">{{ __('messages.reminder.duration', ['days' => $reminder->duration_days]) }}</span>
                                    </td>
                                    <td class="small text-gray-600">
                                        {{ $reminder->next_remind_at ? $reminder->next_remind_at->format('d/m/Y H:i') : __('messages.reminder.no_next') }}
                                    </td>
                                    <td class="text-end">
                                        <form method="POST" action="{{ route('reminders.cancel', $reminder->MaNN) }}" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill"
                                                    aria-label="{{ __('messages.reminder.btn_cancel_aria', ['name' => $productName]) }}">
                                                <i class="bi bi-bell-slash me-1" aria-hidden="true"></i>{{ __('messages.reminder.btn_cancel') }}
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

    </div>
</div>
@endsection
