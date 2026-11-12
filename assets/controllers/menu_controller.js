import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    close() {
        this.element.open = false;
    }

    closeOutside(event) {
        if (!this.element.contains(event.target)) {
            this.close();
        }
    }
}
