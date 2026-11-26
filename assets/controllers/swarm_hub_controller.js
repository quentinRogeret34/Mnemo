import { Controller } from '@hotwired/stimulus';

const POLL_INTERVAL_MS = 2500;
const BROADCAST_GROUP_WINDOW_MS = 8000;

export default class extends Controller {
    static values = {
        stateUrl: String,
        orderUrl: String,
        orderCsrf: String,
        messageUrl: String,
        messageCsrf: String,
        controlUrl: String,
        controlCsrf: String,
        active: { type: Boolean, default: false },
        hubMode: { type: Boolean, default: true },
        labels: { type: Object, default: {} },
        nodes: { type: Array, default: [] },
    };

    static targets = [
        'shell',
        'statusBadge',
        'presenceBadge',
        'presenceDot',
        'nodesOnline',
        'ordersActive',
        'ordersCompleted',
        'nodesList',
        'stream',
        'thread',
        'composerArea',
        'composerInput',
        'composerError',
        'sendButton',
        'targetChip',
        'targetChipLabel',
        'kindSelect',
        'ruleCodeInput',
        'filesHintInput',
        'optionsPanel',
        'autocomplete',
        'autocompleteList',
        'tab',
        'tabPanel',
    ];

    #timer = null;
    #inFlight = false;
    #submitting = false;
    #lastNodesSignature = null;
    #lastStreamSignature = null;
    #nodes = [];
    #target = { type: 'auto', id: null, name: null };
    #suggestions = [];
    #activeSuggestion = 0;

