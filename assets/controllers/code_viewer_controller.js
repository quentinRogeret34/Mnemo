import { Controller } from '@hotwired/stimulus';
import { EditorView, drawSelection, lineNumbers } from '@codemirror/view';
import { EditorState } from '@codemirror/state';
import { HighlightStyle, syntaxHighlighting } from '@codemirror/language';
import { markdown } from '@codemirror/lang-markdown';
import { tags } from '@lezer/highlight';

const viewerTheme = EditorView.theme({
    '&': {
        color: 'var(--color-ink)',
        backgroundColor: 'var(--color-paper)',
        fontSize: '0.8125rem',
        maxHeight: '22rem',
    },
    '&.cm-focused': { outline: 'none' },
    '.cm-scroller': {
        fontFamily: 'var(--font-mono)',
        lineHeight: '1.6',
        overflow: 'auto',
    },
    '.cm-content': { padding: 'var(--space-xs) 0' },
    '.cm-line': { padding: '0 var(--space-sm)' },
    '.cm-gutters': {
        backgroundColor: 'var(--color-paper-2)',
        color: 'var(--color-faint)',
        borderRight: 'var(--rule-width) solid var(--color-rule)',
        minWidth: '2.25rem',
    },
    '.cm-gutterElement': { padding: '0 0.45rem 0 0.35rem', fontSize: '0.72rem' },
    '.cm-selectionBackground, .cm-content ::selection': {
        backgroundColor: 'color-mix(in oklch, var(--color-accent) 22%, transparent)',
    },
});

const viewerHighlight = HighlightStyle.define([
    { tag: [tags.heading1, tags.heading2], color: 'var(--color-ink)', fontWeight: '700' },
    { tag: [tags.heading3, tags.heading4, tags.heading5, tags.heading6], color: 'var(--color-ink)', fontWeight: '600' },
    { tag: tags.strong, fontWeight: '700', color: 'var(--color-ink)' },
    { tag: tags.emphasis, fontStyle: 'italic' },
    { tag: tags.strikethrough, textDecoration: 'line-through' },
    { tag: [tags.link, tags.url], color: 'var(--color-accent)', textDecoration: 'underline' },
    { tag: tags.monospace, color: 'var(--color-hub-rule)' },
    { tag: [tags.processingInstruction, tags.meta, tags.contentSeparator, tags.quote], color: 'var(--color-faint)' },
    { tag: tags.list, color: 'var(--color-ink-2)' },
    { tag: [tags.propertyName, tags.definition(tags.propertyName)], color: 'var(--color-accent)' },
    { tag: [tags.string, tags.special(tags.string)], color: 'var(--color-success)' },
    { tag: [tags.number, tags.bool, tags.null, tags.atom, tags.keyword], color: 'var(--color-warning)' },
    { tag: [tags.comment, tags.lineComment], color: 'var(--color-faint)', fontStyle: 'italic' },
    { tag: [tags.punctuation, tags.separator, tags.squareBracket, tags.brace], color: 'var(--color-muted)' },
    { tag: [tags.labelName, tags.typeName, tags.tagName], color: 'var(--color-danger)' },
]);

export default class extends Controller {
    static targets = ['source', 'mount', 'copyLabel', 'expandLabel'];
    static values = {
        copiedLabel: { type: String, default: 'Copied' },
        expandLabel: { type: String, default: 'Expand' },
        collapseLabel: { type: String, default: 'Collapse' },
    };

    #view = null;
    #copyTimer = null;

    connect() {
        if (!this.hasSourceTarget || !this.hasMountTarget) {
            return;
        }

        const content = this.sourceTarget.textContent ?? '';
        this.#view = new EditorView({
            parent: this.mountTarget,
            state: EditorState.create({
                doc: content,
                extensions: [
                    lineNumbers(),
                    drawSelection(),
                    EditorView.lineWrapping,
                    EditorState.readOnly.of(true),
                    EditorView.editable.of(false),
                    markdown(),
                    viewerTheme,
                    syntaxHighlighting(viewerHighlight),
                ],
            }),
        });

        this.sourceTarget.hidden = true;
    }

    disconnect() {
        if (this.#copyTimer) {
            window.clearTimeout(this.#copyTimer);
            this.#copyTimer = null;
        }
        this.#view?.destroy();
        this.#view = null;
        if (this.hasSourceTarget) {
            this.sourceTarget.hidden = false;
        }
    }

    async copy() {
        if (!this.hasSourceTarget) {
            return;
        }

        try {
            await navigator.clipboard.writeText(this.sourceTarget.textContent ?? '');
            if (this.hasCopyLabelTarget) {
                const label = this.copyLabelTarget;
                const original = label.dataset.originalLabel ?? (label.dataset.originalLabel = label.textContent);
                label.textContent = this.copiedLabelValue;
                window.clearTimeout(this.#copyTimer);
                this.#copyTimer = window.setTimeout(() => {
                    label.textContent = original;
                }, 2000);
            }
        } catch {
            // Clipboard API may be unavailable on non-HTTPS origins or denied by browser permissions.
        }
    }

    toggleExpand() {
        const expanded = this.element.classList.toggle('is-expanded');
        if (this.hasExpandLabelTarget) {
            this.expandLabelTarget.textContent = expanded ? this.collapseLabelValue : this.expandLabelValue;
        }
    }
}
