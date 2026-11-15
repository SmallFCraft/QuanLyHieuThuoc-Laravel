<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NhacUongThuoc extends Model
{
    protected $table = 'nhac_uong_thuocs';

    protected $primaryKey = 'ma_nn';

    protected $fillable = [
        'ma_nd',
        'ma_dh',
        'ma_sp',
        'dosage_text',
        'times_per_day',
        'duration_days',
        'start_date',
        'next_remind_at',
        'follow_up_at',
        'status',
    ];

    protected $casts = [
        'times_per_day' => 'integer',
        'duration_days' => 'integer',
        'start_date' => 'date',
        'next_remind_at' => 'datetime',
        'follow_up_at' => 'datetime',
    ];

    public function nguoiDung()
    {
        return $this->belongsTo(NguoiDung::class, 'ma_nd', 'ma_nd');
    }

    public function donHang()
    {
        return $this->belongsTo(DonHang::class, 'ma_dh', 'ma_dh');
    }

    public function sanPham()
    {
        return $this->belongsTo(SanPham::class, 'ma_sp', 'ma_sp');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeDue($query)
    {
        return $query->where('status', 'active')
            ->whereNotNull('next_remind_at')
            ->where('next_remind_at', '<=', now());
    }

    /**
     * Nhắc uống được rải đều trong giờ thức (08:00–20:00) thay vì qua đêm, để không
     * ai bị đánh thức lúc 3 giờ sáng vì một liều paracetamol.
     *
     * Khung giờ nhắc tiếp đo từ hiện tại, không phải từ ngày đến hạn đã lưu: sau một
     * gián đoạn của scheduler hay worker, ngày đã lưu có thể chỉ là thời điểm tương
     * lai cách nhiều giờ hoặc nhiều ngày, và bước tuần tự từ đó sẽ bơm một loạt mail,
     * mỗi liều uống lỡ mất một mail.
     */
    public function advanceSchedule(): void
    {
        $soGioMoiLan = max(1, (int) floor(12 / max(1, $this->times_per_day)));
        $tuThoiDiem = max($this->next_remind_at ?? now(), now());
        $thoiDiemNhacTiep = $tuThoiDiem->copy()->addHours($soGioMoiLan);

        if ($thoiDiemNhacTiep->hour >= 20) {
            $thoiDiemNhacTiep = $thoiDiemNhacTiep->copy()->addDay()->setTime(8, 0);
        } elseif ($thoiDiemNhacTiep->hour < 8) {
            $thoiDiemNhacTiep = $thoiDiemNhacTiep->copy()->setTime(8, 0);
        }

        $thoiDiemKetThuc = $this->start_date->copy()->addDays($this->duration_days);

        if ($thoiDiemNhacTiep->greaterThan($thoiDiemKetThuc)) {
            $this->status = 'completed';
            $this->next_remind_at = null;
        } else {
            $this->next_remind_at = $thoiDiemNhacTiep;
        }

        $this->save();
    }
}
