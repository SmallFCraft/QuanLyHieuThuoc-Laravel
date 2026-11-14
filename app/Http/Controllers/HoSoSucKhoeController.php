<?php

namespace App\Http\Controllers;

use App\Http\Requests\HoSoSucKhoeRequest;
use App\Models\HoSoSucKhoe;
use App\Services\NhatKyKiemToanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;

class HoSoSucKhoeController extends Controller
{
    /**
     * Cac field ma *noi dung* cua chung la du lieu suc khoe. Chi ten va so luong muc
     * cua chung duoc phep den nhat ky kiam toan — khong bao gio gia tri.
     */
    protected const SENSITIVE_LISTS = ['allergies', 'chronic_conditions', 'current_medications'];

    public function update(HoSoSucKhoeRequest $request): RedirectResponse
    {
        $userId = auth()->id();
        $hoSo = HoSoSucKhoe::where('ma_nd', $userId)->first();

        $attributes = $request->profileAttributes();
        $attributes['consented_at'] = $request->boolean('consent')
            ? ($hoSo?->consented_at ?? now())
            : null;

        // Tach khoi consented_at: bo tick la mot viec rut lai dong y AI that su,
        // ke ca khi dong y canh bao an toan phia duoi van con tick.
        $attributes['ai_consent_at'] = $request->boolean('ai_consent')
            ? ($hoSo?->ai_consent_at ?? now())
            : null;

        $changed = $this->changedFields($hoSo, $attributes);

        if ($hoSo) {
            $hoSo->fill($attributes)->save();
        } else {
            $hoSo = HoSoSucKhoe::create($attributes + ['ma_nd' => $userId]);
        }

        app(NhatKyKiemToanService::class)->ghi(
            action: 'health_profile_updated',
            modelType: HoSoSucKhoe::class,
            modelId: $hoSo->ma_hs,
            oldValues: null,
            newValues: $this->safeSummary($hoSo, $changed),
        );

        return Redirect::to(route('ho-so.edit') . '#health-profile')
            ->with('status', 'health-profile-updated');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $hoSo = HoSoSucKhoe::where('ma_nd', auth()->id())->first();

        if ($hoSo) {
            $profileId = $hoSo->ma_hs;
            $hoSo->delete();

            app(NhatKyKiemToanService::class)->ghi(
                action: 'health_profile_deleted',
                modelType: HoSoSucKhoe::class,
                modelId: $profileId,
                oldValues: null,
                newValues: ['ma_nd' => auth()->id()],
            );
        }

        return Redirect::to(route('ho-so.edit') . '#health-profile')
            ->with('status', 'health-profile-deleted');
    }

    /**
     * Ten cac field ma gia tri cua chung khac voi nhung gi dang luu.
     *
     * @return array<int, string>
     */
    protected function changedFields(?HoSoSucKhoe $hoSo, array $attributes): array
    {
        if (! $hoSo) {
            return array_keys($attributes);
        }

        $changed = [];

        foreach ($attributes as $field => $value) {
            $current = $hoSo->{$field};

            if ($field === 'consented_at' || $field === 'ai_consent_at') {
                if (($current === null) !== ($value === null)) {
                    $changed[] = $field;
                }

                continue;
            }

            if (is_array($value)) {
                if ($value !== ($current ?? [])) {
                    $changed[] = $field;
                }

                continue;
            }

            if ((string) $value !== (string) $current) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    /**
     * Payload audit chi gom so dem: van ban di ung va benh man tinh khong duoc duoc
     * ghi vao audit_logs duoi dang trong.
     *
     * @return array<string, mixed>
     */
    protected function safeSummary(HoSoSucKhoe $hoSo, array $changed): array
    {
        $counts = [];

        foreach (self::SENSITIVE_LISTS as $field) {
            $counts[$field] = count($hoSo->{$field} ?? []);
        }

        return [
            'ma_nd' => $hoSo->ma_nd,
            'changed_fields' => array_values($changed),
            'item_counts' => $counts,
            'has_notes' => filled($hoSo->notes),
            'consented' => $hoSo->hasConsented(),
        ];
    }
}
