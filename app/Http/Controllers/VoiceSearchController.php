<?php

namespace App\Http\Controllers;

use App\Services\VoiceSearchNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Chuẩn hoá chuỗi mà browser nghe được thành từ khoá tìm kiếm.
 *
 * Nhận TEXT, không nhận audio: nhận diện chạy bằng Web Speech API trong máy khách.
 * Không có route nào ở đây nhận file — xem
 * docs/superpowers/specs/2026-09-01-voice-search-design.md §1.1 để biết vì sao.
 *
 * Công khai như chính ô tìm kiếm. Ràng `auth` vào đây sẽ làm nút mic chết với đúng
 * nhóm khách hay dùng nó nhất, mà endpoint không đọc và không ghi dữ liệu của ai.
 */
class VoiceSearchController extends Controller
{
    public function normalize(Request $request, VoiceSearchNormalizer $normalizer): JsonResponse
    {
        if (! (bool) config('search.voice.enabled', true)) {
            // 404 chứ không phải 403: tắt bằng config nghĩa là tính năng không tồn
            // tại, và một 403 lại tiết lộ rằng nó có mà đang bị chặn.
            throw new NotFoundHttpException();
        }

        $validated = $request->validate([
            // Web Speech trả tối đa 3 alternative theo maxAlternatives của client.
            'alternatives' => ['required', 'array', 'min:1', 'max:3'],
            // 200 ký tự: dài hơn thì không còn là một truy vấn tìm kiếm.
            'alternatives.*' => ['required', 'string', 'max:200'],
        ]);

        return response()->json($normalizer->normalize($validated['alternatives']));
    }
}
