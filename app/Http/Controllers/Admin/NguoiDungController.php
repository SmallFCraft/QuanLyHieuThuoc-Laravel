<?php

namespace App\Http\Controllers\Admin;

use App\Exports\QuanTriNguoiDungExport;
use App\Http\Controllers\Controller;
use App\Models\NguoiDung;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Maatwebsite\Excel\Facades\Excel;

class NguoiDungController extends Controller
{
    /**
     * Hiển thị danh sách người dùng
     */
    public function index()
    {
        $nguoiDungs = NguoiDung::orderBy('created_at', 'desc')->get();
        return view('admin.users.index', compact('nguoiDungs'));
    }

    public function export(Request $request)
    {
        return Excel::download(
            new QuanTriNguoiDungExport(
                search: $request->filled('q') ? $request->string('q')->trim()->toString() : null,
            ),
            'nguoi-dung.xlsx'
        );
    }

    /**
     * Lưu người dùng mới tạo
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:nguoi_dungs'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'role' => ['required', 'in:Administrator,Pharmacist,User'],
        ], [
            'name.required' => 'Tên là bắt buộc',
            'email.required' => 'Email là bắt buộc',
            'email.email' => 'Email không hợp lệ',
            'email.unique' => 'Email đã tồn tại',
            'password.required' => 'Mật khẩu là bắt buộc',
            'password.confirmed' => 'Xác nhận mật khẩu không khớp',
            'role.required' => 'Vai trò là bắt buộc',
        ]);

        NguoiDung::create([
            'ho_ten' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'vai_tro' => $validated['role'],
            'trang_thai' => 'active',
        ]);

        return redirect()->route('admin.nguoi-dung.index')
            ->with('success', 'Tạo người dùng thành công');
    }

    /**
     * Cập nhật người dùng chỉ định
     */
    public function update(Request $request, $MaKH)
    {
        $nguoiDung = NguoiDung::findOrFail($MaKH);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:nguoi_dungs,email,' . $MaKH . ',ma_nd'],
            'role' => ['required', 'in:Administrator,Pharmacist,User'],
            'password' => ['nullable', 'confirmed', Rules\Password::defaults()],
        ], [
            'name.required' => 'Tên là bắt buộc',
            'email.required' => 'Email là bắt buộc',
            'email.email' => 'Email không hợp lệ',
            'email.unique' => 'Email đã tồn tại',
            'role.required' => 'Vai trò là bắt buộc',
            'password.confirmed' => 'Xác nhận mật khẩu không khớp',
        ]);

        $nguoiDung->ho_ten = $validated['name'];
        $nguoiDung->email = $validated['email'];
        $nguoiDung->vai_tro = $validated['role'];

        if (!empty($validated['password'])) {
            $nguoiDung->password = Hash::make($validated['password']);
        }

        $nguoiDung->save();

        return redirect()->route('admin.nguoi-dung.index')
            ->with('success', 'Cập nhật người dùng thành công');
    }

    /**
     * Cập nhật trạng thái người dùng (kích hoạt/vô hiệu hoá)
     */
    public function updateStatus(Request $request, $MaKH)
    {
        $nguoiDung = NguoiDung::findOrFail($MaKH);

        $validated = $request->validate([
            'status' => ['required', 'in:active,inactive'],
        ]);

        $nguoiDung->trang_thai = $validated['status'];
        $nguoiDung->save();

        $message = $validated['status'] === 'active' 
            ? 'Kích hoạt người dùng thành công' 
            : 'Vô hiệu hóa người dùng thành công';

        return redirect()->route('admin.nguoi-dung.index')
            ->with('success', $message);
    }
}
