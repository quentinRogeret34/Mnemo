import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['source', 'button'];

    async copy() {
        await navigator.clipboard.writeText(this.sourceTarget.textContent.trim());
        const label = this.buttonTarget.textContent;
        this.buttonTarget.textContent = 'Copiée';
        setTimeout(() => { this.buttonTarget.textContent = label; }, 2000);
    }
}
