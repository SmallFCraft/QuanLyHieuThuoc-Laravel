<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

class NguoiDung extends Authenticatable
{
    protected $table = 'nguoi_dungs';

    /** @use HasFactory<\Database\Factories\NguoiDungFactory> */
    use HasFactory, Notifiable;

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'ma_nd';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ho_ten',
        'email',
        'ngay_sinh',
        'gioi_tinh',
        'dia_chi',
        'so_dien_thoai',
        'anh_dai_dien',
        'password',
        'vai_tro',
        'trang_thai',
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
    // Cột đã việt hóa thành thoi_diem_xac_thuc_email; Breeze (VerifyEmailController,
    // MustVerifyEmail trait) gọi $this->email_verified_at nên map qua accessor/mutator.
    public function getEmailVerifiedAtAttribute(): ?\Illuminate\Support\Carbon
    {
        return $this->thoi_diem_xac_thuc_email;
    }

    public function setEmailVerifiedAtAttribute($value): void
    {
        $this->attributes['thoi_diem_xac_thuc_email'] = $value;
    }

    public function markEmailAsVerified(): static
    {
        $this->thoi_diem_xac_thuc_email = $this->freshTimestamp();
        $this->save();

        return $this;
    }

    protected function casts(): array
    {
        return [
            'thoi_diem_xac_thuc_email' => 'datetime',
            'ngay_sinh' => 'date',
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
        return $this->vai_tro === 'Administrator';
    }

    /**
     * Check if user is a pharmacist (handles the triage review queue)
     *
     * @return bool
     */
    public function isPharmacist(): bool
    {
        return $this->vai_tro === 'Pharmacist';
    }

    /**
     * Check if user account is active
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->trang_thai === 'active';
    }

    /**
     * Get orders for this user
     */
    public function donHangs()
    {
        return $this->hasMany(DonHang::class, 'ma_nd', 'ma_nd');
    }

    /**
     * Get the health profile for this user
     */
    public function hoSoSucKhoe()
    {
        return $this->hasOne(HoSoSucKhoe::class, 'ma_nd', 'ma_nd');
    }

    /**
     * Age in whole years, or null when birth_date was never filled in.
     * Triage rules treat a null age as unknown, not as an adult.
     */
    public function ageInYears(): ?int
    {
        return $this->ngay_sinh?->age;
    }

    /**
     * URL ảnh đại diện, hoặc null khi chưa tải lên (giao diện tự đổ về chữ cái đầu).
     *
     * Kiểm tra file có thật: bản ghi có thể còn trỏ tới ảnh đã bị xoá khỏi disk, lúc
     * đó một <img> hỏng xấu hơn là chữ cái đầu.
     */
    public function avatarUrl(): ?string
    {
        if (! $this->anh_dai_dien) {
            return null;
        }

        if (! Storage::disk(config('uploads.avatars.disk', 'public'))->exists($this->anh_dai_dien)) {
            return null;
        }

        // asset(), không phải Storage::url(): URL của disk 'public' dựng từ APP_URL, nên
        // trên dev server (cổng 8123) nó trả về http://localhost/... và ảnh không tải
        // được. asset() lấy origin từ request hiện tại.
        // ponytail: gắn với disk 'public' + symlink public/storage. Đổi sang S3 thì
        // quay lại Storage::url() và đặt APP_URL/ASSET_URL cho đúng.
        return asset('storage/' . ltrim($this->anh_dai_dien, '/'));
    }

    /** Chữ cái đầu của tên, dùng khi chưa có ảnh đại diện. */
    public function initial(): string
    {
        return mb_strtoupper(mb_substr(trim($this->ho_ten), 0, 1)) ?: '?';
    }
}
