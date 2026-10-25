import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['status'];

    start() {
        this.statusTarget.hidden = false;
        this.element.setAttribute('aria-busy', 'true');
    }

    reset() {
        this.statusTarget.hidden = true;
        this.element.removeAttribute('aria-busy');
    }
}
