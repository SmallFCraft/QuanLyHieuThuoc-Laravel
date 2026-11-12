<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AnhChatbot;
use App\Models\TinNhanChat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ChatSessionController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('q', ''));
        $sessionSearch = preg_replace('/^CHAT-/i', '', $search);

        $cacPhien = TinNhanChat::query()
            ->select([
                'session_id',
                'MaKH',
                DB::raw('COUNT(*) as messages_count'),
                DB::raw('MIN(created_at) as started_at'),
                DB::raw('MAX(created_at) as last_message_at'),
            ])
            ->with('nguoiDung')
            ->when($search !== '', function ($query) use ($search, $sessionSearch) {
                $query->where(function ($inner) use ($search, $sessionSearch) {
                    $inner->where('session_id', 'like', "%{$search}%")
                        ->orWhere('session_id', 'like', "%{$sessionSearch}%")
                        ->orWhereHas('nguoiDung', function ($nguoiDungQuery) use ($search) {
                            $nguoiDungQuery->where('name', 'like', "%{$search}%")
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
            'total_sessions' => $cacPhien->count(),
            'total_messages' => $cacPhien->sum(fn ($session) => (int) $session->messages_count),
            'identified_users' => $cacPhien->pluck('MaKH')->filter()->unique()->count(),
            'latest_at' => $cacPhien->first()?->last_message_at,
        ];

        return view('admin.chat-sessions.index', [
            'sessions' => $cacPhien,
            'search' => $search,
            'summary' => $summary,
        ]);
    }

    public function show(string $sessionId)
    {
        $cacTinNhan = TinNhanChat::with('nguoiDung')
            ->where('session_id', $sessionId)
            ->orderBy('created_at')
            ->orderBy('MaTN')
            ->get();

        abort_if($cacTinNhan->isEmpty(), 404);

        $cacAnh = AnhChatbot::with('nguoiDung')
            ->where('session_id', $sessionId)
            ->orderBy('created_at')
            ->get();

        $cacAnh->each(function (AnhChatbot $anh) use ($sessionId): void {
            $anh->admin_url = route('admin.chatSessions.images.show', [
                'sessionId' => $sessionId,
                'image' => $anh->MaAnh,
            ]);
        });

        $messageImageMap = $this->mapImagesToAssistantMessages($cacTinNhan, $cacAnh);

        return view('admin.chat-sessions.show', [
            'sessionId' => $sessionId,
            'displaySessionId' => $this->displaySessionId($sessionId),
            'messages' => $cacTinNhan,
            'images' => $cacAnh,
            'messageImageMap' => $messageImageMap,
            'sessionUser' => $cacTinNhan->first()->nguoiDung,
            'startedAt' => $cacTinNhan->first()->created_at,
            'lastMessageAt' => $cacTinNhan->last()->created_at,
        ]);
    }

    public function image(string $sessionId, AnhChatbot $image)
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

    private function mapImagesToAssistantMessages($cacTinNhan, $cacAnh): array
    {
        $availableImages = $cacAnh->values();
        $map = [];

        foreach ($cacTinNhan as $tinNhan) {
            if ($availableImages->isEmpty()) {
                break;
            }

            $anhKhop = $this->findImageNearMessage($tinNhan, $availableImages);

            if (!$anhKhop && $this->messageReferencesImageContext($tinNhan)) {
                $anhKhop = $availableImages
                    ->filter(fn (AnhChatbot $anh) => $anh->created_at <= $tinNhan->created_at->copy()->addSeconds(5))
                    ->sortByDesc('created_at')
                    ->first();
            }

            if (!$anhKhop) {
                continue;
            }

            $map[$tinNhan->MaTN] = $anhKhop;
        }

        return $map;
    }

    private function findImageNearMessage(TinNhanChat $tinNhan, $cacAnh): ?AnhChatbot
    {
        $hasUploadMetadata = (bool) data_get($tinNhan->metadata, 'image_upload');
        $looksLikeImageResult = $tinNhan->role === 'assistant'
            && !empty($tinNhan->products)
            && $this->messageReferencesImageContext($tinNhan);

        if (!$hasUploadMetadata && !$looksLikeImageResult) {
            return null;
        }

        return $cacAnh
            ->filter(function (AnhChatbot $anh) use ($tinNhan): bool {
                return $anh->created_at >= $tinNhan->created_at->copy()->subSeconds(10)
                    && $anh->created_at <= $tinNhan->created_at->copy()->addSeconds(10);
            })
            ->sortBy(fn (AnhChatbot $anh) => abs($anh->created_at->diffInSeconds($tinNhan->created_at, false)))
            ->first();
    }

    private function messageReferencesImageContext(TinNhanChat $tinNhan): bool
    {
        if ((bool) data_get($tinNhan->metadata, 'image_upload')) {
            return true;
        }

        if (!empty(data_get($tinNhan->metadata, 'image_recognition'))) {
            return true;
        }

        $responseType = (string) data_get($tinNhan->metadata, 'response_type', '');
        if (in_array($responseType, ['image_product_follow_up', 'image_recognition'], true)) {
            return true;
        }

        $content = mb_strtolower($tinNhan->content ?? '', 'UTF-8');

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
