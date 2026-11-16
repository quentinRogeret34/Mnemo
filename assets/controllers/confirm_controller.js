import { Controller } from '@hotwired/stimulus';
import { trans } from '../translator.js';

export default class extends Controller {
    static targets = ['title', 'accept'];

    #submitter = null;
    #confirmed = false;

    ask(event) {
        const submitter = event.submitter;
        if (this.#confirmed || !submitter?.dataset.confirm) {
            return;
        }

        event.preventDefault();
        this.#submitter = submitter;
        this.titleTarget.textContent = submitter.dataset.confirm;
        this.acceptTarget.textContent = submitter.dataset.confirmLabel ?? trans('js.confirm');
        this.element.showModal();
    }

    accept() {
        this.element.close();
        this.#confirmed = true;
        this.#submitter.form.requestSubmit(this.#submitter);
        this.#confirmed = false;
    }
}
