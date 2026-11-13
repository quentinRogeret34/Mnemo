// Cytoscape and canvas parse colours themselves and do not understand oklch(), so tokens are resolved to rgb through a canvas.
// A token is a light-dark() pair that only resolves as a used colour, hence the probe.
export function resolveColor(element, token) {
    const probe = document.createElement('span');
    probe.style.color = `var(${token})`;
    element.append(probe);
    const context = document.createElement('canvas').getContext('2d', { willReadFrequently: true });
    context.fillStyle = getComputedStyle(probe).color;
    probe.remove();
    context.fillRect(0, 0, 1, 1);
    const [r, g, b] = context.getImageData(0, 0, 1, 1).data;

    return `rgb(${r}, ${g}, ${b})`;
}
