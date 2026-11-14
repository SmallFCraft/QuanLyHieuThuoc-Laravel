<?php

namespace App\Services\Triage;

use App\Models\CoDoYTe;
use App\Models\TrieuChungYTe;
use App\Models\PhienSangLoc;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class BaoCaoSangLocService
{
    public const OUTCOMES = ['emergency', 'refer_doctor', 'pharmacist_review', 'self_care'];

    /**
     * Bang mau duoc do tuong phan trong resources/views/health/partials/styles.blade.php.
     * Giu tai day de endpoint bieu do va view khong bao gio suy lai mau.
     */
    public const OUTCOME_STYLES = [
        'emergency' => ['color' => '#B91C1C', 'bg' => '#FEF2F2', 'icon' => 'bi-exclamation-octagon-fill'],
        'refer_doctor' => ['color' => '#B45309', 'bg' => '#FFFBEB', 'icon' => 'bi-hospital-fill'],
        'pharmacist_review' => ['color' => '#0E7490', 'bg' => '#ECFEFF', 'icon' => 'bi-person-badge-fill'],
        'self_care' => ['color' => '#047857', 'bg' => '#ECFDF5', 'icon' => 'bi-house-heart-fill'],
    ];

    public function layTongHop(array $filters = []): array
    {
        $outcomes = $this->outcomeCounts($filters);
        $total = array_sum($outcomes) + $this->applyFilters(PhienSangLoc::query(), $filters)
            ->whereNull('outcome')
            ->count();

        $referred = $outcomes['refer_doctor'] + $outcomes['emergency'];

        // Tong hop trong SQL: tai moi row da xem de trung binh hai moc thoi gian trong
        // PHP se lon vo han theo bang.
        $wait = $this->applyFilters(PhienSangLoc::query(), $filters)
            ->where('status', 'reviewed')
            ->whereNotNull('reviewed_at')
            ->selectRaw('COUNT(*) as reviewed_count, AVG('.$this->waitMinutesExpression().') as average_wait')
            ->first();

        $reviewedCount = (int) ($wait->reviewed_count ?? 0);

        return [
            'total' => $total,
            'outcomes' => $outcomes,
            'referral_rate' => $total > 0 ? round($referred / $total * 100, 1) : 0.0,
            'pending_pharmacist' => $this->applyFilters(PhienSangLoc::query(), $filters)
                ->where('status', 'pending_pharmacist')
                ->count(),
            'average_wait_minutes' => $reviewedCount > 0 ? round((float) $wait->average_wait, 1) : null,
            'reviewed_count' => $reviewedCount,
        ];
    }

    /**
     * So phut giua created_at va reviewed_at, viet theo phuong ngu cua driver.
     * MySQL tren production, SQLite trong suite test.
     */
    protected function waitMinutesExpression(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? '(julianday(reviewed_at) - julianday(created_at)) * 1440'
            : 'TIMESTAMPDIFF(MINUTE, created_at, reviewed_at)';
    }

    /**
     * @return array<string, int>
     */
    protected function outcomeCounts(array $filters = []): array
    {
        $byOutcome = $this->applyFilters(PhienSangLoc::query(), $filters)
            ->select('outcome', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('outcome')
            ->pluck('aggregate', 'outcome')
            ->toArray();

        $outcomes = [];
        foreach (self::OUTCOMES as $outcome) {
            $outcomes[$outcome] = (int) ($byOutcome[$outcome] ?? 0);
        }

        return $outcomes;
    }

    public function layPhanBoKetQua(array $filters = []): array
    {
        // Chi so luong ket qua, chu khong phai toan bo tong hop: endpoint bieu do neu khong
        // se chay lai cac truy van tong, dang cho va thoi gian cho ma no khong bao gio doc.
        $counts = $this->outcomeCounts($filters);

        $rows = [];
        foreach (self::OUTCOMES as $outcome) {
            $rows[] = [
                'outcome' => $outcome,
                'label' => __('messages.health.outcome_'.$outcome),
                'count' => $counts[$outcome],
                'color' => self::OUTCOME_STYLES[$outcome]['color'],
                'icon' => self::OUTCOME_STYLES[$outcome]['icon'],
            ];
        }

        return [
            'labels' => array_column($rows, 'label'),
            'data' => array_column($rows, 'count'),
            'colors' => array_column($rows, 'color'),
            'rows' => $rows,
        ];
    }

    /**
     * @param  array  $filters  cung bo loc voi phan con lai cua bao cao; xu huong phai
     *                          khop voi cac the chi so thay vi luon hien
     *                          moi ket qua.
     */
    public function layXuHuongNgay(int $days = 30, array $filters = []): array
    {
        $days = min(max($days, 1), 365);

        $startDate = Carbon::today()->subDays($days - 1)->startOfDay();
        $endDate = Carbon::today()->endOfDay();

        $counts = $this->applyFilters(PhienSangLoc::query(), $filters)
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as aggregate'))
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('aggregate', 'date')
            ->toArray();

        // Ngay khong co phien van phai chiem mot slot, neu truc x noi loi.
        $labels = [];
        $data = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i)->format('Y-m-d');
            $labels[] = Carbon::parse($date)->format('d/m');
            $data[] = (int) ($counts[$date] ?? 0);
        }

        return [
            'labels' => $labels,
            'data' => $data,
            'days' => $days,
        ];
    }

    public function layTrieuChungNhieu(int $limit = 10, array $filters = []): array
    {
        $lists = $this->applyFilters(PhienSangLoc::query(), $filters)
            ->whereNotNull('matched_symptoms')
            ->pluck('matched_symptoms');

        $tally = [];
        foreach ($lists as $codes) {
            foreach ((array) $codes as $code) {
                if (! is_string($code) || $code === '') {
                    continue;
                }
                $tally[$code] = ($tally[$code] ?? 0) + 1;
            }
        }

        if ($tally === []) {
            return [];
        }

        arsort($tally);
        $tally = array_slice($tally, 0, $limit, true);

        $names = TrieuChungYTe::whereIn('code', array_keys($tally))
            ->pluck('name_vi', 'code')
            ->toArray();

        $rows = [];
        foreach ($tally as $code => $count) {
            $rows[] = [
                'code' => $code,
                'name_vi' => $names[$code] ?? $code,
                'count' => $count,
            ];
        }

        return $rows;
    }

    public function layCoDoNhieu(int $limit = 10, array $filters = []): array
    {
        $lists = $this->applyFilters(PhienSangLoc::query(), $filters)
            ->whereNotNull('triggered_red_flags')
            ->pluck('triggered_red_flags');

        $tally = [];
        $inline = [];
        foreach ($lists as $flags) {
            foreach ((array) $flags as $flag) {
                $code = is_array($flag) ? ($flag['code'] ?? null) : (is_string($flag) ? $flag : null);
                if (! is_string($code) || $code === '') {
                    continue;
                }
                $tally[$code] = ($tally[$code] ?? 0) + 1;
                if (is_array($flag)) {
                    $inline[$code] ??= [
                        'name_vi' => $flag['name_vi'] ?? null,
                        'severity' => $flag['severity'] ?? null,
                    ];
                }
            }
        }

        if ($tally === []) {
            return [];
        }

        arsort($tally);
        $tally = array_slice($tally, 0, $limit, true);

        $known = CoDoYTe::whereIn('code', array_keys($tally))
            ->get(['code', 'name_vi', 'severity'])
            ->keyBy('code');

        $rows = [];
        foreach ($tally as $code => $count) {
            $rows[] = [
                'code' => $code,
                'name_vi' => $known[$code]->name_vi ?? $inline[$code]['name_vi'] ?? $code,
                'severity' => $known[$code]->severity ?? $inline[$code]['severity'] ?? 'caution',
                'count' => $count,
            ];
        }

        return $rows;
    }

    public function layPhanBoNguonVao(array $filters = []): array
    {
        $counts = $this->applyFilters(PhienSangLoc::query(), $filters)
            ->select('entry_point', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('entry_point')
            ->pluck('aggregate', 'entry_point')
            ->toArray();

        return [
            'chat' => (int) ($counts['chat'] ?? 0),
            'page' => (int) ($counts['page'] ?? 0),
        ];
    }

    /**
     * Moi truy van bao cao di qua day nen ngung nghia bo loc giu nguyen
     * giua cac chi so, bieu do va file xuat Excel.
     */
    protected function applyFilters(Builder $query, array $filters): Builder
    {
        $outcome = $filters['outcome'] ?? null;
        if (is_string($outcome) && in_array($outcome, self::OUTCOMES, true)) {
            $query->where('outcome', $outcome);
        }

        $status = $filters['status'] ?? null;
        if (is_string($status) && in_array($status, ['in_progress', 'completed', 'pending_pharmacist', 'reviewed'], true)) {
            $query->where('status', $status);
        }

        $entryPoint = $filters['entry_point'] ?? null;
        if (is_string($entryPoint) && in_array($entryPoint, ['chat', 'page'], true)) {
            $query->where('entry_point', $entryPoint);
        }

        // Ngay den tu query string, nen gia tri khong doc duoc phai
        // bo qua thay vi lam 500 ca trang bao cao.
        $dateFrom = $this->parseDate($filters['date_from'] ?? null);
        if ($dateFrom) {
            $query->where('created_at', '>=', $dateFrom->startOfDay());
        }

        $dateTo = $this->parseDate($filters['date_to'] ?? null);
        if ($dateTo) {
            $query->where('created_at', '<=', $dateTo->endOfDay());
        }

        return $query;
    }

    protected function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    public function locCacPhien(array $filters): Builder
    {
        return $this->applyFilters(PhienSangLoc::query(), $filters);
    }
}