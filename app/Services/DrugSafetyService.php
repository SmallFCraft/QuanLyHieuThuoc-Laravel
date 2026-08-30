<?php

namespace App\Services;

use App\Models\DrugContraindication;
use App\Models\DrugInteraction;
use App\Models\Product;
use App\Models\User;
use App\Models\UserHealthProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Drug interaction and contraindication checks.
 *
 * All knowledge lives in `drug_interactions` and `drug_contraindications`; nothing
 * here asks a language model. Ingredient names are matched after stripping dosage,
 * because `products.ingredients` stores strings like "Paracetamol 500mg".
 */
class DrugSafetyService
{
    /**
     * Words that appear inside ingredient strings but never identify a drug.
     */
    protected const NOISE_TOKENS = [
        'mg', 'g', 'ml', 'mcg', 'iu', 'vien', 'sui', 'hcl', 'sodium', 'potassium',
        'calcium', 'sulfate', 'besylate', 'maleate', 'tartrate', 'bisulfate',
        'trihydrate', 'oxide', 'carbonate', 'acid', 'va', 'chua', 'tong', 'hop',
    ];

    /**
     * Multi-word ingredient names must survive tokenisation, so they are matched
     * as whole phrases before single tokens are considered.
     */
    protected const COMPOUND_INGREDIENTS = [
        'acid acetylsalicylic',
        'acid ascorbic',
        'acid clavulanic',
        'acid folic',
        'ferrous sulfate',
        'calcium carbonate',
        'magnesium oxide',
        'zinc sulfate',
        'coenzyme q10',
    ];

    public function __construct(
        protected AuditLogService $auditLogService,
    ) {
    }

    /**
     * Interaction table indexed by ingredient pair, built once per request.
     *
     * @var array<string, DrugInteraction>|null
     */
    protected ?array $interactionIndex = null;

    /**
     * Contraindication rows indexed by ingredient, built once per request.
     *
     * @var array<string, array<int, DrugContraindication>>|null
     */
    protected ?array $contraindicationIndex = null;

    /**
     * Every warning that applies to buying this product as this user.
     *
     * @return array{warnings: array<int, array>, blocking: bool}
     */
    public function checkProduct(Product $product, ?UserHealthProfile $profile = null, ?User $user = null): array
    {
        $ingredients = $this->extractIngredients($product->ingredients ?? '');

        if ($ingredients === []) {
            return ['warnings' => [], 'blocking' => false];
        }

        $warnings = [
            ...$this->contraindicationWarnings($ingredients, $profile, $user),
            ...$this->currentMedicationWarnings($ingredients, $profile, $product),
            ...$this->allergyWarnings($ingredients, $profile, $product),
        ];

        return [
            'warnings' => $warnings,
            'blocking' => $this->hasBlocking($warnings),
        ];
    }

    /**
     * Interaction between two raw ingredient strings (as stored on products).
     */
    public function checkPair(string $ingredientsA, string $ingredientsB): Collection
    {
        $a = $this->extractIngredients($ingredientsA);
        $b = $this->extractIngredients($ingredientsB);

        if ($a === [] || $b === []) {
            return collect();
        }

        $found = collect();

        foreach ($a as $left) {
            foreach ($b as $right) {
                if ($left === $right) {
                    continue;
                }

                $interaction = $this->findInteraction($left, $right);

                if ($interaction) {
                    $found->push($interaction);
                }
            }
        }

        return $found->unique('MaTT')->values();
    }

    /**
     * One query per request instead of one per ingredient pair.
     *
     * A cart of n products with k ingredients each produced O(n²k²) SELECTs — at the
     * 100-item cart ceiling that was ~10k queries for a single checkout render. The
     * curated interaction table is small enough to index in memory once.
     *
     * @return array<string, DrugInteraction>
     */
    protected function interactionIndex(): array
    {
        if ($this->interactionIndex !== null) {
            return $this->interactionIndex;
        }

        $index = [];

        foreach (DrugInteraction::active()->get() as $interaction) {
            // Stored once but symmetric, so index both directions.
            $index[$interaction->ingredient_a . '|' . $interaction->ingredient_b] = $interaction;
            $index[$interaction->ingredient_b . '|' . $interaction->ingredient_a] = $interaction;
        }

        return $this->interactionIndex = $index;
    }

    protected function findInteraction(string $a, string $b): ?DrugInteraction
    {
        return $this->interactionIndex()[$a . '|' . $b] ?? null;
    }

