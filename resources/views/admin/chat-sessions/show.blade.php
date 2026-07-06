@extends('layouts.admin')

@section('title', 'Chi tiết phiên chat')
@section('page-title', 'Chi tiết phiên chat AI')
@section('page-subtitle', $displaySessionId . ' · ' . ($sessionUser?->name ?? 'Không rõ người dùng'))

@section('content')
@php
    $userMessages = $messages->where('role', 'user')->count();
    $assistantMessages = $messages->where('role', 'assistant')->count();
    $formatChatMarkdown = function (?string $content): string {
        $html = e((string) $content);
        $html = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $html) ?? $html;
        $html = preg_replace('/`([^`]+)`/', '<code>$1</code>', $html) ?? $html;
        $paragraphs = preg_split("/\R{2,}/", trim($html), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (empty($paragraphs)) {
            return '';
        }

        return collect($paragraphs)
            ->map(fn (string $paragraph): string => '<p>' . preg_replace("/\R/", '<br>', $paragraph) . '</p>')
            ->implode('');
    };
@endphp

<div class="container-fluid px-0">
    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-4">
        <a class="btn btn-outline-secondary align-self-start" href="{{ route('admin.chatSessions.index') }}">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Quay lại danh sách
        </a>
        <div class="admin-session-code">
            <span>{{ $displaySessionId }}</span>
            <code>{{ $sessionId }}</code>
        </div>
    </div>

    <div class="row g-3 g-xl-4 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="metric-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="label">Tổng tin nhắn</div>
                        <div class="value">{{ number_format($messages->count()) }}</div>
                    </div>
                    <div class="icon"><i class="bi bi-chat-left-text" aria-hidden="true"></i></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="metric-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="label">Người dùng gửi</div>
                        <div class="value">{{ number_format($userMessages) }}</div>
                    </div>
                    <div class="icon" style="background:#eff6ff;color:#0369a1;"><i class="bi bi-person" aria-hidden="true"></i></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="metric-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="label">AI phản hồi</div>
                        <div class="value">{{ number_format($assistantMessages) }}</div>
                    </div>
                    <div class="icon" style="background:#f0fdf4;color:#15803d;"><i class="bi bi-stars" aria-hidden="true"></i></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="metric-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="label">Ảnh/OCR</div>
                        <div class="value">{{ number_format($images->count()) }}</div>
                    </div>
                    <div class="icon" style="background:#fff7ed;color:#b45309;"><i class="bi bi-image" aria-hidden="true"></i></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 g-xl-4">
        <div class="col-xl-4">
            <section class="admin-card h-100">
                <div class="admin-card__header">
                    <div>
                        <h2 class="admin-card__title">
                            <i class="bi bi-info-circle me-2 text-success" aria-hidden="true"></i>Thông tin phiên
                        </h2>
                        <p class="admin-card__description">Dữ liệu dùng để đối chiếu khi chatbot trả lời sai hoặc gặp lỗi.</p>
                    </div>
                </div>

                <dl class="admin-detail-list mb-0">
                    <div>
                        <dt>Mã ngắn</dt>
                        <dd>{{ $displaySessionId }}</dd>
                    </div>
                    <div>
                        <dt>Session ID</dt>
                        <dd><code class="text-break">{{ $sessionId }}</code></dd>
                    </div>
                    <div>
                        <dt>Người dùng</dt>
                        <dd>
                            <span class="fw-semibold">{{ $sessionUser?->name ?? 'Không rõ' }}</span>
                            @if($sessionUser?->email)
                                <span class="d-block small text-secondary">{{ $sessionUser->email }}</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt>Bắt đầu</dt>
                        <dd><time datetime="{{ $startedAt->toIso8601String() }}">{{ $startedAt->format('d/m/Y H:i') }}</time></dd>
                    </div>
                    <div>
                        <dt>Cập nhật cuối</dt>
                        <dd><time datetime="{{ $lastMessageAt->toIso8601String() }}">{{ $lastMessageAt->format('d/m/Y H:i') }}</time></dd>
                    </div>
                </dl>
            </section>
        </div>

        <div class="col-xl-8">
            <section class="admin-card">
                <div class="admin-card__header">
                    <div>
                        <h2 class="admin-card__title">
                            <i class="bi bi-chat-square-dots me-2 text-success" aria-hidden="true"></i>Hội thoại
                        </h2>
                        <p class="admin-card__description">Timeline lưu từ bảng chat_messages theo đúng phiên.</p>
                    </div>
                </div>

                <div class="admin-chat-timeline">
                    @foreach($messages as $message)
                        @php
                            $roleLabels = [
                                'user' => 'Người dùng',
                                'assistant' => 'Trợ lý AI',
                                'system' => 'Hệ thống',
                            ];
                            $roleIcons = [
                                'user' => 'bi-person',
                                'assistant' => 'bi-stars',
                                'system' => 'bi-gear',
                            ];
                            $roleClass = [
                                'user' => 'admin-chat-bubble--user',
                                'assistant' => 'admin-chat-bubble--assistant',
                                'system' => 'admin-chat-bubble--system',
                            ][$message->role] ?? 'admin-chat-bubble--system';
                            $metadata = $message->metadata ?? [];
                            $debug = $metadata['debug'] ?? [];
                            $responseType = $metadata['response_type'] ?? null;
                            $aiProviderUsed = (bool) data_get($debug, 'ai_provider_used', false);
                            $fallbackType = data_get($debug, 'fallback_type');
                            $providerErrorType = data_get($debug, 'provider_error_type');
                            $providerMode = data_get($debug, 'ai_provider_mode');
                            $providerModel = data_get($debug, 'ai_provider_model', data_get($debug, 'ai_model'));
                            if (!$providerModel && ($aiProviderUsed || $providerMode)) {
                                $providerModel = config('chatbot.ai.model');
                            }
                            $messageSource = null;
                            $messageImage = $messageImageMap[$message->MaTN] ?? null;
                            $messageImageLabel = data_get($message->metadata, 'image_upload')
                                ? 'Ảnh người dùng gửi'
                                : 'Ảnh tham chiếu';

                            if ($message->role === 'assistant') {
                                if ($fallbackType || $providerErrorType) {
                                    $messageSource = [
                                        'label' => 'Fallback',
                                        'class' => 'admin-chat-source-badge--fallback',
                                        'icon' => 'bi-exclamation-triangle',
                                    ];
                                } elseif ($aiProviderUsed) {
                                    $messageSource = [
                                        'label' => 'AI Provider',
                                        'class' => 'admin-chat-source-badge--provider',
                                        'icon' => 'bi-cpu',
                                    ];
                                } elseif ($responseType) {
                                    $messageSource = [
                                        'label' => 'Local rule',
                                        'class' => 'admin-chat-source-badge--local',
                                        'icon' => 'bi-lightning-charge',
                                    ];
                                } else {
                                    $messageSource = [
                                        'label' => 'Chưa rõ',
                                        'class' => 'admin-chat-source-badge--unknown',
                                        'icon' => 'bi-question-circle',
                                    ];
                                }
                            }
                            $categories = $message->metadata['categories'] ?? [];
                        @endphp

                        <article class="admin-chat-entry {{ $message->role === 'user' ? 'admin-chat-entry--user' : '' }}">
                            <div class="admin-chat-bubble {{ $roleClass }}">
                                <div class="admin-chat-meta">
                                    <div class="admin-chat-meta-main">
                                        <span class="admin-chat-role">
                                            <i class="bi {{ $roleIcons[$message->role] ?? 'bi-circle' }}" aria-hidden="true"></i>
                                            {{ $roleLabels[$message->role] ?? $message->role }}
                                        </span>
                                        @if($messageSource)
                                            <span class="admin-chat-source-badge {{ $messageSource['class'] }}">
                                                <i class="bi {{ $messageSource['icon'] }}" aria-hidden="true"></i>
                                                {{ $messageSource['label'] }}
                                            </span>
                                        @endif
                                    </div>
                                    <div class="admin-chat-meta-side">
                                        <time class="small text-secondary" datetime="{{ $message->created_at->toIso8601String() }}">
                                            {{ $message->created_at->format('d/m/Y H:i') }}
                                        </time>
                                    </div>
                                </div>

                                @if($responseType || $providerMode || $providerModel || $fallbackType || $providerErrorType)
                                    <details class="admin-chat-debug-details">
                                        <summary>
                                            <i class="bi bi-sliders2" aria-hidden="true"></i>
                                            Chi tiết xử lý
                                        </summary>
                                        <div class="admin-chat-debug-strip" aria-label="Thông tin xử lý chatbot">
                                            @if($responseType)
                                                <span>Luồng: <strong>{{ $responseType }}</strong></span>
                                            @endif
                                            @if($providerMode)
                                                <span>Mode: <strong>{{ $providerMode }}</strong></span>
                                            @endif
                                            @if($providerModel)
                                                <span>Model: <strong>{{ $providerModel }}</strong></span>
                                            @endif
                                            @if($fallbackType)
                                                <span>Fallback: <strong>{{ $fallbackType }}</strong></span>
                                            @endif
                                            @if($providerErrorType)
                                                <span>Lỗi provider: <strong>{{ $providerErrorType }}</strong></span>
                                            @endif
                                        </div>
                                    </details>
                                @endif

                                @if($message->role === 'user')
                                    <div class="admin-chat-content">{{ $message->content }}</div>
                                @else
                                    <div class="admin-chat-content admin-chat-markdown">{!! $formatChatMarkdown($message->content) !!}</div>
                                @endif

                                @if($messageImage)
                                    <div class="admin-chat-message-image mt-3">
                                        <a href="{{ $messageImage->admin_url }}" target="_blank" rel="noopener" class="admin-chat-message-image__preview">
                                            <img src="{{ $messageImage->admin_url }}" alt="Ảnh người dùng gửi trong phiên {{ $displaySessionId }}" loading="lazy">
                                        </a>
                                        <div class="admin-chat-message-image__body">
                                            <div class="fw-semibold">Ảnh đã xử lý</div>
                                            <div class="mb-1">
                                                <span class="admin-chat-image-context-badge">{{ $messageImageLabel }}</span>
                                            </div>
                                            <div class="small text-secondary">
                                                {{ strtoupper($messageImage->image_type) }}
                                                · {{ number_format((int) $messageImage->file_size / 1024, 1) }} KB
                                                · {{ optional($messageImage->processed_at ?? $messageImage->created_at)->format('d/m/Y H:i') }}
                                            </div>
                                            @if($messageImage->ocr_text)
                                                <div class="admin-chat-message-image__ocr">{{ $messageImage->ocr_text }}</div>
                                            @endif
                                        </div>
                                    </div>
                                @endif

                                @if(!empty($message->products))
                                    <div class="admin-chat-related mt-3">
                                        <div class="small fw-semibold mb-2">Sản phẩm liên quan</div>
                                        <div class="d-flex flex-column gap-2">
                                            @foreach($message->products as $product)
                                                @php
                                                    $matchedProduct = data_get($product, 'product');
                                                    $productPayload = is_array($matchedProduct) ? $matchedProduct : $product;
                                                    $isMatched = (bool) data_get($product, 'matched', data_get($productPayload, 'id') || data_get($productPayload, 'MaSP'));
                                                    $productName = data_get($productPayload, 'name') ?: data_get($product, 'parsed_name', 'Không rõ tên');
                                                    $productId = data_get($productPayload, 'id', data_get($productPayload, 'MaSP'));
                                                    $stockQuantity = data_get($productPayload, 'stock_quantity');
                                                    $parsedName = data_get($product, 'parsed_name');
                                                    $dosage = data_get($product, 'dosage');
                                                    $confidence = data_get($product, 'confidence');
                                                    $suggestions = data_get($product, 'suggestions', []);
                                                @endphp
                                                <div class="admin-chat-related-item {{ $isMatched ? 'admin-chat-related-item--matched' : 'admin-chat-related-item--unmatched' }}">
                                                    <div class="d-flex flex-column flex-sm-row justify-content-between gap-2">
                                                        <div>
                                                            <div class="fw-semibold">{{ $productName }}</div>
                                                            @if($parsedName && $parsedName !== $productName)
                                                                <div class="small text-secondary">Nhận dạng: {{ $parsedName }}</div>
                                                            @endif
                                                        </div>
                                                        <span class="admin-chat-match-badge {{ $isMatched ? 'admin-chat-match-badge--matched' : 'admin-chat-match-badge--unmatched' }}">
                                                            {{ $isMatched ? 'Đã khớp kho' : 'Chưa khớp kho' }}
                                                        </span>
                                                    </div>
                                                    <div class="small text-secondary">
                                                        Mã: {{ $productId ?: 'N/A' }}
                                                        · Tồn: {{ $stockQuantity ?? 'N/A' }}
                                                        @if($dosage)
                                                            · Liều/nhãn: {{ $dosage }}
                                                        @endif
                                                        @if($confidence)
                                                            · Tin cậy: {{ number_format((float) $confidence * 100, 0) }}%
                                                        @endif
                                                    </div>
                                                    @if(!$isMatched && !empty($suggestions))
                                                        <div class="small mt-2">
                                                            <span class="text-secondary">Gợi ý gần đúng:</span>
                                                            @foreach($suggestions as $suggestion)
                                                                <span class="badge text-bg-light">{{ data_get($suggestion, 'name') }}</span>
                                                            @endforeach
                                                        </div>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                @if(!empty($categories))
                                    <div class="mt-3">
                                        <div class="small fw-semibold mb-2">Danh mục gợi ý</div>
                                        <div class="d-flex flex-wrap gap-2">
                                            @foreach($categories as $category)
                                                <span class="badge text-bg-light">{{ data_get($category, 'name', 'Không rõ') }}</span>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                @if($message->show_disclaimer)
                                    <div class="alert alert-warning py-2 px-3 mt-3 mb-0 small">
                                        <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
                                        {{ $message->metadata['disclaimer'] ?? __('messages.chatbot.disclaimer_text') }}
                                    </div>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>

            <section class="admin-card mt-3">
                <div class="admin-card__header">
                    <div>
                        <h2 class="admin-card__title">
                            <i class="bi bi-card-image me-2 text-success" aria-hidden="true"></i>Ảnh và OCR
                        </h2>
                        <p class="admin-card__description">Các ảnh đơn thuốc/hóa đơn đã xử lý trong cùng phiên.</p>
                    </div>
                </div>

                <div class="admin-chat-image-list">
                    @forelse($images as $image)
                        <article class="admin-chat-image-card">
                            <div class="admin-chat-image-card__layout">
                                <a href="{{ $image->admin_url }}" target="_blank" rel="noopener" class="admin-chat-image-thumb">
                                    <img src="{{ $image->admin_url }}" alt="{{ $image->original_name ?? $image->filename ?? 'Ảnh chatbot' }}" loading="lazy">
                                </a>
                                <div class="admin-chat-image-card__body">
                                    <div class="d-flex flex-column flex-md-row justify-content-between gap-2 mb-2">
                                        <div>
                                            <strong>{{ $image->original_name ?? $image->filename ?? 'Ảnh chatbot' }}</strong>
                                            <div class="small text-secondary">
                                                {{ strtoupper($image->image_type) }} · {{ number_format((int) $image->file_size / 1024, 1) }} KB
                                            </div>
                                        </div>
                                        <time class="small text-secondary" datetime="{{ optional($image->processed_at ?? $image->created_at)->toIso8601String() }}">
                                            {{ optional($image->processed_at ?? $image->created_at)->format('d/m/Y H:i') }}
                                        </time>
                                    </div>

                                    @if($image->ocr_text)
                                        <pre class="admin-chat-ocr mb-0">{{ $image->ocr_text }}</pre>
                                    @else
                                        <div class="admin-empty-state mb-0">Không có nội dung OCR.</div>
                                    @endif
                                </div>
                            </div>
                        </article>
                    @empty
                        <div class="admin-empty-state">Phiên này chưa có ảnh chatbot.</div>
                    @endforelse
                </div>
            </section>
        </div>
    </div>
</div>
@endsection
