import { Controller } from '@hotwired/stimulus';
import { trans } from '../translator.js';
import cytoscape from 'cytoscape';
import { resolveColor } from '../color.js';

const HUBS = { code_file: ['--color-hub-file', 'round-rectangle'], business_rule: ['--color-hub-rule', 'diamond'], document: ['--color-hub-document', 'triangle'], project: ['--color-hub-project', 'hexagon'], none: ['--color-faint', 'ellipse'] };

// Memories orbit what they are about: hubs are sized by the number of memories attached to them.
export default class extends Controller {
    static targets = ['canvas', 'empty', 'detail', 'count'];
    static values = { url: String };

    async connect() {
        const response = await fetch(this.urlValue, { headers: { Accept: 'application/json' } });
        this.galaxy = await response.json();
        this.render();
    }

    disconnect() {
        this.graph?.destroy();
    }

    render() {
        if (!this.galaxy) {
            return;
        }
        const { nodes, edges } = this.galaxy;
        this.graph?.destroy();
        this.emptyTarget.hidden = nodes.length > 0;
        this.countTarget.textContent = trans('js.galaxy.count', { memories: nodes.filter((node) => node.kind === 'memory').length, subjects: nodes.filter((node) => node.kind !== 'memory').length });
        if (nodes.length === 0) {
            return;
        }

        const color = (token) => resolveColor(this.element, token);
        const mono = getComputedStyle(this.element).getPropertyValue('--font-mono').trim();
        const hubLabels = nodes.filter((node) => node.kind !== 'memory').length <= 40;

        this.graph = cytoscape({
            container: this.canvasTarget,
            elements: [
                ...nodes.map((node) => ({ data: { ...node, short: node.label.length > 42 ? `…${node.label.slice(-40)}` : node.label }, classes: node.kind === 'memory' ? 'memory' : `hub ${node.kind}` })),
                ...edges.map((edge) => ({ data: { ...edge, id: `${edge.source}->${edge.target}` } })),
            ],
            style: [
                { selector: 'node.memory', style: { 'background-color': color('--color-series'), width: 9, height: 9, 'border-width': 2, 'border-color': color('--color-card') } },
                {
                    selector: 'node.hub',
                    style: {
                        width: 'mapData(weight, 1, 12, 18, 44)',
                        height: 'mapData(weight, 1, 12, 18, 44)',
                        label: hubLabels ? 'data(short)' : '',
                        color: color('--color-ink-2'),
                        'font-family': mono,
                        'font-size': 9,
                        'text-valign': 'bottom',
                        'text-margin-y': 5,
                        'text-outline-color': color('--color-card'),
                        'text-outline-width': 2,
                    },
                },
                ...Object.entries(HUBS).map(([kind, [token, shape]]) => ({ selector: `node.${kind}`, style: { 'background-color': color(token), shape } })),
                { selector: 'edge', style: { width: 1, 'line-color': color('--color-rule-2'), 'curve-style': 'straight' } },
                { selector: 'node.dim', style: { opacity: 0.18 } },
                { selector: 'edge.dim', style: { opacity: 0.1 } },
                { selector: 'node.hub.focus', style: { label: 'data(short)', 'z-index': 20 } },
                { selector: 'edge.focus', style: { 'line-color': color('--color-series'), width: 1.5 } },
            ],
            layout: { name: 'cose', animate: false, nodeRepulsion: (node) => (node.hasClass('hub') ? 60000 : 5000), idealEdgeLength: () => 46, gravity: 0.6, numIter: 1500, padding: 28 },
            minZoom: 0.2,
            maxZoom: 2.5,
        });

        if (this.graph.zoom() > 1) {
            this.graph.zoom(1);
            this.graph.center();
        }

        this.graph.on('mouseover', 'node', (event) => {
            const neighbourhood = event.target.closedNeighborhood();
            this.graph.elements().not(neighbourhood).addClass('dim');
            neighbourhood.addClass('focus');
            this.canvasTarget.style.cursor = event.target.data('url') ? 'pointer' : 'default';
            this.detailTarget.textContent = event.target.data('label');
        });
        this.graph.on('mouseout', 'node', () => {
            this.graph.elements().removeClass('dim focus');
            this.canvasTarget.style.cursor = '';
            this.detailTarget.textContent = trans('js.galaxy.hint');
        });
        this.graph.on('tap', 'node', (event) => {
            if (event.target.data('url')) {
                window.location.assign(event.target.data('url'));
            }
        });
    }
}
