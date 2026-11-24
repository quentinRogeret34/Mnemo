import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['table', 'button'];
    static values = {
        storageKey: { type: String, default: 'mnemo.table.density' },
        default: { type: String, default: 'comfortable' },
    };

    connect() {
        let saved = this.defaultValue;
        try {
            const stored = window.localStorage.getItem(this.storageKeyValue);
            if (stored === 'compact' || stored === 'comfortable') {
                saved = stored;
            }
        } catch {
            // Storage may be unavailable in restricted contexts.
        }
        this.apply(saved);
    }

    choose(event) {
        const mode = event.currentTarget.dataset.densityValue;
        if (mode !== 'compact' && mode !== 'comfortable') {
            return;
        }
        this.apply(mode);
        try {
            window.localStorage.setItem(this.storageKeyValue, mode);
        } catch {
            // Ignore storage quota / privacy errors.
        }
    }

    apply(mode) {
        if (this.hasTableTarget) {
            this.tableTarget.dataset.density = mode;
        }
        this.buttonTargets.forEach((button) => {
            const active = button.dataset.densityValue === mode;
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
            button.classList.toggle('is-active', active);
        });
    }
}
