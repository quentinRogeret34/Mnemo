import { Controller } from '@hotwired/stimulus';
import { trans } from '../translator.js';

export default class extends Controller {
    static targets = ['source', 'label'];

    connect() {
        this.originalLabel = this.labelTarget.textContent;
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    async copy() {
        try {
            await navigator.clipboard.writeText(this.sourceTarget.textContent.trim());
            this.labelTarget.textContent = trans('js.copied');
            clearTimeout(this.timer);
            this.timer = setTimeout(() => {
                this.labelTarget.textContent = this.originalLabel;
            }, 2000);
        } catch {
            // Clipboard API may be unavailable on non-HTTPS origins or denied by browser permissions.
        }
    }
}
