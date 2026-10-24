{{--
    The Beamer display of Tuto-Mikro (MikroBoard): "Mikro an" in Lichtblau with a ring, "Mikro aus" in red, and the lesson's
    topic. Nothing else is on the page: no names, no text of the session. The state has two sources, so it works in
    the same browser and on another device:

     1. a Tuto-Mikro tab of this browser that holds the session tells it over a BroadcastChannel (instant; a message every 2 s
        and on every change). A tab without a session says nothing that counts, so an idle tab never turns the board red,
     2. the status endpoint is asked every 3 s (does the teacher's session run). It is the only source on another device,
        and it counts again as soon as no tab with a session has been heard for 8 s.

    Until the first answer the page shows neither "an" nor "aus", and when the status cannot be read the red "Mikro aus" goes
    back to the neutral state: a red "Mikro aus" while it is on must not be shown by mistake.
--}}
<div
    class="mikro-board"
    x-data="mikroBoard(@js($config))"
    x-bind:data-state="state"
    data-state="wait"
>
    <style>
        .mikro-board {
            --board-bg: var(--gray-100, #f1f5f9);
            --board-ring: var(--gray-400, #94a3b8);
            --board-ink: var(--gray-700, #334155);

            position: fixed;
            inset: 0;
            z-index: 100000;
            display: grid;
            grid-template-rows: 1fr auto;
            justify-items: center;
            align-items: center;
            gap: 2vmin;
            padding: 4vmin;
            overflow: hidden;
            background: var(--board-bg);
            color: var(--board-ink);
            color-scheme: light;
            text-align: center;
        }

        .mikro-board[data-state="on"] {
            --board-bg: var(--primary-100, #dbeafe);
            --board-ring: var(--primary-600, #2563eb);
            --board-ink: var(--primary-950, #172554);
        }

        .mikro-board[data-state="off"] {
            --board-bg: var(--danger-100, #ffe4e6);
            --board-ring: var(--danger-600, #e11d48);
            --board-ink: var(--danger-900, #881337);
        }

        /* The ring: solid with a round dot while on, dashed with a square while off, so the colour is never the only sign. */
        .mikro-ring {
            display: grid;
            place-content: center;
            gap: 1vmin;
            inline-size: min(68vmin, 44rem);
            aspect-ratio: 1;
            border: 3.5vmin solid var(--board-ring);
            border-radius: 50%;
            background: color-mix(in oklab, var(--board-bg) 40%, white);
        }

        .mikro-board[data-state="off"] .mikro-ring {
            border-style: dashed;
        }

        .mikro-symbol {
            font-size: 12vmin;
            line-height: 1;
            color: var(--board-ring);
        }

        .mikro-word {
            margin: 0;
            font-size: 9vmin;
            font-weight: 700;
            line-height: 1.1;
            text-wrap: balance;
        }

        .mikro-topic {
            max-inline-size: 90vw;
            margin: 0;
            font-size: clamp(1.5rem, 5vmin, 3.5rem);
            font-weight: 600;
            line-height: 1.2;
            overflow-wrap: anywhere;
            text-wrap: balance;
        }

        .mikro-hint {
            position: absolute;
            inset-block-end: 1.5vmin;
            inset-inline: 0;
            margin: 0;
            font-size: 1.125rem;
        }

        .mikro-fullscreen {
            position: absolute;
            inset-block-start: 1rem;
            inset-inline-end: 1rem;
            padding: 0.5rem 1rem;
            border: 2px solid var(--board-ring);
            border-radius: 0.75rem;
            background: transparent;
            color: var(--board-ink);
            font: inherit;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
        }

        .mikro-fullscreen:focus-visible {
            outline: 3px solid var(--board-ink);
            outline-offset: 2px;
        }

        /* The pulse is decoration: without it the word, the ring and the colour still say "Mikro an". */
        @media (prefers-reduced-motion: no-preference) {
            .mikro-board[data-state="on"] .mikro-ring {
                animation: mikro-pulse 2.4s ease-in-out infinite;
            }
        }

        @keyframes mikro-pulse {
            50% {
                box-shadow: 0 0 0 3vmin color-mix(in oklab, var(--board-ring) 25%, transparent);
            }
        }
    </style>

    <div class="mikro-ring" role="status" aria-live="polite" aria-atomic="true">
        <span class="mikro-symbol" aria-hidden="true" x-text="state === 'on' ? '●' : state === 'off' ? '■' : '…'">…</span>
        <p class="mikro-word" x-text="state === 'on' ? 'Mikro an' : state === 'off' ? 'Mikro aus' : 'Einen Moment …'">Einen Moment …</p>
    </div>

    <p class="mikro-topic">{{ $topic }}</p>

    <p class="mikro-hint" x-cloak x-show="stale">Der Status wird gerade nicht aktualisiert.</p>

    <button type="button" class="mikro-fullscreen" x-cloak x-show="canFullscreen && ! fullscreen" x-on:click="enterFullscreen()">Vollbild</button>
</div>

@verbatim
<script>
    window.mikroBoard = (config) => {
        const POLL_MS = 3000;
        // A Tuto-Mikro tab repeats its state every 2 s; silent for longer means the tab is gone or throttled.
        const CHANNEL_FRESH_MS = 8000;
        // After a tab has ended its session the server may not have heard it yet: an "an" of the server does not count for this long.
        const SERVER_LAG_MS = 4000;

        // What each Tuto-Mikro tab of this browser said last: tab id -> { on, session, at }.
        const tabs = new Map();
        let endedAt = 0;
        let server = 'wait'; // wait | on | off: the answer of the status endpoint

        // Only tabs that hold a session count; an idle tab of the lesson says "aus" all the time and means nothing.
        const sessionTabs = () => [...tabs.values()].filter((tab) => tab.session && Date.now() - tab.at < CHANNEL_FRESH_MS);

        return {
            state: 'wait', // wait | on | off
            stale: false,
            fullscreen: false,
            canFullscreen: !!(document.fullscreenEnabled && document.documentElement.requestFullscreen),

            init() {
                if ('BroadcastChannel' in window) {
                    const channel = new BroadcastChannel(config.channel);

                    channel.onmessage = (event) => {
                        const data = event.data;

                        if (!data || data.type !== 'state' || typeof data.tab !== 'string') {
                            return;
                        }

                        const before = tabs.get(data.tab);

                        tabs.set(data.tab, { on: data.on === true, session: data.session === true, at: Date.now() });

                        if (before && before.session && data.session !== true) {
                            endedAt = Date.now();
                            server = 'off';
                            setTimeout(() => this.poll(), SERVER_LAG_MS);
                        }

                        this.refresh();
                    };
                    // Asks a Tuto-Mikro tab that is already open to tell its state right away.
                    channel.postMessage({ type: 'hello' });
                }

                document.addEventListener('fullscreenchange', () => (this.fullscreen = !!document.fullscreenElement));

                this.poll();
                setInterval(() => this.poll(), POLL_MS);
            },

            // A tab that holds a session knows whether the microphone really listens: "an" from any of them wins, and
            // when all of them say "aus" (manual mode) that wins over the server. Without such a tab the server decides.
            refresh() {
                const live = sessionTabs();

                this.state = live.length > 0 ? (live.some((tab) => tab.on) ? 'on' : 'off') : server;
            },

            async poll() {
                try {
                    const response = await fetch(config.statusUrl, {
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json' },
                        signal: AbortSignal.timeout(5000),
                    });

                    if (!response.ok) {
                        throw new Error(String(response.status));
                    }

                    const body = await response.json();

                    server = body.on === true && Date.now() - endedAt >= SERVER_LAG_MS ? 'on' : 'off';
                    this.refresh();
                    this.stale = false;
                } catch (error) {
                    // "an" may stay, it errs on the safe side; a red "aus" must not stay when the board no longer knows.
                    if (server === 'off') {
                        server = 'wait';
                    }

                    this.refresh();
                    this.stale = sessionTabs().length === 0;
                }
            },

            enterFullscreen() {
                document.documentElement.requestFullscreen().catch(() => {});
            },
        };
    };
</script>
@endverbatim
