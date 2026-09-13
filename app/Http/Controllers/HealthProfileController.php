<?php

namespace App\Http\Controllers;

use App\Http\Requests\HealthProfileRequest;
use App\Models\UserHealthProfile;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;

class HealthProfileController extends Controller
{
    /**
     * Fields whose *content* is health data. Only their names and item counts
     * may reach the audit log — never the values themselves.
     */
    protected const SENSITIVE_LISTS = ['allergies', 'chronic_conditions', 'current_medications'];

    public function update(HealthProfileRequest $request): RedirectResponse
    {
        $userId = auth()->id();
        $profile = UserHealthProfile::where('MaKH', $userId)->first();

        $attributes = $request->profileAttributes();
        $attributes['consented_at'] = $request->boolean('consent')
            ? ($profile?->consented_at ?? now())
            : null;

        // Separate from consented_at: an unticked box is a real withdrawal of the
        // AI consent, even when the safety-warning consent below stays ticked.
        $attributes['ai_consent_at'] = $request->boolean('ai_consent')
            ? ($profile?->ai_consent_at ?? now())
            : null;

        $changed = $this->changedFields($profile, $attributes);

        if ($profile) {
            $profile->fill($attributes)->save();
        } else {
            $profile = UserHealthProfile::create($attributes + ['MaKH' => $userId]);
        }

        app(AuditLogService::class)->log(
            action: 'health_profile_updated',
            modelType: UserHealthProfile::class,
            modelId: $profile->MaHS,
            oldValues: null,
            newValues: $this->safeSummary($profile, $changed),
        );

        return Redirect::to(route('profile.edit') . '#health-profile')
            ->with('status', 'health-profile-updated');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $profile = UserHealthProfile::where('MaKH', auth()->id())->first();

        if ($profile) {
            $profileId = $profile->MaHS;
            $profile->delete();

            app(AuditLogService::class)->log(
                action: 'health_profile_deleted',
                modelType: UserHealthProfile::class,
                modelId: $profileId,
                oldValues: null,
                newValues: ['MaKH' => auth()->id()],
            );
        }

        return Redirect::to(route('profile.edit') . '#health-profile')
            ->with('status', 'health-profile-deleted');
    }

    /**
     * Names of the fields whose value differs from what is stored.
     *
     * @return array<int, string>
     */
    protected function changedFields(?UserHealthProfile $profile, array $attributes): array
    {
        if (! $profile) {
            return array_keys($attributes);
        }

        $changed = [];

        foreach ($attributes as $field => $value) {
            $current = $profile->{$field};

            if ($field === 'consented_at') {
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
     * Audit payload with counts only: allergy and condition text must never be
     * written to audit_logs in the clear.
     *
     * @return array<string, mixed>
     */
    protected function safeSummary(UserHealthProfile $profile, array $changed): array
    {
        $counts = [];

        foreach (self::SENSITIVE_LISTS as $field) {
            $counts[$field] = count($profile->{$field} ?? []);
        }

        return [
            'MaKH' => $profile->MaKH,
            'changed_fields' => array_values($changed),
            'item_counts' => $counts,
            'has_notes' => filled($profile->notes),
            'consented' => $profile->hasConsented(),
        ];
    }
}
