{{--
    Vorlesen und Diktieren, nur für Schüler:innen (see AppServiceProvider).

    Everything runs on the pupil's device: the browser's own speech synthesis with a local voice of the page language reads
    aloud, and dictation only appears when the browser confirms it can recognise that language on the device.
    Nothing is requested from or sent to a third party, so no external resource is referenced here.

    Wiring: every .fi-section gets a "Vorlesen" button in its header; any element with [data-read-aloud]
    (the value, if set, names it for screen readers) gets one at its end; every <textarea> gets "Diktieren".
    Put [data-read-aloud] on a wrapper on the card background (e.g. around a chat bubble and its name), not on the
    coloured bubble itself; the button has an opaque surface, but that keeps it unambiguous.
--}}
@php
    // The browser speaks and understands the language of the page; the texts the script shows come from the translation files.
    $speech = [
        'lang' => app()->getLocale() === 'en' ? 'en-US' : 'de-DE',
        'labels' => [
            'read' => __('Vorlesen'),
            'dictate' => __('Diktieren'),
            'setup' => __('Diktieren einrichten'),
        ],
        'messages' => [
            'listening' => __('Ich höre zu …'),
            'installing' => __('Das deutsche Sprachpaket wird auf dein Gerät geladen …'),
            'not-allowed' => __('Das Mikrofon ist gesperrt. Erlaube es in den Einstellungen deines Browsers.'),
            'service-not-allowed' => __('Das Mikrofon ist gesperrt. Erlaube es in den Einstellungen deines Browsers.'),
            'no-speech' => __('Ich habe nichts gehört.'),
            'failed' => __('Das hat leider nicht geklappt.'),
            'ready' => __('Fertig. Du kannst jetzt diktieren.'),
            'stopped' => __('Ich habe aufgehört zuzuhören.'),
        ],
    ];
@endphp
@verbatim
<style>
    .lp-speech-bar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.5rem;
        margin-top: 0.5rem;
    }

    .lp-speech-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
        min-height: 2.25rem;
        padding: 0.25rem 0.875rem;
        border: 1px solid var(--primary-600);
        border-radius: 9999px;
        background: #fff; /* opaque, so the button stays legible even inside a coloured bubble */
        color: var(--primary-700);
        font: inherit;
        font-size: 0.875rem;
        font-weight: 500;
        line-height: 1.25rem;
        cursor: pointer;
    }

    .lp-speech-btn:hover {
        background: var(--primary-50);
    }

    /* Pressed is shown by fill and by the stop icon, never by colour alone. */
    .lp-speech-btn[aria-pressed="true"] {
        background: var(--primary-600);
        color: #fff;
    }

    .lp-speech-ico {
        display: inline-flex;
        width: 1.25rem;
        height: 1.25rem;
        flex: none;
    }

    .lp-speech-ico svg {
        width: 100%;
        height: 100%;
    }

    .lp-speech-btn .lp-speech-stop,
    .lp-speech-btn[aria-pressed="true"] .lp-speech-start {
        display: none;
    }

    .lp-speech-btn[aria-pressed="true"] .lp-speech-stop {
        display: block;
    }

    .lp-speech-sr {
        position: absolute;
        width: 1px;
        height: 1px;
        margin: -1px;
        padding: 0;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        border: 0;
    }

    .lp-speech-status {
        font-size: 0.875rem;
        line-height: 1.25rem;
        color: var(--gray-700);
    }

    /* Out of the flex flow (no gap) but never display:none: a live region must exist before its first message. */
    .lp-speech-status:empty {
        position: absolute;
    }

    .lp-speech-status[data-listening]::before {
        content: "";
        display: inline-block;
        width: 0.5rem;
        height: 0.5rem;
        margin-right: 0.5rem;
        border-radius: 9999px;
        background: var(--primary-600);
    }

    .dark .lp-speech-btn {
        border-color: var(--primary-400);
        background: var(--gray-900);
        color: var(--primary-400);
    }

    .dark .lp-speech-btn:hover {
        background: color-mix(in oklab, var(--primary-400) 12%, var(--gray-900));
    }

    .dark .lp-speech-btn[aria-pressed="true"] {
        background: var(--primary-500);
        color: #fff;
    }

    .dark .lp-speech-status {
        color: var(--gray-300);
    }

    .dark .lp-speech-status[data-listening]::before {
        background: var(--primary-400);
    }

    @media (prefers-reduced-motion: no-preference) {
        .lp-speech-btn {
            transition: background-color 0.15s;
        }

        .lp-speech-status[data-listening]::before {
            animation: lp-speech-pulse 1.4s ease-in-out infinite;
        }
    }

    @keyframes lp-speech-pulse {
        50% {
            opacity: 0.35;
        }
    }

    @media print {
        .lp-speech-bar,
        .lp-speech-btn {
            display: none;
        }
    }
