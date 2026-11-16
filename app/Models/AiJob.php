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
     * Không được worker nào nhận sau thời gian này nghĩa là sẽ không có worker nào đến.
     *
     * Đo theo thời điểm nhận, không phải thời gian chạy: worker nhận công việc và đánh
     * dấu thành `running`, còn `isStranded()` trả về false cho mọi thứ không phải
     * `queued`. Ngưỡng phải vượt qua độ trễ nhận của worker: worker daemon nhận trong
     * vòng một giây, nhưng worker cron mỗi phút trên hosting chia sẻ (`queue:work
     * --stop-when-empty`) chỉ nhận được công việc ở tick kế tiếp — cách tới 60s cộng
     * thời gian khởi động. Giá trị 12s làm mọi công việc thua cuộc đua này: trang kết
     * quả bỏ cuộc công việc và xoá khỏi hàng đợi vài giây trước tick.
     */
    public const SYNC_FALLBACK_SECONDS = 75;

    /**
     * Công việc `running` già hơn thời gian này đã mất worker, nên không còn tính là
     * bằng chứng có worker đang sống. Khớp với XuLyTinNhanChatbot::$timeout.
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
     * Vẫn nằm trong hàng đợi dài đến mức không thể có worker nào đang chạy. Công việc
     * `running` chỉ đơn giản là một lời gọi AI chậm và không được nhân bản lại.
     *
     * Riêng bài test độ tuổi là không đủ: với một worker và ngân sách 45s+45s của
     * nhà cung cấp, một công việc có thể hợp lệ nằm đợi sau công việc đang chạy lâu
     * hơn nhiều so với SYNC_FALLBACK_SECONDS. Vì vậy công việc nằm đợi sau một worker
     * rõ ràng còn sống — công việc khác của cùng khách đang `running` và mới hơn
     * WORKER_ALIVE_SECONDS — là đang chờ, không phải bị bỏ rơi, và chạy nó inline chỉ
     * chặn dev server một tiến trình vô văn.
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
     * Bằng chứng một worker đang tiêu thụ hàng đợi lúc này: công việc vừa đạt trạng
     * thái `running` gần đây. Giới hạn trong phạm vi khách này, để một tenant bị kẹt
     * không thể giữ công việc của khách khác nằm trong trạng thái "waiting".
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
