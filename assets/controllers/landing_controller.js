import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['reveal', 'distance', 'search', 'offers'];

    connect() {
        this.motion = window.matchMedia('(prefers-reduced-motion: reduce)');
        if (this.motion.matches) return;
        this.observer = new IntersectionObserver(entries => {
            entries.forEach(entry => {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('landing-visible');
                this.observer.unobserve(entry.target);
            });
        }, { threshold: 0.1 });
        this.revealTargets.forEach(element => this.observer.observe(element));
    }

    choose(event) {
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        this.distanceTargets.forEach(link => link.removeAttribute('aria-current'));
        event.currentTarget.setAttribute('aria-current', 'true');
        this.searchTarget.href = event.currentTarget.href;
        if (!this.motion.matches) {
            this.offersTarget.animate([{ opacity: 0.5, transform: 'translateY(12px)' }, { opacity: 1, transform: 'translateY(0)' }], { duration: 320, easing: 'ease-out' });
        }
    }

    reset() {
        this.distanceTargets.forEach(link => link.removeAttribute('aria-current'));
        this.distanceTargets[0].setAttribute('aria-current', 'true');
        this.searchTarget.href = this.distanceTargets[0].href;
        this.revealTargets.forEach(element => element.classList.remove('landing-visible'));
    }

    disconnect() {
        this.observer?.disconnect();
    }
}
