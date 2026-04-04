<x-guest-layout>
    <div class="text-center mb-4">
        <h4 class="fw-bold text-gray-800 mb-1">{{ __('messages.auth.verify_email.title') }}</h4>
        <p class="text-gray-500 small mb-0">{{ __('messages.auth.verify_email.description') }}</p>
    </div>

    @if (session('status') == 'verification-link-sent')
        <div class="alert alert-success rounded-3 border-0 small py-2 mb-3" style="background: #ecfdf5; color: #065f46;">
            {{ __('messages.auth.verify_email.sent_success') }}
        </div>
    @endif

    <div class="d-flex flex-column gap-3">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit" class="btn btn-lg w-100 text-white fw-bold rounded-pill shadow" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                {{ __('messages.auth.verify_email.resend_btn') }}
            </button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn btn-outline-secondary w-100 rounded-pill">
                {{ __('messages.auth.verify_email.logout') }}
            </button>
        </form>
    </div>
</x-guest-layout>
