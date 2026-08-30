@extends('layouts.app')

@section('title', __('messages.health.page_title') . ' - ' . __('messages.app_name'))

@section('content')
@push('styles')
    @include('health.partials.styles')
@endpush

<div class="health-page py-4 py-md-5">
    <div class="container">
        <div class="health-shell">

            {{-- Page header --}}
            <div class="text-center mb-4">
                <span class="health-eyebrow mb-3">
                    <i class="bi bi-clipboard2-pulse" aria-hidden="true"></i>
                    {{ __('messages.health.nav_full') }}
                </span>
                <h1 class="mb-2">{{ __('messages.health.page_title') }}</h1>
                <p class="mb-0 health-lede">{{ __('messages.health.page_subtitle') }}</p>
            </div>

            @include('health.partials.disclaimer')

            @if ($showDisclaimerPopup ?? false)
                @include('health.partials.disclaimer-popup')
            @endif

            {{-- Live region: the AJAX path replaces the stage without a reload, so
                 errors must be announced rather than just appearing. --}}
            <div data-triage-alerts aria-live="polite">
                @if (session('error'))
                    <div class="alert alert-warning rounded-4 mt-3 mb-0" role="alert" tabindex="-1">
                        {{ session('error') }}
                    </div>
                @endif

                @error('answer')
                    {{-- role=alert so screen readers announce it without the user hunting for it --}}
                    <div class="alert alert-danger rounded-4 mt-3 mb-0" role="alert" tabindex="-1">
                        <i class="bi bi-exclamation-circle me-1" aria-hidden="true"></i>{{ $message }}
                    </div>
                @enderror
            </div>

            {{-- The only region the AJAX layer swaps. Everything outside it — header,
                 disclaimer, footer links — stays put, which is what makes the step
                 change read as a step change rather than a page load. --}}
            <div class="health-stage mt-4" data-triage-stage data-direction="forward">
                @include('health.partials.stage')
            </div>

            <div class="d-flex flex-wrap gap-2 justify-content-center mt-4 health-no-print">
                <a href="{{ route('health.history') }}" class="health-link small">
                    <i class="bi bi-clock-history me-1" aria-hidden="true"></i>{{ __('messages.health.btn_history') }}
                </a>

                <form method="POST" action="{{ route('health.restart') }}" class="d-inline m-0">
                    @csrf
                    <button type="submit" class="health-link small">
                        <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>{{ __('messages.health.btn_restart') }}
                    </button>
                </form>

                @unless ($hasProfile)
                    <a href="{{ route('profile.edit') }}#health-profile" class="health-link health-link--accent small">
                        <i class="bi bi-person-vcard me-1" aria-hidden="true"></i>{{ __('messages.health.profile_cta') }}
                    </a>
                @endunless
            </div>
        </div>
    </div>
</div>
@endsection
