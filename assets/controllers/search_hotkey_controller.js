import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { url: String };

    handle(event) {
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            this.palette();
            return;
        }
        if (event.key !== '/' || event.target.closest('input, textarea, select, [contenteditable]')) {
            return;
        }

        const input = document.querySelector('[data-search-input]');
        if (input) {
            event.preventDefault();
            input.focus();
            input.select();
        } else if (this.urlValue) {
            event.preventDefault();
            window.location.assign(this.urlValue);
        }
    }

    palette() {
        const element = document.getElementById('palette');
        this.application.getControllerForElementAndIdentifier(element, 'palette')?.toggle();
    }
}
