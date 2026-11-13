import './stimulus_bootstrap.js';
import './styles/app.css';

if (!('commandForElement' in HTMLButtonElement.prototype)) {
    import('invokers-polyfill');
}

if (!Object.hasOwn(HTMLElement.prototype, 'popover')) {
    import('@oddbird/popover-polyfill');
}
