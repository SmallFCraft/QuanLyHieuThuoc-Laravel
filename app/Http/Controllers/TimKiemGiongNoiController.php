<?php

namespace App\Http\Controllers;

use App\Services\ChuanHoaTimKiemGiongNoi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Chuan hoa chuoi ma browser nghe duoc thanh tu khoa tim kiem.
 *
 * Nhan TEXT, khong nhan audio: nhan dien chay bang Web Speech API trong may khach.
 * Khong co route nao o day nhan file — audio khong bao gio roi may khach.
 *
 * Cong khai nhu chinh o tim kiem. Rang `auth` vao day se lam nut mic chet voi dung
 * nhom khach hay dung no nhat, ma endpoint khong doc va khong ghi du lieu cua ai.
 */
class TimKiemGiongNoiController extends Controller
{
    public function normalize(Request $request, ChuanHoaTimKiemGiongNoi $normalizer): JsonResponse
    {
        if (! (bool) config('search.voice.enabled', true)) {
            // 404 chu khong phai 403: tat bang config nghia la tinh nang khong ton
            // tai, va mot 403 lai tiet lo rang no co ma dang bi chan.
            throw new NotFoundHttpException();
        }

        $validated = $request->validate([
            // Web Speech tra toi da 3 alternative theo maxAlternatives cua client.
            'alternatives' => ['required', 'array', 'min:1', 'max:3'],
            // 200 ky tu: dai hon thi khong con la mot truy van tim kiem.
            //
            // Rule `string` KHONG kiem encoding, va mot byte UTF-8 hong di toi
            // response()->json() la mot 500 khong can dang nhap. Chan o bien chu
            // khong sua o trong: mot chuoi khong phai UTF-8 thi Web Speech khong
            // sinh ra duoc, nen no khong phai khach that.
            //
            // is_string truoc mb_check_encoding: rule `string` truot KHONG chan
            // closure nay chay (khong co `bail`), nen mot phan tu mang long vao day
            // la "Array to string conversion" — lai dung cai 500 dang phai dong.
            'alternatives.*' => ['required', 'string', 'max:200', function (string $attribute, mixed $value, callable $fail): void {
                if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                    $fail('Chuỗi không phải UTF-8 hợp lệ.');
                }
            }],
        ]);

        // array_values: khoa khong tuan tu ({"a": "..."}) qua duoc `array|min:1`
        // nhung $alternatives[0] thi miss, nen `heard` rong trong khi `text` co chu
        // va nut "Dung nguyen van" khong khoi phuc duoc gi.
        return response()->json($normalizer->chuanHoa(array_values($validated['alternatives'])));
    }
}
