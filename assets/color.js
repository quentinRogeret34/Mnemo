// Cytoscape and canvas parse colours themselves and do not understand oklch(), so tokens are resolved to rgb through a canvas.
export function resolveColor(element, token) {
    const context = document.createElement('canvas').getContext('2d', { willReadFrequently: true });
    context.fillStyle = getComputedStyle(element).getPropertyValue(token).trim();
    context.fillRect(0, 0, 1, 1);
    const [r, g, b] = context.getImageData(0, 0, 1, 1).data;

    return `rgb(${r}, ${g}, ${b})`;
}