    /**
     * Same reason as interactionIndex: one query instead of one per product.
     *
     * @return array<string, array<int, DrugContraindication>>
     */
    protected function contraindicationIndex(): array
    {
        if ($this->contraindicationIndex !== null) {
            return $this->contraindicationIndex;
        }

        $index = [];

        foreach (DrugContraindication::active()->get() as $row) {
            $index[$row->ingredient][] = $row;
        }

        return $this->contraindicationIndex = $index;
    }

    /**
     * Scan the whole cart: every product against the user's profile, and every
     * pair of products against each other.
     *
     * @param array $cartItems session cart rows, each with at least a MaSP key
     * @return array{warnings: array<int, array>, blocking: bool}
     */
    public function checkCart(array $cartItems, int $userId): array
    {
        $productIds = collect($cartItems)->pluck('MaSP')->filter()->unique()->values();

        if ($productIds->isEmpty()) {
            return ['warnings' => [], 'blocking' => false];
        }

        $products = Product::whereIn('MaSP', $productIds)->get();
        $user = User::find($userId);
        $profile = $this->consentedProfile($userId);

        $warnings = [];

        foreach ($products as $product) {
            $warnings = [...$warnings, ...$this->checkProduct($product, $profile, $user)['warnings']];
        }

        foreach ($this->productPairs($products) as [$left, $right]) {
            foreach ($this->checkPair($left->ingredients ?? '', $right->ingredients ?? '') as $interaction) {
                $warnings[] = [
                    'type' => 'interaction',
                    'severity' => $interaction->severity,
                    'title' => "{$left->name} + {$right->name}",
                    'message' => $interaction->description_vi,
                    'action' => $interaction->recommendation_vi,
                    'product_ids' => [$left->MaSP, $right->MaSP],
                ];
            }
        }

        return [
            'warnings' => $this->dedupe($warnings),
            'blocking' => $this->hasBlocking($warnings),
        ];
    }

    /**
     * Adding one product to a cart that already holds others.
     *
     * @return array{warnings: array<int, array>, blocking: bool}
     */
    public function checkAddition(Product $product, array $existingCart, int $userId): array
    {
        $user = User::find($userId);
        $profile = $this->consentedProfile($userId);

        $warnings = $this->checkProduct($product, $profile, $user)['warnings'];

        $existingIds = collect($existingCart)->pluck('MaSP')->filter()
            ->reject(fn ($id) => (int) $id === (int) $product->MaSP)
            ->unique();

        if ($existingIds->isNotEmpty()) {
            foreach (Product::whereIn('MaSP', $existingIds)->get() as $existing) {
                foreach ($this->checkPair($product->ingredients ?? '', $existing->ingredients ?? '') as $interaction) {
                    $warnings[] = [
                        'type' => 'interaction',
                        'severity' => $interaction->severity,
                        'title' => "{$product->name} + {$existing->name}",
                        'message' => $interaction->description_vi,
                        'action' => $interaction->recommendation_vi,
                        'product_ids' => [$product->MaSP, $existing->MaSP],
                    ];
                }
            }
        }

        $warnings = $this->dedupe($warnings);

        return ['warnings' => $warnings, 'blocking' => $this->hasBlocking($warnings)];
    }

