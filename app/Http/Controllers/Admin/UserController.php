<?php

namespace App\Http\Controllers\Admin;

use App\Exports\AdminUsersExport;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Maatwebsite\Excel\Facades\Excel;

class UserController extends Controller
{
    /**
     * Display a listing of users
     */
    public function index()
    {
        $users = User::orderBy('created_at', 'desc')->get();
        return view('admin.users.index', compact('users'));
    }

    public function export(Request $request)
    {
        return Excel::download(
            new AdminUsersExport(
                search: $request->filled('q') ? $request->string('q')->trim()->toString() : null,
            ),
            'nguoi-dung.xlsx'
        );
    }

    /**
     * Store a newly created user
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
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

        User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'status' => 'active',
        ]);

        return redirect()->route('admin.users.index')
            ->with('success', 'Tạo người dùng thành công');
    }

    /**
     * Update the specified user
     */
    public function update(Request $request, $MaKH)
    {
        $user = User::findOrFail($MaKH);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email,' . $MaKH . ',MaKH'],
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

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->role = $validated['role'];

        if (!empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();

        return redirect()->route('admin.users.index')
            ->with('success', 'Cập nhật người dùng thành công');
    }

    /**
     * Update user status (activate/deactivate)
     */
    public function updateStatus(Request $request, $MaKH)
    {
        $user = User::findOrFail($MaKH);

        $validated = $request->validate([
            'status' => ['required', 'in:active,inactive'],
        ]);

        $user->status = $validated['status'];
        $user->save();

        $message = $validated['status'] === 'active' 
            ? 'Kích hoạt người dùng thành công' 
            : 'Vô hiệu hóa người dùng thành công';

        return redirect()->route('admin.users.index')
            ->with('success', $message);
    }
}
