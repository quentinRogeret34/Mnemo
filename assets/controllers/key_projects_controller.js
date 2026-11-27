import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['all', 'project'];

    toggleAll() {
        if (this.allTarget.checked) {
            for (const input of this.projectTargets) {
                input.checked = false;
            }
        } else if (!this.projectTargets.some((input) => input.checked)) {
            this.allTarget.checked = true;
        }
    }

    toggleProject() {
        const anyChecked = this.projectTargets.some((input) => input.checked);
        this.allTarget.checked = !anyChecked;
    }
}
