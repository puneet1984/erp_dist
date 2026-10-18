class TaxCalculator {
    constructor() {
        this.taxRates = {};
        this.isPharmacy = window.location.pathname.includes('/pharmacy/');
        this.loadTaxRates();
    }

    async loadTaxRates() {
        try {
            const response = await fetch('../../config/tax_rates.json');
            if (!response.ok) throw new Error('Failed to load tax rates');
            this.taxRates = await response.json();
        } catch (error) {
            console.error('Error loading tax rates:', error);
            // Fallback tax rates based on service categories
            this.taxRates = {
                'CONSULTATION': { gst: 0, description: 'Doctor consultation fees are exempt from GST' },
                'PROCEDURE': { gst: 0, description: 'Medical procedures and treatments' },
                'DIAGNOSTIC': { gst: 0, description: 'Diagnostic services' },
                'RADIOLOGY': { gst: 0, description: 'X-rays, CT scans, MRI, etc.' },
                'PATHOLOGY': { gst: 0, description: 'Laboratory tests and diagnostics' },
                'PHYSIOTHERAPY': { gst: 0, description: 'Physiotherapy services' },
                'PHARMACY': { gst: 12, description: 'Medicines and medical supplies' },
                'BNP': { gst: 0, description: 'BNP Package services are exempt' },
                'IPD': { gst: 0, description: 'IPD services are exempt from GST' },
                'OTHER': { gst: 0, description: 'Other medical services' }
            };
        }
    }

    calculateTotalTax(services) {
        return services.reduce((total, service) => {
            const categoryRate = this.taxRates[service.category]?.gst || 0;
            const taxableAmount = service.price * service.quantity;
            const gstAmount = taxableAmount * (categoryRate / 100);
            return total + gstAmount;
        }, 0);
    }

    shouldShowTax() {
        return services.some(service => {
            const categoryRate = this.taxRates[service.category]?.gst || 0;
            return categoryRate > 0;
        });
    }

    formatTaxDisplay(totalTax) {
        const cgst = totalTax / 2;
        const sgst = totalTax / 2;
        return `${cgst.toFixed(2)} (CGST) + ${sgst.toFixed(2)} (SGST) = ${totalTax.toFixed(2)}`;
    }
}
