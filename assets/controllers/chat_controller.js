import { Controller } from '@hotwired/stimulus';

// Enhances ProjectChat: auto-scrolls new messages, submits on Enter (Shift+Enter inserts newline), and fills clickable example prompts.
export default class extends Controller {
    static targets = ['log', 'input'];

    connect() {
        this.scrollToBottom();
        this.observer = new MutationObserver(() => this.scrollToBottom());
        if (this.hasLogTarget) {
            this.observer.observe(this.logTarget, { childList: true, subtree: true });
        }
    }

    disconnect() {
        this.observer?.disconnect();
    }

    scrollToBottom() {
        if (this.hasLogTarget) {
            this.logTarget.scrollTop = this.logTarget.scrollHeight;
        }
    }

    keydown(event) {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            this.element.querySelector('form')?.requestSubmit();
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
    }
}
