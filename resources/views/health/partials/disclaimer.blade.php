{{-- Scope notice. Never removable — a triage tool must keep its scope reachable at all
     times — but compact where the popup can be reopened to read it in full.

     Two shapes, decided by $disclaimerReviewable:
       * reopenable (the normal case): title + "Xem lại", with the body in the DOM for
         screen readers and restored by @media print for the clinic copy;
       * not reopenable (an emergency result, where the popup is suppressed so it can
         never cover the 115 button): the body renders inline and the link is withheld,
         because a link to nothing is worse than no link, and this is the page where the
         scope matters most. --}}
@php $reviewable = $disclaimerReviewable ?? true; @endphp

<div class="health-disclaimer {{ $reviewable ? '' : 'health-disclaimer--emergency' }}" role="note">
    @if ($reviewable)
        {{-- A scope notice is not a severity signal. Borrowed the four triage outcome
             tones it used to wear (#FFFBEB / #FDE68A), which made every page announce a
             referral that had not happened yet. It now sits in the page's own material. --}}
        <span class="health-disclaimer__lead">
            <span class="health-disclaimer__badge" aria-hidden="true">
                <i class="bi bi-info-circle"></i>
            </span>
            <span class="health-disclaimer__title">{{ __('messages.health.disclaimer_title') }}</span>
        </span>

        {{-- A GET link, not a form: reopening is a read, so it must not touch the daily
             flag. Rendered even while the modal is open — where it sits inside the inert
             region — because Escape closes the modal client-side without a reload, and a
             notice with no way back to the full text would be a dead end.
             fullUrlWithQuery keeps {MaSL} on the result page. --}}
        <a href="{{ request()->fullUrlWithQuery(['disclaimer' => 1]) }}"
           class="health-disclaimer__action health-no-print">
            <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>{{ __('messages.health.disclaimer_review') }}
        </a>

        <p class="health-disclaimer__body">{{ __('messages.health.disclaimer_body') }}</p>
    @else
        <i class="bi bi-info-circle-fill flex-shrink-0" aria-hidden="true" style="margin-top: 2px;"></i>
        <div>
            <p class="fw-semibold small mb-1">{{ __('messages.health.disclaimer_title') }}</p>
            <p class="mb-0 small">{{ __('messages.health.disclaimer_body') }}</p>
        </div>
    @endif
</div>
