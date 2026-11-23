import { Controller } from '@hotwired/stimulus';

// Desktop: folds the sidebar down to an icon rail (persisted per browser and applied before paint by _appearance_head.html.twig).
// Mobile & tablet (< 56rem): slides the sidebar in as an off-canvas drawer.
export default class extends Controller {
    static targets = ['toggle', 'panel', 'opener', 'backdrop'];
    static values = { collapseLabel: String, expandLabel: String };

    #media = window.matchMedia('(min-width: 56rem)');
    #onMediaChange = (event) => {
        if (event.matches) {
            this.close(false);
        }
    };

    connect() {
        this.sync();
        this.#media.addEventListener('change', this.#onMediaChange);
    }

    disconnect() {
        this.#media.removeEventListener('change', this.#onMediaChange);
        this.close(false);
    }

    toggle() {
        const collapsed = document.documentElement.getAttribute('data-sidebar') !== 'collapsed';
        if (collapsed) {
            document.documentElement.setAttribute('data-sidebar', 'collapsed');
        } else {
            document.documentElement.removeAttribute('data-sidebar');
        }
        try {
            if (collapsed) {
                localStorage.setItem('sidebar', 'collapsed');
            } else {
                localStorage.removeItem('sidebar');
            }
        } catch {}
        this.sync();
    }

    open() {
        if (!this.hasPanelTarget) {
            return;
        }
        this.panelTarget.classList.add('is-open');
        this.panelTarget.setAttribute('role', 'dialog');
        this.panelTarget.setAttribute('aria-modal', 'true');
        this.element.querySelector('.main')?.setAttribute('inert', '');
        if (this.hasBackdropTarget) {
            this.backdropTarget.hidden = false;
        }
        if (this.hasOpenerTarget) {
            this.openerTarget.setAttribute('aria-expanded', 'true');
        }
        document.documentElement.setAttribute('data-drawer-open', '');
        this.panelTarget.querySelector('a, button')?.focus();
    }

    close(restoreFocus = true) {
        if (!this.hasPanelTarget || !this.panelTarget.classList.contains('is-open')) {
            return;
        }
        this.panelTarget.classList.remove('is-open');
        this.panelTarget.removeAttribute('role');
        this.panelTarget.removeAttribute('aria-modal');
        this.element.querySelector('.main')?.removeAttribute('inert');
        if (this.hasBackdropTarget) {
            this.backdropTarget.hidden = true;
        }
        if (this.hasOpenerTarget) {
            this.openerTarget.setAttribute('aria-expanded', 'false');
            if (restoreFocus && !this.#media.matches) {
                this.openerTarget.focus();
            }
        }
        document.documentElement.removeAttribute('data-drawer-open');
    }

    sync() {
        const collapsed = document.documentElement.getAttribute('data-sidebar') === 'collapsed';
        const label = collapsed ? this.expandLabelValue : this.collapseLabelValue;
        if (this.hasToggleTarget) {
            this.toggleTarget.setAttribute('aria-expanded', String(!collapsed));
            this.toggleTarget.setAttribute('aria-label', label);
            this.toggleTarget.title = label;
        }
        const root = this.hasPanelTarget ? this.panelTarget : this.element;
        root.querySelectorAll('.nav-item, .menu__trigger').forEach((item) => {
            const text = item.querySelector('.nav-item__label')?.textContent.trim();
            if (collapsed && text) {
                item.title = text;
            } else {
                item.removeAttribute('title');
            }
        });
    }
}
