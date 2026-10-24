{{-- The student's choice is remembered in this browser only (per student); nothing about it is stored on the server. --}}
<div
    x-data="{
        storageKey: @js($storageKey),

        async init() {
            try {
                if (window.localStorage.getItem(this.storageKey) !== '1') {
                    return
                }
            } catch (error) {
                // Storage is blocked: the switch starts off.
                return
            }

            await this.$wire.set('simpleLanguage', true)
            this.remember()
        },

        async toggle() {
            await this.$wire.set('simpleLanguage', ! this.$wire.simpleLanguage)
            this.remember()
        },

        // After the server answered: it turns the switch off again when the simple texts could not be made.
        remember() {
            try {
                window.localStorage.setItem(this.storageKey, this.$wire.simpleLanguage ? '1' : '0')
            } catch (error) {
                // Storage is blocked: the choice only lasts until the page is reloaded.
            }
        },
    }"
    class="lp-card flex items-start gap-4 p-4 ring-1 ring-gray-950/5 dark:ring-white/10"
>
    <button
        type="button"
        role="switch"
        id="simple-language-switch"
        aria-describedby="simple-language-hint"
        x-bind:aria-checked="$wire.simpleLanguage ? 'true' : 'false'"
        x-bind:class="$wire.simpleLanguage ? 'bg-primary-600' : 'bg-gray-300 dark:bg-white/20'"
        x-on:click="toggle()"
        class="lp-focus relative mt-0.5 inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full transition-colors"
    >
        <span
            aria-hidden="true"
            x-bind:class="$wire.simpleLanguage ? 'translate-x-5' : 'translate-x-0'"
            class="pointer-events-none m-0.5 inline-block size-5 rounded-full bg-white shadow-sm transition-transform"
        ></span>
    </button>

    <div class="min-w-0">
        <label for="simple-language-switch" class="block cursor-pointer font-medium text-gray-950 dark:text-white">
            {{ __('Einfache Sprache') }}
        </label>

        <p id="simple-language-hint" class="mt-0.5 text-sm text-gray-600 dark:text-gray-400">
            {{ __('Die Zusammenfassung und die Fragen deiner Lehrkraft in kurzen Sätzen mit einfachen Wörtern.') }}
            {{ __('Deine eigenen Antworten bleiben, wie du sie schreibst.') }}
        </p>

        <p x-cloak x-show="$wire.simpleLanguage" class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            @if ($aiActive)
                {{ __('Die einfache Fassung schreibt eine KI. Sie sieht nur die Texte deiner Lehrkraft, nie deine Antworten.') }}
            @else
                {{ __('Demo-Modus: Die einfache Fassung entsteht ohne KI und ist nur ein Beispiel.') }}
            @endif
        </p>
    </div>
</div>
