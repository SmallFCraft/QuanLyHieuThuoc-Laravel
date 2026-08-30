<?php

namespace App\Mail;

use App\Models\MedicationReminder;
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
        public MedicationReminder $reminder,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('messages.reminder.mail_subject', [
                'name' => $this->reminder->product->name ?? $this->reminder->dosage_text,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.medication-reminder',
            with: [
                'reminder' => $this->reminder,
                'productName' => $this->reminder->product->name ?? $this->reminder->dosage_text,
                'userName' => $this->reminder->user->name ?? '',
                'cancelUrl' => route('reminders.index'),
            ],
        );
    }
}
