{{-- The declaration form of the public consent page. Nothing is pre-ticked: consent needs an active choice. --}}
<form method="POST" action="{{ route('consents.store', $token) }}" class="consent-form">
    <input type="hidden" name="consent_form" value="{{ $form->getKey() }}">

    <fieldset @error('purposes') aria-describedby="purposes-error" @enderror>
        <legend class="consent-legend">Ich willige ein in:</legend>

        <div style="margin-top: 0.5rem">
            @foreach ($form->purposeLabels() as $purpose => $label)
                <label class="consent-field">
                    <input
                        type="checkbox"
                        name="purposes[]"
                        value="{{ $purpose }}"
                        @checked(in_array($purpose, $selected, true))
                        @required($purpose === 'audio')
                    >
                    <span>
                        {{ $label }}
                        @if ($purpose === 'audio')
                            <span class="consent-hint">(notwendig, die anderen Zwecke bauen darauf auf)</span>
                        @endif
                    </span>
                </label>
            @endforeach
        </div>

        @error('purposes')
            <p class="consent-error" id="purposes-error">{{ $message }}</p>
        @enderror
    </fieldset>

    <div>
        <label for="guardian_name" class="consent-label">Ihr Name</label>
        <input
            type="text"
            id="guardian_name"
            name="guardian_name"
            value="{{ $guardianName }}"
            class="consent-input"
            autocomplete="name"
            maxlength="120"
            required
            @error('guardian_name') aria-invalid="true" aria-describedby="guardian-name-error" @enderror
        >
        @error('guardian_name')
            <p class="consent-error" id="guardian-name-error">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label class="consent-field">
            <input type="checkbox" name="is_guardian" value="1" required @error('is_guardian') aria-describedby="is-guardian-error" @enderror>
            <span>Ich bin erziehungsberechtigt und willige für {{ $firstName }} ein. Ich habe den Text oben gelesen.</span>
        </label>
        @error('is_guardian')
            <p class="consent-error" id="is-guardian-error">{{ $message }}</p>
        @enderror
    </div>

    <button type="submit" class="consent-button">Einwilligung erteilen</button>
</form>
