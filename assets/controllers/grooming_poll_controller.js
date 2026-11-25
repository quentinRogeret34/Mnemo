import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        url: String,
        cleanUrl: String,
        expected: { type: Number, default: 7 },
    };

    static targets = ['counter', 'bar', 'latest'];

    #timer = null;

    connect() {
        this.#schedule();
    }

    disconnect() {
        if (this.#timer !== null) {
            window.clearTimeout(this.#timer);
            this.#timer = null;
        }
    }

    #schedule() {
        this.#timer = window.setTimeout(() => this.#poll(), 2500);
    }

    async #poll() {
        try {
            const response = await fetch(this.urlValue, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                this.#schedule();
                return;
            }

            const data = await response.json();
            const expected = Math.max(1, Number(data.expected_steps || this.expectedValue || 1));
            const stepsCount = Math.max(0, Number(data.steps_count || 0));
            const pct = Math.min(95, Math.max(8, Math.round((stepsCount / expected) * 100)));

            for (const el of this.counterTargets) {
                el.textContent = `${stepsCount} / ${expected}`;
            }
            for (const el of this.barTargets) {
                el.style.width = `${pct}%`;
            }
            if (data.latest_role) {
                const label = data.latest_round && data.latest_round <= (data.rounds || 0)
                    ? `Tour ${data.latest_round}/${data.rounds} · ${data.latest_role}`
                    : data.latest_role;
                for (const el of this.latestTargets) {
                    el.textContent = label;
                    el.hidden = false;
                }
            }

            if (data.status && data.status !== 'running') {
                window.location.replace(this.cleanUrlValue || window.location.pathname);
                return;
            }
        } catch {
            // Keep polling quietly on transient network blips.
        }

        this.#schedule();
    }
}
