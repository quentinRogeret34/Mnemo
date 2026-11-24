import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'button'];

    toggle() {
        const showing = this.inputTarget.type === 'text';
        this.inputTarget.type = showing ? 'password' : 'text';
        this.buttonTarget.setAttribute('aria-pressed', showing ? 'false' : 'true');
        this.buttonTarget.classList.toggle('is-visible', !showing);
    }
}
