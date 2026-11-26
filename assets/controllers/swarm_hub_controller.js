import { Controller } from '@hotwired/stimulus';

const POLL_INTERVAL_MS = 2500;

export default class extends Controller {
    static values = {
        stateUrl: String,
        active: { type: Boolean, default: false },
        labels: { type: Object, default: {} },
    };

    static targets = [
        'statusBadge',
        'liveBadge',
        'nodesOnline',
        'ordersPending',
        'ordersRunning',
        'ordersCompleted',
        'ordersTotal',
        'messagesCount',
        'nodesList',
        'ordersList',
        'messagesList',
        'targetSelect',
        'instructionsInput',
        'tab',
        'tabPanel',
    ];

    #timer = null;
    #inFlight = false;
    #lastNodesSignature = null;
    #lastOrdersSignature = null;
    #lastTargetOptionsSignature = null;

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
        document.addEventListener('visibilitychange', this.#onVisibilityChange);
        if (this.activeValue && document.visibilityState === 'visible') {
            this.#schedule();
        }
    }

    disconnect() {
        this.#clearTimer();
        document.removeEventListener('visibilitychange', this.#onVisibilityChange);
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
        if (!nodeId) {
            return;
        }

        if (this.hasTargetSelectTarget) {
            const exists = Array.from(this.targetSelectTarget.options).some((opt) => opt.value === nodeId);
            if (!exists) {
                const option = document.createElement('option');
                option.value = nodeId;
                option.textContent = `@${event.currentTarget.dataset.nodeName || nodeId}`;
                this.targetSelectTarget.appendChild(option);
            }
            this.targetSelectTarget.value = nodeId;
        }

        if (this.hasInstructionsInputTarget) {
            this.instructionsInputTarget.scrollIntoView({ behavior: 'smooth', block: 'center' });
            this.instructionsInputTarget.focus();
        }
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

    async #poll() {
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
            this.#applyState(data);

            if (data.is_active === false) {
                this.activeValue = false;
                if (this.hasLiveBadgeTarget) {
                    this.liveBadgeTarget.hidden = true;
                }
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

    #applyState(data) {
        const counts = data.counts || {};
        this.#setTargetsText(this.nodesOnlineTargets, String(counts.nodes_online ?? 0));
        this.#setTargetsText(this.ordersPendingTargets, String(counts.orders_pending ?? 0));
        this.#setTargetsText(this.ordersRunningTargets, String(counts.orders_running ?? 0));
        this.#setTargetsText(this.ordersCompletedTargets, String(counts.orders_completed ?? 0));

        const orders = Array.isArray(data.orders) ? data.orders : [];
        const nodes = Array.isArray(data.nodes) ? data.nodes : [];
        const messages = Array.isArray(data.messages) ? data.messages : [];

        this.#setTargetsText(this.ordersTotalTargets, String(orders.length));
        this.#setTargetsText(this.messagesCountTargets, String(messages.length));

        if (data.status && data.status_label) {
            for (const badge of this.statusBadgeTargets) {
                badge.textContent = data.status_label;
                badge.className = `factory-status factory-status--${data.status}${data.is_active && ['planning', 'running', 'reviewing'].includes(data.status) ? ' factory-status--pulse' : ''}`;
            }
        }

        this.#updateNodes(nodes, Boolean(data.is_active));
        this.#updateTargetSelect(nodes);
        this.#updateOrders(orders, Boolean(data.is_active));
        this.#updateMessages(messages);
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
            const parts = [node.role];
            if (node.client) {
                parts.push(node.client);
            }
            if (node.branch) {
                parts.push(node.branch);
            }
            if (node.last_heartbeat_display) {
                parts.push(node.last_heartbeat_display);
            }
            meta.textContent = parts.filter(Boolean).join(' · ');
            main.appendChild(meta);

            item.appendChild(main);

            if (isActive) {
                const targetBtn = document.createElement('button');
                targetBtn.type = 'button';
                targetBtn.className = 'button button--ghost hub-node-target-btn';
                targetBtn.dataset.action = 'swarm-hub#selectTarget';
                targetBtn.dataset.nodeId = node.public_id;
                targetBtn.dataset.nodeName = node.name;
                targetBtn.textContent = this.#label('target', 'Target');
                item.appendChild(targetBtn);
            }

            container.appendChild(item);
        }
    }

    #updateTargetSelect(nodes) {
        if (!this.hasTargetSelectTarget) {
            return;
        }

        const signature = JSON.stringify(nodes.map((n) => [n.public_id, n.name, n.client, n.is_online]));
        if (signature === this.#lastTargetOptionsSignature) {
            return;
        }
        this.#lastTargetOptionsSignature = signature;

        const select = this.targetSelectTarget;
        const previousValue = select.value;

        select.replaceChildren();

        const anyOpt = document.createElement('option');
        anyOpt.value = 'any';
        anyOpt.textContent = this.#label('anyNode', 'First free node');
        select.appendChild(anyOpt);

        const allOpt = document.createElement('option');
        allOpt.value = 'all';
        allOpt.textContent = this.#label('allNodes', 'All connected nodes');
        select.appendChild(allOpt);

        for (const node of nodes) {
            const opt = document.createElement('option');
            opt.value = node.public_id;
            const suffix = node.client ? ` (${node.client})` : '';
            opt.textContent = `@${node.name}${suffix}`;
            select.appendChild(opt);
        }

        if (Array.from(select.options).some((opt) => opt.value === previousValue)) {
            select.value = previousValue;
        }
    }

