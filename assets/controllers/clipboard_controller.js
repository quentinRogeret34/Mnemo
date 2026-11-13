import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['source', 'label'];

    async copy() {
        await navigator.clipboard.writeText(this.sourceTarget.textContent.trim());
        const label = this.labelTarget.textContent;
        this.labelTarget.textContent = 'Copiée';
        setTimeout(() => { this.labelTarget.textContent = label; }, 2000);
    }
}
