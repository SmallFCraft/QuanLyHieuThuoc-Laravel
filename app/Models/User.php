<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'MaKH';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'birth_date',
        'gender',
        'address',
        'phone',
        'avatar',
        'password',
        'role',
        'status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'birth_date' => 'date',
            'password' => 'hashed',
        ];
    }
    
    /**
     * Check if user is an administrator
     *
     * @return bool
     */
    public function isAdministrator(): bool
    {
        return $this->role === 'Administrator';
    }

    /**
     * Check if user is a pharmacist (handles the triage review queue)
     *
     * @return bool
     */
    public function isPharmacist(): bool
    {
        return $this->role === 'Pharmacist';
    }
    
    /**
     * Check if user account is active
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }
    
    /**
     * Get orders for this user
     */
    public function orders()
    {
        return $this->hasMany(Order::class, 'MaKH', 'MaKH');
    }
    
    /**
     * Get the health profile for this user
     */
    public function healthProfile()
    {
        return $this->hasOne(UserHealthProfile::class, 'MaKH', 'MaKH');
    }

    /**
     * Age in whole years, or null when birth_date was never filled in.
     * Triage rules treat a null age as unknown, not as an adult.
     */
    public function ageInYears(): ?int
    {
        return $this->birth_date?->age;
    }

    /**
     * URL ảnh đại diện, hoặc null khi chưa tải lên (giao diện tự đổ về chữ cái đầu).
     *
     * Kiểm tra file có thật: bản ghi có thể còn trỏ tới ảnh đã bị xoá khỏi disk, lúc
     * đó một <img> hỏng xấu hơn là chữ cái đầu.
     */
    public function avatarUrl(): ?string
    {
        if (! $this->avatar) {
            return null;
        }

        if (! Storage::disk(config('uploads.avatars.disk', 'public'))->exists($this->avatar)) {
            return null;
        }

        // asset(), không phải Storage::url(): URL của disk 'public' dựng từ APP_URL, nên
        // trên dev server (cổng 8123) nó trả về http://localhost/... và ảnh không tải
        // được. asset() lấy origin từ request hiện tại.
        // ponytail: gắn với disk 'public' + symlink public/storage. Đổi sang S3 thì
        // quay lại Storage::url() và đặt APP_URL/ASSET_URL cho đúng.
        return asset('storage/' . ltrim($this->avatar, '/'));
    }

    /** Chữ cái đầu của tên, dùng khi chưa có ảnh đại diện. */
    public function initial(): string
    {
        return mb_strtoupper(mb_substr(trim($this->name), 0, 1)) ?: '?';
    }
}
