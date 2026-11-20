import { Controller } from '@hotwired/stimulus';
import { trans } from '../translator.js';
import cytoscape from 'cytoscape';
import { resolveColor } from '../color.js';

const KINDS = ['code_file', 'business_rule', 'document', 'project', 'none'];
const KIND_TOKENS = { code_file: '--color-hub-file', business_rule: '--color-hub-rule', document: '--color-hub-document', project: '--color-hub-project', none: '--color-faint' };
const TYPE_TOKENS = { decision: '--color-accent', convention: '--color-hub-file', task_learning: '--color-success', preference: '--color-warning', fact: '--color-faint' };
const CARD = { width: 236, height: 62 };
const GAP = { column: 268, level: 128, stack: 92 };
const OPEN_ALL = { hubs: 6, memories: 12 };
const OPEN_KINDS = 8;
const MIN_FIT_ZOOM = 0.55;

function clip(text, length, fromEnd = false) {
    if (text.length <= length) {
        return text;
    }

    return fromEnd ? `…${text.slice(-(length - 1))}` : `${text.slice(0, length - 1)}…`;
}

function icon(color) {
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="${color}"/><stop offset="1" stop-color="${color}" stop-opacity="0.45"/></linearGradient></defs><rect width="28" height="28" rx="8" fill="url(#g)"/></svg>`;

    return `data:image/svg+xml;utf8,${encodeURIComponent(svg)}`;
}

// The memory graph as a tree of cards: scope, kinds of subject, subjects, then the memories about each subject stacked below it.
// A memory about several subjects is drawn under each of them. A graph too big to read at once opens folded, one level per click.
export default class extends Controller {
    static targets = ['canvas', 'empty', 'detail', 'count', 'zoom'];
    static values = { url: String, labels: Object };

    async connect() {
        const response = await fetch(this.urlValue, { headers: { Accept: 'application/json' } });
        const { nodes, edges } = await response.json();
        const memories = new Map(nodes.filter((node) => node.kind === 'memory').map((node) => [node.id, node]));
        this.hubs = nodes.filter((node) => node.kind !== 'memory').sort((a, b) => b.weight - a.weight || a.label.localeCompare(b.label));
        this.children = new Map(this.hubs.map((hub) => [hub.id, []]));
        edges.forEach((edge) => this.children.get(edge.target)?.push(memories.get(edge.source)));
        this.kinds = KINDS.filter((kind) => this.hubs.some((hub) => hub.kind === kind));
        const small = this.hubs.length <= OPEN_ALL.hubs && memories.size <= OPEN_ALL.memories;
        this.open = new Set([...(this.hubs.length <= OPEN_KINDS ? this.kinds : []), ...(small ? this.hubs.map((hub) => hub.id) : [])]);
        this.memoryCount = memories.size;
        this.render();
    }

    disconnect() {
        this.graph?.destroy();
    }

    render() {
        if (!this.hubs) {
            return;
        }
        this.graph?.destroy();
        this.emptyTarget.hidden = this.hubs.length > 0;
        this.countTarget.textContent = trans('js.galaxy.count', { memories: this.memoryCount, subjects: this.hubs.length });
        if (this.hubs.length === 0) {
            return;
        }

        this.colors = Object.fromEntries(['--color-card', '--color-rule', '--color-rule-2', '--color-ink', '--color-accent', '--color-series', ...Object.values(KIND_TOKENS), ...Object.values(TYPE_TOKENS)].map((token) => [token, resolveColor(this.element, token)]));
        const color = (token) => this.colors[token];
        const font = getComputedStyle(this.element).getPropertyValue('--font-body').trim();

        this.measure = document.createElement('canvas').getContext('2d');
        this.measure.font = `11px ${font}`;

        this.graph = cytoscape({
            container: this.canvasTarget,
            elements: this.elements(),
            style: [
                {
                    selector: 'node',
                    style: {
                        shape: 'round-rectangle',
                        width: CARD.width,
                        height: CARD.height,
                        'background-color': color('--color-card'),
                        'border-width': 1,
                        'border-color': color('--color-rule'),
                        label: 'data(text)',
                        color: color('--color-ink'),
                        'font-family': font,
                        'font-size': 11,
                        'line-height': 1.4,
                        'text-wrap': 'wrap',
                        'text-max-width': CARD.width - 76,
                        'text-valign': 'center',
                        'text-halign': 'center',
                        'text-justification': 'left',
                        'text-margin-x': 'data(margin)',
                        'background-image': 'data(icon)',
                        'background-width': 28,
                        'background-height': 28,
                        'background-fit': 'none',
                        'background-clip': 'none',
                        'background-position-x': '14px',
                        'background-position-y': '50%',
                    },
                },
                { selector: 'node.folded', style: { 'border-style': 'dashed', 'border-color': color('--color-rule-2') } },
                { selector: 'node.root', style: { 'border-width': 1.5, 'border-color': color('--color-ink') } },
                { selector: 'node.hover', style: { 'border-color': color('--color-accent'), 'border-width': 1.5, 'border-style': 'solid' } },
                {
                    selector: 'edge',
                    style: {
                        width: 1.25,
                        'line-color': color('--color-rule-2'),
                        'curve-style': 'taxi',
                        'taxi-direction': 'downward',
                        'taxi-turn': '50%',
                        'taxi-radius': 8,
                        'target-arrow-shape': 'triangle',
                        'target-arrow-color': color('--color-rule-2'),
                        'arrow-scale': 0.7,
                    },
                },
                { selector: 'edge.stack', style: { 'curve-style': 'straight' } },
            ],
            layout: { name: 'preset', padding: 48 },
            minZoom: 0.15,
            maxZoom: 2,
            autoungrabify: true,
        });

        this.graph.on('viewport', () => this.viewport());
        this.graph.on('mouseover', 'node', (event) => {
            event.target.addClass('hover');
            this.canvasTarget.style.cursor = 'pointer';
            this.describe(event.target);
        });
        this.graph.on('mouseout', 'node', (event) => {
            event.target.removeClass('hover');
            this.canvasTarget.style.cursor = '';
        });
        this.graph.on('tap', 'node', (event) => this.activate(event.target));
        this.fit();
    }

    elements() {
        const color = (token) => this.colors[token];
        const labels = this.labelsValue;
        const nodes = [];
        const edges = [];
        let column = 0;
        const kindX = [];

        this.kinds.forEach((kind) => {
            const hubs = this.hubs.filter((hub) => hub.kind === kind);
            const kindOpen = this.open.has(kind);
            const hubX = [];
            if (kindOpen) {
                hubs.forEach((hub) => {
                    const x = column++ * GAP.column;
                    const hubOpen = this.open.has(hub.id);
                    const memories = this.children.get(hub.id);
                    hubX.push(x);
                    nodes.push({
                        group: 'nodes',
                        data: { id: hub.id, role: 'hub', url: hub.url, full: hub.label, icon: icon(color(KIND_TOKENS[kind])), text: `${labels.hubs[kind]} · ${trans('js.galaxy.memories', { count: memories.length })}\n${clip(hub.label, 26, kind === 'code_file')}` },
                        position: { x, y: GAP.level * 2 },
                        classes: hubOpen ? '' : 'folded',
                    });
                    edges.push({ group: 'edges', data: { id: `${kind}->${hub.id}`, source: kind, target: hub.id } });
                    if (hubOpen) {
                        memories.forEach((memory, index) => {
                            const id = `${hub.id}/${memory.id}`;
                            nodes.push({
                                group: 'nodes',
                                data: { id, role: 'memory', url: memory.url, full: memory.label, icon: icon(color(TYPE_TOKENS[memory.type] ?? '--color-faint')), text: `${labels.types[memory.type] ?? memory.type}\n${clip(memory.label, 56)}` },
                                position: { x, y: GAP.level * 3 + index * GAP.stack },
                            });
                            const previous = index === 0 ? hub.id : `${hub.id}/${memories[index - 1].id}`;
                            edges.push({ group: 'edges', data: { id: `${previous}->${id}`, source: previous, target: id }, classes: 'stack' });
                        });
                    }
                });
            } else {
                hubX.push(column++ * GAP.column);
            }
            const x = (Math.min(...hubX) + Math.max(...hubX)) / 2;
            kindX.push(x);
            nodes.push({
                group: 'nodes',
                data: { id: kind, role: 'kind', icon: icon(color(KIND_TOKENS[kind])), full: labels.kinds[kind], text: `${trans('js.galaxy.subjects', { count: hubs.length })}\n${labels.kinds[kind]}` },
                position: { x, y: GAP.level },
                classes: kindOpen ? '' : 'folded',
            });
            edges.push({ group: 'edges', data: { id: `root->${kind}`, source: 'root', target: kind } });
        });

        nodes.push({
            group: 'nodes',
            data: { id: 'root', role: 'root', full: labels.root, icon: icon(color('--color-accent')), text: `${labels.overline}\n${clip(labels.root, 30)}` },
            position: { x: (Math.min(...kindX) + Math.max(...kindX)) / 2, y: 0 },
            classes: 'root',
        });

        nodes.forEach((node) => {
            node.data.margin = this.margin(node.data.text);
        });

        return [...nodes, ...edges];
    }

    // Cytoscape centres a label on its node: the offset puts the text block's left edge right after the icon.
    margin(text) {
        const max = CARD.width - 76;
        const width = Math.min(max, Math.max(...text.split('\n').map((line) => this.measure.measureText(line).width)));

        return -CARD.width / 2 + 54 + width / 2;
    }

    activate(node) {
        const role = node.data('role');
        if (role === 'memory') {
            window.location.assign(node.data('url'));

            return;
        }
        if (role === 'root') {
            return;
        }
        const id = node.id();
        if (this.open.has(id)) {
            this.open.delete(id);
        } else {
            this.open.add(id);
        }
        this.rebuild(node.id());
        this.describe(this.graph.getElementById(id));
    }

    rebuild(anchor) {
        const before = this.graph.getElementById(anchor).renderedPosition();
        this.graph.elements().remove();
        this.graph.add(this.elements());
        const after = this.graph.getElementById(anchor).renderedPosition();
        this.graph.panBy({ x: before.x - after.x, y: before.y - after.y });
    }

    describe(node) {
        const url = node.data('url');
        const text = document.createTextNode(node.data('full'));
        if (!url || node.data('role') === 'memory') {
            this.detailTarget.replaceChildren(text);

            return;
        }
        const link = Object.assign(document.createElement('a'), { href: url, className: 'link', textContent: this.labelsValue.open });
        this.detailTarget.replaceChildren(text, ' · ', link);
    }

    viewport() {
        const zoom = this.graph.zoom();
        const pan = this.graph.pan();
        let size = 18 * zoom;
        while (size < 12) {
            size *= 2;
        }
        this.canvasTarget.style.backgroundSize = `${size}px ${size}px`;
        this.canvasTarget.style.backgroundPosition = `${pan.x}px ${pan.y}px`;
        this.zoomTarget.textContent = `${Math.round(zoom * 100)} %`;
    }

    zoomBy(factor) {
        const { width, height } = this.canvasTarget.getBoundingClientRect();
        this.graph.zoom({ level: this.graph.zoom() * factor, renderedPosition: { x: width / 2, y: height / 2 } });
    }

    zoomIn() {
        this.zoomBy(1.25);
    }

    zoomOut() {
        this.zoomBy(0.8);
    }

    fit() {
        this.graph.fit(undefined, 48);
        const zoom = this.graph.zoom();
        if (zoom > 1 || zoom < MIN_FIT_ZOOM) {
            const level = zoom > 1 ? 1 : MIN_FIT_ZOOM;
            this.graph.zoom(level);
            this.graph.pan({ x: this.canvasTarget.clientWidth / 2 - this.graph.getElementById('root').position('x') * level, y: 48 + (CARD.height / 2) * level });
        }
        this.viewport();
    }

    expand() {
        this.hubs.forEach((hub) => this.open.add(hub.id));
        this.kinds.forEach((kind) => this.open.add(kind));
        this.rebuild('root');
        this.fit();
    }

    collapse() {
        this.hubs.forEach((hub) => this.open.delete(hub.id));
        this.rebuild('root');
        this.fit();
    }
}