    #onVisibilityChange = () => {
        if (!this.activeValue) {
            return;
        }
        if (document.visibilityState === 'visible') {
            this.#clearTimer();
            this.#poll();
        } else {
            this.#clearTimer();
        }
    };

    connect() {
        this.#nodes = Array.isArray(this.nodesValue) ? [...this.nodesValue] : [];
        this.#target = this.hubModeValue
            ? { type: 'auto', id: null, name: null }
            : { type: 'note', id: null, name: null };
        this.#renderTargetChip();

        document.addEventListener('visibilitychange', this.#onVisibilityChange);
        if (this.hasStreamTarget) {
            this.streamTarget.scrollTop = this.streamTarget.scrollHeight;
        }
        if (this.activeValue && document.visibilityState === 'visible') {
            this.#schedule();
        }
    }

    disconnect() {
        this.#clearTimer();
        document.removeEventListener('visibilitychange', this.#onVisibilityChange);
    }

    toggleRail() {
        if (!this.hasShellTarget) {
            return;
        }
        this.shellTarget.classList.toggle('is-history-collapsed');
    }

    openRail() {
        if (!this.hasShellTarget) {
            return;
        }
        this.shellTarget.classList.remove('is-history-collapsed');
    }

    toggleOptions() {
        if (!this.hasOptionsPanelTarget) {
            return;
        }
        this.optionsPanelTarget.hidden = !this.optionsPanelTarget.hidden;
    }

    switchTab(event) {
        const selected = event.currentTarget?.dataset?.tab;
        if (!selected) {
            return;
        }

        for (const button of this.tabTargets) {
            const isSelected = button.dataset.tab === selected;
            button.classList.toggle('is-active', isSelected);
            button.setAttribute('aria-selected', isSelected ? 'true' : 'false');
        }

        for (const panel of this.tabPanelTargets) {
            panel.hidden = panel.dataset.tab !== selected;
        }
    }

    selectTarget(event) {
        const nodeId = event.currentTarget?.dataset?.nodeId;
        const nodeName = event.currentTarget?.dataset?.nodeName;
        if (!nodeId) {
            return;
        }

        this.#target = { type: 'agent', id: nodeId, name: nodeName || nodeId };
        this.#renderTargetChip();
        this.#closeAutocomplete();
        if (this.hasComposerInputTarget) {
            this.composerInputTarget.focus();
        }
    }

    insertCommand(event) {
        const cmd = event.currentTarget?.dataset?.command;
        if (!cmd || !this.hasComposerInputTarget) {
            return;
        }

        this.#clearError();
        if (cmd === 'all') {
            this.#target = { type: 'all', id: null, name: null };
            this.#renderTargetChip();
            this.#closeAutocomplete();
            this.composerInputTarget.focus();
            return;
        }
        if (cmd === 'agent') {
            this.composerInputTarget.value = '/agent ';
            this.composerInputTarget.focus();
            this.onComposerInput();
            return;
        }
        if (cmd === 'end') {
            this.composerInputTarget.value = '/end';
            this.composerInputTarget.focus();
            this.#closeAutocomplete();
            return;
        }
        if (cmd === 'note') {
            this.#target = { type: 'note', id: null, name: null };
            this.#renderTargetChip();
            this.#closeAutocomplete();
            this.composerInputTarget.focus();
        }
    }

    clearTarget() {
        this.#target = this.hubModeValue
            ? { type: 'auto', id: null, name: null }
            : { type: 'note', id: null, name: null };
        this.#renderTargetChip();
        if (this.hasComposerInputTarget) {
            this.composerInputTarget.focus();
        }
    }

    onComposerInput() {
        if (!this.hasComposerInputTarget) {
            return;
        }

        this.#clearError();
        const value = this.composerInputTarget.value;
        const caret = this.composerInputTarget.selectionStart ?? value.length;
        const before = value.slice(0, caret);

        // Agent autocomplete: "/agent <query>" or "@<query>" at start
        const agentMatch = before.match(/^(?:\/agent\s+|@)([^\n]*)$/);
        if (agentMatch) {
            const query = agentMatch[1].trim().toLowerCase();
            const sortedNodes = [...this.#nodes].sort((a, b) => Number(Boolean(b.is_online)) - Number(Boolean(a.is_online)));
            const matches = sortedNodes.filter((node) => {
                if (!query) {
                    return true;
                }
                const hay = `${node.name} ${node.client || ''} ${node.branch || ''} ${node.role || ''}`.toLowerCase();
                return hay.includes(query);
            });

            this.#suggestions = matches.map((node) => ({
                kind: 'agent',
                id: node.public_id,
                name: node.name,
                status: node.status || 'offline',
                isOnline: Boolean(node.is_online),
                meta: [node.client, node.branch].filter(Boolean).join(' · '),
                replacePrefix: before,
            }));
            this.#activeSuggestion = 0;
            this.#renderAutocomplete('agent');
            return;
        }

        // Slash command autocomplete: "/..." before any space
        const cmdMatch = before.match(/^\/([a-z]*)$/i);
        if (cmdMatch) {
            const query = cmdMatch[1].toLowerCase();
            const commands = [
                { kind: 'command', name: '/all', action: 'all', desc: this.#label('cmdAll', 'Send to all connected agents') },
                { kind: 'command', name: '/agent', action: 'agent', desc: this.#label('cmdAgent', 'Target a single agent with autocomplete') },
                { kind: 'command', name: '/end', action: 'end', desc: this.#label('cmdEnd', 'Finish and close this swarm session') },
                { kind: 'command', name: '/pause', action: 'pause', desc: this.#label('cmdPause', 'Pause this swarm') },
                { kind: 'command', name: '/note', action: 'note', desc: this.#label('cmdNote', 'Post a note without dispatching an order') },
            ];
            this.#suggestions = commands.filter((c) => c.name.slice(1).startsWith(query));
            this.#activeSuggestion = 0;
            this.#renderAutocomplete('command');
            return;
        }

        this.#closeAutocomplete();
    }

    onComposerKeydown(event) {
        if (this.#isAutocompleteOpen()) {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                this.#activeSuggestion = (this.#activeSuggestion + 1) % this.#suggestions.length;
                this.#highlightActiveSuggestion();
                return;
            }
            if (event.key === 'ArrowUp') {
                event.preventDefault();
                this.#activeSuggestion = (this.#activeSuggestion - 1 + this.#suggestions.length) % this.#suggestions.length;
                this.#highlightActiveSuggestion();
                return;
            }
            if (event.key === 'Tab' || (event.key === 'Enter' && !event.shiftKey)) {
                event.preventDefault();
                const chosen = this.#suggestions[this.#activeSuggestion];
                if (chosen) {
                    this.#applySuggestion(chosen);
                }
                return;
            }
            if (event.key === 'Escape') {
                event.preventDefault();
                this.#closeAutocomplete();
                return;
            }
        }

        if (event.key === 'Backspace' && this.hasComposerInputTarget && this.composerInputTarget.value === '' && this.#target.type !== 'auto') {
            this.clearTarget();
            return;
        }

        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            this.submitComposer(event);
        }
    }

    pickSuggestion(event) {
        const index = Number(event.currentTarget?.dataset?.index ?? -1);
        const chosen = this.#suggestions[index];
        if (chosen) {
            this.#applySuggestion(chosen);
        }
    }

    async submitComposer(event) {
        event?.preventDefault();
        if (this.#submitting || !this.hasComposerInputTarget || !this.activeValue) {
            return;
        }

        const raw = this.composerInputTarget.value.trim();
        if (!raw) {
            return;
        }

        this.#clearError();
        this.#closeAutocomplete();

        // /end [optional summary]
        const endMatch = raw.match(/^\/end(?:\s+([\s\S]+))?$/i);
        if (endMatch) {
            await this.#runControl('complete', endMatch[1]?.trim() || '');
            return;
        }

        // /pause
        if (/^\/pause$/i.test(raw)) {
            await this.#runControl('pause', '');
            return;
        }

        // /note <message>
        const noteMatch = raw.match(/^\/note\s+([\s\S]+)$/i);
        if (noteMatch) {
            await this.#postNote(noteMatch[1].trim());
            return;
        }

        // /all <instructions>
        const allMatch = raw.match(/^\/all\s+([\s\S]+)$/i);
        if (allMatch) {
            await this.#dispatchOrder('all', allMatch[1].trim());
            return;
        }

        // /agent <name> <instructions>
        const agentMatch = raw.match(/^\/agent\s+([\s\S]+)$/i);
        if (agentMatch) {
            const rest = agentMatch[1].trim();
            const resolved = this.#resolveAgentFromText(rest);
            if (!resolved) {
                this.#showError(this.#label('agentNotFound', 'No matching agent found. Type /agent to pick from the list.'));
                return;
            }
            if (!resolved.body) {
                this.#target = { type: 'agent', id: resolved.node.public_id, name: resolved.node.name };
                this.#renderTargetChip();
                this.composerInputTarget.value = '';
                this.composerInputTarget.focus();
                return;
            }
            await this.#dispatchOrder(resolved.node.public_id, resolved.body);
            return;
        }

        // Plain message using active target chip
        if (this.#target.type === 'note') {
            await this.#postNote(raw);
            return;
        }
        if (this.#target.type === 'all') {
            await this.#dispatchOrder('all', raw);
            return;
        }
        if (this.#target.type === 'agent' && this.#target.id) {
            await this.#dispatchOrder(this.#target.id, raw);
            return;
        }

        await this.#dispatchOrder('any', raw);
    }

    async copyDirectUrl(event) {
        const button = event.currentTarget;
        const url = button?.dataset?.url;
        if (!url) {
            return;
        }

        try {
            await navigator.clipboard.writeText(url);
            const labelEl = button.querySelector('[data-copy-label]');
            if (labelEl) {
                const original = button.dataset.originalLabel || labelEl.textContent;
                button.dataset.originalLabel = original;
                labelEl.textContent = this.#label('copied', 'Copied');
                window.setTimeout(() => {
                    labelEl.textContent = original;
                }, 2000);
            }
        } catch {
            // Clipboard API may be unavailable on non-HTTPS origins or denied by browser permissions.
        }
    }

    #resolveAgentFromText(text) {
        const normalized = text.trim();
        if (!normalized) {
            return null;
        }

        // Match longest node name first (handles names with spaces like "Claude Code - symfony-ai (main)")
        const sorted = [...this.#nodes].sort((a, b) => b.name.length - a.name.length);
        const lower = normalized.toLowerCase();
        for (const node of sorted) {
            const candidate = node.name.toLowerCase();
            if (lower === candidate) {
                return { node, body: '' };
            }
            if (lower.startsWith(`${candidate} `) || lower.startsWith(`${candidate}\n`)) {
                return { node, body: normalized.slice(node.name.length).trim() };
            }
        }

        // Fallback: first whitespace token prefix match
        const firstSpace = normalized.search(/\s/);
        const token = (firstSpace === -1 ? normalized : normalized.slice(0, firstSpace)).replace(/^@/, '').toLowerCase();
        const body = firstSpace === -1 ? '' : normalized.slice(firstSpace).trim();
        const matched = sorted.find((n) => n.name.toLowerCase().includes(token));
        if (!matched) {
            return null;
        }
        return { node: matched, body };
    }

    async #dispatchOrder(target, instructions) {
        if (!this.orderUrlValue || !this.orderCsrfValue) {
            return;
        }

        this.#setSubmitting(true);
        try {
            const form = new URLSearchParams();
            form.set('_token', this.orderCsrfValue);
            form.set('target', target);
            form.set('kind', this.hasKindSelectTarget ? this.kindSelectTarget.value : 'custom');
            form.set('instructions', instructions);
            if (this.hasRuleCodeInputTarget && this.ruleCodeInputTarget.value.trim()) {
                form.set('rule_code', this.ruleCodeInputTarget.value.trim());
            }
            if (this.hasFilesHintInputTarget && this.filesHintInputTarget.value.trim()) {
                form.set('files_hint', this.filesHintInputTarget.value.trim());
            }

            const response = await fetch(this.orderUrlValue, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                },
                credentials: 'same-origin',
                body: form.toString(),
            });

            if (!response.ok) {
                const payload = await response.json().catch(() => ({}));
                this.#showError(payload.error || this.#label('sendError', 'Unable to dispatch order.'));
                return;
            }

            this.composerInputTarget.value = '';
            this.#clearError();
            await this.#poll(true);
        } catch {
            this.#showError(this.#label('sendError', 'Network error while dispatching order.'));
        } finally {
            this.#setSubmitting(false);
            this.composerInputTarget?.focus();
        }
    }

    async #postNote(body) {
        if (!this.messageUrlValue || !this.messageCsrfValue) {
            return;
        }

        this.#setSubmitting(true);
        try {
            const form = new URLSearchParams();
            form.set('_token', this.messageCsrfValue);
            form.set('body', body);

            const response = await fetch(this.messageUrlValue, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                },
                credentials: 'same-origin',
                body: form.toString(),
            });

            if (!response.ok) {
                this.#showError(this.#label('sendError', 'Unable to post message.'));
                return;
            }

            this.composerInputTarget.value = '';
            this.#clearError();
            await this.#poll(true);
        } catch {
            this.#showError(this.#label('sendError', 'Network error while posting message.'));
        } finally {
            this.#setSubmitting(false);
            this.composerInputTarget?.focus();
        }
    }

    async #runControl(action, summary) {
        if (!this.controlUrlValue || !this.controlCsrfValue) {
            return;
        }

        this.#setSubmitting(true);
        try {
            const form = new URLSearchParams();
            form.set('_token', this.controlCsrfValue);
            form.set('action', action);
            if (summary) {
                form.set('summary', summary);
            }

            const response = await fetch(this.controlUrlValue, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                },
                credentials: 'same-origin',
                body: form.toString(),
            });

            if (!response.ok) {
                this.#showError(this.#label('sendError', 'Unable to update session state.'));
                return;
            }

            this.composerInputTarget.value = '';
            this.#clearError();
            await this.#poll(true);
            if (action === 'complete') {
                window.location.reload();
            }
        } catch {
            this.#showError(this.#label('sendError', 'Network error while updating session.'));
        } finally {
            this.#setSubmitting(false);
        }
    }

    #setSubmitting(state) {
        this.#submitting = state;
        if (this.hasSendButtonTarget) {
            this.sendButtonTarget.disabled = state;
        }
    }

    #showError(message) {
        if (!this.hasComposerErrorTarget) {
            return;
        }
        this.composerErrorTarget.textContent = message;
        this.composerErrorTarget.hidden = false;
    }

    #clearError() {
        if (!this.hasComposerErrorTarget) {
            return;
        }
        this.composerErrorTarget.textContent = '';
        this.composerErrorTarget.hidden = true;
    }

    #renderTargetChip() {
        if (!this.hasTargetChipTarget || !this.hasTargetChipLabelTarget) {
            return;
        }

        const chip = this.targetChipTarget;
        chip.className = 'swarm-target-chip';

        if (this.#target.type === 'all') {
            chip.classList.add('swarm-target-chip--all');
            this.targetChipLabelTarget.textContent = `/all · ${this.#label('allNodes', 'All agents')}`;
            chip.hidden = false;
        } else if (this.#target.type === 'agent') {
            chip.classList.add('swarm-target-chip--agent');
            this.targetChipLabelTarget.textContent = `@${this.#target.name}`;
            chip.hidden = false;
        } else if (this.#target.type === 'note') {
            chip.classList.add('swarm-target-chip--note');
            this.targetChipLabelTarget.textContent = `/note · ${this.#label('noteTarget', 'Board note')}`;
            chip.hidden = false;
        } else {
            chip.hidden = true;
        }
    }

    #isAutocompleteOpen() {
        return this.hasAutocompleteTarget && !this.autocompleteTarget.hidden && this.#suggestions.length > 0;
    }

    #closeAutocomplete() {
        this.#suggestions = [];
        this.#activeSuggestion = 0;
        if (this.hasAutocompleteTarget) {
            this.autocompleteTarget.hidden = true;
        }
    }

    #renderAutocomplete(mode) {
        if (!this.hasAutocompleteTarget || !this.hasAutocompleteListTarget) {
            return;
        }

        const list = this.autocompleteListTarget;
        list.replaceChildren();

        if (this.#suggestions.length === 0) {
            if (mode === 'agent') {
                const empty = document.createElement('li');
                empty.className = 'swarm-ac__empty';
                empty.textContent = this.#label('noNodes', 'No client connected yet.');
                list.appendChild(empty);
                this.autocompleteTarget.hidden = false;
            } else {
                this.autocompleteTarget.hidden = true;
            }
            return;
        }

        this.#suggestions.forEach((item, index) => {
            const li = document.createElement('li');
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = `swarm-ac__item${index === this.#activeSuggestion ? ' is-active' : ''}`;
            btn.dataset.action = 'mousedown->swarm-hub#pickSuggestion';
            btn.dataset.index = String(index);

            if (item.kind === 'command') {
                const code = document.createElement('code');
                code.className = 'swarm-ac__cmd';
                code.textContent = item.name;
                btn.appendChild(code);

                const desc = document.createElement('span');
                desc.className = 'swarm-ac__desc';
                desc.textContent = item.desc;
                btn.appendChild(desc);
            } else {
                const dot = document.createElement('span');
                dot.className = `hub-node-dot hub-node-dot--${item.status}`;
                dot.setAttribute('aria-hidden', 'true');
                btn.appendChild(dot);

                const name = document.createElement('span');
                name.className = 'swarm-ac__name';
                name.textContent = `@${item.name}`;
                btn.appendChild(name);

                if (item.meta) {
                    const meta = document.createElement('span');
                    meta.className = 'swarm-ac__meta';
                    meta.textContent = item.meta;
                    btn.appendChild(meta);
                }
            }

            li.appendChild(btn);
            list.appendChild(li);
        });

        this.autocompleteTarget.hidden = false;
    }

    #highlightActiveSuggestion() {
        if (!this.hasAutocompleteListTarget) {
            return;
        }
        const items = this.autocompleteListTarget.querySelectorAll('.swarm-ac__item');
        items.forEach((el, idx) => {
            el.classList.toggle('is-active', idx === this.#activeSuggestion);
            if (idx === this.#activeSuggestion) {
                el.scrollIntoView({ block: 'nearest' });
            }
        });
    }

    #applySuggestion(item) {
        if (!this.hasComposerInputTarget) {
            return;
        }

        const input = this.composerInputTarget;
        if (item.kind === 'command') {
            if (item.action === 'all') {
                this.#target = { type: 'all', id: null, name: null };
                this.#renderTargetChip();
                input.value = '';
                this.#closeAutocomplete();
                input.focus();
                return;
            }
            if (item.action === 'agent') {
                input.value = '/agent ';
                input.focus();
                this.onComposerInput();
                return;
            }
            if (item.action === 'note') {
                this.#target = { type: 'note', id: null, name: null };
                this.#renderTargetChip();
                input.value = '';
                this.#closeAutocomplete();
                input.focus();
                return;
            }
            if (item.action === 'end') {
                input.value = '/end ';
                this.#closeAutocomplete();
                input.focus();
                return;
            }
            if (item.action === 'pause') {
                input.value = '/pause';
                this.#closeAutocomplete();
                input.focus();
                return;
            }
        } else if (item.kind === 'agent') {
            this.#target = { type: 'agent', id: item.id, name: item.name };
            this.#renderTargetChip();
            const rest = input.value.slice(item.replacePrefix.length).trimStart();
            input.value = rest;
            this.#closeAutocomplete();
            input.focus();
        }
    }

    #clearTimer() {
        if (this.#timer !== null) {
            window.clearTimeout(this.#timer);
            this.#timer = null;
        }
    }

    #schedule() {
        this.#clearTimer();
        if (!this.activeValue || document.visibilityState !== 'visible') {
            return;
        }
        this.#timer = window.setTimeout(() => this.#poll(), POLL_INTERVAL_MS);
    }

    async #poll(forceScroll = false) {
        if (this.#inFlight || !this.stateUrlValue || document.visibilityState !== 'visible') {
            this.#schedule();
            return;
        }

        this.#inFlight = true;
        try {
            const response = await fetch(this.stateUrlValue, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                return;
            }

            const data = await response.json();
            this.#applyState(data, forceScroll);

            if (data.is_active === false) {
                this.activeValue = false;
                this.#clearTimer();
                return;
            }
        } catch {
            // Keep polling quietly on transient network blips.
        } finally {
            this.#inFlight = false;
            if (this.activeValue && document.visibilityState === 'visible') {
                this.#schedule();
            }
        }
    }

    #applyState(data, forceScroll = false) {
        const counts = data.counts || {};
        const onlineCount = Number(counts.nodes_online ?? 0);
        const activeOrdersCount = Number(counts.orders_pending ?? 0) + Number(counts.orders_running ?? 0);
        const completedCount = Number(counts.orders_completed ?? 0);

        this.#setTargetsText(this.nodesOnlineTargets, String(onlineCount));
        this.#setTargetsText(this.ordersActiveTargets, String(activeOrdersCount));
        this.#setTargetsText(this.ordersCompletedTargets, String(completedCount));

        if (this.hasPresenceDotTarget) {
            this.presenceDotTarget.classList.toggle('swarm-presence__dot--online', onlineCount > 0);
        }
        if (this.hasPresenceBadgeTarget) {
            this.presenceBadgeTarget.classList.toggle('is-online', onlineCount > 0);
        }

        const orders = Array.isArray(data.orders) ? data.orders : [];
        const nodes = Array.isArray(data.nodes) ? data.nodes : [];
        const messages = Array.isArray(data.messages) ? data.messages : [];
        this.#nodes = nodes;

        if (data.status && data.status_label) {
            for (const badge of this.statusBadgeTargets) {
                badge.textContent = data.status_label;
                badge.className = `factory-status factory-status--${data.status}${data.is_active && ['planning', 'running', 'reviewing'].includes(data.status) ? ' factory-status--pulse' : ''}`;
            }
        }

        this.#updateNodes(nodes, Boolean(data.is_active));
        this.#updateStream(orders, messages, Boolean(data.is_active), forceScroll);
    }

    #updateNodes(nodes, isActive) {
        if (!this.hasNodesListTarget) {
            return;
        }

        const signature = JSON.stringify({ nodes, isActive });
        if (signature === this.#lastNodesSignature) {
            return;
        }
        this.#lastNodesSignature = signature;

        const container = this.nodesListTarget;
        container.replaceChildren();

        if (nodes.length === 0) {
            const empty = document.createElement('li');
            empty.className = 'hub-nodes__empty help-text';
            empty.textContent = this.#label('noNodes', 'No client connected yet.');
            container.appendChild(empty);
            return;
        }

        for (const node of nodes) {
            const item = document.createElement('li');
            item.className = `hub-node hub-node--${node.status}`;

            const main = document.createElement('div');
            main.className = 'hub-node__main';

            const head = document.createElement('div');
            head.className = 'hub-node__head';

            const dot = document.createElement('span');
            dot.className = `hub-node-dot hub-node-dot--${node.status}`;
            dot.setAttribute('aria-hidden', 'true');
            head.appendChild(dot);

            const name = document.createElement('strong');
            name.className = 'hub-node__name';
            name.textContent = `@${node.name}`;
            head.appendChild(name);

            const statusPill = document.createElement('span');
            statusPill.className = `hub-node__status hub-node__status--${node.status}`;
            statusPill.textContent = node.status_label || node.status;
            head.appendChild(statusPill);

            main.appendChild(head);

            const meta = document.createElement('div');
            meta.className = 'hub-node__meta row__sub';
            const parts = [node.role, node.client, node.branch, node.last_heartbeat_display].filter(Boolean);
            meta.textContent = parts.join(' · ');
            main.appendChild(meta);

            item.appendChild(main);

            if (isActive) {
                const targetBtn = document.createElement('button');
                targetBtn.type = 'button';
                targetBtn.className = 'button button--ghost hub-node-target-btn';
                targetBtn.dataset.action = 'swarm-hub#selectTarget';
                targetBtn.dataset.nodeId = node.public_id;
                targetBtn.dataset.nodeName = node.name;
                targetBtn.textContent = '/agent';
                item.appendChild(targetBtn);
            }

            container.appendChild(item);
        }
    }

    #updateStream(orders, messages, isActive, forceScroll = false) {
        if (!this.hasThreadTarget) {
            return;
        }

        const signature = JSON.stringify({ orders, messages, isActive });
        if (signature === this.#lastStreamSignature) {
            return;
        }
        this.#lastStreamSignature = signature;

        const streamEl = this.hasStreamTarget ? this.streamTarget : null;
        const wasNearBottom = !streamEl || (streamEl.scrollHeight - streamEl.scrollTop - streamEl.clientHeight < 120);

        const items = this.#buildChronologicalFeed(orders, messages);
        const container = this.threadTarget;
        container.replaceChildren();

        if (items.length === 0) {
            const empty = document.createElement('li');
            empty.className = 'swarm-thread__empty';
            empty.textContent = this.#label('noOrders', 'No messages yet. Type /all or /agent to drive your agents.');
            container.appendChild(empty);
            return;
        }

        for (const item of items) {
            if (item.type === 'order_group') {
                container.appendChild(this.#renderOrderGroup(item, isActive));
            } else {
                container.appendChild(this.#renderBoardMessage(item.message));
            }
        }

        if (streamEl && (forceScroll || wasNearBottom)) {
            streamEl.scrollTop = streamEl.scrollHeight;
        }
    }

    #buildChronologicalFeed(orders, messages) {
        // Sort orders oldest -> newest so chat reads naturally top to bottom
        const chronoOrders = [...orders].sort((a, b) => Date.parse(a.created_at || 0) - Date.parse(b.created_at || 0));

        // Group broadcast orders (/all) that share kind + title + instructions within 8s
        const groups = [];
        for (const order of chronoOrders) {
            const ts = Date.parse(order.created_at || 0) || 0;
            const prev = groups[groups.length - 1];
            if (
                prev
                && prev.kind === order.kind
                && prev.title === order.title
                && prev.instructions === order.instructions
                && Math.abs(ts - prev.ts) <= BROADCAST_GROUP_WINDOW_MS
            ) {
                prev.orders.push(order);
            } else {
                groups.push({
                    type: 'order_group',
                    ts,
                    kind: order.kind,
                    kindLabel: order.kind_label || order.kind,
                    title: order.title,
                    instructions: order.instructions,
                    ruleCode: order.rule_code,
                    filesHint: order.files_hint || [],
                    createdAt: order.created_at,
                    createdAtDisplay: order.created_at_display,
                    orders: [order],
                });
            }
        }

        const hasOrders = chronoOrders.length > 0;
        const feed = [...groups];

        for (const msg of messages) {
            // Skip redundant automatic board echoes when orders already represent them
            if (hasOrders && (msg.kind === 'handoff' || msg.kind === 'progress')) {
                continue;
            }
            if (msg.kind === 'brief') {
                continue;
            }
            feed.push({
                type: 'message',
                ts: Date.parse(msg.created_at || 0) || 0,
                message: msg,
            });
        }

        feed.sort((a, b) => a.ts - b.ts);
        return feed;
    }

    #renderOrderGroup(group, isActive) {
        const li = document.createElement('li');
        li.className = 'swarm-turn';

        // 1. User instruction bubble (right-aligned)
        const userRow = document.createElement('div');
        userRow.className = 'swarm-msg swarm-msg--user';

        const bubble = document.createElement('div');
        bubble.className = 'swarm-bubble swarm-bubble--user';

        const head = document.createElement('div');
        head.className = 'swarm-bubble__head';

        const isBroadcast = group.orders.length > 1;
        const firstOrder = group.orders[0];
        const targetBadge = document.createElement('span');
        if (isBroadcast) {
            targetBadge.className = 'pill pill--accent';
            targetBadge.textContent = `/all · ${group.orders.length}`;
        } else if (firstOrder.target_node) {
            targetBadge.className = 'pill pill--accent';
            targetBadge.textContent = `@${firstOrder.target_node.name}`;
        } else {
            targetBadge.className = 'pill';
            targetBadge.textContent = this.#label('anyNode', 'First free');
        }
        head.appendChild(targetBadge);

        const kindTag = document.createElement('span');
        kindTag.className = 'tag';
        kindTag.textContent = group.kindLabel;
        head.appendChild(kindTag);

        if (group.ruleCode) {
            const ruleTag = document.createElement('span');
            ruleTag.className = 'tag tag--accent';
            ruleTag.textContent = group.ruleCode;
            head.appendChild(ruleTag);
        }

        if (group.createdAtDisplay) {
            const time = document.createElement('time');
            time.className = 'swarm-bubble__time num';
            time.dateTime = group.createdAt || '';
            time.textContent = group.createdAtDisplay;
            head.appendChild(time);
        }

        bubble.appendChild(head);

        const showSeparateTitle = group.title
            && group.instructions
            && !group.instructions.startsWith(group.title.replace(/…$/, ''));

        if (showSeparateTitle) {
            const titleEl = document.createElement('p');
            titleEl.className = 'swarm-bubble__title';
            titleEl.textContent = group.title;
            bubble.appendChild(titleEl);
        }

        const bodyEl = document.createElement('p');
        bodyEl.className = 'swarm-bubble__text';
        bodyEl.textContent = group.instructions || group.title;
        bubble.appendChild(bodyEl);

        if (Array.isArray(group.filesHint) && group.filesHint.length > 0) {
            const chips = document.createElement('div');
            chips.className = 'swarm-chip-row';
            for (const f of group.filesHint) {
                const code = document.createElement('code');
                code.className = 'code-chip';
                code.textContent = f;
                chips.appendChild(code);
            }
            bubble.appendChild(chips);
        }

        userRow.appendChild(bubble);
        li.appendChild(userRow);

        // 2. Agent reply bubbles (left-aligned, one per order in group)
        const replies = document.createElement('div');
        replies.className = 'swarm-replies';

        for (const order of group.orders) {
            replies.appendChild(this.#renderAgentReply(order, isActive));
        }

        li.appendChild(replies);
        return li;
    }

    #renderAgentReply(order, isActive) {
        const card = document.createElement('article');
        card.className = `swarm-reply swarm-reply--${order.status}`;
        card.dataset.orderId = order.public_id;

        const head = document.createElement('header');
        head.className = 'swarm-reply__head';

        const identity = document.createElement('div');
        identity.className = 'swarm-reply__identity';

        const agentName = order.claimed_by_node?.name || order.target_node?.name;
        const avatar = document.createElement('span');
        avatar.className = `swarm-reply__avatar swarm-reply__avatar--${order.status}`;
        avatar.textContent = agentName ? agentName.slice(0, 2).toUpperCase() : 'AI';
        identity.appendChild(avatar);

        const author = document.createElement('strong');
        author.className = 'swarm-reply__author';
        author.textContent = agentName ? `@${agentName}` : this.#label('waitingAgent', 'Waiting for an agent…');
        identity.appendChild(author);

        const isPulsing = order.status === 'claimed' || order.status === 'running';
        const statusBadge = document.createElement('span');
        statusBadge.className = `factory-status factory-status--${order.status}${isPulsing ? ' factory-status--pulse' : ''}`;
        statusBadge.textContent = order.status_label || order.status;
        identity.appendChild(statusBadge);

        if (order.branch) {
            const branchChip = document.createElement('code');
            branchChip.className = 'code-chip';
            branchChip.textContent = order.branch;
            identity.appendChild(branchChip);
        }

        const idChip = document.createElement('code');
        idChip.className = 'code-chip swarm-reply__id';
        idChip.textContent = order.public_id;
        identity.appendChild(idChip);

        head.appendChild(identity);

        const actions = document.createElement('div');
        actions.className = 'swarm-reply__actions';

        if (order.direct_url) {
            const copyBtn = document.createElement('button');
            copyBtn.type = 'button';
            copyBtn.className = 'button button--ghost hub-order-btn';
            copyBtn.dataset.action = 'swarm-hub#copyDirectUrl';
            copyBtn.dataset.url = order.direct_url;
            const copyLabel = document.createElement('span');
            copyLabel.dataset.copyLabel = '';
            copyLabel.textContent = this.#label('copyUrl', 'Copy URL');
            copyBtn.appendChild(copyLabel);
            actions.appendChild(copyBtn);
        }

        if (order.status === 'pending' && isActive && order.cancel_url && order.cancel_csrf) {
            const form = document.createElement('form');
            form.method = 'post';
            form.action = order.cancel_url;
            const token = document.createElement('input');
            token.type = 'hidden';
            token.name = '_token';
            token.value = order.cancel_csrf;
            form.appendChild(token);

            const cancelBtn = document.createElement('button');
            cancelBtn.type = 'submit';
            cancelBtn.className = 'button button--ghost hub-order-btn hub-order-btn--danger';
            cancelBtn.textContent = this.#label('cancel', 'Cancel');
            form.appendChild(cancelBtn);
            actions.appendChild(form);
        }

        if (actions.childElementCount > 0) {
            head.appendChild(actions);
        }

        card.appendChild(head);

        if (order.result_summary) {
            const summary = document.createElement('p');
            summary.className = 'swarm-reply__summary';
            summary.textContent = order.result_summary;
            card.appendChild(summary);
        } else if (order.status === 'pending' || order.status === 'claimed' || order.status === 'running') {
            const waiting = document.createElement('p');
            waiting.className = 'swarm-reply__waiting';
            waiting.textContent = order.status === 'pending'
                ? this.#label('pendingHint', 'Queued for execution…')
                : this.#label('runningHint', 'Agent is working on this instruction…');
            card.appendChild(waiting);
        }

        if (Array.isArray(order.files_touched) && order.files_touched.length > 0) {
            const filesWrap = document.createElement('div');
            filesWrap.className = 'swarm-chip-row';

            const filesLabel = document.createElement('span');
            filesLabel.className = 'row__sub';
            filesLabel.textContent = `${this.#label('filesTouched', 'Files')}:`;
            filesWrap.appendChild(filesLabel);

            for (const file of order.files_touched) {
                const code = document.createElement('code');
                code.className = 'code-chip';
                code.textContent = file;
                filesWrap.appendChild(code);
            }
            card.appendChild(filesWrap);
        }

        if (order.result_output) {
            const cmWrap = document.createElement('div');
            cmWrap.className = 'swarm-cm';
            cmWrap.dataset.controller = 'code-viewer';
            cmWrap.dataset.codeViewerCopiedLabelValue = this.#label('copied', 'Copied');
            cmWrap.dataset.codeViewerExpandLabelValue = this.#label('expand', 'Expand');
            cmWrap.dataset.codeViewerCollapseLabelValue = this.#label('collapse', 'Collapse');

            const bar = document.createElement('div');
            bar.className = 'swarm-cm__bar';

            const barTitle = document.createElement('span');
            barTitle.className = 'swarm-cm__label';
            barTitle.textContent = this.#label('outputBadge', 'Response · CodeMirror');
            bar.appendChild(barTitle);

            const barBtns = document.createElement('div');
            barBtns.className = 'swarm-cm__actions';

            const expandBtn = document.createElement('button');
            expandBtn.type = 'button';
            expandBtn.className = 'button button--ghost swarm-cm__btn';
            expandBtn.dataset.action = 'code-viewer#toggleExpand';
            const expandSpan = document.createElement('span');
            expandSpan.dataset.codeViewerTarget = 'expandLabel';
            expandSpan.textContent = this.#label('expand', 'Expand');
            expandBtn.appendChild(expandSpan);
            barBtns.appendChild(expandBtn);

            const copyBtn = document.createElement('button');
            copyBtn.type = 'button';
            copyBtn.className = 'button button--ghost swarm-cm__btn';
            copyBtn.dataset.action = 'code-viewer#copy';
            const copySpan = document.createElement('span');
            copySpan.dataset.codeViewerTarget = 'copyLabel';
            copySpan.textContent = this.#label('copyOutput', 'Copy');
            copyBtn.appendChild(copySpan);
            barBtns.appendChild(copyBtn);

            bar.appendChild(barBtns);
            cmWrap.appendChild(bar);

            const mount = document.createElement('div');
            mount.className = 'swarm-cm__mount';
            mount.dataset.codeViewerTarget = 'mount';
            cmWrap.appendChild(mount);

            const pre = document.createElement('pre');
            pre.className = 'swarm-cm__fallback';
            pre.dataset.codeViewerTarget = 'source';
            pre.textContent = order.result_output;
            cmWrap.appendChild(pre);

            card.appendChild(cmWrap);
        }

        return card;
    }

    #renderBoardMessage(msg) {
        const li = document.createElement('li');
        if (msg.id) {
            li.dataset.messageId = String(msg.id);
        }

        if (msg.kind === 'system') {
            li.className = 'swarm-system-event';
            const pill = document.createElement('span');
            pill.className = 'swarm-system-event__pill';
            pill.textContent = `${msg.author} · ${msg.body}`;
            li.appendChild(pill);
            if (msg.created_at_display) {
                const time = document.createElement('time');
                time.className = 'swarm-system-event__time num';
                time.dateTime = msg.created_at || '';
                time.textContent = msg.created_at_display;
                li.appendChild(time);
            }
            return li;
        }

        const isHuman = msg.kind === 'human';
        li.className = `swarm-msg ${isHuman ? 'swarm-msg--user' : 'swarm-msg--board'}`;

        const bubble = document.createElement('div');
        bubble.className = `swarm-bubble ${isHuman ? 'swarm-bubble--user' : 'swarm-bubble--board'}`;

        const head = document.createElement('div');
        head.className = 'swarm-bubble__head';

        const author = document.createElement('strong');
        author.textContent = msg.author;
        head.appendChild(author);

        const tag = document.createElement('span');
        tag.className = 'tag';
        tag.textContent = msg.kind_label || msg.kind;
        head.appendChild(tag);

        if (msg.created_at_display) {
            const time = document.createElement('time');
            time.className = 'swarm-bubble__time num';
            time.dateTime = msg.created_at || '';
            time.textContent = msg.created_at_display;
            head.appendChild(time);
        }

        bubble.appendChild(head);

        const body = document.createElement('p');
        body.className = 'swarm-bubble__text';
        body.textContent = msg.body;
        bubble.appendChild(body);

        li.appendChild(bubble);
        return li;
    }

    #setTargetsText(targets, value) {
        for (const el of targets) {
            el.textContent = value;
        }
    }

    #label(key, fallback) {
        return (this.labelsValue && this.labelsValue[key]) || fallback;
    }
}
