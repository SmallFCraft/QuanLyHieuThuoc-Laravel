<?php

namespace App\Models;

use App\Support\VietnameseText;
use Illuminate\Database\Eloquent\Model;

class UserHealthProfile extends Model
{
    protected $primaryKey = 'MaHS';

    protected $fillable = [
        'MaKH',
        'allergies',
        'chronic_conditions',
        'current_medications',
        'is_pregnant',
        'is_breastfeeding',
        'weight_kg',
        'notes',
        'consented_at',
    ];

    protected $casts = [
        // Health data is sensitive: encrypted at rest, decrypted only on read.
        'allergies' => 'encrypted:array',
        'chronic_conditions' => 'encrypted:array',
        'current_medications' => 'encrypted:array',
        'notes' => 'encrypted',
        'is_pregnant' => 'boolean',
        'is_breastfeeding' => 'boolean',
        'weight_kg' => 'float',
        'consented_at' => 'datetime',
    ];

    protected $hidden = [
        'allergies',
        'chronic_conditions',
        'current_medications',
        'notes',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'MaKH', 'MaKH');
    }

    /**
     * Nothing in this profile may influence triage or safety checks until the
     * user has explicitly consented.
     */
    public function hasConsented(): bool
    {
        return $this->consented_at !== null;
    }

    /**
     * What people type into a tag field to mean "nothing to declare".
     *
     * Not medical history: a literal "Không" in the allergy list made knownTerms()
     * non-empty, which made MedicalTriageService::isStepCoveredByProfile() skip wizard
     * step 4 — so the triage never asked about chronic disease, allergy, pregnancy or
     * breastfeeding at all, and a follow-up round asked it instead.
     *
     * Also keeps the encrypted column clean, because DrugSafetyService reads `allergies`
     * directly and falls back to substring-matching product names for terms of 4+ chars.
     * "Không" folds to a 5-character string, so it is one unlucky product name away from
     * blocking a cart with a bogus allergy warning.
     */
    public const NEGATION_TERMS = [
        'khong', 'khong co', 'khong co gi', 'khong bi gi', 'ko', 'ko co', 'ko co gi',
        'k', 'kg', 'chua', 'chua co', 'chang co', 'no', 'none', 'nothing', 'nil',
        'n a', 'na', 'trong', 'khong biet', '0', 'x',
    ];

    /**
     * fold() strips punctuation, so "N/A", "N.A." and "-" all either land in the list
     * above or fold to the empty string.
     */
    public static function isNegationTerm(string $term): bool
    {
        $folded = VietnameseText::fold($term);

        return $folded === '' || in_array($folded, self::NEGATION_TERMS, true);
    }

    /**
     * Flattened, lowercase list of every term the safety checks match against.
     */
    public function knownTerms(): array
    {
        return collect([
            ...($this->allergies ?? []),
            ...($this->chronic_conditions ?? []),
            ...($this->current_medications ?? []),
        ])
            ->map(fn ($term) => mb_strtolower(trim((string) $term), 'UTF-8'))
            ->filter()
            ->unique()
            ->reject(fn (string $term) => self::isNegationTerm($term))
            ->values()
            ->all();
    }

    public function scopeConsented($query)
    {
        return $query->whereNotNull('consented_at');
    }

    /**
     * The consented profile for a user, or null. Four call sites needed this exact
     * query, so the "only read with consent" rule lives in one place.
     */
    public static function consentedFor(int $userId): ?self
    {
        return static::where('MaKH', $userId)->consented()->first();
    }
}
