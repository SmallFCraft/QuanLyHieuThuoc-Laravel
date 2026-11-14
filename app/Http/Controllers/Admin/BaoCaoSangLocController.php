<?php

namespace App\Http\Controllers\Admin;


use App\Exports\QuanTriSangLocExport;
use App\Http\Controllers\Controller;
use App\Models\TrieuChungYTe;
use App\Models\PhienSangLoc;
use App\Services\Triage\BaoCaoSangLocService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class BaoCaoSangLocController extends Controller
{
    public function __construct(private readonly BaoCaoSangLocService $reportService)
    {
    }

    public function index(Request $request)
    {
        $filters = $this->filters($request);

        $cacPhien = $this->reportService->locCacPhien($filters)
            ->with(['nguoiDung:ma_nd,ho_ten,email', 'pharmacist:ma_nd,ho_ten'])
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $cacTenTrieuChung = $this->symptomNamesFor(
            collect($cacPhien->items())->flatMap(fn (PhienSangLoc $phien) => (array) ($phien->matched_symptoms ?? []))
        );

        return view('admin.triage-reports.index', [
            'summary' => $this->reportService->layTongHop($filters),
            'distribution' => $this->reportService->layPhanBoKetQua($filters),
            'topSymptoms' => $this->reportService->layTrieuChungNhieu(10, $filters),
            'topRedFlags' => $this->reportService->layCoDoNhieu(10, $filters),
            'entryPoints' => $this->reportService->layPhanBoNguonVao($filters),
            'sessions' => $cacPhien,
            'symptomNames' => $cacTenTrieuChung,
            'filters' => $filters,
            'outcomeStyles' => BaoCaoSangLocService::OUTCOME_STYLES,
            'trendDays' => min(max($request->integer('days', 30), 1), 365),
        ]);
    }

    /**
     * Quan tri vien chi thay nhung gi triage da ghi. Ho so suc khoe thuoc ve duoc si
     * xu ly truong hop, nen khong cho nao o day cham vao user_health_profiles.
     */
    public function show(int $MaSL)
    {
        $phien = PhienSangLoc::with(['nguoiDung:ma_nd,ho_ten,email', 'pharmacist:ma_nd,ho_ten'])
            ->findOrFail($MaSL);

        $cacTenTrieuChung = $this->symptomNamesFor(collect((array) ($phien->matched_symptoms ?? [])));

        return view('admin.triage-reports.show', [
            'session' => $phien,
            'symptomNames' => $cacTenTrieuChung,
            'answers' => $this->collectedAnswers($phien),
            'outcomeStyles' => BaoCaoSangLocService::OUTCOME_STYLES,
        ]);
    }

    public function outcomeChart(Request $request): JsonResponse
    {
        return response()->json($this->reportService->layPhanBoKetQua($this->filters($request)));
    }

    public function trendChart(Request $request): JsonResponse
    {
        $days = min(max($request->integer('days', 30), 1), 365);

        return response()->json($this->reportService->layXuHuongNgay($days, $this->filters($request)));
    }

    public function export(Request $request)
    {
        return Excel::download(
            new QuanTriSangLocExport($this->reportService, $this->filters($request)),
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

        return TrieuChungYTe::whereIn('code', $codes->all())
            ->pluck('name_vi', 'code')
            ->toArray();
    }

    /**
     * Cau tra loi wizard duoc luu duoi cac key may; bao cao hien nhan tieng Viet.
     */
    private function collectedAnswers(PhienSangLoc $phien): array
    {
        $data = $phien->collected_data ?? [];
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
