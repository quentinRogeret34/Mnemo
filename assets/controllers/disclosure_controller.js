import { Controller } from '@hotwired/stimulus';

// Collapsible panel used on mobile and tablet for file trees and the document table of contents.
// On desktop, CSS keeps the panel visible and hides the toggle button.
export default class extends Controller {
    static targets = ['button', 'panel'];

    toggle() {
        const open = this.element.classList.toggle('is-open');
        this.buttonTarget.setAttribute('aria-expanded', String(open));
    }

    close() {
        if (!this.element.classList.contains('is-open')) {
            return;
        }
        this.element.classList.remove('is-open');
        this.buttonTarget.setAttribute('aria-expanded', 'false');
    }
}
