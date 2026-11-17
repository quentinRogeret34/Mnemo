import { Controller } from '@hotwired/stimulus';
import { trans } from '../translator.js';
import TomSelect from 'tom-select';
import 'tom-select/dist/css/tom-select.min.css';

/*
 * Turns a native select into a searchable list. The data-meta attribute of an option is shown under its label.
 */
export default class extends Controller {
    connect() {
        const multiple = this.element.multiple;
        this.select = new TomSelect(this.element, {
            maxOptions: null,
            hidePlaceholder: true,
            refreshThrottle: 0,
            plugins: multiple ? { remove_button: { title: trans('js.model.remove') } } : {},
            // Tom Select keeps the typed text after a choice: the search would hide the other options.
            onItemAdd() {
                if (multiple) {
                    this.setTextboxValue('');
                    this.refreshOptions(false);
                }
            },
            render: {
                option: (data, escape) => `<div class="model-option"><span class="model-option__name">${escape(data.text)}</span>`
                    + `${data.meta ? `<span class="model-option__meta">${escape(data.meta)}</span>` : ''}</div>`,
                no_results: () => `<div class="no-results">${trans('js.select.no_results')}</div>`,
            },
        });
    }

    disconnect() {
        this.select?.destroy();
        this.select = null;
    }
}
