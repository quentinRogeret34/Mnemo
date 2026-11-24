import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        key: String,
        defaultOpen: { type: Boolean, default: true },
    };

    connect() {
        const hasCurrent = this.element.querySelector('[aria-current="page"]') !== null;
        if (hasCurrent) {
            this.element.open = true;
            return;
        }
        try {
            const stored = window.localStorage.getItem('mnemo.nav.' + this.keyValue);
            if (stored === 'open') {
                this.element.open = true;
            } else if (stored === 'closed') {
                this.element.open = false;
            } else {
                this.element.open = this.defaultOpenValue;
            }
        } catch {
            this.element.open = this.defaultOpenValue;
        }
    }

    remember() {
        try {
            window.localStorage.setItem('mnemo.nav.' + this.keyValue, this.element.open ? 'open' : 'closed');
        } catch {
            // Ignore storage errors in restricted contexts.
        }
    }
}
