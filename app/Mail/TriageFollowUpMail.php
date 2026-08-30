<?php

namespace App\Mail;

use App\Models\TriageSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class TriageFollowUpMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** Long enough for a patient who reads mail late, short enough to expire. */
    private const LINK_VALID_DAYS = 14;

    public function __construct(
        public TriageSession $session,
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
                'session' => $this->session,
                'userName' => $this->session->user->name ?? '',
                'improvedUrl' => $this->responseUrl(true),
                'notImprovedUrl' => $this->responseUrl(false),
            ],
        );
    }

    /**
     * A signed GET link to a confirmation page, not the mutation itself: mail
     * scanners and link prefetchers open every URL in an email, so recording the
     * answer straight from the link would answer for the patient.
     */
    private function responseUrl(bool $improved): string
    {
        return URL::temporarySignedRoute(
            'health.followup.confirm',
            now()->addDays(self::LINK_VALID_DAYS),
            [
                'MaSL' => $this->session->MaSL,
                'improved' => $improved ? 1 : 0,
            ]
        );
    }
}
