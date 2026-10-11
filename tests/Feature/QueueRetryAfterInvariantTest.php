<?php

namespace Tests\Feature;

use App\Jobs\ProcessChatbotMessage;
use App\Jobs\ProcessTriageAdviceRewrite;
use App\Jobs\ProcessTriageBriefing;
use App\Jobs\ProcessTriageEvaluation;
use Tests\TestCase;

/**
 * `retry_after` must outlast the longest job, or the database driver reclaims a job
 * that is still running and hands it to a second worker.
 *
 * Guarding this as a test rather than a comment: the two numbers live in different
 * files, so raising a job timeout silently widens the race window that this value
 * exists to close.
 */
class QueueRetryAfterInvariantTest extends TestCase
{
    public function test_database_retry_after_exceeds_every_job_timeout(): void
    {
        $retryAfter = (int) config('queue.connections.database.retry_after');

        $timeouts = [
            ProcessTriageBriefing::class => (new ProcessTriageBriefing('x'))->timeout,
            ProcessTriageEvaluation::class => (new ProcessTriageEvaluation('x'))->timeout,
            ProcessChatbotMessage::class => (new ProcessChatbotMessage('x'))->timeout,
            ProcessTriageAdviceRewrite::class => (new ProcessTriageAdviceRewrite('x'))->timeout,
        ];

        foreach ($timeouts as $jobClass => $timeout) {
            $this->assertGreaterThan(
                $timeout,
                $retryAfter,
                sprintf(
                    '%s has $timeout = %ds but DB_QUEUE_RETRY_AFTER is only %ds; '
                    .'a job that slow is re-delivered while it is still running.',
                    $jobClass,
                    $timeout,
                    $retryAfter
                )
            );
        }
    }
}
