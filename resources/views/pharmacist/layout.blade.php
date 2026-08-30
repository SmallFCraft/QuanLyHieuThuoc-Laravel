<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('messages.pharmacist.area_name'))</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @include('health.partials.styles')
    <style>
        .rx-body { background: #f8fafc; font-family: 'Inter', system-ui, sans-serif; }

        /* White on #10b981 is only 2.54:1. Both gradient stops are darkened so the
           header text and the nav pills clear 4.5:1: #047857 = 5.48:1, #065F46 = 7.68:1. */
        .rx-navbar {
            background: linear-gradient(135deg, #047857, #065F46);
            color: #fff;
        }

        .rx-navbar a, .rx-navbar .rx-user { color: #fff; }

        .rx-nav-link {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            min-height: 44px;
            padding: 10px 14px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            /* A white veil this light keeps the underlying dark green dominant, so
               white text stays above 4.5:1; the active pill darkens further. */
            background: rgba(255, 255, 255, 0.12);
        }

        .rx-nav-link:hover, .rx-nav-link[aria-current="page"] { background: rgba(0, 0, 0, 0.22); }

        .rx-logout {
            min-height: 44px;
            padding: 10px 16px;
            border-radius: 10px;
            border: 1.5px solid rgba(255, 255, 255, 0.65);
            background: transparent;
            color: #fff;
            font-weight: 600;
        }

        .rx-logout:hover { background: rgba(0, 0, 0, 0.22); color: #fff; }

        .rx-nav-link:focus-visible, .rx-logout:focus-visible {
            outline: 3px solid #ffffff;
            outline-offset: 2px;
        }

        .rx-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
        }

        .rx-metric-label { color: #4b5563; font-size: .8125rem; font-weight: 500; }
        .rx-metric-value { color: #111827; font-size: 1.75rem; font-weight: 700; line-height: 1.2; }

        /* Severity badge: colour + icon + text, never colour alone. */
        .rx-badge {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: .8125rem;
            font-weight: 600;
            border: 1px solid transparent;
        }

        .rx-badge--emergency { background: var(--health-emergency-bg); color: var(--health-emergency); border-color: var(--health-emergency-border); }
        .rx-badge--urgent { background: var(--health-refer-bg); color: var(--health-refer); border-color: var(--health-refer-border); }
        .rx-badge--caution { background: var(--health-pharmacist-bg); color: var(--health-pharmacist); border-color: var(--health-pharmacist-border); }
        .rx-badge--calm { background: var(--health-selfcare-bg); color: var(--health-selfcare); border-color: var(--health-selfcare-border); }

        .rx-btn {
            min-height: 44px;
            padding: 11px 20px;
            border-radius: 10px;
            font-weight: 600;
            border: 1.5px solid transparent;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .4rem;
            cursor: pointer;
        }

        /* White on #059669 is 3.77:1 — under the threshold. #047857 = 5.48:1. */
        .rx-btn-primary { background: #047857; color: #fff; }
        .rx-btn-primary:hover { background: #065F46; color: #fff; }

        .rx-btn-ghost { background: #fff; border-color: #d1d5db; color: #374151; }
        .rx-btn-ghost:hover { background: #f3f4f6; color: #111827; }

        /* Escalation is the heavier decision: warning tone, kept away from "reply". */
        .rx-btn-warning { background: var(--health-refer); color: #fff; }
        .rx-btn-warning:hover { background: #92400E; color: #fff; }

        .rx-btn:focus-visible { outline: none; box-shadow: var(--health-focus-ring); }

        .rx-textarea {
            width: 100%;
            /* 16px keeps iOS Safari from zooming the viewport on focus. */
            font-size: 1rem;
            line-height: 1.6;
            border: 1.5px solid #d1d5db;
            border-radius: 12px;
            padding: 12px 14px;
        }

        .rx-textarea:focus { outline: none; border-color: #10b981; box-shadow: var(--health-focus-ring); }

        .rx-actions { display: flex; flex-wrap: wrap; gap: 12px; }

        .rx-dl dt { color: #4b5563; font-size: .8125rem; font-weight: 500; }
        .rx-dl dd { color: #111827; margin-bottom: .75rem; }

        .rx-table th { color: #374151; font-size: .8125rem; text-transform: uppercase; letter-spacing: .02em; }
        .rx-table td { vertical-align: middle; }
    </style>
    @stack('styles')
</head>
<body class="rx-body health-page">
    <a href="#rx-main" class="visually-hidden-focusable p-2 d-inline-block">Bỏ qua điều hướng</a>

    <header class="rx-navbar">
        <nav class="container py-3 d-flex flex-wrap align-items-center justify-content-between gap-3"
             aria-label="{{ __('messages.pharmacist.area_name') }}">
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <span class="fw-bold d-inline-flex align-items-center gap-2">
                    <i class="bi bi-prescription2 fs-5" aria-hidden="true"></i>
                    {{ __('messages.pharmacist.area_name') }}
                </span>
                <a href="{{ route('pharmacist.triage.index') }}"
                   class="rx-nav-link"
                   @if(request()->routeIs('pharmacist.triage.*')) aria-current="page" @endif>
                    <i class="bi bi-inboxes-fill" aria-hidden="true"></i>{{ __('messages.pharmacist.nav_queue') }}
                </a>
                <a href="{{ route('home') }}" class="rx-nav-link">
                    <i class="bi bi-house-door" aria-hidden="true"></i>{{ __('messages.pharmacist.nav_home') }}
                </a>
            </div>

            <div class="d-flex align-items-center gap-3 flex-wrap">
                <span class="rx-user small">
                    <span class="d-block opacity-75">{{ __('messages.pharmacist.signed_in_as') }}</span>
                    <strong>{{ auth()->user()->name }}</strong>
                </span>
                <form method="POST" action="{{ route('logout') }}" class="m-0">
                    @csrf
                    <button type="submit" class="rx-logout">
                        <i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i>{{ __('messages.pharmacist.nav_logout') }}
                    </button>
                </form>
            </div>
        </nav>
    </header>

    <main id="rx-main" class="container py-4 py-md-5">
        @if(session('success'))
            <div class="alert alert-success d-flex align-items-start gap-2" role="status">
                <i class="bi bi-check-circle-fill mt-1" aria-hidden="true"></i>
                <div>{{ session('success') }}</div>
            </div>
        @endif

        @if(session('error'))
            <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
                <i class="bi bi-exclamation-triangle-fill mt-1" aria-hidden="true"></i>
                <div>{{ session('error') }}</div>
            </div>
        @endif

        @yield('content')
    </main>

    @stack('scripts')
</body>
</html>
