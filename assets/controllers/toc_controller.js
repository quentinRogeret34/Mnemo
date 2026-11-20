import { Controller } from '@hotwired/stimulus';

// "On this page": lists the headings of the rendered Markdown and marks the one being read.
// The Markdown renderer gives headings no id, so they get one here.
export default class extends Controller {
    static targets = ['content', 'nav', 'list'];

    connect() {
        if (!this.hasContentTarget) {
            return;
        }
        const headings = [...this.contentTarget.querySelectorAll('h1, h2, h3')];
        if (headings.length < 2) {
            return;
        }
        const used = new Set();
        this.links = new Map();
        headings.forEach((heading) => {
            const base = heading.id || heading.textContent.trim().toLocaleLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'section';
            let id = base;
            for (let index = 2; used.has(id) || (document.getElementById(id) && document.getElementById(id) !== heading); index++) {
                id = `${base}-${index}`;
            }
            used.add(id);
            heading.id = id;

            const item = document.createElement('li');
            item.className = `doc-toc__item doc-toc__item--${heading.tagName.toLowerCase()}`;
            const link = Object.assign(document.createElement('a'), { href: `#${id}`, textContent: heading.textContent.trim(), title: heading.textContent.trim() });
            item.append(link);
            this.listTarget.append(item);
            this.links.set(heading, link);
        });
        this.navTarget.hidden = false;
        this.links.get(headings[0]).setAttribute('aria-current', 'true');

        this.observer = new IntersectionObserver((entries) => {
            const visible = entries.filter((entry) => entry.isIntersecting).sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top)[0];
            if (visible) {
                this.links.forEach((link) => link.removeAttribute('aria-current'));
                this.links.get(visible.target).setAttribute('aria-current', 'true');
            }
        }, { rootMargin: '-64px 0px -70% 0px' });
        headings.forEach((heading) => this.observer.observe(heading));
    }

    disconnect() {
        this.observer?.disconnect();
    }
}
