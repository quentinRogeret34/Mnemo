import { Controller } from '@hotwired/stimulus';
import { renderedScheme, setScheme, systemScheme } from '../appearance.js';

export default class extends Controller {
    toggle() {
        const target = renderedScheme() === 'dark' ? 'light' : 'dark';
        setScheme(target === systemScheme() ? null : target);
    }
}
