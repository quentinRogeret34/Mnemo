const OPTIONS = ['accent', 'contrast', 'text-size'];
const system = matchMedia('(prefers-color-scheme: dark)');
const meta = () => document.querySelector('meta[name="color-scheme"]');

function store(key, value) {
    try {
        if (value === null) {
            localStorage.removeItem(key);
        } else {
            localStorage.setItem(key, value);
        }
    } catch (error) {
        // Storage blocked: the choice still applies, for this page only.
    }
}

function applyScheme(value) {
    meta().content = value ?? 'light dark';
}

function applyOption(key, value) {
    if (value === null) {
        document.documentElement.removeAttribute(`data-${key}`);
    } else {
        document.documentElement.setAttribute(`data-${key}`, value);
    }
}

function announce() {
    window.dispatchEvent(new CustomEvent('theme:change'));
}

export function systemScheme() {
    return system.matches ? 'dark' : 'light';
}

export function storedScheme() {
    const value = meta().content;

    return value === 'light' || value === 'dark' ? value : null;
}

export function renderedScheme() {
    return storedScheme() ?? systemScheme();
}

export function setScheme(value) {
    store('theme', value);
    applyScheme(value);
    announce();
}

export function option(key) {
    return document.documentElement.getAttribute(`data-${key}`);
}

export function setOption(key, value) {
    store(key, value);
    applyOption(key, value);
    announce();
}

window.addEventListener('storage', (event) => {
    if (event.storageArea !== localStorage || (event.key !== null && event.key !== 'theme' && !OPTIONS.includes(event.key))) {
        return;
    }
    applyScheme(localStorage.getItem('theme'));
    OPTIONS.forEach((key) => applyOption(key, localStorage.getItem(key)));
    announce();
});

// The system preference never touches the stored choice: a pinned scheme stays pinned when the OS happens to match it.
system.addEventListener('change', announce);
