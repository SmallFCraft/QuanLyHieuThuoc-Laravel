@extends('layouts.app')

@section('title', __('messages.reminder.response_confirm_title') . ' - ' . __('messages.app_name'))

@section('content')
<div class="py-4 py-md-5" style="background: linear-gradient(180deg, #f9fafb 0%, #ffffff 240px);">
    <div class="container" style="max-width: 640px;">

        <div class="card border-0 rounded-4 shadow-sm p-4 p-md-5 text-center">
            <i class="bi bi-clipboard2-heart d-block mb-3 {{ $improved ? 'text-success' : 'text-warning' }}"
               style="font-size: 2.75rem;" aria-hidden="true"></i>

            <h1 class="h4 fw-bold text-gray-900 mb-3">{{ __('messages.reminder.response_confirm_title') }}</h1>

            <p class="text-gray-700 mb-4">
                {{ $improved
                    ? __('messages.reminder.response_confirm_improved')
                    : __('messages.reminder.response_confirm_not_improved') }}
            </p>

            <form method="POST" action="{{ route('health.followup', $session->MaSL) }}"
                  class="d-flex flex-column flex-sm-row gap-2 justify-content-center">
                @csrf
                <input type="hidden" name="improved" value="{{ $improved ? 1 : 0 }}">

                <button type="submit" class="btn text-white fw-semibold rounded-pill px-4"
                        style="min-height:44px; background: {{ $improved ? '#047857' : '#B45309' }};">
                    <i class="bi bi-check2-circle me-2" aria-hidden="true"></i>{{ __('messages.reminder.response_confirm_button') }}
                </button>

                <a href="{{ route('health.result', $session->MaSL) }}"
                   class="btn btn-outline-secondary fw-semibold rounded-pill px-4 d-inline-flex align-items-center justify-content-center"
                   style="min-height:44px;">
                    {{ __('messages.health.btn_back') }}
                </a>
            </form>

            <p class="small text-gray-500 mt-4 mb-0">{{ __('messages.reminder.response_confirm_hint') }}</p>
        </div>

    </div>
</div>
@endsection
