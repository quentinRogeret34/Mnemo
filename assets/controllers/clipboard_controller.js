import { Controller } from '@hotwired/stimulus';
import { trans } from '../translator.js';

export default class extends Controller {
    static targets = ['source', 'label'];

    async copy() {
        await navigator.clipboard.writeText(this.sourceTarget.textContent.trim());
        const label = this.labelTarget.textContent;
        this.labelTarget.textContent = trans('js.copied');
        setTimeout(() => { this.labelTarget.textContent = label; }, 2000);
    }
}
