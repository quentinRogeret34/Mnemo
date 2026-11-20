import { Controller } from '@hotwired/stimulus';

// Folds the sidebar down to its icons; the choice is kept per browser and applied before paint by _appearance_head.html.twig.
export default class extends Controller {
    static targets = ['toggle'];
    static values = { collapseLabel: String, expandLabel: String };

    connect() {
        this.sync();
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

    sync() {
        const collapsed = document.documentElement.getAttribute('data-sidebar') === 'collapsed';
        const label = collapsed ? this.expandLabelValue : this.collapseLabelValue;
        this.toggleTarget.setAttribute('aria-expanded', String(!collapsed));
        this.toggleTarget.setAttribute('aria-label', label);
        this.toggleTarget.title = label;
        this.element.querySelectorAll('.nav-item, .menu__trigger').forEach((item) => {
            const text = item.querySelector('.nav-item__label')?.textContent.trim();
            if (collapsed && text) {
                item.title = text;
            } else {
                item.removeAttribute('title');
            }
        });
    }
}
