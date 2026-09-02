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
            //
            // Rule `string` KHÔNG kiểm encoding, và một byte UTF-8 hỏng đi tới
            // response()->json() là một 500 không cần đăng nhập. Chặn ở biên chứ
            // không sửa ở trong: một chuỗi không phải UTF-8 thì Web Speech không
            // sinh ra được, nên nó không phải khách thật.
            //
            // is_string trước mb_check_encoding: rule `string` trượt KHÔNG chặn
            // closure này chạy (không có `bail`), nên một phần tử mảng lồng vào đây
            // là "Array to string conversion" — lại đúng cái 500 đang phải đóng.
            'alternatives.*' => ['required', 'string', 'max:200', function (string $attribute, mixed $value, callable $fail): void {
                if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                    $fail('Chuỗi không phải UTF-8 hợp lệ.');
                }
            }],
        ]);

        // array_values: khoá không tuần tự ({"a": "..."}) qua được `array|min:1`
        // nhưng $alternatives[0] thì miss, nên `heard` rỗng trong khi `text` có chữ
        // và nút "Dùng nguyên văn" không khôi phục được gì.
        return response()->json($normalizer->normalize(array_values($validated['alternatives'])));
    }
}
