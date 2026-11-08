import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { url: String };

    handle(event) {
        const commandK = (event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k';
        const slash = event.key === '/' && !event.target.closest('input, textarea, select, [contenteditable]');
        if (!commandK && !slash) {
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
}
