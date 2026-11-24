import { Controller } from '@hotwired/stimulus';
import { trans } from '../translator.js';

const STORAGE_KEY = 'mnemo.chat.history_collapsed';
const DESKTOP_MEDIA = '(min-width: 64rem)';

const COPY_ICON = '<svg class="icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>';
const CHECK_ICON = '<svg class="icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>';

// Enhances ProjectChat: smart auto-scroll, Enter submit, example prompts, codeblock headers with copy button,
// response copy, collapsible/drawer history panel, info popover, and modal confirmations.
export default class extends Controller {
    static targets = [
        'log',
        'input',
        'historyPanel',
        'historyOpener',
        'railToggle',
        'backdrop',
        'infoWrap',
        'infoButton',
        'infoPopover',
        'confirmDialog',
        'confirmTitle',
        'confirmAccept',
        'clearHistoryTrigger',
        'deleteSelectedTrigger',
    ];

    static values = {
        conversationId: Number,
        messageCount: Number,
    };

    #lastConversationId = null;
    #lastMessageCount = 0;
    #pendingConfirmAction = null;
    #copyTimers = new WeakMap();
    #media = null;
    #onMediaChange = null;

    connect() {
        this.#media = window.matchMedia(DESKTOP_MEDIA);
        this.#applySavedHistoryState();
        this.enhanceCodeBlocks();
        this.scrollToBottom();
        this.#lastConversationId = this.conversationIdValue;
        this.#lastMessageCount = this.messageCountValue;

        this.observer = new MutationObserver(() => {
            this.enhanceCodeBlocks();
        });
        if (this.hasLogTarget) {
            this.observer.observe(this.logTarget, { childList: true, subtree: true });
        }

        this.#onMediaChange = () => this.#applySavedHistoryState();
        this.#media.addEventListener('change', this.#onMediaChange);
    }

    disconnect() {
        this.observer?.disconnect();
        if (this.#media && this.#onMediaChange) {
            this.#media.removeEventListener('change', this.#onMediaChange);
        }
    }

    conversationIdValueChanged(value) {
        if (this.#lastConversationId !== null && value !== this.#lastConversationId) {
            this.scrollToBottom();
        }
        this.#lastConversationId = value;
    }

    messageCountValueChanged(value) {
        if (value > this.#lastMessageCount) {
            this.scrollToBottom();
        }
        this.#lastMessageCount = value;
    }

    scrollToBottom() {
        if (this.hasLogTarget) {
            requestAnimationFrame(() => {
                if (this.hasLogTarget) {
                    this.logTarget.scrollTop = this.logTarget.scrollHeight;
                }
            });
        }
    }