    #updateOrders(orders, isActive) {
        if (!this.hasOrdersListTarget) {
            return;
        }

        const signature = JSON.stringify({ orders, isActive });
        if (signature === this.#lastOrdersSignature) {
            return;
        }
        this.#lastOrdersSignature = signature;

        const container = this.ordersListTarget;
        const openOutputs = new Set(
            Array.from(container.querySelectorAll('details[data-order-output][open]'))
                .map((el) => el.dataset.orderOutput)
                .filter(Boolean),
        );

        container.replaceChildren();

        if (orders.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'help-text hub-orders__empty';
            empty.textContent = this.#label('noOrders', 'No order dispatched yet.');
            container.appendChild(empty);
            return;
        }

        for (const order of orders) {
            const card = document.createElement('article');
            card.className = `hub-order-card hub-order-card--${order.status}`;
            card.dataset.orderId = order.public_id;

            const head = document.createElement('div');
            head.className = 'hub-order-card__head';

            const badges = document.createElement('div');
            badges.className = 'hub-order-card__badges';

            const kindTag = document.createElement('span');
            kindTag.className = 'tag';
            kindTag.textContent = order.kind_label || order.kind;
            badges.appendChild(kindTag);

            const statusBadge = document.createElement('span');
            const isPulsing = order.status === 'claimed' || order.status === 'running';
            statusBadge.className = `factory-status factory-status--${order.status}${isPulsing ? ' factory-status--pulse' : ''}`;
            statusBadge.textContent = order.status_label || order.status;
            badges.appendChild(statusBadge);

            const idCode = document.createElement('code');
            idCode.className = 'code-chip';
            idCode.textContent = order.public_id;
            badges.appendChild(idCode);

            if (order.created_at_display) {
                const time = document.createElement('time');
                time.className = 'row__sub num';
                time.dateTime = order.created_at || '';
                time.textContent = order.created_at_display;
                badges.appendChild(time);
            }

            head.appendChild(badges);

            const actions = document.createElement('div');
            actions.className = 'hub-order-card__actions';

            if (order.direct_url) {
                const copyBtn = document.createElement('button');
                copyBtn.type = 'button';
                copyBtn.className = 'button button--ghost hub-order-btn';
                copyBtn.dataset.action = 'swarm-hub#copyDirectUrl';
                copyBtn.dataset.url = order.direct_url;
                const copyLabel = document.createElement('span');
                copyLabel.dataset.copyLabel = '';
                copyLabel.textContent = this.#label('copyUrl', 'Copy task URL');
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

            const title = document.createElement('h3');
            title.className = 'hub-order-card__title';
            title.textContent = order.title;
            card.appendChild(title);

            const instructions = document.createElement('p');
            instructions.className = 'hub-order-card__instructions';
            instructions.textContent = order.instructions;
            card.appendChild(instructions);

            const metaChips = document.createElement('div');
            metaChips.className = 'factory-context-chips';

            if (order.claimed_by_node) {
                const chip = document.createElement('span');
                chip.className = 'tag tag--accent';
                chip.textContent = `${this.#label('assignedTo', 'Claimed by')} @${order.claimed_by_node.name}`;
                metaChips.appendChild(chip);
            } else if (order.target_node) {
                const chip = document.createElement('span');
                chip.className = 'tag';
                chip.textContent = `${this.#label('targetedAt', 'Target:')} @${order.target_node.name}`;
                metaChips.appendChild(chip);
            }

            if (order.rule_code) {
                const chip = document.createElement('span');
                chip.className = 'tag tag--accent';
                chip.textContent = order.rule_code;
                metaChips.appendChild(chip);
            }

            if (order.branch) {
                const chip = document.createElement('code');
                chip.className = 'code-chip';
                chip.textContent = order.branch;
                metaChips.appendChild(chip);
            }

            for (const hint of order.files_hint || []) {
                const chip = document.createElement('code');
                chip.className = 'code-chip';
                chip.textContent = hint;
                metaChips.appendChild(chip);
            }

            if (metaChips.childElementCount > 0) {
                card.appendChild(metaChips);
            }

            if (order.result_summary || (order.files_touched && order.files_touched.length > 0) || order.result_output) {
                const result = document.createElement('div');
                result.className = `hub-order-card__result${order.status === 'failed' ? ' hub-order-card__result--failed' : ''}`;

                if (order.result_summary) {
                    const summary = document.createElement('p');
                    summary.className = 'hub-order-card__summary';
                    summary.textContent = order.result_summary;
                    result.appendChild(summary);
                }

                if (Array.isArray(order.files_touched) && order.files_touched.length > 0) {
                    const filesWrap = document.createElement('div');
                    filesWrap.className = 'hub-order-card__files';

                    const filesLabel = document.createElement('span');
                    filesLabel.className = 'row__sub';
                    filesLabel.textContent = `${this.#label('filesTouched', 'Files touched')}:`;
                    filesWrap.appendChild(filesLabel);

                    for (const file of order.files_touched) {
                        const code = document.createElement('code');
                        code.className = 'code-chip';
                        code.textContent = file;
                        filesWrap.appendChild(code);
                    }
                    result.appendChild(filesWrap);
                }

                if (order.result_output) {
                    const details = document.createElement('details');
                    details.className = 'factory-timeline__payload';
                    details.dataset.orderOutput = order.public_id;
                    if (openOutputs.has(order.public_id)) {
                        details.open = true;
                    }

                    const summaryEl = document.createElement('summary');
                    summaryEl.textContent = this.#label('outputToggle', 'View full output');
                    details.appendChild(summaryEl);

                    const pre = document.createElement('pre');
                    pre.className = 'factory-code';
                    const code = document.createElement('code');
                    code.textContent = order.result_output;
                    pre.appendChild(code);
                    details.appendChild(pre);

                    result.appendChild(details);
                }

                card.appendChild(result);
            }

            container.appendChild(card);
        }
    }

