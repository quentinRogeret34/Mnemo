import { Controller } from '@hotwired/stimulus';

const fold = (text) => text.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase();

export default class extends Controller {
    static targets = ['input', 'option', 'group', 'remote', 'empty', 'status'];
    static values = { url: String, project: String };

    connect() {
        this.optionTargets.forEach((option, index) => {
            option.id = `palette-option-${index}`;
            option.dataset.search = fold(option.textContent);
        });
    }

    toggle() {
        if (this.element.open) {
            this.element.close();
        } else {
            this.element.showModal();
            this.filter();
        }
    }

    reset() {
        this.inputTarget.value = '';
        this.controller?.abort();
        clearTimeout(this.timer);
        this.remoteTargets.forEach((group) => this.fill(group, []));
    }

    filter() {
        const query = fold(this.inputTarget.value.trim());
        this.optionTargets.forEach((option) => { option.hidden = query !== '' && !option.dataset.search.includes(query); });
        this.groupTargets.forEach((group) => { group.hidden = !group.querySelector('[role="option"]:not([hidden])'); });
        this.select(this.visible()[0]);
        this.announce();

        clearTimeout(this.timer);
        this.controller?.abort();
        if (query.length < 2) {
            this.remoteTargets.forEach((group) => this.fill(group, []));
            return;
        }
        this.timer = setTimeout(() => this.fetch(this.inputTarget.value.trim()), 150);
    }

    async fetch(query) {
        this.controller = new AbortController();
        const url = new URL(this.urlValue, window.location.origin);
        url.searchParams.set('q', query);
        if (this.projectValue) {
            url.searchParams.set('project', this.projectValue);
        }
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: this.controller.signal });
            if (!response.ok) {
                return;
            }
            const results = await response.json();
            this.remoteTargets.forEach((group) => this.fill(group, results[group.dataset.kind] ?? []));
            if (!this.current() || this.current().hidden) {
                this.select(this.visible()[0]);
            }
            this.announce();
        } catch (error) {
            if (error.name !== 'AbortError') {
                throw error;
            }
        }
    }

    fill(group, items) {
        group.querySelectorAll('[role="option"]').forEach((option) => option.remove());
        items.forEach((item, index) => {
            const option = document.createElement('a');
            option.className = 'palette__option';
            option.setAttribute('role', 'option');
            option.id = `palette-${group.dataset.kind}-${index}`;
            option.href = item.url;
            option.textContent = item.label;
            group.append(option);
        });
        group.hidden = items.length === 0;
    }

    navigate(event) {
        const options = this.visible();
        const index = options.indexOf(this.current());
        const moves = { ArrowDown: index + 1, ArrowUp: index - 1, Home: 0, End: options.length - 1 };
        if (event.key in moves && options.length > 0) {
            event.preventDefault();
            this.select(options[(moves[event.key] + options.length) % options.length]);
        } else if (event.key === 'Enter' && this.current()) {
            event.preventDefault();
            this.open(this.current());
        }
    }

    choose(event) {
        const option = event.target.closest('[role="option"]');
        if (option) {
            event.preventDefault();
            this.open(option);
        }
    }

    hover(event) {
        const option = event.target.closest('[role="option"]');
        if (option && option !== this.current()) {
            this.select(option);
        }
    }

    open(option) {
        const dialog = option.dataset.command && document.getElementById(option.dataset.command);
        if (dialog) {
            this.element.close();
            dialog.showModal();
            return;
        }
        window.location.assign(option.href);
    }

    select(option) {
        this.element.querySelectorAll('[aria-selected="true"]').forEach((selected) => selected.setAttribute('aria-selected', 'false'));
        if (!option) {
            this.inputTarget.removeAttribute('aria-activedescendant');
            return;
        }
        option.setAttribute('aria-selected', 'true');
        this.inputTarget.setAttribute('aria-activedescendant', option.id);
        option.scrollIntoView({ block: 'nearest' });
    }

    current() {
        return this.element.querySelector('[role="option"][aria-selected="true"]');
    }

    visible() {
        return [...this.element.querySelectorAll('[role="option"]')].filter((option) => !option.hidden && !option.closest('[hidden]'));
    }

    announce() {
        const count = this.visible().length;
        this.emptyTarget.hidden = count > 0;
        this.statusTarget.textContent = count === 0 ? 'Aucun résultat' : `${count} résultat${count > 1 ? 's' : ''}`;
    }
}