    keydown(event) {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            this.element.querySelector('form.chat__form')?.requestSubmit();
        }
    }

    useExample(event) {
        const prompt = event.currentTarget.dataset.prompt;
        if (!prompt || !this.hasInputTarget) {
            return;
        }
        this.inputTarget.value = prompt;
        this.inputTarget.dispatchEvent(new Event('input', { bubbles: true }));
        this.inputTarget.focus();
        this.inputTarget.setSelectionRange(prompt.length, prompt.length);
    }

    async copyMessage(event) {
        const button = event.currentTarget;
        const text = button.dataset.copyText ?? '';
        if (!text) {
            return;
        }
        try {
            await navigator.clipboard.writeText(text);
            button.classList.add('is-copied');
            clearTimeout(this.#copyTimers.get(button));
            this.#copyTimers.set(button, setTimeout(() => {
                button.classList.remove('is-copied');
            }, 1800));
        } catch {
            // Clipboard API may be unavailable on non-HTTPS origins or denied by browser permissions.
        }
    }

    toggleHistory() {
        if (this.#isDesktop()) {
            const collapsed = this.element.classList.toggle('is-history-collapsed');
            this.#syncHistoryAria(!collapsed);
            try {
                localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0');
            } catch {
                // Storage can be disabled in private browsing.
            }
            return;
        }

        const open = this.element.classList.toggle('is-history-open');
        if (this.hasBackdropTarget) {
            this.backdropTarget.hidden = !open;
        }
        this.#syncHistoryAria(open);
    }

    openHistoryForSearch() {
        if (this.#isDesktop() && this.element.classList.contains('is-history-collapsed')) {
            this.element.classList.remove('is-history-collapsed');
            this.#syncHistoryAria(true);
            try {
                localStorage.setItem(STORAGE_KEY, '0');
            } catch {
                // Ignore storage failures.
            }
        }
    }

    closeHistoryMobile() {
        if (!this.#isDesktop() && this.element.classList.contains('is-history-open')) {
            this.element.classList.remove('is-history-open');
            if (this.hasBackdropTarget) {
                this.backdropTarget.hidden = true;
            }
            this.#syncHistoryAria(false);
        }
    }

    toggleInfo() {
        if (!this.hasInfoPopoverTarget || !this.hasInfoButtonTarget) {
            return;
        }
        const opening = this.infoPopoverTarget.hidden;
        this.infoPopoverTarget.hidden = !opening;
        this.infoButtonTarget.setAttribute('aria-expanded', opening ? 'true' : 'false');
    }

    handleEscape() {
        if (this.hasInfoPopoverTarget && !this.infoPopoverTarget.hidden) {
            this.infoPopoverTarget.hidden = true;
            if (this.hasInfoButtonTarget) {
                this.infoButtonTarget.setAttribute('aria-expanded', 'false');
            }
        }
        this.closeHistoryMobile();
    }

    handleOutsideClick(event) {
        if (
            this.hasInfoPopoverTarget
            && !this.infoPopoverTarget.hidden
            && this.hasInfoWrapTarget
            && !this.infoWrapTarget.contains(event.target)
        ) {
            this.infoPopoverTarget.hidden = true;
            if (this.hasInfoButtonTarget) {
                this.infoButtonTarget.setAttribute('aria-expanded', 'false');
            }
        }
    }

    askConfirm(event) {
        const trigger = event.currentTarget;
        this.#pendingConfirmAction = trigger.dataset.confirmTarget ?? null;
        if (!this.#pendingConfirmAction || !this.hasConfirmDialogTarget) {
            return;
        }
        if (this.hasConfirmTitleTarget && trigger.dataset.confirmTitle) {
            this.confirmTitleTarget.textContent = trigger.dataset.confirmTitle;
        }
        if (this.hasConfirmAcceptTarget) {
            this.confirmAcceptTarget.textContent = trigger.dataset.confirmLabel ?? trans('js.confirm');
        }
        this.confirmDialogTarget.showModal();
    }

    cancelConfirm() {
        this.#pendingConfirmAction = null;
        if (this.hasConfirmDialogTarget) {
            this.confirmDialogTarget.close();
        }
    }

    acceptConfirm() {
        const action = this.#pendingConfirmAction;
        this.#pendingConfirmAction = null;
        if (this.hasConfirmDialogTarget) {
            this.confirmDialogTarget.close();
        }
        if (action === 'clearHistory' && this.hasClearHistoryTriggerTarget) {
            this.clearHistoryTriggerTarget.click();
        } else if (action === 'deleteSelected' && this.hasDeleteSelectedTriggerTarget) {
            this.deleteSelectedTriggerTarget.click();
        }
    }

    enhanceCodeBlocks() {
        if (!this.hasLogTarget) {
            return;
        }
        const copyLabel = trans('js.chat.copy_code');
        const copiedLabel = trans('js.copied');

        for (const pre of this.logTarget.querySelectorAll('.prose pre:not([data-enhanced])')) {
            pre.dataset.enhanced = '1';
            const code = pre.querySelector('code');
            const langClass = code ? [...code.classList].find((c) => c.startsWith('language-')) : null;
            const lang = langClass ? langClass.slice('language-'.length).toUpperCase() : 'CODE';

            const wrapper = document.createElement('div');
            wrapper.className = 'codeblock';

            const bar = document.createElement('div');
            bar.className = 'codeblock__bar';

            const badge = document.createElement('span');
            badge.className = 'codeblock__lang';
            badge.textContent = lang;

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'codeblock__copy';
            const setButtonState = (iconMarkup, text, copied) => {
                button.classList.toggle('is-copied', copied);
                button.innerHTML = iconMarkup;
                const span = document.createElement('span');
                span.textContent = text;
                button.append(span);
            };
            setButtonState(COPY_ICON, copyLabel, false);

            button.addEventListener('click', async () => {
                const raw = (code ?? pre).textContent ?? '';
                try {
                    await navigator.clipboard.writeText(raw.trimEnd());
                    setButtonState(CHECK_ICON, copiedLabel, true);
                    clearTimeout(this.#copyTimers.get(button));
                    this.#copyTimers.set(button, setTimeout(() => {
                        setButtonState(COPY_ICON, copyLabel, false);
                    }, 1800));
                } catch {
                    // Clipboard unavailable.
                }
            });

            bar.append(badge, button);
            pre.parentNode?.insertBefore(wrapper, pre);
            wrapper.append(bar, pre);
        }
    }

    #applySavedHistoryState() {
        if (this.#isDesktop()) {
            this.element.classList.remove('is-history-open');
            if (this.hasBackdropTarget) {
                this.backdropTarget.hidden = true;
            }
            let collapsed = false;
            try {
                collapsed = localStorage.getItem(STORAGE_KEY) === '1';
            } catch {
                collapsed = false;
            }
            this.element.classList.toggle('is-history-collapsed', collapsed);
            this.#syncHistoryAria(!collapsed);
        } else {
            this.element.classList.remove('is-history-collapsed');
            const open = this.element.classList.contains('is-history-open');
            this.#syncHistoryAria(open);
        }
    }

    #syncHistoryAria(expanded) {
        const value = expanded ? 'true' : 'false';
        if (this.hasHistoryOpenerTarget) {
            this.historyOpenerTarget.setAttribute('aria-expanded', value);
        }
        if (this.hasRailToggleTarget) {
            this.railToggleTarget.setAttribute('aria-expanded', value);
        }
    }

    #isDesktop() {
        return this.#media ? this.#media.matches : window.innerWidth >= 1024;
    }
}
