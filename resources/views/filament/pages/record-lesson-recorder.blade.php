{{--
    The recorder of "Stunde aufnehmen" (RecordLesson page): styles for the recording bar and the Alpine component
    `lpRecorder`. Audio only; everything stays in this browser until it is posted, chunk by chunk, to the page's own
    authorised upload route. No external resource is referenced and no audio goes anywhere else.

    Order of events: the teacher confirms the consent dialog -> only then the browser is asked for the microphone
    (audio only) -> the recording is created on the server with the consent -> MediaRecorder posts a chunk
    every few seconds -> "Aufnahme beenden" stops the microphone first, then posts the last chunk and the duration.
    Leaving the page ends the recording and releases the microphone; nothing ever records in the background.

    Failure rules: every request has a deadline and is retried; a stalled connection shows a warning in the bar. A
    lapsed login keeps recording (and renews the page's token); a refusal that cannot get better ends the recording
    and offers the parts that were not saved as a download.
--}}
@verbatim
<style>
    /* Fixed above everything, also above Filament's modals and topbar, so it can never be scrolled or covered away. */
    .lp-rec-bar {
        position: fixed;
        inset-block-start: 0;
        inset-inline: 0;
        z-index: 100000;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.75rem;
        height: 3.5rem;
        padding-inline: 0.75rem;
        border-block-end: 4px solid var(--danger-950, #4c0519);
        background: var(--danger-700, #be123c);
        color: #fff;
        font-size: clamp(0.95rem, 4.4vw, 1.125rem);
        font-weight: 700;
        line-height: 1.25;
        white-space: nowrap;
    }

    /*
     * Not recording: the bar stays in the page as a live region (visually hidden, not display:none), so screen
     * readers announce it when it fills with "Aufnahme läuft".
     */
    .lp-rec-bar.lp-rec-bar--off,
    .lp-rec-sr {
        position: absolute;
        inset: auto;
        width: 1px;
        height: 1px;
        margin: -1px;
        padding: 0;
        border: 0;
        overflow: hidden;
        clip-path: inset(50%);
        white-space: nowrap;
    }

    /* Paused is a different colour, a different symbol and different words. */
    .lp-rec-bar[data-state="paused"] {
        border-block-end-color: var(--warning-700, #b45309);
        background: var(--warning-300, #fcd34d);
        color: var(--gray-950, #030712);
    }

    .lp-rec-dot {
        font-size: 1.5rem;
        line-height: 1;
    }

    .lp-rec-text {
        display: flex;
        flex-direction: column;
        min-width: 0;
    }

    .lp-rec-note {
        overflow: hidden;
        text-overflow: ellipsis;
        font-size: 0.8125rem;
        line-height: 1.2;
    }

    .lp-rec-clock {
        font-variant-numeric: tabular-nums;
    }

    /* Room for the bar, and the fixed Filament chrome and toasts move down with it. */
    html.lp-rec-active body {
        padding-block-start: 3.5rem;
    }

    html.lp-rec-active .fi-topbar,
    html.lp-rec-active .fi-sidebar {
        inset-block-start: 3.5rem;
    }

    html.lp-rec-active .fi-sidebar {
        height: calc(100dvh - 3.5rem);
    }

    html.lp-rec-active .fi-no {
        inset-block-start: 4.5rem;
    }

    /* The pulse is optional decoration: without it the bar, the words and the timer still say "recording". */
    @media (prefers-reduced-motion: no-preference) {
        .lp-rec-bar[data-state="recording"] .lp-rec-dot {
            animation: lp-rec-pulse 1.4s ease-in-out infinite;
        }
    }

    @keyframes lp-rec-pulse {
        50% {
            opacity: 0.25;
        }
    }

    .lp-rec-meter {
        inline-size: 8rem;
        block-size: 0.625rem;
        overflow: hidden;
        border-radius: 999px;
        background: var(--gray-200, #e5e7eb);
    }

    .lp-rec-meter > span {
        display: block;
        block-size: 100%;
        background: var(--primary-600, #2563eb);
    }

    .lp-rec-dialog::backdrop {
        background: rgb(3 7 18 / 0.6);
    }
</style>

<script>
    window.lpRecorder = (config) => {
        // Native objects stay out of Alpine's reactive state: proxies around a MediaRecorder break its methods.
        let stream = null;
        let recorder = null;
        let recognition = null;
        let wakeLock = null;
        let audioContext = null;
        let analyser = null;
        let levelData = null;
        let meterTimer = null;
        let lastSoundAt = 0;
        let urls = null;
        let mimeType = '';
        let queue = [];
        let headBlob = null;
        let nextIndex = 0;
        let sending = false;
        let startedAt = 0;
        let pausedAt = 0;
        let pausedTotal = 0;
        let timer = null;
        let transcriptTimer = null;
        let pushedTranscript = '';
        let originalTitle = document.title;
        let uploadFailed = false;
        let abandoned = false;
        let lastRenewAt = -Infinity;

        const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
        const baseType = (type) => String(type || '').split(';')[0].trim().toLowerCase();
        const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        // Only a recogniser that has the attribute can be kept on the device; one without it might use a cloud service.
        const recognisesOnDevice = !!(Recognition && 'processLocally' in Recognition.prototype);
        const extensions = { 'audio/webm': 'webm', 'audio/ogg': 'ogg', 'audio/mp4': 'm4a' };

        // Every request has a deadline: a stalled connection must end in the retry and warning path, not in silence.
        const request = (url, options, timeout = 30000) => {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), timeout);

            return fetch(url, { credentials: 'same-origin', ...options, signal: controller.signal }).finally(() => clearTimeout(timeoutId));
        };
        const jsonHeaders = () => ({ 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': config.csrf });

        return {
            phase: 'idle', // idle | requesting | recording | paused | finishing | done
            supported: !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder),
            secure: window.isSecureContext !== false,
            canRecord: config.canRecord !== false,
            micState: 'unknown', // unknown | prompt | granted | denied
            accessDenied: false,
            consentChecked: false,
            error: '',
            warning: '',
            uploadWarning: '',
            earlyReason: '',
            doneKind: '', // saved | early | discarded
            announcement: '',
            muted: false,
            level: null,
            heard: true,
            elapsed: 0,
            uploaded: 0,
            waiting: 0,
            unsentParts: 0,
            sttAvailable: false,
            sttEnabled: false,
            sttFinal: '',
            sttInterim: '',
            transcriptUnsaved: false,
            copyState: '',

            init() {
                this.checkPermission();
                this.probeSpeech();

                this.$watch('sttEnabled', (enabled) => (enabled ? this.startSpeech() : this.stopSpeech()));

                // The browser's own "leave this page?" question; the recording ends if the teacher leaves anyway.
                window.addEventListener('beforeunload', (event) => {
                    if (this.active) {
                        event.preventDefault();
                        event.returnValue = '';
                    }
                });
                window.addEventListener('pagehide', () => this.abandon());
                // The page may come back from the back/forward cache: it was reset on the way out, the list is stale.
                window.addEventListener('pageshow', (event) => {
                    if (event.persisted) {
                        Promise.resolve(this.$wire.reloadRecordings()).catch(() => {});
                    }
                });
                // Also when Livewire swaps the page without unloading it (wire:navigate or a panel switched to SPA mode).
                document.addEventListener('livewire:navigate', () => this.abandon());
                document.addEventListener('visibilitychange', () => {
                    if (document.visibilityState === 'visible' && this.phase === 'recording') {
                        this.lockScreen();
                        this.syncMuted();
                    }
                });
            },

            get active() {
                return ['recording', 'paused', 'finishing'].includes(this.phase);
            },

            get clock() {
                const total = this.elapsed;
                const hours = Math.floor(total / 3600);
                const minutes = Math.floor((total % 3600) / 60);
                const seconds = String(total % 60).padStart(2, '0');

                return hours > 0 ? `${hours}:${String(minutes).padStart(2, '0')}:${seconds}` : `${minutes}:${seconds}`;
            },

            get barLabel() {
                return { recording: 'Aufnahme läuft', paused: 'Aufnahme pausiert', finishing: 'Aufnahme wird gesichert' }[this.phase] || '';
            },

            // The second line of the bar: whatever endangers the recording, in the place nobody can scroll away from.
            get barNote() {
                if (!this.active) {
                    return '';
                }

                if (this.muted) {
                    return 'Kein Ton: Mikrofon stumm oder getrennt';
                }

                if (this.uploadWarning) {
                    return this.waiting > 0 ? `Speichern hakt, ${this.waiting} ${this.waiting === 1 ? 'Teil wartet' : 'Teile warten'}` : 'Speichern hakt';
                }

                return '';
            },

            // Written a moment after the change, into a region that is always on the page, so screen readers say it.
            announce(message) {
                this.announcement = '';
                setTimeout(() => (this.announcement = message), 100);
            },

            // Moves the focus to a control that is shown after the one that had it was hidden. Alpine reveals an
            // element in the next animation frame, and focusing something that is still display:none does nothing:
            // so this waits for that frame (this callback is queued after Alpine's own) and tries again if needed.
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

            // On-device recognition for German only; 'available' is the one state that offers the live transcript.
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

            openConsent() {
                this.consentChecked = false;
                this.error = '';
                this.$refs.consentDialog.showModal();
            },

            closeConsent() {
                this.$refs.consentDialog.close();
            },

            // Called from the consent dialog: the checkbox is required, and only now is the microphone requested.
            async confirmConsent() {
                if (!this.consentChecked) {
                    return;
                }

                this.closeConsent();
                await this.start();
            },

            async start() {
                if (!['idle', 'done'].includes(this.phase) || !this.canRecord) {
                    return;
                }

                this.error = '';
                this.warning = '';
                this.uploadWarning = '';
                this.earlyReason = '';
                this.doneKind = '';
                this.accessDenied = false;
                this.muted = false;
                this.transcriptUnsaved = false;
                this.copyState = '';
                this.unsentParts = 0;
                this.phase = 'requesting';
                this.announce('Dein Browser fragt jetzt nach dem Mikrofon.');

                try {
                    stream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
                } catch (error) {
                    this.phase = 'idle';
                    await this.explainMicrophoneError(error);

                    return;
                }

                const candidates = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'];
                const supportedType = candidates.find((type) => MediaRecorder.isTypeSupported(type));

                try {
                    recorder = new MediaRecorder(stream, { ...(supportedType ? { mimeType: supportedType } : {}), audioBitsPerSecond: config.bitsPerSecond });
                } catch (error) {
                    this.cancelStart('Dein Browser kann den Ton nicht aufnehmen. Bitte probiere eine aktuelle Version von Chrome, Edge, Firefox oder Safari.');

                    return;
                }

                mimeType = baseType(recorder.mimeType || supportedType);

                let status = 0;

                try {
                    const response = await request(config.startUrl, {
                        method: 'POST',
                        headers: jsonHeaders(),
                        body: JSON.stringify({ consent: true, mime_type: mimeType }),
                    });

                    status = response.status;

                    if (!response.ok) {
                        throw new Error(String(status));
                    }

                    urls = await response.json();
                } catch (error) {
                    this.cancelStart(this.describeStartError(status));

                    return;
                }

                queue = [];
                headBlob = null;
                nextIndex = 0;
                uploadFailed = false;
                abandoned = false;
                pushedTranscript = '';
                this.uploaded = 0;
                this.waiting = 0;
                this.sttFinal = '';
                this.sttInterim = '';
                this.elapsed = 0;
                pausedTotal = 0;

                recorder.ondataavailable = (event) => {
                    if (event.data && event.data.size > 0) {
                        const part = { index: nextIndex++, blob: event.data };

                        if (part.index === 0) {
                            headBlob = part.blob;
                        }

                        queue.push(part);
                        this.waiting = queue.length;

                        if (uploadFailed) {
                            this.unsentParts = queue.length;

                            return;
                        }

                        this.flush();
                    }
                };
                recorder.onerror = () => this.fail('Die Aufnahme wurde vom Browser unterbrochen.', true);

                // Unplugging the microphone or withdrawing the permission ends the recording; it never goes on silently.
                // A muted track (locked screen, another app, a call) keeps recording, but silence: that is shown at once.
                stream.getAudioTracks().forEach((track) => {
                    track.addEventListener('ended', () => this.trackEnded());
                    track.addEventListener('mute', () => (this.muted = true));
                    track.addEventListener('unmute', () => (this.muted = false));
                    this.muted = track.muted;
                });

                originalTitle = document.title;
                startedAt = performance.now();
                recorder.start(config.chunkSeconds * 1000);
                this.phase = 'recording';
                this.micState = 'granted';
                this.syncChrome();
                this.lockScreen();
                this.startMeter();
                timer = setInterval(() => this.tick(), 500);
                // Every 15 s: the live text, or at least a sign of life (also while paused) for the list on the page.
                transcriptTimer = setInterval(() => this.pushTranscript(), 15000);
                this.focusOn('pauseButton');

                if (this.sttEnabled) {
                    this.startSpeech();
                }
            },

            cancelStart(message) {
                this.stopTracks();
                recorder = null;
                this.phase = 'idle';
                this.error = message;
            },

            describeStartError(status) {
                switch (status) {
                    case 403:
                        this.canRecord = false;

                        return 'Aufnahmen sind für deine Schule gerade nicht freigeschaltet. Die Schuladministration entscheidet darüber.';
                    case 401:
                    case 419:
                        return 'Deine Anmeldung ist abgelaufen. Lade die Seite neu und melde dich gegebenenfalls an.';
                    case 429:
                        return 'Es gibt schon mehrere nicht beendete Aufnahmen, oder du hast zu oft gestartet. Lade die Seite neu und lösche Aufnahmen, die du nicht brauchst.';
                    default:
                        return 'Die Aufnahme konnte nicht angelegt werden. Bitte lade die Seite neu und versuche es noch einmal.';
                }
            },

            // The browser says "NotAllowedError" for a refusal and for a closed prompt alike: ask what the permission really is.
            async explainMicrophoneError(error) {
                switch (error && error.name) {
                    case 'NotAllowedError':
                        await this.checkPermission();

                        if (this.micState === 'prompt') {
                            this.error = 'Das Mikrofon wurde nicht freigegeben: Du hast die Anfrage des Browsers abgelehnt oder das Fenster geschlossen. Starte die Aufnahme noch einmal und wähle „Zulassen“.';

                            return;
                        }

                        this.accessDenied = true;
                        this.error = this.micState === 'denied'
                            ? 'Der Browser hat den Zugriff auf das Mikrofon nicht erlaubt. Wie du ihn erlaubst, steht unten.'
                            : 'Das Mikrofon wurde nicht freigegeben: Du hast die Anfrage des Browsers abgelehnt oder das Fenster geschlossen. Fragt der Browser nicht mehr nach, hilft die Anleitung unten.';

                        return;
                    case 'SecurityError':
                        this.error = 'Der Browser erlaubt dieser Seite hier keinen Zugriff auf das Mikrofon. Das liegt meist an einer ungesicherten Verbindung (http statt https).';

                        return;
                    case 'NotFoundError':
                    case 'OverconstrainedError':
                        this.error = 'Es wurde kein Mikrofon gefunden. Schließe ein Mikrofon an und versuche es noch einmal.';

                        return;
                    case 'NotReadableError':
                        this.error = 'Das Mikrofon wird gerade von einem anderen Programm benutzt. Schließe es dort und versuche es noch einmal.';

                        return;
                    default:
                        this.error = 'Das Mikrofon konnte nicht gestartet werden. Bitte versuche es noch einmal.';
                }
            },

            tick() {
                if (this.phase === 'recording') {
                    this.elapsed = Math.floor((performance.now() - startedAt - pausedTotal) / 1000);
                }
            },

            syncMuted() {
                const track = stream && stream.getAudioTracks()[0];

                if (track) {
                    this.muted = track.muted;
                }
            },

            // A small level meter: a locked screen or a wrong input device still "records", so the teacher can see sound arrive.
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

            pause() {
                if (this.phase !== 'recording') {
                    return;
                }

                this.tick();
                recorder.pause();
                pausedAt = performance.now();
                this.phase = 'paused';
                this.stopSpeech();
                this.syncChrome();
                this.focusOn('resumeButton');
            },

            resume() {
                if (this.phase !== 'paused') {
                    return;
                }

                recorder.resume();
                pausedTotal += performance.now() - pausedAt;
                this.phase = 'recording';
                this.syncChrome();
                this.lockScreen();
                this.focusOn('pauseButton');

                if (this.sttEnabled) {
                    this.startSpeech();
                }
            },

            async end() {
                if (!['recording', 'paused'].includes(this.phase)) {
                    return;
                }

                this.tick();
                this.phase = 'finishing';
                this.syncChrome();
                clearInterval(timer);
                this.focusOn('statusLine');

                // Stop means stop: the microphone is released first, whatever the network is doing. The recogniser
                // is told to stop right here too; only its last words are awaited later.
                const speechSettled = this.stopSpeech(true);
                const stopped = new Promise((resolve) => {
                    if (recorder.state === 'inactive') {
                        resolve();

                        return;
                    }

                    // The last chunk arrives with the stop event; it is in the queue once this resolves.
                    recorder.addEventListener('stop', resolve, { once: true });
                    recorder.stop();
                });

                await Promise.race([stopped, sleep(5000)]);
                this.stopTracks();
                this.stopMeter();
                await speechSettled;
                await this.pushFinalTranscript();

                while ((queue.length > 0 || sending) && !uploadFailed) {
                    await sleep(300);
                }

                if (uploadFailed) {
                    return;
                }

                for (let attempt = 0; attempt < 5; attempt++) {
                    try {
                        const response = await request(urls.finish_url, {
                            method: 'POST',
                            headers: jsonHeaders(),
                            body: JSON.stringify({ duration_seconds: this.elapsed }),
                        }, 15000);

                        if (response.ok) {
                            this.finished(await response.json().catch(() => ({})));

                            return;
                        }

                        if ([401, 419].includes(response.status)) {
                            await this.renewToken();
                        }
                    } catch (error) {
                        // Offline: try again below.
                    }

                    await sleep(1500 * (attempt + 1));
                }

                this.fail('Die Aufnahme ist gesichert, konnte aber nicht abgeschlossen werden. Du findest sie nach dem Neuladen der Seite in der Liste.');
            },

            finished(result) {
                clearInterval(transcriptTimer);
                queue = [];
                headBlob = null;
                this.doneKind = result.discarded ? 'discarded' : this.earlyReason ? 'early' : 'saved';
                this.phase = 'done';
                this.syncChrome();
                this.releaseScreen();
                Promise.resolve(this.$wire.reloadRecordings()).catch(() => {});
                this.announce({
                    saved: 'Die Aufnahme ist gesichert.',
                    early: 'Die Aufnahme endete vorzeitig. Gesichert ist der Teil bis dahin.',
                    discarded: 'Es kam kein Ton an, deshalb wurde nichts gespeichert.',
                }[this.doneKind]);
                this.focusOn('doneNotice');
            },

            // Posts the queued chunks strictly one after another; a failed chunk is retried and nothing is skipped.
            async flush() {
                if (sending) {
                    return;
                }

                sending = true;
                let attempt = 0;

                while (queue.length > 0 && !uploadFailed) {
                    const item = queue[0];

                    try {
                        const response = await request(`${urls.append_url}?index=${item.index}`, {
                            method: 'POST',
                            headers: { 'Content-Type': mimeType, Accept: 'application/json', 'X-CSRF-TOKEN': config.csrf },
                            body: item.blob,
                        });

                        if (response.ok) {
                            queue.shift();
                            attempt = 0;
                            this.uploaded++;
                            this.waiting = queue.length;
                            this.uploadWarning = '';

                            continue;
                        }

                        if ([401, 419].includes(response.status)) {
                            // A lapsed login or a stale token is no reason to throw the lesson away: the parts stay in
                            // this browser, the recording goes on, and the token is renewed until it works again.
                            this.setUploadWarning('Deine Anmeldung ist abgelaufen. Die Aufnahme läuft weiter und wird zwischengespeichert. Melde dich in einem neuen Tab an und lass diese Seite geöffnet, dann geht es von selbst weiter.');
                            await this.renewToken();
                        } else if (response.status >= 400 && response.status < 500 && ![408, 429].includes(response.status)) {
                            // A refused chunk (finished or deleted recording, size limit) never succeeds by waiting.
                            sending = false;

                            if (!abandoned) {
                                this.fail(`Die Aufnahme wird nicht mehr gespeichert (Fehler ${response.status}). Was schon gesichert war, steht unten in der Liste.`, true);
                            }

                            return;
                        }
                    } catch (error) {
                        // No connection or no answer in time: retry below.
                    }

                    attempt++;
                    this.waiting = queue.length;

                    if (!this.uploadWarning) {
                        this.setUploadWarning('Ein Teil der Aufnahme konnte noch nicht gespeichert werden. Die Aufnahme läuft weiter und es wird erneut versucht. Lass diese Seite geöffnet.');
                    }

                    await sleep(Math.min(15000, 1500 * attempt));
                }

                sending = false;
            },

            setUploadWarning(message) {
                if (this.uploadWarning !== message) {
                    this.uploadWarning = message;
                    this.announce(message);
                }
            },

            // The page's token is the one from when it was opened. After a new login (in this or another tab) the
            // page itself holds the fresh one; a login page or a redirect means "not signed in yet".
            async renewToken() {
                if (performance.now() - lastRenewAt < 30000) {
                    return;
                }

                lastRenewAt = performance.now();

                try {
                    const response = await request(window.location.href, { headers: { Accept: 'text/html' } });

                    if (!response.ok || response.redirected) {
                        return;
                    }

                    const match = (await response.text()).match(/<meta name="csrf-token" content="([^"]+)"/);

                    if (match) {
                        config.csrf = match[1];
                    }
                } catch (error) {
                    // The next attempt tries again.
                }
            },

            // Ends everything at once: stops the recorder, releases the microphone, tells the teacher why.
            fail(message, tryFinish = false) {
                uploadFailed = true;
                clearInterval(timer);
                clearInterval(transcriptTimer);
                this.tick();
                this.stopSpeech();

                try {
                    if (recorder && recorder.state !== 'inactive') {
                        recorder.stop();
                    }
                } catch (error) {
                    // Already stopped.
                }

                this.stopTracks();
                this.stopMeter();
                this.phase = 'idle';
                this.error = this.earlyReason ? `${this.earlyReason} ${message}` : message;
                this.unsentParts = queue.length;
                this.syncChrome();
                this.releaseScreen();
                this.focusOn('errorNotice');

                if (tryFinish) {
                    this.finishQuietly(true);
                }
            },

            // What was recorded but never reached the server, as a file on this device: the first part carries the
            // audio header, so it is put in front of parts that come later.
            downloadUnsent() {
                if (queue.length === 0) {
                    return;
                }

                const parts = queue.map((item) => item.blob);

                if (headBlob && queue[0].index > 0) {
                    parts.unshift(headBlob);
                }

                const url = URL.createObjectURL(new Blob(parts, { type: mimeType }));
                const link = document.createElement('a');

                link.href = url;
                link.download = `aufnahme-nicht-gespeichert.${extensions[mimeType] || 'webm'}`;
                document.body.appendChild(link);
                link.click();
                link.remove();
                setTimeout(() => URL.revokeObjectURL(url), 60000);
            },

            // Best effort: closes the recording on the server with whatever it has, so it shows up in the list.
            finishQuietly(reload) {
                if (!urls) {
                    return;
                }

                request(urls.finish_url, {
                    method: 'POST',
                    keepalive: true,
                    headers: jsonHeaders(),
                    body: JSON.stringify({ duration_seconds: this.elapsed }),
                }, 15000)
                    .then((response) => (response.ok && reload ? this.$wire.reloadRecordings() : null))
                    .catch(() => {});
            },

            trackEnded() {
                if (this.phase === 'recording' || this.phase === 'paused') {
                    this.earlyReason = 'Das Mikrofon wurde getrennt oder gesperrt, deshalb endete die Aufnahme vorzeitig.';
                    this.end();
                }
            },

            // Leaving the page: stop and release the microphone right away; the finish call is a best effort. The
            // page is reset too, because a browser may show it again from its back/forward cache.
            abandon() {
                if (!this.active) {
                    this.stopTracks();

                    return;
                }

                abandoned = true;
                clearInterval(timer);
                clearInterval(transcriptTimer);
                this.tick();
                this.stopSpeech();

                try {
                    if (recorder && recorder.state !== 'inactive') {
                        recorder.stop();
                    }
                } catch (error) {
                    // Already stopped.
                }

                this.stopTracks();
                this.stopMeter();
                this.finishQuietly(false);
                this.phase = 'idle';
                this.warning = 'Die Aufnahme wurde beendet, weil die Seite verlassen wurde.';
                this.syncChrome();
                this.releaseScreen();
            },

            stopTracks() {
                if (stream) {
                    stream.getTracks().forEach((track) => track.stop());
                    stream = null;
                }
            },

            // The bar, the page title and the room for the bar all follow the phase.
            syncChrome() {
                document.documentElement.classList.toggle('lp-rec-active', this.active);
                document.title = ({ recording: '● REC – ', finishing: '● REC – ', paused: '⏸ Pause – ' }[this.phase] || '') + originalTitle;
            },

            async lockScreen() {
                try {
                    if ('wakeLock' in navigator && !wakeLock) {
                        wakeLock = await navigator.wakeLock.request('screen');
                        wakeLock.addEventListener('release', () => (wakeLock = null));
                    }
                } catch (error) {
                    // A sleeping screen is only an inconvenience; the recorder carries on.
                }
            },

            releaseScreen() {
                if (wakeLock) {
                    wakeLock.release().catch(() => {});
                    wakeLock = null;
                }
            },

            startSpeech() {
                if (!this.sttAvailable || !this.sttEnabled || this.phase !== 'recording' || recognition || !Recognition) {
                    return;
                }

                // Never use a recogniser that cannot be kept on the device: its audio might go to a cloud service.
                if (!recognisesOnDevice) {
                    this.sttEnabled = false;

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
                            this.sttFinal += (this.sttFinal ? ' ' : '') + text;
                        } else {
                            interim += ' ' + text;
                        }
                    }

                    this.sttInterim = interim.trim();
                };
                instance.onerror = (event) => {
                    if (['not-allowed', 'service-not-allowed', 'language-not-supported'].includes(event.error)) {
                        this.sttEnabled = false;
                        this.warning = 'Das Live-Transkript wurde ausgeschaltet, weil die Spracherkennung auf diesem Gerät nicht läuft. Die Aufnahme läuft weiter.';
                        this.announce(this.warning);
                    }
                };
                instance.onend = () => {
                    if (recognition === instance) {
                        recognition = null;
                        this.sttInterim = '';

                        // The recogniser ends by itself after a pause in speech; carry on while it is wanted.
                        if (this.sttEnabled && this.phase === 'recording') {
                            setTimeout(() => this.startSpeech(), 400);
                        }
                    }
                };

                try {
                    instance.start();
                    recognition = instance;
                } catch (error) {
                    this.sttEnabled = false;
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

            // Every 15 s while the recording runs: saves the live transcript with the recording (before "finish":
            // afterwards it is edited on the page). Without new text the call is only a sign of life.
            async pushTranscript() {
                if (!urls) {
                    return true;
                }

                const text = this.sttFinal === pushedTranscript ? '' : this.sttFinal;

                try {
                    const response = await request(urls.transcript_url, {
                        method: 'PUT',
                        headers: jsonHeaders(),
                        body: JSON.stringify({ transcript: text }),
                    }, 15000);

                    if (response.ok) {
                        pushedTranscript = text === '' ? pushedTranscript : text;

                        return true;
                    }
                } catch (error) {
                    // The next push (or the final one) sends the whole text again.
                }

                return false;
            },

            // The last push is tried again; if it still fails, the text is not lost: the panel keeps it and offers to copy it.
            async pushFinalTranscript() {
                if (this.sttFinal === '' || this.sttFinal === pushedTranscript) {
                    return;
                }

                for (let attempt = 0; attempt < 3; attempt++) {
                    if (await this.pushTranscript()) {
                        return;
                    }

                    await sleep(1000 * (attempt + 1));
                }

                this.transcriptUnsaved = true;
                this.announce('Der Live-Text konnte nicht gespeichert werden. Du kannst ihn kopieren.');
            },

            async copyLiveText() {
                try {
                    await navigator.clipboard.writeText(this.sttFinal);
                    this.copyState = 'copied';
                } catch (error) {
                    this.copyState = 'failed';
                }
            },
        };
    };
</script>
@endverbatim
