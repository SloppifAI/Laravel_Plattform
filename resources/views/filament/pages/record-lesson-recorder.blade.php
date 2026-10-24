{{--
    The browser side of "Tuto-Mikro" (RecordLesson page): the styles of the indicator and the Alpine component
    `tutoMikro`. Audio only. No external resource is referenced.

    Order of events: the teacher presses "Mikro an" -> the browser asks for the microphone (audio only; not at all when
    nobody could listen, see below) -> the start endpoint checks the consent of the present students and the teacher's
    confirmation of the list, and opens the session -> when the school's AI is on, the browser cuts the sound into segments
    of ~25 s (a new MediaRecorder per segment, so each segment is a complete file) and posts each one; the server
    recognises it from memory and returns the text, which is appended to "Notizen aus der Stunde" (browser memory only).
    Without the AI, the browser's on-device German recognition writes the notes, if it has one, and no sound is sent
    anywhere; the browser then asks the check endpoint at the rhythm of the segments, so the consent is checked during
    the whole session in every mode. Without the AI and without on-device recognition the microphone is not opened at
    all: the bar says "Mikro aus" and that the teacher types the notes. "Mikro aus" stops everything and ends the
    session; "Zusammenfassung erstellen" posts the reviewed text to the summary endpoint. Leaving the page discards the
    notes (the browser asks first) and the microphone is released at once; nothing ever listens in the background.

    Failure rules: every request has a deadline. A segment that cannot be delivered after two retries is dropped (the sound
    is not kept anywhere) and the teacher is told; a refusal by the server (consent withdrawn, school switch off, AI
    switched off, session too old, login expired) turns the microphone off at once.
--}}
@verbatim
<style>
    /* Fixed above everything, also above Filament's modals and topbar, so it can never be scrolled or covered away. */
    .lp-mic-bar {
        position: fixed;
        inset-block-start: 0;
        inset-inline: 0;
        z-index: 100000;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.75rem;
        min-height: 3.5rem;
        padding: 0.375rem 0.75rem;
        border-block-end: 4px solid var(--danger-600, #e11d48);
        background: var(--danger-100, #ffe4e6);
        color: var(--danger-900, #881337);
        font-size: clamp(0.95rem, 4.4vw, 1.125rem);
        font-weight: 700;
        line-height: 1.25;
    }

    /* Listening: Lichtblau with a blue edge, a different word and a different symbol. */
    .lp-mic-bar[data-state="on"] {
        border-block-end-color: var(--primary-500, #2563eb);
        background: var(--primary-100, #dbeafe);
        color: var(--primary-950, #172554);
    }

    :is(.dark) .lp-mic-bar {
        background: var(--danger-950, #4c0519);
        color: var(--danger-100, #ffe4e6);
    }

    :is(.dark) .lp-mic-bar[data-state="on"] {
        background: var(--primary-950, #172554);
        color: var(--primary-100, #dbeafe);
    }

    .lp-mic-dot {
        font-size: 1.25rem;
        line-height: 1;
    }

    .lp-mic-text {
        display: flex;
        flex-direction: column;
        min-width: 0;
        text-align: center;
    }

    .lp-mic-note {
        font-size: 0.8125rem;
        font-weight: 600;
        line-height: 1.2;
    }

    /* The blue ring around the whole window while Tuto listens. It never takes a click. */
    .lp-mic-ring {
        position: fixed;
        inset: 0;
        z-index: 99999;
        pointer-events: none;
        box-shadow: inset 0 0 0 6px var(--primary-400, #60a5fa);
    }

    /* Room for the bar; the fixed Filament chrome and the toasts move down with it. */
    html.lp-mic-page body {
        padding-block-start: 3.5rem;
    }

    html.lp-mic-page .fi-topbar,
    html.lp-mic-page .fi-sidebar {
        inset-block-start: 3.5rem;
    }

    html.lp-mic-page .fi-sidebar {
        height: calc(100dvh - 3.5rem);
    }

    html.lp-mic-page .fi-no {
        inset-block-start: 4.5rem;
    }

    /* The pulse is optional decoration: without it the bar, the words and the colour still say "Mikro an". */
    @media (prefers-reduced-motion: no-preference) {
        .lp-mic-bar[data-state="on"] .lp-mic-dot {
            animation: lp-mic-pulse 1.6s ease-in-out infinite;
        }
    }

    @keyframes lp-mic-pulse {
        50% {
            opacity: 0.3;
        }
    }

    .lp-mic-sr {
        position: absolute;
        width: 1px;
        height: 1px;
        margin: -1px;
        padding: 0;
        border: 0;
        overflow: hidden;
        clip-path: inset(50%);
        white-space: nowrap;
    }

    .lp-mic-meter {
        inline-size: 8rem;
        block-size: 0.625rem;
        overflow: hidden;
        border-radius: 999px;
        background: var(--gray-200, #e5e7eb);
    }

    .lp-mic-meter > span {
        display: block;
        block-size: 100%;
        background: var(--primary-600, #2563eb);
    }
</style>

<script>
    window.tutoMikro = (config) => {
        // Native objects stay out of Alpine's reactive state: proxies around a MediaRecorder break its methods.
        let stream = null;
        let recorder = null;
        let segmentTimer = null;
        let heartbeatTimer = null;
        let recognition = null;
        let wakeLock = null;
        let audioContext = null;
        let analyser = null;
        let levelData = null;
        let meterTimer = null;
        let lastSoundAt = 0;
        let session = null;
        let mimeType = '';
        let queue = [];
        let sending = false;
        let startedAt = 0;
        let timer = null;
        let leaving = false;
        let originalTitle = document.title;
        let boardChannel = null;
        const tabId = Math.random().toString(36).slice(2);
        let boardTimer = null;

        const MAX_QUEUED_SEGMENTS = 6;
        const HIDE_NAMES_KEY = 'tuto-mikro-hide-names';
        const ALLOWED_TYPES = ['audio/webm', 'audio/ogg', 'audio/mp4'];
        const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
        const baseType = (type) => String(type || '').split(';')[0].trim().toLowerCase();
        const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        // Only a recogniser that has the attribute can be kept on the device; one without it might use a cloud service.
        const recognisesOnDevice = !!(Recognition && 'processLocally' in Recognition.prototype);

        // Every request has a deadline: a stalled connection must end in the retry path, not in silence.
        const request = (url, options, timeout = 30000) => {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), timeout);

            return fetch(url, { credentials: 'same-origin', ...options, signal: controller.signal }).finally(() => clearTimeout(timeoutId));
        };
        const jsonHeaders = () => ({ 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': config.csrf });

        return {
            phase: 'off', // off | requesting | on | stopping
            supported: !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder),
            secure: window.isSecureContext !== false,
            micState: 'unknown', // unknown | prompt | granted | denied
            accessDenied: false,
            hideNames: false,
            mode: '', // server | device | manual: where the notes come from while the microphone is on
            sttAvailable: false,
            sttInterim: '',
            notes: '',
            error: '',
            warning: '',
            announcement: '',
            muted: false,
            level: null,
            heard: true,
            elapsed: 0,
            hasSession: false,
            summarising: false,
            result: null,
            resultError: '',
            taken: false,

            init() {
                document.documentElement.classList.add('lp-mic-page');

                // The class may be looking at this screen: a teacher who hid the names last time finds them hidden again.
                try {
                    this.hideNames = window.localStorage.getItem(HIDE_NAMES_KEY) === '1';
                } catch (error) {
                    // Storage is blocked: the names are shown until the teacher hides them.
                }

                this.checkPermission();
                this.probeSpeech();

                // The Beamer display (MikroBoard) in another window of this browser learns the state from here: only "on" or
                // "off", never a name or a word of the notes. It asks once when it opens, and the state is repeated every
                // 2 s so that it can tell a closed tab from a quiet one. On another device it asks the server instead.
                if ('BroadcastChannel' in window && config.boardChannel) {
                    boardChannel = new BroadcastChannel(config.boardChannel);
                    boardChannel.onmessage = (event) => event.data && event.data.type === 'hello' && this.publishState();
                    boardTimer = setInterval(() => this.publishState(), 2000);
                    this.$watch('listening', () => this.publishState());
                    this.publishState();
                }

                // The browser's own "leave this page?" question: the notes exist nowhere else.
                window.addEventListener('beforeunload', (event) => {
                    if (!leaving && (this.active || this.notes.trim() !== '')) {
                        event.preventDefault();
                        event.returnValue = '';
                    }
                });
                window.addEventListener('pagehide', () => this.abandon());
                // Also when Livewire swaps the page without unloading it (wire:navigate or a panel switched to SPA mode).
                document.addEventListener('livewire:navigate', () => {
                    this.abandon();
                    document.documentElement.classList.remove('lp-mic-page');
                });
                document.addEventListener('visibilitychange', () => {
                    if (document.visibilityState === 'visible' && this.phase === 'on') {
                        this.lockScreen();
                        this.syncMuted();
                    }
                });
            },

            destroy() {
                clearInterval(boardTimer);

                if (boardChannel) {
                    boardChannel.close();
                }
            },

            // Tells the Beamer display whether the microphone really listens ("Mikro an") or not ("Mikro aus"). Only a tab that
            // holds the session may say "aus": an idle tab sends session: false, and the display then ignores its "aus".
            publishState() {
                if (boardChannel) {
                    boardChannel.postMessage({ type: 'state', tab: tabId, on: this.listening, session: this.phase === 'on' || this.phase === 'stopping' });
                }
            },

            toggleNames() {
                this.hideNames = !this.hideNames;

                try {
                    window.localStorage.setItem(HIDE_NAMES_KEY, this.hideNames ? '1' : '0');
                } catch (error) {
                    // Not remembered; it still works for this visit.
                }
            },

            get active() {
                return this.phase !== 'off';
            },

            // Only while something actually listens: in manual mode the session is on, the microphone is not.
            get listening() {
                return this.phase === 'on' && this.mode !== 'manual';
            },

            // Written by the server: whether the consents allow starting; the teacher's confirmation of the list is needed
            // as well. The button follows both, the server decides again.
            get startable() {
                return this.supported && this.secure && this.$wire.canStart && this.$wire.attendanceConfirmed && this.phase === 'off';
            },

            // While the microphone is on and Tuto writes, the text is Tuto's; typing in between would be overwritten.
            get notesLocked() {
                return this.listening;
            },

            get canSummarise() {
                return this.hasSession && !this.active && !this.summarising && this.notes.trim() !== '';
            },

            get clock() {
                const total = this.elapsed;
                const hours = Math.floor(total / 3600);
                const minutes = Math.floor((total % 3600) / 60);
                const seconds = String(total % 60).padStart(2, '0');

                return hours > 0 ? `${hours}:${String(minutes).padStart(2, '0')}:${seconds}` : `${minutes}:${seconds}`;
            },

            // The bar says it in words, in colour and in its symbol: Mikro an in blue, Mikro aus in red.
            get barLabel() {
                if (this.listening) {
                    return 'Mikro an – Tuto schreibt mit';
                }

                return this.phase === 'on' ? 'Mikro aus – du schreibst die Notizen selbst' : 'Mikro aus';
            },

            // The second line of the bar: whatever endangers the notes, in the place nobody can scroll away from.
            get barNote() {
                if (this.phase === 'stopping') {
                    return 'Der letzte Teil wird noch mitgeschrieben';
                }

                if (this.listening && this.muted) {
                    return 'Kein Ton: Mikro stumm oder getrennt';
                }

                return '';
            },

            // Written a moment after the change, into a region that is always on the page, so screen readers say it.
            announce(message) {
                this.announcement = '';
                setTimeout(() => (this.announcement = message), 100);
            },

            // Moves the focus to a control that is shown after the one that had it was hidden. Alpine reveals an
            // element in the next animation frame, and focusing something that is still display:none does nothing.
            focusOn(reference) {
                const attempt = (retries) => {
                    const element = this.$refs[reference];

                    element?.focus();

                    if (element && document.activeElement !== element && retries > 0) {
                        setTimeout(() => attempt(retries - 1), 100);
                    }
                };

                this.$nextTick(() => requestAnimationFrame(() => attempt(3)));
            },

            async checkPermission() {
                if (!navigator.permissions || !navigator.permissions.query) {
                    return;
                }

                try {
                    const status = await navigator.permissions.query({ name: 'microphone' });
                    const apply = () => (this.micState = status.state);

                    apply();
                    status.onchange = apply;
                } catch (error) {
                    // Not every browser knows the "microphone" permission; the state stays "unknown".
                }
            },

            // On-device recognition for German only; 'available' is the one state that lets Tuto write without the AI.
            async probeSpeech() {
                if (!recognisesOnDevice || typeof Recognition.available !== 'function') {
                    return;
                }

                try {
                    this.sttAvailable = (await Recognition.available({ langs: ['de-DE'], processLocally: true })) === 'available';
                } catch (error) {
                    this.sttAvailable = false;
                }
            },

            async start() {
                if (!this.startable) {
                    return;
                }

                this.error = '';
                this.warning = '';
                this.accessDenied = false;
                this.muted = false;
                this.phase = 'requesting';

                // Nobody could listen without the school's AI and without recognition on the device: then the
                // microphone is not opened at all, and the bar does not claim that Tuto writes along.
                if (config.aiEnabled || this.sttAvailable) {
                    this.announce('Dein Browser fragt jetzt nach dem Mikrofon.');

                    try {
                        stream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
                    } catch (error) {
                        this.phase = 'off';
                        await this.explainMicrophoneError(error);

                        return;
                    }
                }

                let status = 0;
                let body = {};

                try {
                    const response = await request(config.startUrl, {
                        method: 'POST',
                        headers: jsonHeaders(),
                        body: JSON.stringify({ present: this.$wire.present.map(Number), attendance_confirmed: this.$wire.attendanceConfirmed }),
                    });

                    status = response.status;
                    body = await response.json().catch(() => ({}));

                    if (!response.ok) {
                        throw new Error(String(status));
                    }
                } catch (error) {
                    this.cancelStart(this.describeStartError(status, body));

                    return;
                }

                session = body;
                queue = [];
                this.elapsed = 0;
                this.mode = body.transcribes ? 'server' : this.sttAvailable ? 'device' : 'manual';

                // The school's AI setting changed after this page was loaded: the microphone was opened (or not) for another mode.
                if (this.mode !== 'manual' && !stream) {
                    this.cancelStart('Die Einstellungen deiner Schule haben sich geändert. Lade die Seite neu und schalte Tuto-Mikro noch einmal an.');
                    this.endQuietly();

                    return;
                }

                if (this.mode === 'manual') {
                    this.stopTracks();
                }

                this.hasSession = true;
                this.taken = false;

                if (this.mode === 'server' && !this.startSegment(body.segment_seconds)) {
                    this.cancelStart('Dein Browser kann den Ton nicht in Abschnitte teilen. Bitte probiere eine aktuelle Version von Chrome, Edge, Firefox oder Safari.');
                    this.endQuietly();

                    return;
                }

                // Unplugging the microphone or withdrawing the permission turns it off; it never goes on silently.
                // A muted track (locked screen, another app, a call) is shown at once.
                (stream ? stream.getAudioTracks() : []).forEach((track) => {
                    track.addEventListener('ended', () => this.trackEnded());
                    track.addEventListener('mute', () => (this.muted = true));
                    track.addEventListener('unmute', () => (this.muted = false));
                    this.muted = track.muted;
                });

                originalTitle = document.title.replace(/^● Mikro an – /, '');
                startedAt = performance.now();
                this.phase = 'on';
                this.micState = 'granted';
                this.syncTitle();
                this.lockScreen();
                timer = setInterval(() => (this.elapsed = Math.floor((performance.now() - startedAt) / 1000)), 500);
                this.focusOn('stopButton');

                if (stream) {
                    this.startMeter();
                }

                // Segments carry the consent check in server mode; without them the check endpoint is asked at the same rhythm.
                if (this.mode !== 'server') {
                    heartbeatTimer = setInterval(() => this.heartbeat(), body.segment_seconds * 1000);
                }

                this.announce(this.mode === 'manual' ? 'Das Mikro bleibt aus. Du schreibst die Notizen selbst.' : 'Mikro an. Tuto schreibt mit.');

                if (this.mode === 'device') {
                    this.startSpeech();
                }
            },

            // Asks the server whether the session may go on (consent, switches, age); a no turns everything off.
            async heartbeat() {
                if (this.phase !== 'on') {
                    return;
                }

                try {
                    const response = await request(session.check_url, { method: 'POST', headers: jsonHeaders() }, 15000);

                    if (response.status === 409) {
                        const body = await response.json().catch(() => ({}));

                        this.fail(`${body.message || 'Tuto-Mikro darf nicht weiterlaufen.'} Das Mikro ist aus.`);
                        this.$wire.recheck();
                    } else if ([401, 419].includes(response.status)) {
                        this.fail('Deine Anmeldung ist abgelaufen. Das Mikro ist aus. Kopiere deine Notizen, bevor du die Seite neu lädst.');
                    }
                } catch (error) {
                    // No connection or no answer in time: the next beat asks again.
                }
            },

            cancelStart(message) {
                this.stopTracks();
                this.phase = 'off';
                this.error = message;
                this.focusOn('errorNotice');
            },

            describeStartError(status, body) {
                switch (status) {
                    case 403:
                        return 'Tuto-Mikro ist für deine Schule gerade nicht freigeschaltet. Die Schuladministration entscheidet darüber.';
                    case 401:
                    case 419:
                        return 'Deine Anmeldung ist abgelaufen. Lade die Seite neu und melde dich gegebenenfalls an.';
                    case 409:
                        // The reasons are on the page; they are read again, because a consent may just have changed.
                        this.$wire.recheck();

                        return `${body.message || 'Die Einwilligungen reichen nicht aus.'} Was fehlt, steht oben unter „Wer ist heute da?“.`;
                    case 422:
                        return body.message || 'Wähle zuerst aus, wer heute da ist.';
                    case 429:
                        return 'Du hast zu oft gestartet. Warte eine Minute und versuche es noch einmal.';
                    default:
                        return 'Tuto-Mikro konnte nicht starten. Bitte lade die Seite neu und versuche es noch einmal.';
                }
            },

            // The browser says "NotAllowedError" for a refusal and for a closed prompt alike: ask what the permission really is.
            async explainMicrophoneError(error) {
                switch (error && error.name) {
                    case 'NotAllowedError':
                        await this.checkPermission();

                        if (this.micState === 'prompt') {
                            this.error = 'Das Mikrofon wurde nicht freigegeben: Du hast die Anfrage des Browsers abgelehnt oder das Fenster geschlossen. Drücke noch einmal „Mikro an“ und wähle „Zulassen“.';
                        } else {
                            this.accessDenied = true;
                            this.error = this.micState === 'denied'
                                ? 'Der Browser hat den Zugriff auf das Mikrofon nicht erlaubt. Wie du ihn erlaubst, steht unten.'
                                : 'Das Mikrofon wurde nicht freigegeben: Du hast die Anfrage des Browsers abgelehnt oder das Fenster geschlossen. Fragt der Browser nicht mehr nach, hilft die Anleitung unten.';
                        }

                        break;
                    case 'SecurityError':
                        this.error = 'Der Browser erlaubt dieser Seite hier keinen Zugriff auf das Mikrofon. Das liegt meist an einer ungesicherten Verbindung (http statt https).';

                        break;
                    case 'NotFoundError':
                    case 'OverconstrainedError':
                        this.error = 'Es wurde kein Mikrofon gefunden. Schließe ein Headset oder ein Ansteckmikrofon an und versuche es noch einmal.';

                        break;
                    case 'NotReadableError':
                        this.error = 'Das Mikrofon wird gerade von einem anderen Programm benutzt. Schließe es dort und versuche es noch einmal.';

                        break;
                    default:
                        this.error = 'Das Mikrofon konnte nicht gestartet werden. Bitte versuche es noch einmal.';
                }

                this.focusOn('errorNotice');
            },

            syncMuted() {
                const track = stream && stream.getAudioTracks()[0];

                if (track) {
                    this.muted = track.muted;
                }
            },

            // Cuts the sound into complete files: the next recorder starts before the old one stops, so no word falls
            // into the gap (a word at the seam may appear twice). Returns false when the browser cannot record.
            startSegment(seconds) {
                const candidates = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'];
                const supportedType = candidates.find((type) => MediaRecorder.isTypeSupported(type));
                let next;

                try {
                    next = new MediaRecorder(stream, { ...(supportedType ? { mimeType: supportedType } : {}), audioBitsPerSecond: 32000 });
                } catch (error) {
                    return false;
                }

                mimeType = baseType(next.mimeType || supportedType);

                if (!ALLOWED_TYPES.includes(mimeType)) {
                    return false;
                }

                const parts = [];
                const previous = recorder;

                next.ondataavailable = (event) => {
                    if (event.data && event.data.size > 0) {
                        parts.push(event.data);
                    }
                };
                next.onstop = () => {
                    if (parts.length > 0) {
                        this.enqueue(new Blob(parts, { type: mimeType }));
                    }
                };
                next.onerror = () => this.fail('Tuto-Mikro wurde vom Browser unterbrochen.');

                try {
                    next.start();
                } catch (error) {
                    return false;
                }

                recorder = next;

                if (previous && previous.state !== 'inactive') {
                    previous.stop();
                }

                clearTimeout(segmentTimer);
                segmentTimer = setTimeout(() => {
                    if (this.phase === 'on' && !this.startSegment(seconds)) {
                        this.fail('Tuto-Mikro wurde vom Browser unterbrochen. Das Mikro ist aus.');
                    }
                }, seconds * 1000);

                return true;
            },

            // The sound waits here only until the server answered; nothing of it is kept afterwards.
            enqueue(blob) {
                queue.push(blob);

                while (queue.length > MAX_QUEUED_SEGMENTS) {
                    queue.shift();
                    this.warning = 'Die Verbindung ist zu langsam: Ein Teil der Stunde fehlt in den Notizen.';
                }

                this.flush();
            },

            // Posts the segments strictly one after another, so the text arrives in the order it was said.
            async flush() {
                if (sending) {
                    return;
                }

                sending = true;

                while (queue.length > 0) {
                    const blob = queue.shift();

                    if (!(await this.deliver(blob))) {
                        queue = [];
                    }
                }

                sending = false;
            },

            // Returns false when the session must stop (the server said so), true otherwise, also for a dropped segment.
            async deliver(blob) {
                for (let attempt = 0; attempt < 3; attempt++) {
                    try {
                        const response = await request(session.segment_url, {
                            method: 'POST',
                            headers: { 'Content-Type': mimeType, Accept: 'application/json', 'X-CSRF-TOKEN': config.csrf },
                            body: blob,
                        }, 60000);

                        if (response.ok) {
                            const body = await response.json().catch(() => ({}));

                            if (body.text) {
                                this.append(body.text);
                            }

                            return true;
                        }

                        if (response.status === 409) {
                            const body = await response.json().catch(() => ({}));

                            this.fail(`${body.message || 'Tuto-Mikro darf nicht weiterlaufen.'} Das Mikro ist aus.`);
                            this.$wire.recheck();

                            return false;
                        }

                        if ([401, 419].includes(response.status)) {
                            this.fail('Deine Anmeldung ist abgelaufen. Das Mikro ist aus. Kopiere deine Notizen, bevor du die Seite neu lädst.');

                            return false;
                        }

                        if (response.status < 500 && ![408, 429].includes(response.status)) {
                            break;
                        }
                    } catch (error) {
                        // No connection or no answer in time: retry below.
                    }

                    await sleep(1500 * (attempt + 1));
                }

                this.warning = 'Ein Teil der Stunde konnte nicht mitgeschrieben werden und fehlt in den Notizen. Das Mikro ist weiter an.';
                this.announce(this.warning);

                return true;
            },

            append(text) {
                this.notes = (this.notes.trim() === '' ? '' : this.notes.replace(/\s+$/, '') + ' ') + text.trim();
            },

            // A small level meter: a wrong input or a lock screen still "listens", so the teacher can see sound arrive.
            startMeter() {
                try {
                    const Context = window.AudioContext || window.webkitAudioContext;

                    if (!Context) {
                        return;
                    }

                    audioContext = new Context();
                    analyser = audioContext.createAnalyser();
                    analyser.fftSize = 512;
                    levelData = new Uint8Array(analyser.fftSize);
                    audioContext.createMediaStreamSource(stream).connect(analyser);
                    audioContext.resume().catch(() => {});
                    lastSoundAt = performance.now();
                    this.heard = true;
                    meterTimer = setInterval(() => this.measure(), 250);
                } catch (error) {
                    this.stopMeter();
                }
            },

            measure() {
                // A context the browser keeps suspended says nothing about the sound: show no meter then.
                if (!analyser || audioContext.state !== 'running') {
                    this.level = null;

                    return;
                }

                analyser.getByteTimeDomainData(levelData);

                let peak = 0;

                for (const value of levelData) {
                    peak = Math.max(peak, Math.abs(value - 128));
                }

                this.level = Math.min(1, peak / 48);

                if (this.level > 0.05) {
                    lastSoundAt = performance.now();
                }

                this.heard = performance.now() - lastSoundAt < 10000;
            },

            stopMeter() {
                clearInterval(meterTimer);

                if (audioContext) {
                    audioContext.close().catch(() => {});
                }

                audioContext = null;
                analyser = null;
                this.level = null;
            },

            // "Mikro aus": the microphone is released first, whatever the network is doing; then the last segment is
            // delivered, the session is ended on the server and the teacher can review the notes.
            async stop() {
                if (this.phase !== 'on') {
                    return;
                }

                this.phase = 'stopping';
                clearInterval(timer);
                clearInterval(heartbeatTimer);
                clearTimeout(segmentTimer);
                this.elapsed = Math.floor((performance.now() - startedAt) / 1000);
                this.syncTitle();
                this.announce('Mikro aus. Der letzte Teil wird noch mitgeschrieben.');

                const speechSettled = this.stopSpeech(true);
                const stopped = new Promise((resolve) => {
                    if (!recorder || recorder.state === 'inactive') {
                        resolve();

                        return;
                    }

                    // The last segment arrives with the stop event; it is in the queue once this resolves.
                    recorder.addEventListener('stop', resolve, { once: true });
                    recorder.stop();
                });

                await Promise.race([stopped, sleep(5000)]);
                recorder = null;
                this.stopTracks();
                this.stopMeter();
                this.releaseScreen();
                await speechSettled;

                while (queue.length > 0 || sending) {
                    await sleep(300);
                }

                await this.end();
                this.phase = 'off';
                this.syncTitle();
                this.$wire.$refresh();
                this.focusOn('notesField');
                this.announce('Mikro aus. Die Notizen aus der Stunde stehen unten. Prüfe sie und erstelle dann die Zusammenfassung.');
            },

            // Tells the server the session is over; a failure only means the end time is missing in the session list.
            async end() {
                for (let attempt = 0; attempt < 3; attempt++) {
                    try {
                        const response = await request(session.end_url, {
                            method: 'POST',
                            headers: jsonHeaders(),
                            body: JSON.stringify({ duration_seconds: this.elapsed }),
                        }, 15000);

                        if (response.ok) {
                            return;
                        }
                    } catch (error) {
                        // Offline: try again below.
                    }

                    await sleep(1000 * (attempt + 1));
                }
            },

            // Best effort, for a session that has to end abruptly.
            endQuietly() {
                if (!session) {
                    return;
                }

                request(session.end_url, { method: 'POST', keepalive: true, headers: jsonHeaders(), body: JSON.stringify({ duration_seconds: this.elapsed }) }, 15000).catch(() => {});
            },

            // Ends everything at once: the microphone is off, the teacher is told why. The notes stay.
            fail(message) {
                if (this.phase === 'off') {
                    return;
                }

                clearInterval(timer);
                clearInterval(heartbeatTimer);
                clearTimeout(segmentTimer);
                this.stopSpeech();

                try {
                    if (recorder && recorder.state !== 'inactive') {
                        recorder.stop();
                    }
                } catch (error) {
                    // Already stopped.
                }

                recorder = null;
                this.stopTracks();
                this.stopMeter();
                this.releaseScreen();
                this.elapsed = Math.max(this.elapsed, Math.floor((performance.now() - startedAt) / 1000));
                this.endQuietly();
                this.phase = 'off';
                this.syncTitle();
                this.error = message;
                this.focusOn('errorNotice');
                this.announce(message);
            },

            trackEnded() {
                if (this.phase === 'on') {
                    this.fail('Das Mikrofon wurde getrennt oder gesperrt. Das Mikro ist aus.');
                }
            },

            // Leaving the page: the microphone is released right away; nothing listens on. The page is reset too,
            // because a browser may show it again from its back/forward cache.
            abandon() {
                if (!this.active) {
                    this.stopTracks();

                    return;
                }

                clearInterval(timer);
                clearInterval(heartbeatTimer);
                clearTimeout(segmentTimer);
                this.stopSpeech();

                try {
                    if (recorder && recorder.state !== 'inactive') {
                        recorder.stop();
                    }
                } catch (error) {
                    // Already stopped.
                }

                recorder = null;
                queue = [];
                this.stopTracks();
                this.stopMeter();
                this.releaseScreen();
                this.elapsed = Math.floor((performance.now() - startedAt) / 1000);
                this.endQuietly();
                this.phase = 'off';
                this.syncTitle();
                this.publishState();
            },

            stopTracks() {
                if (stream) {
                    stream.getTracks().forEach((track) => track.stop());
                    stream = null;
                }
            },

            syncTitle() {
                document.title = (this.listening ? '● Mikro an – ' : '') + originalTitle;
            },

            async lockScreen() {
                try {
                    if ('wakeLock' in navigator && !wakeLock) {
                        wakeLock = await navigator.wakeLock.request('screen');
                        wakeLock.addEventListener('release', () => (wakeLock = null));
                    }
                } catch (error) {
                    // A sleeping screen is only an inconvenience; the microphone carries on.
                }
            },

            releaseScreen() {
                if (wakeLock) {
                    wakeLock.release().catch(() => {});
                    wakeLock = null;
                }
            },

            // The recognition on the device is not available after all: nobody listens, so the microphone is released too.
            goManual(warning = '') {
                this.mode = 'manual';
                this.stopTracks();
                this.stopMeter();
                this.muted = false;

                if (warning) {
                    this.warning = warning;
                    this.announce(warning);
                }
            },

            startSpeech() {
                if (this.mode !== 'device' || this.phase !== 'on' || recognition || !Recognition) {
                    return;
                }

                // Never use a recogniser that cannot be kept on the device: its sound might go to a cloud service.
                if (!recognisesOnDevice) {
                    this.goManual();

                    return;
                }

                const instance = new Recognition();

                instance.processLocally = true;
                instance.lang = 'de-DE';
                instance.continuous = true;
                instance.interimResults = true;
                instance.onresult = (event) => {
                    let interim = '';

                    for (let index = event.resultIndex; index < event.results.length; index++) {
                        const text = event.results[index][0].transcript.trim();

                        if (event.results[index].isFinal) {
                            this.append(text);
                        } else {
                            interim += ' ' + text;
                        }
                    }

                    this.sttInterim = interim.trim();
                };
                instance.onerror = (event) => {
                    if (['not-allowed', 'service-not-allowed', 'language-not-supported'].includes(event.error)) {
                        this.goManual('Tuto kann hier nicht mitschreiben, weil die Spracherkennung auf diesem Gerät nicht läuft. Das Mikro ist aus, du kannst die Notizen selbst tippen.');
                    }
                };
                instance.onend = () => {
                    if (recognition === instance) {
                        recognition = null;
                        this.sttInterim = '';

                        // The recogniser ends by itself after a pause in speech; carry on while it is wanted.
                        if (this.mode === 'device' && this.phase === 'on') {
                            setTimeout(() => this.startSpeech(), 400);
                        }
                    }
                };

                try {
                    instance.start();
                    recognition = instance;
                } catch (error) {
                    this.goManual();
                }
            },

            // With `settle`, waits (briefly) for the last words the recogniser is still processing.
            async stopSpeech(settle = false) {
                const instance = recognition;

                recognition = null;
                this.sttInterim = '';

                if (!instance) {
                    return;
                }

                const ended = new Promise((resolve) => {
                    instance.onend = resolve;
                });

                try {
                    instance.stop();
                } catch (error) {
                    return;
                }

                if (settle) {
                    await Promise.race([ended, sleep(2000)]);
                }
            },

            // "Zusammenfassung erstellen": the reviewed text goes to the server, which replaces the names of the class
            // first. Nothing is stored there; the result lives in this page until the teacher takes it over.
            async summarise() {
                if (!this.canSummarise) {
                    return;
                }

                this.summarising = true;
                this.resultError = '';
                this.taken = false;

                try {
                    const response = await request(session.analyse_url, {
                        method: 'POST',
                        headers: jsonHeaders(),
                        body: JSON.stringify({ text: this.notes }),
                    }, 130000);
                    const body = await response.json().catch(() => ({}));

                    if (!response.ok) {
                        this.result = null;
                        this.resultError = body.message || (response.status === 419 ? 'Deine Anmeldung ist abgelaufen. Kopiere deine Notizen und lade die Seite neu.' : 'Die Zusammenfassung konnte nicht erstellt werden. Deine Notizen sind noch da.');
                    } else {
                        this.result = body.analysis;
                        this.announce('Die Zusammenfassung ist fertig. Prüfe sie, bevor du etwas übernimmst.');
                        this.focusOn('resultHeading');
                    }
                } catch (error) {
                    this.result = null;
                    this.resultError = 'Die Zusammenfassung konnte nicht erstellt werden, die Verbindung hat nicht geantwortet. Deine Notizen sind noch da.';
                }

                this.summarising = false;
            },

            async takeOver() {
                if (!this.result || !this.result.suggested_notes) {
                    return;
                }

                await this.$wire.applyToNotes(this.result.suggested_notes);
                this.taken = true;
            },

            // The page sends the teacher to the lesson afterwards; the browser's "leave this page?" question would only be in the way.
            async createHomework() {
                if (!this.result || !this.result.suggested_notes) {
                    return;
                }

                leaving = true;
                await this.$wire.createHomework(this.result.suggested_notes);
                setTimeout(() => (leaving = false), 5000);
            },

            discard() {
                if (this.notes.trim() === '' || window.confirm('Die Notizen aus der Stunde werden gelöscht. Was du schon übernommen hast, bleibt in den Stundennotizen. Fortfahren?')) {
                    this.notes = '';
                    this.result = null;
                    this.resultError = '';
                    this.taken = false;
                    this.announce('Die Notizen sind gelöscht.');
                }
            },
        };
    };
</script>
@endverbatim
