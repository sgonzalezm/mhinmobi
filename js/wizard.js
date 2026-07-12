const ProgressManager = {
    currentStep: 1,

    init() {
        this.updateUI();
    },

    updateUI() {
        document.querySelectorAll('.step').forEach((el, index) => {
            const stepNum = index + 1;
            el.classList.toggle('active', stepNum === this.currentStep);
            el.classList.toggle('completed', stepNum < this.currentStep);
        });
    },

    nextStep() {
        if (this.currentStep < 3) {
            this.currentStep++;
            this.updateUI();
            this.renderForm();
        }
    },

    renderForm() {
        const form = document.getElementById('wizardForm');
        // Aquí podrías usar fetch para traer el HTML del paso solicitado
        // evitando el "código espagueti" de tener todo en un solo archivo
        console.log(`Renderizando paso: ${this.currentStep}`);
    }
};

document.addEventListener('DOMContentLoaded', () => ProgressManager.init());