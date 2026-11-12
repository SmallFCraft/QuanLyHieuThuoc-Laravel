<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\YeuThich;
use App\Models\NhacUongThuoc;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        $nguoiDung = $request->user();

        return view('profile.edit', [
            'user' => $nguoiDung,
            'favoriteItems' => YeuThich::profileItemsFor($nguoiDung),
            'recentOrders' => $nguoiDung->donHangs()->with('chiTietDonHangs')->latest()->limit(3)->get(),
            'orderCounts' => $nguoiDung->donHangs()
                ->selectRaw('trang_thai, COUNT(*) as total')
                ->groupBy('trang_thai')
                ->pluck('total', 'trang_thai'),
            'upcomingReminders' => NhacUongThuoc::with('sanPham')
                ->where('ma_nd', (int) $nguoiDung->ma_nd)
                ->active()
                ->orderBy('next_remind_at')
                ->limit(3)
                ->get(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        // Key form (name, birth_date, ...) giữ nguyên là hợp đồng form; map sang cột VN của model.
        $capNhat = collect($request->validated())
            ->mapWithKeys(fn ($giaTri, $khoa) => [ProfileUpdateRequest::COLUMN_MAP[$khoa] ?? $khoa => $giaTri])
            ->all();

        $request->user()->fill($capNhat);

        if ($request->user()->isDirty('email')) {
            $request->user()->thoi_diem_xac_thuc_email = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Replace the user's avatar.
     *
     * Giới hạn dung lượng và định dạng lấy từ config('uploads.images') — cùng nguồn
     * với ảnh chatbot, nên client và server không thể chặn ở hai ngưỡng khác nhau.
     */
    public function updateAvatar(Request $request): RedirectResponse
    {
        $maxSizeKb = (int) config('uploads.images.max_size', 5120);
        $allowedTypes = implode(',', (array) config('uploads.images.allowed_types', ['jpeg', 'jpg', 'png']));

        $request->validate([
            'avatar' => "required|image|mimes:{$allowedTypes}|max:{$maxSizeKb}",
        ], [
            'avatar.required' => __('messages.profile.avatar_required'),
            'avatar.image' => __('messages.profile.avatar_not_image'),
            'avatar.mimes' => __('messages.profile.avatar_bad_type'),
            'avatar.max' => __('messages.profile.avatar_too_large', ['size' => round($maxSizeKb / 1024, 1)]),
        ]);

        $nguoiDung = $request->user();
        $previous = $nguoiDung->anh_dai_dien;
        $disk = Storage::disk(config('uploads.avatars.disk', 'public'));
        $path = trim((string) config('uploads.avatars.path', 'avatars'), '/');
        $maxDimension = (int) config('uploads.avatars.max_dimension', 512);

        try {
            // Ảnh từ điện thoại thường 3000px+ trong khi avatar hiển thị lớn nhất ở
            // 96px. Chuẩn hoá về JPEG đã thu nhỏ, đồng thời loại metadata EXIF —
            // trong đó có toạ độ GPS của người dùng.
            // decodePath/encodeUsingMediaType: API của intervention/image 4.x, giống
            // ImageRecognitionService::prepareImageForProvider().
            $encoded = (new ImageManager(Driver::class))
                ->decodePath($request->file('avatar')->getRealPath())
                ->scaleDown(width: $maxDimension, height: $maxDimension)
                ->encodeUsingMediaType('image/jpeg', quality: 88);
        } catch (\Throwable $e) {
            Log::warning('Không xử lý được ảnh đại diện', ['error' => $e->getMessage()]);

            return Redirect::route('profile.edit')
                ->withErrors(['avatar' => __('messages.profile.avatar_failed')]);
        }

        $filename = $path . '/' . Str::uuid() . '.jpg';
        $disk->put($filename, (string) $encoded);

        $nguoiDung->anh_dai_dien = $filename;
        $nguoiDung->save();

        // Xoá ảnh cũ sau khi bản ghi đã trỏ sang ảnh mới: nếu save() lỗi thì người
        // dùng vẫn còn ảnh cũ chứ không mất cả hai.
        if ($previous && $previous !== $filename) {
            $disk->delete($previous);
        }

        return Redirect::route('profile.edit')->with('status', 'avatar-updated');
    }

    /**
     * Remove the user's avatar and fall back to the name initial.
     */
    public function destroyAvatar(Request $request): RedirectResponse
    {
        $nguoiDung = $request->user();

        if ($nguoiDung->anh_dai_dien) {
            Storage::disk(config('uploads.avatars.disk', 'public'))->delete($nguoiDung->anh_dai_dien);
            $nguoiDung->anh_dai_dien = null;
            $nguoiDung->save();
        }

        return Redirect::route('profile.edit')->with('status', 'avatar-removed');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $nguoiDung = $request->user();

        Auth::logout();

        $nguoiDung->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