</style>
<script data-lp-speech>
(() => {
    'use strict';

    // Livewire navigation can run this script again; the first run keeps working.
    if (window.lpSpeech) {
        window.lpSpeech.enhance();
        return;
    }

    const RATE = 0.9; // a little slower than normal, easier to follow for children
    const SKIP = 'button,input,select,textarea,script,style,svg,template,[hidden],[aria-hidden="true"],.sr-only,.lp-speech-bar,[class*="required-mark"]';
@endverbatim
    const LANG = @js($speech['lang']);
    const LABELS = @js($speech['labels']);
    const MESSAGES = @js($speech['messages']);
@verbatim
    const IDLE_MS = 45000; // an open microphone nobody talks to is switched off
    const ICON_START = {
        read: '<svg class="lp-speech-start" viewBox="0 0 20 20" fill="currentColor"><path d="M10.5 3.75a.75.75 0 0 0-1.264-.546L5.203 7H2.667a.75.75 0 0 0-.7.48A6.985 6.985 0 0 0 1.5 10c0 .887.165 1.737.468 2.52.111.29.39.48.7.48h2.535l4.033 3.796a.75.75 0 0 0 1.264-.546V3.75ZM16.45 5.05a.75.75 0 0 0-1.06 1.061 5.5 5.5 0 0 1 0 7.778.75.75 0 0 0 1.06 1.06 7 7 0 0 0 0-9.899Z"/><path d="M14.329 7.172a.75.75 0 0 0-1.061 1.06 2.5 2.5 0 0 1 0 3.536.75.75 0 0 0 1.06 1.06 4 4 0 0 0 0-5.656Z"/></svg>',
        dictate: '<svg class="lp-speech-start" viewBox="0 0 20 20" fill="currentColor"><path d="m5.433 13.917 1.262-3.155A4 4 0 0 1 7.58 9.42l6.92-6.918a2.121 2.121 0 0 1 3 3l-6.92 6.918c-.383.383-.84.685-1.343.886l-3.154 1.262a.5.5 0 0 1-.65-.65Z"/><path d="M3.5 5.75c0-.69.56-1.25 1.25-1.25H10A.75.75 0 0 0 10 3H4.75A2.75 2.75 0 0 0 2 5.75v9.5A2.75 2.75 0 0 0 4.75 18h9.5A2.75 2.75 0 0 0 17 15.25V10a.75.75 0 0 0-1.5 0v5.25c0 .69-.56 1.25-1.25 1.25h-9.5c-.69 0-1.25-.56-1.25-1.25v-9.5Z"/></svg>',
    };
    const ICON_STOP = '<svg class="lp-speech-stop" viewBox="0 0 20 20" fill="currentColor"><path d="M5.25 3A2.25 2.25 0 0 0 3 5.25v9.5A2.25 2.25 0 0 0 5.25 17h9.5A2.25 2.25 0 0 0 17 14.75v-9.5A2.25 2.25 0 0 0 14.75 3h-9.5Z"/></svg>';

    const synth = 'speechSynthesis' in window && 'SpeechSynthesisUtterance' in window ? window.speechSynthesis : null;
    const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition || null;

    let dictationMode = 'none'; // none | downloadable | available, as reported by the browser for on-device recognition of the page language
    let current = null; // { target, utterance } while reading aloud
    let listening = null; // { field, recognition } while dictating
    let timer = null;
    let idleTimer = null;

    // Only a voice that runs on the device: remote voices would send the text to a third party.
    const pickVoice = () => {
        if (!synth) {
            return null;
        }

        const language = new RegExp(`^${LANG.slice(0, 2)}([-_]|$)`, 'i');
        const local = synth.getVoices().filter((voice) => voice.localService === true && language.test(voice.lang));

        return local.find((voice) => voice.lang.replace('_', '-').toLowerCase() === LANG.toLowerCase()) || local[0] || null;
    };

    // Plain text of the visible parts, one sentence per block so headings and list items do not run together.
    const readableText = (root) => {
        const lines = [];
        let line = '';
        const flush = () => {
            const text = line.replace(/\s+/g, ' ').trim();

            if (text) {
                lines.push(/[.!?…:;]$/.test(text) ? text : `${text}.`);
            }

            line = '';
        };
        const walk = (node) => {
            for (const child of node.childNodes) {
                if (child.nodeType === Node.TEXT_NODE) {
                    line += child.nodeValue;
                    continue;
                }

                if (child.nodeType !== Node.ELEMENT_NODE || child.matches(SKIP)) {
                    continue;
                }

                const style = getComputedStyle(child);

                if (style.display === 'none' || style.visibility === 'hidden') {
                    continue;
                }

                const inline = style.display.startsWith('inline') || style.display === 'contents';

                if (!inline) {
                    flush();
                }

                walk(child);

                if (!inline) {
                    flush();
                }
            }
        };

        walk(root);
        flush();

        return lines.join(' ');
    };

    const labelOf = (btn) => {
        const target = btn.speechTarget;
        let label = '';

        if (btn.speechKind === 'dictate') {
            label = target.labels && target.labels[0] ? target.labels[0].textContent : target.getAttribute('aria-label') || '';
        } else if (target.dataset.readAloud) {
            label = target.dataset.readAloud;
        } else {
            // Sections without a header prop (e.g. a start card) carry their heading in the slot.
            const heading = target.querySelector(':scope > .fi-section-header .fi-section-header-heading, :scope > .fi-section-content-ctn :is(h2, h3, h4)');
            label = heading ? heading.textContent : '';
        }

        return label.replace(/\s+/g, ' ').replace(/[\s*]+$/, '').trim();
    };

    const setStatus = (btn, text, isListening = false) => {
        const status = btn.speechWrap && btn.speechWrap.querySelector('.lp-speech-status');

        if (!status) {
            return;
        }

        status.textContent = text;
        status.toggleAttribute('data-listening', isListening);
    };

    // Looked up by field because Livewire may have replaced the button since dictation started.
    const dictateButtonFor = (field) => [...document.querySelectorAll('.lp-speech-btn')]
        .find((btn) => btn.speechKind === 'dictate' && btn.speechTarget === field);

    const clearListeningStatus = (field) => {
        const btn = dictateButtonFor(field);
        const status = btn && btn.speechWrap.querySelector('.lp-speech-status');

        if (status && status.hasAttribute('data-listening')) {
            setStatus(btn, '');
        }
    };

    // Reflects reading/dictating state on every button and keeps the hidden part of its name current.
    const sync = () => {
        for (const btn of document.querySelectorAll('.lp-speech-btn')) {
            if (btn.hasAttribute('aria-pressed')) {
                const on = btn.speechKind === 'read'
                    ? current !== null && current.target === btn.speechTarget
                    : listening !== null && listening.field === btn.speechTarget;

                btn.setAttribute('aria-pressed', String(on));

                if (on && btn.speechKind === 'dictate') {
                    const status = btn.speechWrap.querySelector('.lp-speech-status');

                    if (status && !status.hasAttribute('data-listening')) {
                        setStatus(btn, MESSAGES.listening, true);
                    }
                }
            }

            const label = labelOf(btn);
            const hidden = btn.querySelector('.lp-speech-sr');
            const text = label ? ` – ${label}` : '';

            if (hidden && hidden.textContent !== text) {
                hidden.textContent = text;
            }
        }
    };

    const stopSpeaking = () => {
        if (!current) {
            return;
        }

        current = null; // first, so the cancelled utterance's end event is ignored
        synth.cancel();
        sync();
    };

    const stopDictation = (message = '') => {
        if (!listening) {
            return;
        }

        const { field, recognition } = listening;

        listening = null;
        clearTimeout(idleTimer);
        clearListeningStatus(field);

        if (message && dictateButtonFor(field)) {
            setStatus(dictateButtonFor(field), message);
        }

        try {
            recognition.stop(); // delivers what was already said
        } catch (error) {
            // already stopped
        }

        sync();
    };

    const stopAll = () => {
        stopSpeaking();
        stopDictation();
    };

    const speak = (target) => {
        const voice = pickVoice();
        const text = readableText(target);

        stopAll();

        if (!voice || !text) {
            return;
        }

        const utterance = new SpeechSynthesisUtterance(text);
        const finished = () => {
            if (current && current.utterance === utterance) {
                current = null;
                sync();
            }
        };

        utterance.voice = voice;
        utterance.lang = voice.lang;
        utterance.rate = RATE;
        utterance.onend = finished;
        utterance.onerror = finished;
        current = { target, utterance };
        synth.speak(utterance);
        sync();
    };

    const insertAtCursor = (field, spoken) => {
        const text = spoken.trim();

        if (!text) {
            return;
        }

        const start = field.selectionStart;
        const before = field.value.slice(0, start);
        const space = before && !/\s$/.test(before) ? ' ' : '';

        field.setRangeText(space + text, start, field.selectionEnd, 'end');
        // Livewire and Filament only learn about the new value through these events.
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.dispatchEvent(new Event('change', { bubbles: true }));
    };

    const armIdleStop = () => {
        clearTimeout(idleTimer);
        idleTimer = setTimeout(() => stopDictation(MESSAGES.stopped), IDLE_MS);
    };

    const startDictation = (btn) => {
        const field = btn.speechTarget;
        const recognition = new Recognition();

        stopAll();

        // Never fall back to a cloud recogniser: without this switch the audio would leave the device.
        if (!('processLocally' in recognition)) {
            setStatus(btn, MESSAGES.failed);

            return;
        }

        recognition.processLocally = true;

        recognition.lang = LANG;
        recognition.continuous = true;
        recognition.interimResults = false;
        recognition.onresult = (event) => {
            armIdleStop();

            for (let index = event.resultIndex; index < event.results.length; index++) {
                if (event.results[index].isFinal) {
                    insertAtCursor(field, event.results[index][0].transcript);
                }
            }
        };
        recognition.onerror = (event) => {
            const button = dictateButtonFor(field);

            if (button && event.error !== 'aborted') {
                setStatus(button, MESSAGES[event.error] || MESSAGES.failed);
            }
        };
        recognition.onend = () => {
            if (listening && listening.recognition === recognition) {
                listening = null;
                clearListeningStatus(field); // an error message stays
                sync();
            }
        };

        try {
            recognition.start();
        } catch (error) {
            setStatus(btn, MESSAGES.failed);

            return;
        }

        listening = { field, recognition };
        armIdleStop();
        sync();
    };

    const removeButtons = (kind) => {
        for (const btn of document.querySelectorAll('.lp-speech-btn')) {
            if (btn.speechKind === kind) {
                (btn.speechWrap || btn).remove();
            }
        }
    };

    // After the language pack is installed the setup buttons become microphone buttons in place, so focus and status stay where they are.
    const upgradeSetupButtons = () => {
        for (const btn of document.querySelectorAll('.lp-speech-btn')) {
            if (btn.speechKind === 'dictate' && !btn.hasAttribute('aria-pressed')) {
                btn.speechLabel.textContent = LABELS.dictate;
                btn.setAttribute('aria-pressed', 'false');
            }
        }
    };

    // Asks the browser whether it can recognise the page language on this device; 'available' is the only state that shows a microphone.
    const probe = () => {
        let result;

        try {
            result = Promise.resolve(Recognition.available({ langs: [LANG], processLocally: true }));
        } catch (error) {
            result = Promise.reject(error);
        }

        return result
            .catch(() => 'unavailable')
            .then((state) => {
                const mode = state === 'available' || state === 'downloadable' ? state : 'none';

                if (mode !== dictationMode) {
                    const installedNow = dictationMode === 'downloadable' && mode === 'available';

                    dictationMode = mode;

                    if (installedNow) {
                        upgradeSetupButtons();
                    } else {
                        removeButtons('dictate');
                    }
                }

                schedule();
            });
    };

    // Only after an explicit click: the language pack is downloaded to the device, no speech leaves it.
    const installDictation = async (btn) => {
        let installed = false;

        setStatus(btn, MESSAGES.installing);

        try {
            installed = await Recognition.install({ langs: [LANG], processLocally: true });
        } catch (error) {
            installed = false;
        }

        if (installed) {
            await probe();
        }

        setStatus(btn, installed && dictationMode === 'available' ? MESSAGES.ready : MESSAGES.failed);
    };

    const makeButton = (kind, target, text, pressable = true) => {
        const btn = document.createElement('button');
        const visible = document.createElement('span');
        const hidden = document.createElement('span');

        btn.type = 'button';
        btn.className = 'lp-speech-btn lp-focus';
        btn.speechKind = kind;
        btn.speechTarget = target;
        btn.innerHTML = `<span class="lp-speech-ico" aria-hidden="true">${ICON_START[kind]}${ICON_STOP}</span>`;
        visible.textContent = text;
        hidden.className = 'lp-speech-sr';
        btn.speechLabel = visible;
        btn.append(visible, hidden);

        if (pressable) {
            btn.setAttribute('aria-pressed', 'false');
        }

        return btn;
    };

    const addReadButton = (target) => {
        const btn = makeButton('read', target, LABELS.read);
        const headerContent = target.matches('.fi-section')
            ? target.querySelector(':scope > .fi-section-header > .fi-section-header-content')
            : null;

        if (headerContent) {
            // Inside the header's action area a click does not collapse a collapsible section.
            let actions = headerContent.querySelector(':scope > .fi-section-header-after-ctn');

            if (!actions) {
                actions = document.createElement('div');
                actions.className = 'fi-section-header-after-ctn';
                headerContent.append(actions);
            }

            actions.append(btn);

            return;
        }

        const bar = document.createElement('div');

        bar.className = 'lp-speech-bar';
        bar.append(btn);
        btn.speechWrap = bar;
        // The padding of a section sits on .fi-section-content, not on its container.
        (target.matches('.fi-section')
            ? target.querySelector(':scope > .fi-section-content-ctn > .fi-section-content') || target.querySelector(':scope > .fi-section-content-ctn') || target
            : target).append(bar);
    };

    const addDictateButton = (field) => {
        const setup = dictationMode === 'downloadable';
        const btn = makeButton('dictate', field, setup ? LABELS.setup : LABELS.dictate, !setup);
        const status = document.createElement('span');
        const bar = document.createElement('div');

        status.className = 'lp-speech-status';
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        bar.className = 'lp-speech-bar';
        bar.append(btn, status);
        btn.speechWrap = bar;
        (field.closest('.fi-input-wrp') || field).after(bar);
    };

    const enhance = () => {
        const voice = pickVoice();
        const owned = new Set();

        for (const btn of document.querySelectorAll('.lp-speech-btn')) {
            const target = btn.speechTarget;
            const obsolete = !target
                || !target.isConnected
                || (btn.speechKind === 'read' && !voice)
                || (btn.speechKind === 'dictate' && dictationMode === 'none');

            if (obsolete) {
                (btn.speechWrap || btn).remove();
            } else {
                owned.add(target);
            }
        }

        if (voice) {
            for (const target of document.querySelectorAll('.fi-section, [data-read-aloud]')) {
                if (!owned.has(target) && target.textContent.trim() !== '') {
                    addReadButton(target);
                }
            }
        }

        if (dictationMode !== 'none') {
            for (const field of document.querySelectorAll('textarea')) {
                if (!owned.has(field) && !field.disabled && !field.readOnly) {
                    addDictateButton(field);
                }
            }
        }

        if (current && !current.target.isConnected) {
            stopSpeaking();
        }

        if (listening && !listening.field.isConnected) {
            stopDictation();
        }

        sync();
    };

    // Livewire morphs away what it does not know, so enhancing is repeated (and is idempotent) after every DOM change.
    const schedule = () => {
        clearTimeout(timer);
        timer = setTimeout(enhance, 60);
    };

    document.addEventListener('click', (event) => {
        const btn = event.target.closest && event.target.closest('.lp-speech-btn');

        if (!btn) {
            return;
        }

        event.preventDefault();

        if (btn.speechKind === 'read') {
            if (current && current.target === btn.speechTarget) {
                stopSpeaking();
            } else {
                speak(btn.speechTarget);
            }
        } else if (dictationMode === 'downloadable') {
            installDictation(btn);
        } else if (listening && listening.field === btn.speechTarget) {
            stopDictation();
        } else {
            startDictation(btn);
        }
    });

    document.addEventListener('livewire:navigated', schedule);
    window.addEventListener('pagehide', stopAll);
    // A pupil who switches to another tab or app must not leave an open microphone behind.
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            stopDictation(MESSAGES.stopped);
        }
    });
    new MutationObserver(schedule).observe(document.documentElement, { childList: true, subtree: true });

    if (synth) {
        synth.addEventListener('voiceschanged', schedule);
    }

    if (Recognition && typeof Recognition.available === 'function') {
        probe();
    }

    window.lpSpeech = { enhance };
    enhance();
})();
</script>
@endverbatim
