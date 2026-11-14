<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiJob extends Model
{
    protected $primaryKey = 'ma_aj';

    public const KIND_CHAT = 'chat';
    public const KIND_TRIAGE_EVALUATION = 'triage_eval';
    public const KIND_TRIAGE_ADVICE = 'triage_advice';
    public const KIND_TRIAGE_BRIEFING = 'triage_briefing';

    /**
     * Khong duoc worker nao nhan sau thoi gian nay nghia la se khong co worker nao den.
     *
     * Do theo thoi diem nhan, khong phai thoi gian chay: worker nhan cong viec va danh
     * dau thanh `running`, con `isStranded()` tra ve false cho moi thu khong phai
     * `queued`. Nguong phai vuot qua do tre nhan cua worker: worker daemon nhan trong
     * vong mot giay, nhung worker cron moi phut tren hosting chia se (`queue:work
     * --stop-when-empty`) chi nhan duoc cong viec o tick ke tiep — cach toi 60s cong
     * thoi gian khoi dong. Gia tri 12s lam moi cong viec thua cuoc dua nay: trang ket
     * qua bo cuoc cong viec va xoa khoi hang doi vai giay truoc tick.
     */
    public const SYNC_FALLBACK_SECONDS = 75;

    /**
     * Cong viec `running` gio hon thoi gian nay da mat worker, nen khong con tinh la
     * bang chung co worker dang song. Khop voi XuLyTinNhanChatbot::$timeout.
     */
    public const WORKER_ALIVE_SECONDS = 120;

    protected $table = 'ai_jobs';

    protected $fillable = [
        'uuid',
        'ma_nd',
        'kind',
        'status',
        'payload',
        'result',
        'error',
        'attempts',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'result' => 'array',
        'attempts' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(NguoiDung::class, 'ma_nd', 'ma_nd');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['done', 'failed', 'cancelled'], true);
    }

    /**
     * Van nam trong hang doi dai den muc khong the co worker nao dang chay. Cong viec
     * `running` chi don gian la mot loi goi AI cham va khong duoc nhan ban lai.
     *
     * Rieng bai test do tuoi la khong du: voi mot worker va ngan sach 45s+45s cua
     * nha cung cap, mot cong viec co the hop le nam doi sau cong viec dang chay lau
     * hon nhieu so voi SYNC_FALLBACK_SECONDS. Vi vay cong viec nam doi sau mot worker
     * ro rang con song — cong viec khac cua cung khach dang `running` va moi hon
     * WORKER_ALIVE_SECONDS — la dang cho, khong phai bi bo roi, va chay no inline chi
     * chan dev server mot tien trinh vo van.
     */
    public function isStranded(): bool
    {
        if ($this->status !== 'queued' || $this->created_at === null) {
            return false;
        }

        if ($this->created_at->gte(now()->subSeconds(self::SYNC_FALLBACK_SECONDS))) {
            return false;
        }

        return !$this->hasLiveWorker();
    }

    /**
     * Bang chung mot worker dang tieu thu hang doi luc nay: cong viec vua dat trang
     * thai `running` gan day. Gioi han trong pham vi khach nay, de mot tenant bi ket
     * khong the giu cong viec cua khach khac nam trong trang thai "waiting".
     */
    protected function hasLiveWorker(): bool
    {
        return static::query()
            ->where('ma_nd', $this->ma_nd)
            ->where('status', 'running')
            ->where('started_at', '>=', now()->subSeconds(self::WORKER_ALIVE_SECONDS))
            ->exists();
    }
}