    #updateMessages(messages) {
        if (!this.hasMessagesListTarget) {
            return;
        }

        const list = this.messagesListTarget;
        const existingIds = new Set(
            Array.from(list.querySelectorAll('[data-message-id]')).map((el) => el.dataset.messageId),
        );

        for (const msg of messages) {
            const idStr = String(msg.id ?? '');
            if (idStr && existingIds.has(idStr)) {
                continue;
            }

            const li = document.createElement('li');
            li.className = `factory-timeline__item swarm-msg--${msg.kind}`;
            if (idStr) {
                li.dataset.messageId = idStr;
            }

            const head = document.createElement('div');
            head.className = 'factory-timeline__head';

            const authorWrap = document.createElement('span');
            const strong = document.createElement('strong');
            strong.textContent = msg.author;
            authorWrap.appendChild(strong);
            authorWrap.appendChild(document.createTextNode(' '));

            const tag = document.createElement('span');
            tag.className = 'tag';
            tag.textContent = msg.kind_label || msg.kind;
            authorWrap.appendChild(tag);

            head.appendChild(authorWrap);

            const time = document.createElement('time');
            time.className = 'row__sub';
            time.dateTime = msg.created_at || '';
            time.textContent = msg.created_at_display || '';
            head.appendChild(time);

            li.appendChild(head);

            const body = document.createElement('p');
            body.className = 'factory-timeline__msg';
            body.textContent = msg.body;
            li.appendChild(body);

            list.appendChild(li);
        }
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
