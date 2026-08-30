<?php

namespace App\Http\Controllers\Admin;

use App\Exports\AdminTriageExport;
use App\Http\Controllers\Controller;
use App\Models\MedicalSymptom;
use App\Models\TriageSession;
use App\Services\TriageReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class TriageReportController extends Controller
{
    public function __construct(private readonly TriageReportService $reportService)
    {
    }

    public function index(Request $request)
    {
        $filters = $this->filters($request);

        $sessions = $this->reportService->filterSessions($filters)
            ->with(['user:MaKH,name,email', 'pharmacist:MaKH,name'])
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $symptomNames = $this->symptomNamesFor(
            collect($sessions->items())->flatMap(fn (TriageSession $session) => (array) ($session->matched_symptoms ?? []))
        );

        return view('admin.triage-reports.index', [
            'summary' => $this->reportService->getSummary($filters),
            'distribution' => $this->reportService->getOutcomeDistribution($filters),
            'topSymptoms' => $this->reportService->getTopSymptoms(10, $filters),
            'topRedFlags' => $this->reportService->getTopRedFlags(10, $filters),
            'entryPoints' => $this->reportService->getEntryPointSplit($filters),
            'sessions' => $sessions,
            'symptomNames' => $symptomNames,
            'filters' => $filters,
            'outcomeStyles' => TriageReportService::OUTCOME_STYLES,
            'trendDays' => min(max($request->integer('days', 30), 1), 365),
        ]);
    }

    /**
     * Administrators see only what triage recorded. Health profiles stay with the
     * pharmacist handling the case, so nothing here touches user_health_profiles.
     */
    public function show(int $MaSL)
    {
        $session = TriageSession::with(['user:MaKH,name,email', 'pharmacist:MaKH,name'])
            ->findOrFail($MaSL);

        $symptomNames = $this->symptomNamesFor(collect((array) ($session->matched_symptoms ?? [])));

        return view('admin.triage-reports.show', [
            'session' => $session,
            'symptomNames' => $symptomNames,
            'answers' => $this->collectedAnswers($session),
            'outcomeStyles' => TriageReportService::OUTCOME_STYLES,
        ]);
    }

    public function outcomeChart(Request $request): JsonResponse
    {
        return response()->json($this->reportService->getOutcomeDistribution($this->filters($request)));
    }

    public function trendChart(Request $request): JsonResponse
    {
        $days = min(max($request->integer('days', 30), 1), 365);

        return response()->json($this->reportService->getDailyTrend($days, $this->filters($request)));
    }

    public function export(Request $request)
    {
        return Excel::download(
            new AdminTriageExport($this->reportService, $this->filters($request)),
            'bao-cao-sang-loc-' . now()->format('Y-m-d') . '.xlsx'
        );
    }

    private function filters(Request $request): array
    {
        return [
            'outcome' => $request->filled('outcome') ? $request->string('outcome')->toString() : null,
            'status' => $request->filled('status') ? $request->string('status')->toString() : null,
            'entry_point' => $request->filled('entry_point') ? $request->string('entry_point')->toString() : null,
            'date_from' => $request->filled('date_from') ? $request->string('date_from')->toString() : null,
            'date_to' => $request->filled('date_to') ? $request->string('date_to')->toString() : null,
        ];
    }

    private function symptomNamesFor($codes): array
    {
        $codes = collect($codes)->filter(fn ($code) => is_string($code) && $code !== '')->unique();

        if ($codes->isEmpty()) {
            return [];
        }

        return MedicalSymptom::whereIn('code', $codes->all())
            ->pluck('name_vi', 'code')
            ->toArray();
    }

    /**
     * Wizard answers are stored under machine keys; the report shows Vietnamese labels.
     */
    private function collectedAnswers(TriageSession $session): array
    {
        $data = $session->collected_data ?? [];
        $skipped = (array) ($data['skipped'] ?? []);

        $labels = [
            'raw_symptom_text' => __('triage_report.answers.raw_symptom_text'),
            'symptoms' => __('triage_report.answers.symptoms'),
            'duration_days' => __('triage_report.answers.duration_days'),
            'medications_taken' => __('triage_report.answers.medications_taken'),
            'history' => __('triage_report.answers.history'),
            'other_symptoms' => __('triage_report.answers.other_symptoms'),
        ];

        $rows = [];
        foreach ($labels as $key => $label) {
            if (!array_key_exists($key, $data) && !in_array($key, $skipped, true)) {
                continue;
            }

            $rows[] = [
                'label' => $label,
                'value' => $this->flattenAnswer($data[$key] ?? null),
                'skipped' => in_array($key, $skipped, true),
            ];
        }

        return $rows;
    }

    private function flattenAnswer(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? __('triage_report.answers.yes') : __('triage_report.answers.no');
        }

        if (is_array($value)) {
            return collect($value)
                ->map(fn ($item) => is_array($item) ? json_encode($item, JSON_UNESCAPED_UNICODE) : (string) $item)
                ->filter(fn ($item) => trim($item) !== '')
                ->implode(', ');
        }

        return trim((string) $value);
    }
}
