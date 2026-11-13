import { Controller } from '@hotwired/stimulus';

const DELAY = 6000;

export default class extends Controller {
    static targets = ['item'];

    #timers = new Map();

    async connect() {
        if (0 === this.itemTargets.length) {
            return;
        }

        if (!Object.hasOwn(HTMLElement.prototype, 'popover')) {
            await import('@oddbird/popover-polyfill');
        }

        this.element.setAttribute('popover', 'manual');
        this.element.showPopover();

        for (const item of this.itemTargets) {
            item.querySelector('button').hidden = false;
            item.ariaNotify?.(item.textContent.trim(), { priority: 'alert' === item.getAttribute('role') ? 'high' : 'normal' });
        }

        this.resume();
    }

    disconnect() {
        this.pause();
    }

    pause() {
        for (const timer of this.#timers.values()) {
            clearTimeout(timer);
        }
        this.#timers.clear();
    }

    resume() {
        if (this.element.matches(':hover, :focus-within')) {
            return;
        }

        for (const item of this.itemTargets) {
            if ('status' === item.getAttribute('role') && !this.#timers.has(item)) {
                this.#timers.set(item, setTimeout(() => this.#remove(item), DELAY));
            }
        }
    }

    close(event) {
        const item = event.currentTarget.closest('[data-toast-target="item"]');
        const next = item.nextElementSibling ?? item.previousElementSibling;
        this.#remove(item);

        if (next) {
            next.querySelector('button').focus();

            return;
        }

        const main = document.querySelector('main');
        main?.setAttribute('tabindex', '-1');
        main?.focus();
    }

    #remove(item) {
        clearTimeout(this.#timers.get(item));
        this.#timers.delete(item);
        item.remove();

        if (0 === this.itemTargets.length) {
            this.element.hidePopover();
        }
    }
}
