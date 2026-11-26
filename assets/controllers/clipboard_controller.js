import { Controller } from '@hotwired/stimulus';
import { trans } from '../translator.js';

export default class extends Controller {
    static targets = ['source', 'label'];

    connect() {
        this.timers = new WeakMap();
    }

    disconnect() {
        for (const label of this.labelTargets) {
            clearTimeout(this.timers.get(label));
        }
    }

    async copy(event) {
        try {
            await navigator.clipboard.writeText(this.sourceTarget.textContent.trim());
            const label = event?.currentTarget?.querySelector('[data-clipboard-target="label"]') ?? this.labelTarget;
            if (!label.dataset.originalLabel) {
                label.dataset.originalLabel = label.textContent;
            }
            label.textContent = trans('js.copied');
            clearTimeout(this.timers.get(label));
            this.timers.set(label, setTimeout(() => {
                label.textContent = label.dataset.originalLabel;
            }, 2000));
        } catch {
            // Clipboard API may be unavailable on non-HTTPS origins or denied by browser permissions.
        }
    }
}
