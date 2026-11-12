import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    toggle() {
        const theme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
        document.documentElement.dataset.theme = theme;
        try {
            localStorage.setItem('theme', theme);
        } catch (error) {
            // Private browsing: the choice lasts for the page only.
        }
        window.dispatchEvent(new CustomEvent('theme:change'));
    }
}
