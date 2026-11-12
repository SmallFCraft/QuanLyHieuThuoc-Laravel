<?php

namespace App\Exports;

use App\Models\PhienSangLoc;
use App\Models\TrieuChungYTe;
use App\Services\Triage\TriageReportService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Deliberately omits advice_given and any health-profile content: exported files
 * leave the application's access controls behind.
 */
class AdminTriageExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    private array $symptomNames = [];

    public function __construct(
        private readonly TriageReportService $reportService,
        private readonly array $filters = [],
    ) {
    }

    public function collection(): Collection
    {
        $sessions = $this->reportService->filterSessions($this->filters)
            ->with(['nguoiDung:ma_nd,ho_ten,email', 'pharmacist:ma_nd,ho_ten'])
            ->orderByDesc('created_at')
            ->get();

        $codes = $sessions
            ->flatMap(fn (PhienSangLoc $phien) => (array) ($phien->matched_symptoms ?? []))
            ->filter(fn ($code) => is_string($code) && $code !== '')
            ->unique();

        $this->symptomNames = $codes->isEmpty()
            ? []
            : TrieuChungYTe::whereIn('code', $codes->all())->pluck('name_vi', 'code')->toArray();

        return $sessions;
    }

    public function headings(): array
    {
        // Same keys the on-screen table uses, so the export cannot drift from it.
        return [
            __('triage_report.table.code'),
            __('triage_report.table.date'),
            __('triage_report.table.customer'),
            __('triage_report.table.symptoms'),
            __('triage_report.table.outcome'),
            __('triage_report.table.red_flags'),
            __('triage_report.table.status'),
            __('triage_report.table.pharmacist'),
            __('triage_report.table.entry_point'),
        ];
    }

    public function map($phien): array
    {
        return [
            'SL-' . $phien->ma_sl,
            optional($phien->created_at)->format('d/m/Y H:i'),
            $phien->nguoiDung->ho_ten ?? 'Không rõ',
            $this->symptomList($phien),
            $this->outcomeLabel($phien->outcome),
            $this->redFlagList($phien),
            $this->statusLabel($phien->status),
            $phien->pharmacist->ho_ten ?? '—',
            $this->entryPointLabel($phien->entry_point),
        ];
    }

    private function symptomList(PhienSangLoc $phien): string
    {
        $names = collect((array) ($phien->matched_symptoms ?? []))
            ->filter(fn ($code) => is_string($code) && $code !== '')
            ->map(fn (string $code) => $this->symptomNames[$code] ?? $code);

        return $names->isEmpty() ? '—' : $names->implode(', ');
    }

    private function redFlagList(PhienSangLoc $phien): string
    {
        $names = collect((array) ($phien->triggered_red_flags ?? []))
            ->map(fn ($flag) => is_array($flag) ? ($flag['name_vi'] ?? $flag['code'] ?? null) : $flag)
            ->filter(fn ($name) => is_string($name) && $name !== '');

        return $names->isEmpty() ? 'Không' : $names->implode(', ');
    }

    private function outcomeLabel(?string $outcome): string
    {
        if (!$outcome) {
            return 'Chưa có kết quả';
        }

        return __('messages.health.outcome_' . $outcome);
    }

    private function statusLabel(?string $status): string
    {
        return $status
            ? __('triage_report.status.' . $status)
            : '—';
    }

    private function entryPointLabel(?string $entryPoint): string
    {
        return $entryPoint
            ? __('triage_report.entry_point.' . $entryPoint)
            : '—';
    }
}