    /**
     * Called when the user acknowledges a major warning and buys anyway.
     * Recorded so the decision is traceable — this is a health record, not a UX event.
     *
     * The write is best-effort: the safety decision itself has already been made, so a
     * failing audit row must not turn the acknowledged add-to-cart into a 500.
     */
    public function logOverride(int $userId, Product $product, array $warnings): void
    {
        try {
            $this->auditLogService->log(
                action: 'drug_warning_overridden',
                modelType: Product::class,
                modelId: $product->MaSP,
                oldValues: null,
                newValues: [
                    'MaKH' => $userId,
                    'product' => $product->name,
                    'warning_types' => collect($warnings)->pluck('type')->unique()->values()->all(),
                    'severities' => collect($warnings)->pluck('severity')->unique()->values()->all(),
                ],
                userId: $userId
            );
        } catch (\Throwable $exception) {
            Log::warning('Drug warning override audit failed', [
                'MaKH' => $userId,
                'MaSP' => $product->MaSP,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Profile is only readable once the user consented; without consent the checks
     * fall back to product-level rules that need no personal data.
     */
    protected function consentedProfile(int $userId): ?UserHealthProfile
    {
        return UserHealthProfile::consentedFor($userId);
    }

    /**
     * "Amoxicillin 500mg + Acid clavulanic 125mg" -> ['amoxicillin', 'acid clavulanic']
     *
     * @return array<int, string>
     */
    public function extractIngredients(string $raw): array
    {
        $normalized = $this->normalizeIngredientText($raw);

        if ($normalized === '') {
            return [];
        }

        $found = [];

        // Compound names first, then remove them so their parts are not re-matched.
        foreach (self::COMPOUND_INGREDIENTS as $compound) {
            if (str_contains($normalized, $compound)) {
                $found[] = $compound;
                $normalized = str_replace($compound, ' ', $normalized);
            }
        }

        foreach (preg_split('/\s+/', $normalized) ?: [] as $token) {
            if ($this->isMeaningfulToken($token)) {
                $found[] = $token;
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Same folding rules as ImageRecognitionService::normalizeProductText so both
     * services agree on ingredient spelling.
     */
    protected function normalizeIngredientText(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = str_replace(['đ', 'Đ'], ['d', 'd'], $text);
        $text = Str::ascii($text);
        // Drop the dosage entirely: it is not part of the drug identity.
        $text = preg_replace('/\d+[.,]?\d*\s*(mg|mcg|g|ml|iu)\b/u', ' ', $text) ?? $text;
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    protected function isMeaningfulToken(string $token): bool
    {
        return mb_strlen($token) >= 4
            && !is_numeric($token)
            && !in_array($token, self::NOISE_TOKENS, true);
    }

    /**
     * Age, pregnancy, breastfeeding and chronic-disease contraindications.
     *
     * @return array<int, array>
     */
    protected function contraindicationWarnings(array $ingredients, ?UserHealthProfile $profile, ?User $user): array
    {
        $rows = collect($ingredients)
            ->flatMap(fn (string $ingredient) => $this->contraindicationIndex()[$ingredient] ?? [])
            ->unique(fn (DrugContraindication $row) => $row->MaCCD);

        if ($rows->isEmpty()) {
            return [];
        }

        $age = $user?->ageInYears();
        $chronic = $profile?->hasConsented() ? $this->lowercaseList($profile->chronic_conditions) : [];
        $allergies = $profile?->hasConsented() ? $this->lowercaseList($profile->allergies) : [];

        $warnings = [];

        foreach ($rows as $row) {
            if (!$this->contraindicationApplies($row, $age, $profile, $chronic, $allergies)) {
                continue;
            }

            $warnings[] = [
                'type' => 'contraindication',
                'severity' => $row->isAbsolute() ? 'major' : 'moderate',
                'title' => $this->contraindicationTitle($row),
                'message' => $row->warning_vi,
                'action' => $row->isAbsolute()
                    ? 'Anh/chị không nên dùng thuốc này. Vui lòng hỏi dược sĩ để chọn loại phù hợp.'
                    : 'Anh/chị nên hỏi dược sĩ trước khi dùng.',
                'ingredient' => $row->ingredient,
            ];
        }

        return $warnings;
    }

    protected function contraindicationApplies(
        DrugContraindication $row,
        ?int $age,
        ?UserHealthProfile $profile,
        array $chronic,
        array $allergies
    ): bool {
        return match ($row->condition_type) {
            // Unknown age must not silently pass an age guard.
            'age_under' => $age !== null && $age < (int) $row->condition_value,
            'age_over' => $age !== null && $age > (int) $row->condition_value,
            'pregnancy' => (bool) ($profile?->hasConsented() && $profile->is_pregnant),
            'breastfeeding' => (bool) ($profile?->hasConsented() && $profile->is_breastfeeding),
            'chronic_disease' => $this->listMentions($chronic, (string) $row->condition_value),
            'allergy' => $this->listMentions($allergies, (string) $row->condition_value),
            default => false,
        };
    }

    protected function contraindicationTitle(DrugContraindication $row): string
    {
        return match ($row->condition_type) {
            'age_under' => "Không phù hợp với người dưới {$row->condition_value} tuổi",
            'age_over' => "Cần thận trọng với người trên {$row->condition_value} tuổi",
            'pregnancy' => 'Cần thận trọng khi mang thai',
            'breastfeeding' => 'Cần thận trọng khi cho con bú',
            'chronic_disease' => "Cần thận trọng với tình trạng: {$row->condition_value}",
            'allergy' => "Liên quan dị ứng đã khai: {$row->condition_value}",
            default => 'Cảnh báo chống chỉ định',
        };
    }

    /**
     * Interaction between the product being bought and what the user already takes.
     *
     * @return array<int, array>
     */
    protected function currentMedicationWarnings(array $ingredients, ?UserHealthProfile $profile, Product $product): array
    {
        if (!$profile?->hasConsented() || empty($profile->current_medications)) {
            return [];
        }

        $warnings = [];

        foreach ($profile->current_medications as $medication) {
            foreach ($this->extractIngredients((string) $medication) as $existing) {
                foreach ($ingredients as $incoming) {
                    if ($incoming === $existing) {
                        continue;
                    }

                    $interaction = $this->findInteraction($incoming, $existing);

                    if (!$interaction) {
                        continue;
                    }

                    $warnings[] = [
                        'type' => 'current_medication',
                        'severity' => $interaction->severity,
                        'title' => "{$product->name} + thuốc đang dùng ({$medication})",
                        'message' => $interaction->description_vi,
                        'action' => $interaction->recommendation_vi,
                        'ingredient' => $incoming,
                    ];
                }
            }
        }

        return $warnings;
    }

    /**
     * Direct allergy match. Always blocking: a declared allergy is not a suggestion.
     *
     * @return array<int, array>
     */
    protected function allergyWarnings(array $ingredients, ?UserHealthProfile $profile, Product $product): array
    {
        if (!$profile?->hasConsented() || empty($profile->allergies)) {
            return [];
        }

        $warnings = [];

        foreach ($profile->allergies as $allergy) {
            $allergyTokens = $this->extractIngredients((string) $allergy);
            $normalizedAllergy = $this->normalizeIngredientText((string) $allergy);
            $productName = $this->normalizeIngredientText($product->name);

            // The name fallback needs the same length floor as isMeaningfulToken:
            // an allergy typed as "C" or "cá" would otherwise substring-match nearly
            // every product name and block the whole catalog as a major warning.
            $nameMatch = mb_strlen($normalizedAllergy) >= 4
                && preg_match('/(?<![a-z0-9])' . preg_quote($normalizedAllergy, '/') . '/u', $productName) === 1;

            $matched = array_intersect($allergyTokens, $ingredients) !== [] || $nameMatch;

            if (!$matched) {
                continue;
            }

            $warnings[] = [
                'type' => 'allergy',
                'severity' => 'major',
                'title' => "Anh/chị đã khai dị ứng với: {$allergy}",
                'message' => "Sản phẩm {$product->name} có thành phần trùng hoặc liên quan đến chất anh/chị đã khai dị ứng.",
                'action' => 'Anh/chị không nên dùng thuốc này. Vui lòng hỏi dược sĩ để được đổi sang thuốc khác.',
                'ingredient' => $allergy,
            ];
        }

        return $warnings;
    }

    /**
     * @return array<int, array{0: Product, 1: Product}>
     */
    protected function productPairs(Collection $products): array
    {
        $list = $products->values();
        $pairs = [];

        for ($i = 0; $i < $list->count(); $i++) {
            for ($j = $i + 1; $j < $list->count(); $j++) {
                $pairs[] = [$list[$i], $list[$j]];
            }
        }

        return $pairs;
    }

    protected function hasBlocking(array $warnings): bool
    {
        return collect($warnings)->contains(fn (array $warning) => ($warning['severity'] ?? '') === 'major');
    }

    protected function dedupe(array $warnings): array
    {
        return collect($warnings)
            ->unique(fn (array $warning) => ($warning['type'] ?? '') . '|' . ($warning['title'] ?? ''))
            ->sortBy(fn (array $warning) => match ($warning['severity'] ?? 'minor') {
                'major' => 1,
                'moderate' => 2,
                default => 3,
            })
            ->values()
            ->all();
    }

    protected function lowercaseList(?array $items): array
    {
        return collect($items ?? [])
            ->map(fn ($item) => mb_strtolower(trim((string) $item), 'UTF-8'))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Substring match in both directions: the user may write "bệnh dạ dày" where
     * the rule says "loét dạ dày", or the other way round.
     */
    protected function listMentions(array $haystack, string $needle): bool
    {
        if ($needle === '' || $haystack === []) {
            return false;
        }

        $needle = mb_strtolower(trim($needle), 'UTF-8');
        $needleAscii = $this->normalizeIngredientText($needle);

        foreach ($haystack as $item) {
            $itemAscii = $this->normalizeIngredientText($item);

            if ($itemAscii === '' || $needleAscii === '') {
                continue;
            }

            if (str_contains($itemAscii, $needleAscii) || str_contains($needleAscii, $itemAscii)) {
                return true;
            }
        }

        return false;
    }
}
