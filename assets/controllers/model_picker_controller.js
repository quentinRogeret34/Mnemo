import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';
import 'tom-select/dist/css/tom-select.min.css';

const contextLabel = (tokens) => (tokens >= 1e6 ? `${+(tokens / 1e6).toFixed(1)}M` : `${Math.round(tokens / 1000)}k`);
const price = (perMillion) => (0 === perMillion ? 'gratuit' : `$${perMillion}`);

/*
 * Turns a model field into a searchable list: the favourites of the instance first, then the catalogue.
 * Any identifier can still be typed, so a model missing from the list stays usable.
 */
export default class extends Controller {
    static values = { url: String, multiple: Boolean };

    connect() {
        const current = this.element.value.split(',').map((id) => id.trim()).filter(Boolean);
        this.select = new TomSelect(this.element, {
            maxItems: this.multipleValue ? null : 1,
            delimiter: ',',
            create: true,
            createOnBlur: true,
            persist: false,
            valueField: 'id',
            labelField: 'id',
            searchField: ['id', 'name'],
            optgroupField: 'group',
            optgroups: [{ value: 'favorites', label: 'Favoris' }, { value: 'all', label: 'Tous les modèles' }],
            lockOptgroupOrder: true,
            maxOptions: 300,
            // Filtered at once: with the default delay, an Enter typed quickly picks the first unfiltered model.
            refreshThrottle: 0,
            options: current.map((id) => ({ id, name: id, group: 'all' })),
            items: current,
            placeholder: this.element.placeholder,
            plugins: this.multipleValue ? { remove_button: { title: 'Retirer' } } : { clear_button: { title: 'Revenir au modèle par défaut' } },
            // Tom Select keeps the typed text after a choice: the next Enter would add it again.
            onItemAdd() {
                this.setTextboxValue('');
                this.refreshOptions(false);
            },
            render: {
                option: (model, escape) => {
                    const meta = [];
                    if (model.context) meta.push(`${contextLabel(model.context)} tokens`);
                    if (null !== model.prompt && undefined !== model.prompt) meta.push(`${price(model.prompt)} / ${price(model.completion)} par M`);

                    return `<div class="model-option"><span class="model-option__name">${escape(model.name)}</span>`
                        + `<span class="model-option__meta"><span class="mono">${escape(model.id)}</span>${meta.length ? ` · ${escape(meta.join(' · '))}` : ''}</span></div>`;
                },
                item: (model, escape) => `<div class="mono">${escape(model.id)}</div>`,
                option_create: (data, escape) => `<div class="create">Utiliser <span class="mono">${escape(data.input)}</span></div>`,
                no_results: () => '<div class="no-results">Aucun modèle de la liste ne correspond.</div>',
            },
        });
        this.load();
    }

    disconnect() {
        this.select?.destroy();
        this.select = null;
    }

    async load() {
        const response = await fetch(this.urlValue, { headers: { Accept: 'application/json' } });
        if (!response.ok || !this.select) {
            return;
        }
        const { favorites, models } = await response.json();
        const favored = new Set(favorites);
        // Favourites in the order they were chosen, then the rest of the catalogue.
        const byId = new Map(models.map((model) => [model.id, model]));
        const ordered = [...favorites.filter((id) => byId.has(id)).map((id) => byId.get(id)), ...models.filter((model) => !favored.has(model.id))];
        // The chosen models already have an option, kept when missing from the catalogue.
        for (const model of ordered) {
            const option = { ...model, group: favored.has(model.id) ? 'favorites' : 'all' };
            if (this.select.options[model.id]) {
                this.select.updateOption(model.id, option);
            } else {
                this.select.addOption(option);
            }
        }
        this.select.refreshOptions(false);
    }
}
