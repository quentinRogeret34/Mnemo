import { Controller } from '@hotwired/stimulus';

const SVG = 'http://www.w3.org/2000/svg';
const HEIGHT = 220;
const PAD = { top: 12, right: 12, bottom: 26, left: 40 };

function node(name, attributes = {}, text = null) {
    const element = document.createElementNS(SVG, name);
    Object.entries(attributes).forEach(([key, value]) => element.setAttribute(key, value));
    if (text !== null) {
        element.textContent = text;
    }

    return element;
}

function niceMax(value) {
    if (value <= 4) {
        return 4;
    }
    const magnitude = 10 ** Math.floor(Math.log10(value));

    return [1, 2, 4, 5, 10].map((step) => step * magnitude).find((step) => step >= value);
}

// One series over time: 2px line, 10% wash, crosshair and tooltip on hover.
export default class extends Controller {
    static targets = ['plot', 'tooltip'];
    static values = { points: Array, hourly: Boolean, unit: String };

    connect() {
        this.observer = new ResizeObserver(() => this.draw());
        this.observer.observe(this.plotTarget);
    }

    disconnect() {
        this.observer.disconnect();
    }

    label(point, long = false) {
        const date = new Date(point.at);
        if (this.hourlyValue) {
            return date.toLocaleString(document.documentElement.lang, long ? { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' } : { hour: '2-digit', minute: '2-digit' });
        }

        return date.toLocaleDateString(document.documentElement.lang, long ? { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' } : { day: '2-digit', month: 'short' });
    }

    draw() {
        const points = this.pointsValue;
        const width = this.plotTarget.clientWidth;
        this.plotTarget.replaceChildren();
        if (width === 0 || points.length === 0) {
            return;
        }

        const max = niceMax(Math.max(...points.map((point) => point.value)));
        const innerWidth = width - PAD.left - PAD.right;
        const innerHeight = HEIGHT - PAD.top - PAD.bottom;
        const x = (index) => PAD.left + (points.length === 1 ? innerWidth / 2 : (index / (points.length - 1)) * innerWidth);
        const y = (value) => PAD.top + innerHeight - (value / max) * innerHeight;
        const svg = node('svg', { viewBox: `0 0 ${width} ${HEIGHT}`, width, height: HEIGHT, role: 'img', 'aria-label': this.element.getAttribute('aria-label') ?? '' });

        for (let tick = 0; tick <= 4; tick++) {
            const value = (max / 4) * tick;
            svg.append(
                node('line', { class: tick === 0 ? 'chart__axis' : 'chart__grid', x1: PAD.left, x2: width - PAD.right, y1: y(value), y2: y(value) }),
                node('text', { class: 'chart__tick', x: PAD.left - 8, y: y(value) + 4, 'text-anchor': 'end' }, new Intl.NumberFormat(document.documentElement.lang, { notation: max >= 10000 ? 'compact' : 'standard' }).format(value)),
            );
        }

        const every = Math.max(1, Math.ceil(points.length / Math.max(2, Math.floor(innerWidth / 80))));
        points.forEach((point, index) => {
            if (index % every === 0 && (points.length - 1 - index >= every / 2 || index === points.length - 1)) {
                svg.append(node('text', { class: 'chart__tick', x: x(index), y: HEIGHT - 6, 'text-anchor': index === 0 ? 'start' : 'middle' }, this.label(point)));
            }
        });

        const path = points.map((point, index) => `${index === 0 ? 'M' : 'L'}${x(index)},${y(point.value)}`).join('');
        svg.append(
            node('path', { class: 'chart__area', d: `${path}L${x(points.length - 1)},${y(0)}L${x(0)},${y(0)}Z` }),
            node('path', { class: 'chart__line', d: path }),
        );

        this.crosshair = node('line', { class: 'chart__crosshair', y1: PAD.top, y2: PAD.top + innerHeight, visibility: 'hidden' });
        this.dot = node('circle', { class: 'chart__dot', r: 4, visibility: 'hidden' });
        svg.append(this.crosshair, this.dot);
        this.plotTarget.append(svg);
        this.scale = { x, y, innerWidth };
    }

    move(event) {
        const points = this.pointsValue;
        if (!this.scale || points.length === 0) {
            return;
        }
        const bounds = this.plotTarget.getBoundingClientRect();
        const ratio = (event.clientX - bounds.left - PAD.left) / this.scale.innerWidth;
        const index = Math.max(0, Math.min(points.length - 1, Math.round(ratio * (points.length - 1))));
        const cx = this.scale.x(index);
        const cy = this.scale.y(points[index].value);

        this.crosshair.setAttribute('x1', cx);
        this.crosshair.setAttribute('x2', cx);
        this.crosshair.setAttribute('visibility', 'visible');
        this.dot.setAttribute('cx', cx);
        this.dot.setAttribute('cy', cy);
        this.dot.setAttribute('visibility', 'visible');

        this.tooltipTarget.replaceChildren(
            Object.assign(document.createElement('strong'), { textContent: `${new Intl.NumberFormat(document.documentElement.lang).format(points[index].value)} ${this.unitValue}` }),
            Object.assign(document.createElement('span'), { textContent: this.label(points[index], true) }),
        );
        this.tooltipTarget.hidden = false;
        const left = Math.min(Math.max(cx, this.tooltipTarget.offsetWidth / 2), bounds.width - this.tooltipTarget.offsetWidth / 2);
        this.tooltipTarget.style.transform = `translate(calc(${left}px - 50%), ${Math.max(0, cy - this.tooltipTarget.offsetHeight - 12)}px)`;
    }

    leave() {
        this.tooltipTarget.hidden = true;
        this.crosshair?.setAttribute('visibility', 'hidden');
        this.dot?.setAttribute('visibility', 'hidden');
    }
}
