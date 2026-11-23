import { Controller } from '@hotwired/stimulus';

const SVG = 'http://www.w3.org/2000/svg';
const HEIGHT = 240;
const PAD = { top: 12, right: 8, bottom: 26, left: 40 };
const MAX_BARS = 62;

function node(name, attributes = {}, text = null) {
    const element = document.createElementNS(SVG, name);
    Object.entries(attributes).forEach(([key, value]) => element.setAttribute(key, value));
    if (text !== null) {
        element.textContent = text;
    }

    return element;
}

// Picks a ceiling divisible by 4 so integer counts never render fractional ticks (e.g. 2.5, 7.5).
function niceMax(value) {
    if (value <= 4) {
        return 4;
    }
    const rawStep = value / 4;
    const magnitude = 10 ** Math.floor(Math.log10(rawStep));
    const step = [1, 2, 2.5, 5, 10].map((factor) => factor * magnitude).find((candidate) => candidate >= rawStep);

    return Math.ceil(step) * 4;
}

function bar(x, y, width, height) {
    const radius = Math.min(3, width / 2, height);

    return `M${x},${y + height}V${y + radius}Q${x},${y} ${x + radius},${y}H${x + width - radius}Q${x + width},${y} ${x + width},${y + radius}V${y + height}Z`;
}

// Grouped bars over time, one colour per series. Long ranges are summed into buckets so a bar stays wider than a hairline.
export default class extends Controller {
    static targets = ['plot', 'tooltip'];
    static values = { series: Array, hourly: Boolean };

    connect() {
        this.observer = new ResizeObserver(() => this.draw());
        this.observer.observe(this.plotTarget);
    }

    disconnect() {
        this.observer.disconnect();
    }

    buckets() {
        const length = this.seriesValue[0]?.points.length ?? 0;
        const size = length > MAX_BARS ? Math.max(7, Math.ceil(length / MAX_BARS)) : 1;
        const buckets = [];
        for (let start = 0; start < length; start += size) {
            const end = Math.min(start + size, length) - 1;
            buckets.push({
                from: this.seriesValue[0].points[start].at,
                to: this.seriesValue[0].points[end].at,
                values: this.seriesValue.map((series) => series.points.slice(start, end + 1).reduce((sum, point) => sum + point.value, 0)),
            });
        }

        return buckets;
    }

    format(at, long = false) {
        const date = new Date(at);
        if (this.hourlyValue) {
            return date.toLocaleString(document.documentElement.lang, long ? { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' } : { hour: '2-digit', minute: '2-digit' });
        }

        return date.toLocaleDateString(document.documentElement.lang, long ? { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' } : { day: '2-digit', month: 'short' });
    }

    label(bucket, long = false) {
        return bucket.from === bucket.to ? this.format(bucket.from, long) : `${this.format(bucket.from)} – ${this.format(bucket.to)}`;
    }

    draw() {
        const width = this.plotTarget.clientWidth;
        this.plotTarget.replaceChildren();
        this.bucketList = this.buckets();
        const buckets = this.bucketList;
        if (width === 0 || buckets.length === 0) {
            return;
        }

        const height = this.plotTarget.clientHeight || HEIGHT;
        const max = niceMax(Math.max(...buckets.flatMap((bucket) => bucket.values)));
        const innerWidth = width - PAD.left - PAD.right;
        const innerHeight = height - PAD.top - PAD.bottom;
        const step = innerWidth / buckets.length;
        const count = this.seriesValue.length;
        const barWidth = Math.max(1, Math.min(14, (step * 0.72 - (count - 1) * 2) / count));
        const groupWidth = barWidth * count + (count - 1) * 2;
        const y = (value) => PAD.top + innerHeight - (value / max) * innerHeight;
        const svg = node('svg', { viewBox: `0 0 ${width} ${height}`, width, height, role: 'img', 'aria-label': this.element.getAttribute('aria-label') ?? '' });

        for (let tick = 0; tick <= 4; tick++) {
            const value = (max / 4) * tick;
            svg.append(
                node('line', { class: tick === 0 ? 'chart__axis' : 'chart__grid', x1: PAD.left, x2: width - PAD.right, y1: y(value), y2: y(value) }),
                node('text', { class: 'chart__tick', x: PAD.left - 8, y: y(value) + 4, 'text-anchor': 'end' }, new Intl.NumberFormat(document.documentElement.lang, { notation: max >= 10000 ? 'compact' : 'standard' }).format(value)),
            );
        }

        this.band = node('rect', { class: 'chart__band', y: PAD.top - 6, width: step, height: innerHeight + 6 + PAD.bottom - 2, rx: 6, visibility: 'hidden' });
        svg.append(this.band);

        const every = Math.max(1, Math.ceil(buckets.length / Math.max(2, Math.floor(innerWidth / 72))));
        buckets.forEach((bucket, index) => {
            const left = PAD.left + index * step + (step - groupWidth) / 2;
            bucket.values.forEach((value, series) => {
                if (value > 0) {
                    svg.append(node('path', { class: `chart__bar chart__bar--${series}`, d: bar(left + series * (barWidth + 2), y(value), barWidth, y(0) - y(value)) }));
                }
            });
            if (index % every === 0 && (buckets.length - 1 - index >= every / 2 || index === buckets.length - 1)) {
                svg.append(node('text', { class: 'chart__tick', x: PAD.left + index * step + step / 2, y: height - 6, 'text-anchor': 'middle' }, this.format(bucket.from)));
            }
        });

        this.plotTarget.append(svg);
        this.scale = { step, innerWidth };
    }

    move(event) {
        const buckets = this.bucketList;
        if (!this.scale || !buckets?.length) {
            return;
        }
        const bounds = this.plotTarget.getBoundingClientRect();
        const index = Math.max(0, Math.min(buckets.length - 1, Math.floor((event.clientX - bounds.left - PAD.left) / this.scale.step)));
        const bucket = buckets[index];
        const left = PAD.left + index * this.scale.step;
        this.band.setAttribute('x', left);
        this.band.setAttribute('visibility', 'visible');

        const number = new Intl.NumberFormat(document.documentElement.lang);
        this.tooltipTarget.replaceChildren(
            Object.assign(document.createElement('strong'), { textContent: this.label(bucket, true) }),
            ...this.seriesValue.map((series, position) => {
                const row = document.createElement('span');
                row.className = 'chart__row';
                row.append(
                    Object.assign(document.createElement('span'), { className: `chart__swatch chart__swatch--${position}` }),
                    Object.assign(document.createElement('span'), { textContent: series.label }),
                    Object.assign(document.createElement('b'), { textContent: number.format(bucket.values[position]) }),
                );

                return row;
            }),
        );
        this.tooltipTarget.hidden = false;
        const centre = left + this.scale.step / 2;
        const width = this.tooltipTarget.offsetWidth;
        const raw = centre + 12 + width <= bounds.width ? centre + 12 : centre - 12 - width;
        const x = Math.max(0, Math.min(raw, Math.max(0, bounds.width - width)));
        this.tooltipTarget.style.transform = `translate(${x}px, ${PAD.top}px)`;
    }

    leave() {
        this.tooltipTarget.hidden = true;
        this.band?.setAttribute('visibility', 'hidden');
    }
}
