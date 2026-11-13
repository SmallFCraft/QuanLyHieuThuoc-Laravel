<?php

namespace Tests\Feature;

use App\Jobs\XuLyTinNhanChatbot;
use App\Jobs\XuLyVietLaiLoiKhuyen;
use App\Jobs\XuLyTomTatSangLoc;
use App\Jobs\XuLyDanhGiaSangLoc;
use Tests\TestCase;

/**
 * `retry_after` must outlast the longest job, or the database driver reclaims a job
 * that is still running and hands it to a second worker.
 *
 * Guarding this as a test rather than a comment: the two numbers live in different
 * files, so raising a job timeout silently widens the race window that this value
 * exists to close.
 */
class BatBienThoiGianThuLaiHangDoiTest extends TestCase
{
    public function test_thoi_gian_thu_lai_co_so_du_lieu_lon_hon_timeout_cua_moi_cong_viec(): void
    {
        $retryAfter = (int) config('queue.connections.database.retry_after');

        $timeouts = [
            \App\Jobs\XuLyTomTatSangLoc::class => (new \App\Jobs\XuLyTomTatSangLoc('x'))->timeout,
            \App\Jobs\XuLyDanhGiaSangLoc::class => (new \App\Jobs\XuLyDanhGiaSangLoc('x'))->timeout,
            \App\Jobs\XuLyTinNhanChatbot::class => (new \App\Jobs\XuLyTinNhanChatbot('x'))->timeout,
            \App\Jobs\XuLyVietLaiLoiKhuyen::class => (new \App\Jobs\XuLyVietLaiLoiKhuyen('x'))->timeout,
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
