import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    #onHashChange = () => this.#openFromHash();
    #onClose = () => this.#clearHash();
    #onBackdropClick = (event) => {
        if (event.target === this.element && this.element.getAttribute('closedby') === 'any') {
            this.element.close();
        }
    };

    connect() {
        if (this.element.open && !this.element.matches(':modal')) {
            this.element.close();
            this.element.showModal();
        } else {
            this.#openFromHash();
        }
        window.addEventListener('hashchange', this.#onHashChange);
        this.element.addEventListener('close', this.#onClose);
        this.element.addEventListener('click', this.#onBackdropClick);
    }

    disconnect() {
        window.removeEventListener('hashchange', this.#onHashChange);
        this.element.removeEventListener('close', this.#onClose);
        this.element.removeEventListener('click', this.#onBackdropClick);
    }

    #openFromHash() {
        if (this.element.id && window.location.hash === `#${this.element.id}` && !this.element.open) {
            this.element.showModal();
        }
    }

    #clearHash() {
        if (this.element.id && window.location.hash === `#${this.element.id}`) {
            history.replaceState(null, '', window.location.pathname + window.location.search);
        }
    }
}
