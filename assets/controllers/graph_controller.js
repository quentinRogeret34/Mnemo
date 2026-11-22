import { Controller } from '@hotwired/stimulus';
import cytoscape from 'cytoscape';
import { resolveColor } from '../color.js';

export default class extends Controller {
    static targets = ['canvas', 'empty'];
    static values = { url: String, fileUrl: String, depth: { type: Number, default: 1 } };

    connect() {
        this.load();
        this.observer = new ResizeObserver(() => this.graph?.resize());
        this.observer.observe(this.canvasTarget);
    }

    disconnect() {
        this.observer?.disconnect();
        this.graph?.destroy();
    }

    setDepth(event) {
        this.depthValue = Number(event.target.value);
        this.load();
    }

    async load() {
        const response = await fetch(`${this.urlValue}?depth=${this.depthValue}`, { headers: { Accept: 'application/json' } });
        const { nodes, edges } = await response.json();

        this.graph?.destroy();
        this.emptyTarget.hidden = edges.length > 0;
        if (edges.length === 0) {
            return;
        }

        const color = (token) => resolveColor(this.element, token);
        const mono = getComputedStyle(this.element).getPropertyValue('--font-mono').trim();

        this.graph = cytoscape({
            container: this.canvasTarget,
            elements: [
                ...nodes.map((node) => ({ data: node, classes: node.center ? 'center' : '' })),
                ...edges.map((edge) => ({
                    data: { ...edge, id: `${edge.source}->${edge.target}`, label: edge.relations.join(', ') },
                    classes: edge.inferred ? 'inferred' : '',
                })),
            ],
            style: [
                {
                    selector: 'node',
                    style: {
                        'background-color': color('--color-graphite-muted'),
                        width: 10,
                        height: 10,
                        // Past a dozen files the labels overlap; they then only show on the centre and on hover.
                        label: nodes.length <= 12 ? 'data(label)' : '',
                        color: color('--color-graphite-ink'),
                        'font-family': mono,
                        'font-size': 9,
                        'text-valign': 'bottom',
                        'text-margin-y': 4,
                        'text-outline-color': color('--color-graphite'),
                        'text-outline-width': 2,
                    },
                },
                {
                    selector: 'node.center',
                    style: { 'background-color': color('--color-accent'), 'border-width': 2, 'border-color': color('--color-graphite-ink'), width: 16, height: 16, label: 'data(label)', 'font-size': 10, 'font-weight': 600, 'z-index': 10 },
                },
                {
                    selector: 'node:active, node.hover',
                    style: { 'background-color': color('--color-graphite-ink'), label: 'data(label)', 'overlay-opacity': 0, 'z-index': 20 },
                },
                {
                    selector: 'edge',
                    style: {
                        width: 'mapData(count, 1, 20, 1, 3.5)',
                        'line-color': color('--color-graphite-rule'),
                        'target-arrow-color': color('--color-graphite-rule'),
                        'target-arrow-shape': 'triangle',
                        'arrow-scale': 0.7,
                        'curve-style': 'bezier',
                    },
                },
                { selector: 'edge.inferred', style: { 'line-style': 'dashed' } },
                {
                    selector: 'edge.hover',
                    style: {
                        'line-color': color('--color-graphite-muted'),
                        'target-arrow-color': color('--color-graphite-muted'),
                        label: 'data(label)',
                        color: color('--color-graphite-ink'),
                        'font-family': mono,
                        'font-size': 9,
                        'text-rotation': 'autorotate',
                        'text-outline-color': color('--color-graphite'),
                        'text-outline-width': 2,
                    },
                },
            ],
            layout: this.depthValue === 1
                ? { name: 'concentric', animate: false, concentric: (node) => (node.data('center') ? 2 : 1), levelWidth: () => 1, minNodeSpacing: 70, padding: 28 }
                : { name: 'cose', animate: false, nodeRepulsion: () => 9000, idealEdgeLength: () => 80, padding: 24 },
            minZoom: 0.3,
            maxZoom: 1.4,
        });

        this.graph.on('mouseover', 'node', (event) => {
            event.target.addClass('hover');
            event.target.connectedEdges().addClass('hover');
            this.canvasTarget.style.cursor = event.target.data('center') ? 'default' : 'pointer';
        });
        this.graph.on('mouseout', 'node', (event) => {
            event.target.removeClass('hover');
            event.target.connectedEdges().removeClass('hover');
            this.canvasTarget.style.cursor = '';
        });
        this.graph.on('tap', 'node', (event) => {
            if (!event.target.data('center')) {
                window.location.assign(this.fileUrlValue.replace('__PATH__', encodeURI(event.target.id())));
            }
        });
    }
}
