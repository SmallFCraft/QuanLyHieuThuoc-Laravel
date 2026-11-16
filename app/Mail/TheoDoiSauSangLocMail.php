<?php

namespace App\Mail;

use App\Models\PhienSangLoc;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class TheoDoiSauSangLocMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** Đủ dài cho một bệnh nhân đọc mail muộn, đủ ngắn để hết hạn. */
    private const LINK_VALID_DAYS = 14;

    public function __construct(
        public PhienSangLoc $phien,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('messages.reminder.follow_up_subject'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.triage-follow-up',
            with: [
                'session' => $this->phien,
                'userName' => $this->phien->nguoiDung->ho_ten ?? '',
                'improvedUrl' => $this->responseUrl(true),
                'notImprovedUrl' => $this->responseUrl(false),
            ],
        );
    }

    /**
     * Một link GET đã ký đến một trang xác nhận, không phải chính mutation: mail
     * scanner và link prefetcher mở mọi URL trong email, nên ghi nhận câu trả lời
     * thẳng từ link sẽ trả lời thay bệnh nhân.
     */
    private function responseUrl(bool $improved): string
    {
        return URL::temporarySignedRoute(
            'sang-loc.theo-doi.xac-nhan',
            now()->addDays(self::LINK_VALID_DAYS),
            [
                'MaSL' => $this->phien->ma_sl,
                'improved' => $improved ? 1 : 0,
            ]
        );
    }
}
