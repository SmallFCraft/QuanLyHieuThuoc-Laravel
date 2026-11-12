<?php

namespace App\Mail;

use App\Models\NhacUongThuoc;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MedicationReminderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public NhacUongThuoc $nhacUong,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('messages.reminder.mail_subject', [
                'name' => $this->nhacUong->sanPham->name ?? $this->nhacUong->dosage_text,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.medication-reminder',
            with: [
                'reminder' => $this->nhacUong,
                'productName' => $this->nhacUong->sanPham->name ?? $this->nhacUong->dosage_text,
                'userName' => $this->nhacUong->nguoiDung->name ?? '',
                'cancelUrl' => route('reminders.index'),
            ],
        );
    }
}
