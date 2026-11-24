import { Controller } from '@hotwired/stimulus';
import { EditorView, basicSetup } from 'codemirror';
import { EditorState } from '@codemirror/state';
import { keymap } from '@codemirror/view';
import { indentWithTab } from '@codemirror/commands';
import { HighlightStyle, syntaxHighlighting } from '@codemirror/language';
import { markdown } from '@codemirror/lang-markdown';
import { yaml, yamlFrontmatter } from '@codemirror/lang-yaml';
import { tags } from '@lezer/highlight';

const theme = EditorView.theme({
    '&': { height: '100%', color: 'var(--color-ink)', backgroundColor: 'var(--color-card)', fontSize: '0.8125rem' },
    '&.cm-focused': { outline: 'none' },
    '.cm-scroller': { fontFamily: 'var(--font-mono)', lineHeight: '1.65' },
    '.cm-content': { padding: 'var(--space-md) 0', caretColor: 'var(--color-ink)' },
    '.cm-line': { padding: '0 var(--space-md)' },
    '.cm-gutters': { backgroundColor: 'var(--color-paper)', color: 'var(--color-faint)', borderRight: 'var(--rule-width) solid var(--color-rule)' },
    '.cm-activeLineGutter': { backgroundColor: 'var(--color-hover)', color: 'var(--color-ink-2)' },
    '.cm-activeLine': { backgroundColor: 'color-mix(in oklch, var(--color-hover) 55%, transparent)' },
    '.cm-cursor, .cm-dropCursor': { borderLeftColor: 'var(--color-ink)' },
    '&.cm-focused > .cm-scroller > .cm-selectionLayer .cm-selectionBackground, .cm-selectionBackground, .cm-content ::selection': {
        backgroundColor: 'color-mix(in oklch, var(--color-accent) 22%, transparent)',
    },
    '.cm-selectionMatch': { backgroundColor: 'color-mix(in oklch, var(--color-accent) 12%, transparent)' },
    '&.cm-focused .cm-matchingBracket': { backgroundColor: 'color-mix(in oklch, var(--color-success) 22%, transparent)', outline: 'none' },
    '.cm-foldPlaceholder': { backgroundColor: 'var(--color-hover)', border: 'none', color: 'var(--color-muted)' },
    '.cm-panels': { backgroundColor: 'var(--color-paper)', color: 'var(--color-ink)' },
    '.cm-panels.cm-panels-top': { borderBottom: 'var(--rule-width) solid var(--color-rule)' },
    '.cm-searchMatch': { backgroundColor: 'color-mix(in oklch, var(--color-warning) 28%, transparent)' },
    '.cm-searchMatch.cm-searchMatch-selected': { backgroundColor: 'color-mix(in oklch, var(--color-warning) 50%, transparent)' },
    '.cm-tooltip': { backgroundColor: 'var(--color-card)', border: 'var(--rule-width) solid var(--color-rule)', borderRadius: 'var(--radius-sm)' },
    '.cm-tooltip-autocomplete > ul > li[aria-selected]': { backgroundColor: 'var(--color-selected)', color: 'var(--color-ink)' },
});

const highlight = HighlightStyle.define([
    { tag: [tags.heading1, tags.heading2], color: 'var(--color-ink)', fontWeight: '700' },
    { tag: [tags.heading3, tags.heading4, tags.heading5, tags.heading6], color: 'var(--color-ink)', fontWeight: '600' },
    { tag: tags.strong, fontWeight: '700' },
    { tag: tags.emphasis, fontStyle: 'italic' },
    { tag: tags.strikethrough, textDecoration: 'line-through' },
    { tag: [tags.link, tags.url], color: 'var(--color-accent)', textDecoration: 'underline' },
    { tag: tags.monospace, color: 'var(--color-danger)' },
    { tag: [tags.processingInstruction, tags.meta, tags.contentSeparator, tags.quote], color: 'var(--color-faint)' },
    { tag: tags.list, color: 'var(--color-ink-2)' },
    { tag: [tags.propertyName, tags.definition(tags.propertyName)], color: 'var(--color-accent)' },
    { tag: [tags.string, tags.special(tags.string)], color: 'var(--color-success)' },
    { tag: [tags.number, tags.bool, tags.null, tags.atom, tags.keyword], color: 'var(--color-warning)' },
    { tag: [tags.comment, tags.lineComment], color: 'var(--color-faint)', fontStyle: 'italic' },
    { tag: [tags.punctuation, tags.separator, tags.squareBracket, tags.brace], color: 'var(--color-muted)' },
    { tag: [tags.labelName, tags.typeName, tags.tagName], color: 'var(--color-danger)' },
    { tag: tags.invalid, color: 'var(--color-danger)' },
]);

export default class extends Controller {
    static targets = ['textarea', 'dirty', 'form', 'saveButton', 'treeItem', 'treeGroup', 'search', 'editorPane', 'previewPane', 'modeBtn'];
    static values = { language: String };

    #initialContent = '';
    #initialDescription = '';
    #view = null;

    connect() {
        if (this.hasTextareaTarget) {
            this.#initialContent = this.textareaTarget.value;
            this.#mountEditor();
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
        this.#view?.destroy();
        this.#view = null;
        if (this.hasTextareaTarget) {
            this.textareaTarget.hidden = false;
        }
    }

    #mountEditor() {
        const textarea = this.textareaTarget;
        const readOnly = textarea.readOnly;
        const language = this.languageValue === 'yaml' ? yaml() : yamlFrontmatter({ content: markdown() });

        this.#view = new EditorView({
            parent: textarea.parentElement,
            state: EditorState.create({
                doc: textarea.value,
                extensions: [
                    basicSetup,
                    keymap.of([indentWithTab]),
                    EditorState.tabSize.of(2),
                    EditorView.lineWrapping,
                    EditorState.readOnly.of(readOnly),
                    EditorView.editable.of(!readOnly),
                    EditorView.contentAttributes.of({ 'aria-label': textarea.getAttribute('aria-label') ?? '' }),
                    language,
                    theme,
                    syntaxHighlighting(highlight),
                    EditorView.updateListener.of((update) => {
                        if (update.docChanged) {
                            textarea.value = update.state.doc.toString();
                            this.updateDirty();
                        }
                    }),
                ],
            }),
        });
        textarea.hidden = true;
    }

    onGlobalKeydown(event) {
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') {
            if (this.hasFormTarget && this.hasSaveButtonTarget && !this.saveButtonTarget.disabled) {
                event.preventDefault();
                this.formTarget.requestSubmit();
            }
        }
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
        } else {
            this.#view?.focus();
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
