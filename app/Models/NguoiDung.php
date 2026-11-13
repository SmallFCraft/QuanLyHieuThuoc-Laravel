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
    // Cot da viet hoa thanh thoi_diem_xac_thuc_email; Breeze (VerifyEmailController,
    // MustVerifyEmail trait) goi $this->email_verified_at nen map qua accessor/mutator.
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
     * URL anh dai dien, hoac null khi chua tai len (giao dien tu do ve chu cai dau).
     *
     * Kiem tra file co that: ban ghi co the con tro toi anh da bi xoa khoi disk, luc
     * do mot <img> hong xau hon la chu cai dau.
     */
    public function avatarUrl(): ?string
    {
        if (! $this->anh_dai_dien) {
            return null;
        }

        if (! Storage::disk(config('uploads.avatars.disk', 'public'))->exists($this->anh_dai_dien)) {
            return null;
        }

        // asset(), khong phai Storage::url(): URL cua disk 'public' dung tu APP_URL, nen
        // tren dev server (cong 8123) no tra ve http://localhost/... va anh khong tai
        // duoc. asset() lay origin tu request hien tai.
        // ponytail: gan voi disk 'public' + symlink public/storage. Doi sang S3 thi
        // quay lai Storage::url() va dat APP_URL/ASSET_URL cho dung.
        return asset('storage/' . ltrim($this->anh_dai_dien, '/'));
    }

    /** Chu cai dau cua ten, dung khi chua co anh dai dien. */
    public function initial(): string
    {
        return mb_strtoupper(mb_substr(trim($this->ho_ten), 0, 1)) ?: '?';
    }
}
