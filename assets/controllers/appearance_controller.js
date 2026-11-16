import { Controller } from '@hotwired/stimulus';
import { option, setOption, setScheme, storedScheme, systemScheme } from '../appearance.js';

const DEFAULTS = { accent: 'violet', 'text-size': 'normal' };

export default class extends Controller {
    static targets = ['system'];
    static values = { dark: String, light: String };

    connect() {
        this.refresh();
    }

    refresh() {
        this.check('theme', storedScheme() ?? 'system');
        Object.entries(DEFAULTS).forEach(([key, fallback]) => this.check(key, option(key) ?? fallback));
        this.element.querySelector('input[name="contrast"]').checked = option('contrast') === 'more';
        this.systemTarget.textContent = systemScheme() === 'dark' ? this.darkValue : this.lightValue;
    }

    update({ target }) {
        if (target.name === 'theme') {
            setScheme(target.value === 'system' ? null : target.value);
        } else if (target.name === 'contrast') {
            setOption('contrast', target.checked ? 'more' : null);
        } else {
            setOption(target.name, target.value === DEFAULTS[target.name] ? null : target.value);
        }
    }

    check(name, value) {
        const input = this.element.querySelector(`input[name="${name}"][value="${CSS.escape(value)}"]`);
        if (input) {
            input.checked = true;
        }
    }
}
