import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    mark(event) {
        const form = event.target;
        if (event.defaultPrevented || 'post' !== form.getAttribute('method')?.toLowerCase()) {
            return;
        }

        if ('true' === form.getAttribute('aria-busy')) {
            event.preventDefault();

            return;
        }

        form.setAttribute('aria-busy', 'true');
        const button = event.submitter ?? form.querySelector('button:not([type="button"])');
        button?.setAttribute('aria-disabled', 'true');
        button?.classList.add('is-loading');
    }

    reset() {
        for (const form of this.element.querySelectorAll('form[aria-busy]')) {
            form.removeAttribute('aria-busy');
        }

        for (const button of this.element.querySelectorAll('button.is-loading')) {
            button.removeAttribute('aria-disabled');
            button.classList.remove('is-loading');
        }
    }
}
