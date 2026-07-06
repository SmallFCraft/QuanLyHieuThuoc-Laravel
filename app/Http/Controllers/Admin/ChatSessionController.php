<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatbotImage;
use App\Models\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ChatSessionController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('q', ''));
        $sessionSearch = preg_replace('/^CHAT-/i', '', $search);

        $sessions = ChatMessage::query()
            ->select([
                'session_id',
                'MaKH',
                DB::raw('COUNT(*) as messages_count'),
                DB::raw('MIN(created_at) as started_at'),
                DB::raw('MAX(created_at) as last_message_at'),
            ])
            ->with('user')
            ->when($search !== '', function ($query) use ($search, $sessionSearch) {
                $query->where(function ($inner) use ($search, $sessionSearch) {
                    $inner->where('session_id', 'like', "%{$search}%")
                        ->orWhere('session_id', 'like', "%{$sessionSearch}%")
                        ->orWhereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });

                    $numericSearch = ltrim($search, '#');
                    if (ctype_digit($numericSearch)) {
                        $inner->orWhere('MaKH', (int) $numericSearch);
                    }
                });
            })
            ->groupBy('session_id', 'MaKH')
            ->orderByDesc('last_message_at')
            ->get();

        $summary = [
            'total_sessions' => $sessions->count(),
            'total_messages' => $sessions->sum(fn ($session) => (int) $session->messages_count),
            'identified_users' => $sessions->pluck('MaKH')->filter()->unique()->count(),
            'latest_at' => $sessions->first()?->last_message_at,
        ];

        return view('admin.chat-sessions.index', [
            'sessions' => $sessions,
            'search' => $search,
            'summary' => $summary,
        ]);
    }

    public function show(string $sessionId)
    {
        $messages = ChatMessage::with('user')
            ->where('session_id', $sessionId)
            ->orderBy('created_at')
            ->orderBy('MaTN')
            ->get();

        abort_if($messages->isEmpty(), 404);

        $images = ChatbotImage::with('user')
            ->where('session_id', $sessionId)
            ->orderBy('created_at')
            ->get();

        $images->each(function (ChatbotImage $image) use ($sessionId): void {
            $image->admin_url = route('admin.chatSessions.images.show', [
                'sessionId' => $sessionId,
                'image' => $image->MaAnh,
            ]);
        });

        $messageImageMap = $this->mapImagesToAssistantMessages($messages, $images);

        return view('admin.chat-sessions.show', [
            'sessionId' => $sessionId,
            'displaySessionId' => $this->displaySessionId($sessionId),
            'messages' => $messages,
            'images' => $images,
            'messageImageMap' => $messageImageMap,
            'sessionUser' => $messages->first()->user,
            'startedAt' => $messages->first()->created_at,
            'lastMessageAt' => $messages->last()->created_at,
        ]);
    }

    public function image(string $sessionId, ChatbotImage $image)
    {
        abort_if($image->session_id !== $sessionId, 404);

        $path = $image->file_path ?: $image->image_path;
        abort_if(!$path || !Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => $image->mime_type ?: 'image/jpeg',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    private function displaySessionId(string $sessionId): string
    {
        return 'CHAT-' . strtoupper(substr($sessionId, 0, 8));
    }

    private function mapImagesToAssistantMessages($messages, $images): array
    {
        $availableImages = $images->values();
        $map = [];

        foreach ($messages as $message) {
            if ($availableImages->isEmpty()) {
                break;
            }

            $matchedImage = $this->findImageNearMessage($message, $availableImages);

            if (!$matchedImage && $this->messageReferencesImageContext($message)) {
                $matchedImage = $availableImages
                    ->filter(fn (ChatbotImage $image) => $image->created_at <= $message->created_at->copy()->addSeconds(5))
                    ->sortByDesc('created_at')
                    ->first();
            }

            if (!$matchedImage) {
                continue;
            }

            $map[$message->MaTN] = $matchedImage;
        }

        return $map;
    }

    private function findImageNearMessage(ChatMessage $message, $images): ?ChatbotImage
    {
        $hasUploadMetadata = (bool) data_get($message->metadata, 'image_upload');
        $looksLikeImageResult = $message->role === 'assistant'
            && !empty($message->products)
            && $this->messageReferencesImageContext($message);

        if (!$hasUploadMetadata && !$looksLikeImageResult) {
            return null;
        }

        return $images
            ->filter(function (ChatbotImage $image) use ($message): bool {
                return $image->created_at >= $message->created_at->copy()->subSeconds(10)
                    && $image->created_at <= $message->created_at->copy()->addSeconds(10);
            })
            ->sortBy(fn (ChatbotImage $image) => abs($image->created_at->diffInSeconds($message->created_at, false)))
            ->first();
    }

    private function messageReferencesImageContext(ChatMessage $message): bool
    {
        if ((bool) data_get($message->metadata, 'image_upload')) {
            return true;
        }

        $responseType = (string) data_get($message->metadata, 'response_type', '');
        if (in_array($responseType, ['image_product_follow_up', 'image_recognition'], true)) {
            return true;
        }

        $content = mb_strtolower($message->content ?? '', 'UTF-8');

        foreach ([
            'hình ảnh',
            'hinh anh',
            'trong ảnh',
            'trong anh',
            'trong hình',
            'trong hinh',
            'ảnh này',
            'anh nay',
            'hình này',
            'hinh nay',
            'thuốc này',
            'thuoc nay',
            'sản phẩm này',
            'san pham nay',
            'loại này',
            'loai nay',
            'cái này',
            'cai nay',
            'đã gửi hình',
            'da gui hinh',
        ] as $needle) {
            if (str_contains($content, $needle)) {
                return true;
            }
        }

        return false;
    }
}
