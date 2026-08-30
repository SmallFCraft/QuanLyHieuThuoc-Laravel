{{-- Tag input shared by the three health list fields. Each tag ships as its own
     hidden input so DrugSafetyService receives one term per item, not free text. --}}
@php
    $items = collect($items ?? [])->map(fn ($item) => (string) $item)->values()->all();
    $suggestions = $suggestions ?? [];
    $inputId = $field . '_input';
    $errorKey = $field . '.*';
    $removePrefix = trim(str_replace(':item', '', __('messages.health_profile.tag_remove'))) . ' ';
@endphp

<div x-data="{
        items: @js($items),
        draft: '',
        max: 30,
        add(raw) {
            const value = (raw ?? '').trim();
            if (value === '' || value.length > 120) return;
            if (this.items.length >= this.max) return;
            if (this.has(value)) return;
            this.items.push(value);
        },
        commit() {
            this.draft.split(',').forEach(part => this.add(part));
            this.draft = '';
        },
        remove(index) { this.items.splice(index, 1); },
        has(value) { return this.items.some(item => item.toLowerCase() === value.toLowerCase()); },
        toggle(value) {
            const index = this.items.findIndex(item => item.toLowerCase() === value.toLowerCase());
            index === -1 ? this.add(value) : this.remove(index);
        },
        onKeydown(event) {
            if (event.key === 'Enter' || event.key === ',') {
                event.preventDefault();
                this.commit();
                return;
            }
            if (event.key === 'Backspace' && this.draft === '' && this.items.length) {
                this.remove(this.items.length - 1);
            }
        },
     }">
    <label for="{{ $inputId }}" class="form-label fw-medium text-gray-700 mb-1">
        <i class="bi {{ $icon }} text-emerald-600 me-1"></i>{{ $label }}
    </label>
    <p id="{{ $field }}_hint" class="text-gray-500 small mb-2">{{ $hint }}</p>

    <div class="hp-tag-field">
        <ul class="hp-tag-list mb-0" x-show="items.length" aria-live="polite">
            <template x-for="(item, index) in items" :key="index">
                <li class="hp-tag">
                    <span x-text="item"></span>
                    <input type="hidden" name="{{ $field }}[]" :value="item">
                    <button type="button"
                            class="hp-tag-remove"
                            x-on:click="remove(index)"
                            :aria-label="@js($removePrefix) + item">
                        <i class="bi bi-x-lg" aria-hidden="true" style="font-size: 0.75rem;"></i>
                    </button>
                </li>
            </template>
        </ul>

        <input type="text"
               class="hp-tag-input"
               id="{{ $inputId }}"
               x-model="draft"
               x-on:keydown="onKeydown($event)"
               x-on:blur="commit()"
               :disabled="items.length >= max"
               aria-describedby="{{ $field }}_hint"
               autocomplete="off">
    </div>

    <p class="text-gray-500 small mt-1 mb-0" x-show="items.length >= max" role="status">
        {{ __('messages.health_profile.max_items_reached') }}
    </p>

    @if ($suggestions !== [])
        <div class="mt-2">
            <span class="text-gray-500 small d-block mb-2" id="{{ $field }}_suggestions_label">{{ __('messages.health_profile.suggestions_label') }}</span>
            <div class="d-flex flex-wrap gap-2" role="group" aria-labelledby="{{ $field }}_suggestions_label">
                @foreach ($suggestions as $suggestion)
                    <button type="button"
                            class="hp-chip"
                            x-on:click="toggle(@js($suggestion))"
                            :aria-pressed="has(@js($suggestion)) ? 'true' : 'false'">
                        <i class="bi bi-plus-lg me-1" aria-hidden="true" style="font-size: 0.75rem;"></i>{{ $suggestion }}
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    @error($field)
        <div class="invalid-feedback d-block" role="alert">{{ $message }}</div>
    @enderror
    @error($errorKey)
        <div class="invalid-feedback d-block" role="alert">{{ $message }}</div>
    @enderror
</div>
