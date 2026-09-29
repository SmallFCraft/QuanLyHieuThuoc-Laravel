<?php

namespace App\Exports;

use App\Models\MedicalSymptom;
use App\Models\TriageSession;
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
            ->with(['user:MaKH,name,email', 'pharmacist:MaKH,name'])
            ->orderByDesc('created_at')
            ->get();

        $codes = $sessions
            ->flatMap(fn (TriageSession $session) => (array) ($session->matched_symptoms ?? []))
            ->filter(fn ($code) => is_string($code) && $code !== '')
            ->unique();

        $this->symptomNames = $codes->isEmpty()
            ? []
            : MedicalSymptom::whereIn('code', $codes->all())->pluck('name_vi', 'code')->toArray();

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

    public function map($session): array
    {
        return [
            'SL-' . $session->MaSL,
            optional($session->created_at)->format('d/m/Y H:i'),
            $session->user->name ?? 'Không rõ',
            $this->symptomList($session),
            $this->outcomeLabel($session->outcome),
            $this->redFlagList($session),
            $this->statusLabel($session->status),
            $session->pharmacist->name ?? '—',
            $this->entryPointLabel($session->entry_point),
        ];
    }

    private function symptomList(TriageSession $session): string
    {
        $names = collect((array) ($session->matched_symptoms ?? []))
            ->filter(fn ($code) => is_string($code) && $code !== '')
            ->map(fn (string $code) => $this->symptomNames[$code] ?? $code);

        return $names->isEmpty() ? '—' : $names->implode(', ');
    }

    private function redFlagList(TriageSession $session): string
    {
        $names = collect((array) ($session->triggered_red_flags ?? []))
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
