import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['textarea', 'gutter', 'dirty', 'form', 'saveButton', 'treeItem', 'treeGroup', 'search', 'editorPane', 'previewPane', 'modeBtn'];

    #initialContent = '';
    #initialDescription = '';

    connect() {
        if (this.hasTextareaTarget) {
            this.#initialContent = this.textareaTarget.value;
            this.syncLines();
        }
        const desc = this.element.querySelector('input[name="description"]');
        if (desc) {
            this.#initialDescription = desc.value;
        }
        this.boundKeydown = this.onGlobalKeydown.bind(this);
        window.addEventListener('keydown', this.boundKeydown);
    }

    disconnect() {
        window.removeEventListener('keydown', this.boundKeydown);
    }

    onGlobalKeydown(event) {
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') {
            if (this.hasFormTarget && this.hasSaveButtonTarget && !this.saveButtonTarget.disabled) {
                event.preventDefault();
                this.formTarget.requestSubmit();
            }
        }
    }

    onInput() {
        this.syncLines();
        this.updateDirty();
    }

    onKeydown(event) {
        if (event.key !== 'Tab' || !this.hasTextareaTarget || this.textareaTarget.readOnly) {
            return;
        }
        event.preventDefault();
        const el = this.textareaTarget;
        const start = el.selectionStart;
        const end = el.selectionEnd;
        const val = el.value;
        el.value = val.slice(0, start) + '  ' + val.slice(end);
        el.selectionStart = el.selectionEnd = start + 2;
        this.onInput();
    }

    syncScroll() {
        if (this.hasGutterTarget && this.hasTextareaTarget) {
            this.gutterTarget.scrollTop = this.textareaTarget.scrollTop;
        }
    }

    syncLines() {
        if (!this.hasGutterTarget || !this.hasTextareaTarget) {
            return;
        }
        const count = Math.max(1, this.textareaTarget.value.split('\n').length);
        const lines = [];
        for (let i = 1; i <= count; i++) {
            lines.push(String(i));
        }
        this.gutterTarget.textContent = lines.join('\n');
        this.syncScroll();
    }

    updateDirty() {
        if (!this.hasDirtyTarget || !this.hasTextareaTarget) {
            return;
        }
        const desc = this.element.querySelector('input[name="description"]');
        const isDirty = this.textareaTarget.value !== this.#initialContent
            || (desc && desc.value !== this.#initialDescription);
        this.dirtyTarget.hidden = !isDirty;
    }

    filterTree() {
        if (!this.hasSearchTarget) {
            return;
        }
        const q = this.searchTarget.value.trim().toLowerCase();
        this.treeItemTargets.forEach((item) => {
            const hay = (item.dataset.searchText || item.textContent || '').toLowerCase();
            item.hidden = q !== '' && !hay.includes(q);
        });
        this.treeGroupTargets.forEach((group) => {
            const visible = group.querySelectorAll('[data-studio-editor-target="treeItem"]:not([hidden])').length;
            group.hidden = q !== '' && visible === 0;
            if (q !== '' && visible > 0) {
                group.open = true;
            }
        });
    }

    switchMode(event) {
        const mode = event.currentTarget.dataset.mode;
        if (!this.hasEditorPaneTarget || !this.hasPreviewPaneTarget) {
            return;
        }
        const isPreview = mode === 'preview';
        this.editorPaneTarget.hidden = isPreview;
        this.previewPaneTarget.hidden = !isPreview;
        this.modeBtnTargets.forEach((btn) => {
            const active = btn.dataset.mode === mode;
            btn.classList.toggle('is-active', active);
            btn.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        if (isPreview && this.hasTextareaTarget) {
            this.previewPaneTarget.textContent = '';
            this.renderPreview(this.textareaTarget.value, this.previewPaneTarget);
        }
    }

    renderPreview(markdown, container) {
        const lines = markdown.split('\n');
        let list = null;
        let codeBlock = null;
        const flushList = () => { list = null; };
        for (const line of lines) {
            if (line.trim().startsWith('```')) {
                flushList();
                if (codeBlock) {
                    codeBlock = null;
                } else {
                    const pre = document.createElement('pre');
                    pre.className = 'factory-code';
                    codeBlock = document.createElement('code');
                    pre.appendChild(codeBlock);
                    container.appendChild(pre);
                }
                continue;
            }
            if (codeBlock) {
                codeBlock.textContent += (codeBlock.textContent ? '\n' : '') + line;
                continue;
            }
            const heading = line.match(/^(#{1,3})\s+(.+)$/);
            if (heading) {
                flushList();
                const el = document.createElement('h' + heading[1].length);
                el.textContent = heading[2];
                container.appendChild(el);
                continue;
            }
            const bullet = line.match(/^\s*[-*]\s+(.+)$/);
            if (bullet) {
                if (!list) {
                    list = document.createElement('ul');
                    container.appendChild(list);
                }
                const li = document.createElement('li');
                li.textContent = bullet[1];
                list.appendChild(li);
                continue;
            }
            if (line.trim() === '') {
                flushList();
                continue;
            }
            flushList();
            const p = document.createElement('p');
            p.textContent = line;
            container.appendChild(p);
        }
    }
}
