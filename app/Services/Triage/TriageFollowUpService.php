<?php

namespace App\Services\Triage;

use App\Mail\TriageFollowUpMail;
use App\Models\ChatMessage;
use App\Models\TriageSession;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Safety-netting: every self-care outcome is re-checked after a few days.
 *
 * If the symptom has not improved the answer is always "see a doctor". No
 * product is ever returned on that branch — a triage tool that upsells medicine
 * to someone who did not get better is doing harm, not care.
 */
class TriageFollowUpService
{
    public function __construct(
        protected AuditLogService $auditLogService,
    ) {}

    /**
     * Ask every due session whether the symptom improved.
     */
    public function dispatchDue(): int
    {
        $sent = 0;

        TriageSession::dueForFollowUp()
            ->with('user')
            ->chunkById(100, function ($sessions) use (&$sent) {
                foreach ($sessions as $session) {
                    if ($this->askOne($session)) {
                        $sent++;
                    }
                }
            }, 'MaSL');

        return $sent;
    }

    /**
     * Record the customer's answer.
     *
     * Idempotent: the follow-up link travels by email and can be opened more than
     * once (mail scanners and prefetchers open it unattended), so a second answer
     * returns the first one instead of writing again.
     *
     * @return array{improved: bool, message: string, products: array<int, mixed>, refer_doctor: bool, already_answered: bool}
     */
    public function recordResponse(TriageSession $session, bool $improved): array
    {
        if ($session->follow_up_responded_at !== null) {
            $recorded = (bool) $session->follow_up_improved;

            return [
                'improved' => $recorded,
                'message' => $recorded
                    ? __('messages.reminder.response_improved')
                    : __('messages.reminder.response_not_improved'),
                'products' => [],
                'refer_doctor' => ! $recorded,
                'already_answered' => true,
            ];
        }

        $message = $improved
            ? __('messages.reminder.response_improved')
            : __('messages.reminder.response_not_improved');

        $attributes = [
            'follow_up_at' => null,
            'follow_up_responded_at' => now(),
            'follow_up_improved' => $improved,
        ];

        // Only a self-care outcome may be escalated here. An emergency or a
        // pharmacist decision already sits above refer_doctor, so overwriting it
        // would downgrade the advice the patient was given.
        if (! $improved && $session->outcome === 'self_care') {
            $attributes['outcome'] = 'refer_doctor';
        }

        $session->update($attributes);

        $this->writeChatMessage($session, $message, [
            'type' => 'triage_follow_up_response',
            'improved' => $improved,
            'MaSL' => $session->MaSL,
        ]);

        $this->audit(
            $improved ? 'triage_follow_up_improved' : 'triage_follow_up_not_improved',
            $session,
            [
                'MaKH' => $session->MaKH,
                'improved' => $improved,
                'outcome' => $session->outcome,
                'products_suggested' => 0,
            ]
        );

        return [
            'improved' => $improved,
            'message' => $message,
            // Hard empty on both branches: follow-up is care, not a sales channel.
            'products' => [],
            'refer_doctor' => ! $improved,
            'already_answered' => false,
        ];
    }

    /**
     * @return bool whether the question actually went out
     */
    private function askOne(TriageSession $session): bool
    {
        if ($session->user) {
            try {
                Mail::to($session->user->email)->send(new TriageFollowUpMail($session));
            } catch (\Throwable $e) {
                // Keep follow_up_at so the next tick retries. Clearing it here would
                // silently drop the safety net for a self-care case, which is the one
                // mechanism that catches a triage decision that got it wrong.
                Log::warning('Triage follow-up mail failed, will retry', [
                    'MaSL' => $session->MaSL,
                    'error' => $e->getMessage(),
                ]);

                return false;
            }
        }

        $this->writeChatMessage($session, __('messages.reminder.follow_up_question'), [
            'type' => 'triage_follow_up_question',
            'MaSL' => $session->MaSL,
        ]);

        // Clearing the due date is what stops the same question being asked
        // again on the next scheduler tick.
        $session->update(['follow_up_at' => null]);

        $this->audit('triage_follow_up_sent', $session, [
            'MaKH' => $session->MaKH,
            'outcome' => $session->outcome,
        ]);

        return true;
    }

    /**
     * Follow-up chatter only exists for sessions that came from (or can reach)
     * a chat thread; chat_messages.session_id is not nullable.
     */
    private function writeChatMessage(TriageSession $session, string $content, array $metadata): void
    {
        if (empty($session->session_id)) {
            return;
        }

        ChatMessage::create([
            'MaKH' => $session->MaKH,
            'session_id' => $session->session_id,
            'role' => 'assistant',
            'content' => $content,
            'products' => [],
            'metadata' => $metadata,
            'show_disclaimer' => true,
        ]);
    }

    private function audit(string $action, TriageSession $session, array $values): void
    {
        try {
            $this->auditLogService->log(
                action: $action,
                modelType: TriageSession::class,
                modelId: $session->MaSL,
                oldValues: null,
                newValues: $values,
                userId: $session->MaKH
            );
        } catch (\Throwable $e) {
            Log::warning('Triage follow-up audit failed', [
                'MaSL' => $session->MaSL,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
