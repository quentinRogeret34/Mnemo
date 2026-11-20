import { Controller } from '@hotwired/stimulus';

// Filters the document tree as the user types: a folder stays, opened, when its name or anything under it matches.
export default class extends Controller {
    static targets = ['query', 'list', 'empty'];

    connect() {
        this.initiallyOpen = new Set([...this.listTarget.querySelectorAll('details[open]')]);
    }

    filter() {
        const query = this.queryTarget.value.trim().toLocaleLowerCase();
        const visit = (item, inherited = false) => {
            const label = item.querySelector(':scope > .filetree__row .filetree__label, :scope > details > summary .filetree__label');
            const own = inherited || label.textContent.toLocaleLowerCase().includes(query);
            const details = item.querySelector(':scope > details');
            const children = details ? [...details.querySelectorAll(':scope > .filetree__children > .filetree__item')].map((child) => visit(child, own)).some(Boolean) : false;
            const shown = query === '' || own || children;
            item.hidden = !shown;
            if (details) {
                details.open = query === '' ? this.initiallyOpen.has(details) : children;
            }

            return shown;
        };
        const any = [...this.listTarget.querySelectorAll(':scope > .filetree__item')].map((item) => visit(item)).some(Boolean);
        this.emptyTarget.hidden = any;
    }
}
