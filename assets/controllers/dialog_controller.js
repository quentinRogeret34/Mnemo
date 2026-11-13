import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        if (this.element.open && !this.element.matches(':modal')) {
            this.element.close();
            this.element.showModal();
        }
    }
}
