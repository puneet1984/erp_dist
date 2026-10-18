let reportChart = null;

document.getElementById('reportForm').addEventListener('submit', function(e) {
    e.preventDefault();
    generateReport();
});

document.getElementById('exportExcel').addEventListener('click', function() {
    exportToExcel();
});

// Add PDF export functionality
document.getElementById('exportPDF').addEventListener('click', function() {
    exportToPDF();
});

// Add helper function to format date using local timezone
function formatDate(date) {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

// Update date preset functionality
document.querySelectorAll('.btn[data-days], .btn.current-month, .btn.last-month, .btn.current-year, .btn.yesterday, .btn.this-week').forEach(btn => {
    btn.addEventListener('click', function() {
        // Remove active class from all buttons
        document.querySelectorAll('.btn[data-days], .btn.current-month, .btn.last-month, .btn.current-year, .btn.yesterday, .btn.this-week')
            .forEach(b => b.classList.remove('active'));
        
        // Add active class to clicked button
        this.classList.add('active');

        const today = new Date(); // This will use local timezone
        let startDate = new Date();
        let endDate = new Date();

        if (this.classList.contains('yesterday')) {
            startDate.setDate(today.getDate() - 1);
            endDate = new Date(startDate);
        } else if (this.classList.contains('this-week')) {
            const dayOfWeek = today.getDay();
            const diff = today.getDate() - dayOfWeek + (dayOfWeek === 0 ? -6 : 1);
            startDate = new Date(today.getFullYear(), today.getMonth(), diff);
        } else if (this.classList.contains('current-month')) {
            startDate = new Date(today.getFullYear(), today.getMonth(), 1);
        } else if (this.classList.contains('last-month')) {
            startDate = new Date(today.getFullYear(), today.getMonth() - 1, 1);
            endDate = new Date(today.getFullYear(), today.getMonth(), 0);
        } else if (this.classList.contains('current-year')) {
            startDate = new Date(today.getFullYear(), 0, 1);
        } else {
            const days = parseInt(this.dataset.days);
            if (days === 90 || days === 180) {
                // Use complete previous calendar months for 3/6 month presets.
                const months = days === 90 ? 3 : 6;
                startDate = new Date(today.getFullYear(), today.getMonth() - months, 1);
                endDate = new Date(today.getFullYear(), today.getMonth(), 0);
            } else {
                startDate.setDate(today.getDate() - days + 1);
            }
        }

        // Set the dates using our local timezone formatter
        document.getElementById('startDate').value = formatDate(startDate);
        document.getElementById('endDate').value = formatDate(endDate);
        
        generateReport();
    });
});

async function generateReport() {
    const formData = new FormData(document.getElementById('reportForm'));
    const params = new URLSearchParams(formData);

    try {
        const response = await fetch('../../includes/helpers/reports_gen_helper.php?' + params.toString());
        const data = await response.json();

        if (data.error) {
            alert(data.error);
            return;
        }

        updateTable(data);
        updateChart(data);
    } catch (error) {
        console.error('Error generating report:', error);
        alert('Error generating report. Please try again.');
    }
}

function updateTable(data) {
    const table = document.getElementById('reportTable');
    const thead = table.querySelector('thead');
    const tbody = table.querySelector('tbody');
    const tfoot = table.querySelector('tfoot');

    // Clear existing content
    thead.innerHTML = '';
    tbody.innerHTML = '';
    tfoot.innerHTML = '';

    // Handle sectioned reports (like demographics)
    if (data.sections) {
        // Add total patients count for demographics report
        if (data.total_patients !== undefined) {
            const totalRow = document.createElement('tr');
            const totalCell = document.createElement('td');
            totalCell.colSpan = Object.keys(data.sections[0].headers).length;
            totalCell.className = 'h5 text-primary p-2';
            totalCell.textContent = `Total Patients: ${data.total_patients}`;
            totalRow.appendChild(totalCell);
            tbody.appendChild(totalRow);
        }

        // Create headers for first section
        const headerRow = document.createElement('tr');
        Object.keys(data.sections[0].headers).forEach(key => {
            const th = document.createElement('th');
            th.textContent = data.sections[0].headers[key];
            headerRow.appendChild(th);
        });
        thead.appendChild(headerRow);

        // Add data from all sections with section headers
        data.sections.forEach(section => {
            // Add section header
            const sectionRow = document.createElement('tr');
            const sectionHeader = document.createElement('td');
            sectionHeader.colSpan = Object.keys(section.headers).length;
            sectionHeader.textContent = section.title;
            sectionHeader.className = 'table-secondary fw-bold';
            sectionRow.appendChild(sectionHeader);
            tbody.appendChild(sectionRow);

            // Add section data
            section.rows.forEach(row => {
                const tr = document.createElement('tr');
                Object.keys(section.headers).forEach(key => {
                    const td = document.createElement('td');
                    let value = row[key];
                    
                    // Format percentage values
                    if (key === 'percentage') {
                        td.textContent = value + '%';
                    } else if (typeof value === 'number') {
                        td.textContent = value.toLocaleString('en-IN');
                    } else {
                        td.textContent = value || '';
                    }
                    tr.appendChild(td);
                });
                tbody.appendChild(tr);
            });
        });
        return;
    }

    // Handle regular reports
    // Define currency columns based on headers
    const currencyHeaders = ['amount', 'total', 'balance', 'paid', 'discount', 'fee'];
    const headerMap = data.headers || (data.rows && data.rows.length ? Object.fromEntries(
        Object.keys(data.rows[0]).map(key => [key, key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())])
    ) : {});

    // Add table headers for regular reports.
    const headerRow = document.createElement('tr');
    Object.keys(headerMap).forEach(key => {
        const th = document.createElement('th');
        th.textContent = headerMap[key];
        headerRow.appendChild(th);
    });
    if (headerRow.children.length) {
        thead.appendChild(headerRow);
    }

    // Add data rows
    data.rows.forEach(row => {
        const tr = document.createElement('tr');
        Object.keys(headerMap).forEach(key => {
            const td = document.createElement('td');
            td.textContent = row[key] || '';
            if (typeof row[key] === 'number') {
                td.style.textAlign = '';
                // Check if this column should have currency formatting
                if (currencyHeaders.some(header => key.toLowerCase().includes(header))) {
                    td.textContent = row[key].toLocaleString('en-IN', {
                        style: 'currency',
                        currency: 'INR'
                    });
                } else {
                    td.textContent = row[key].toLocaleString('en-IN');
                }
            }
            tr.appendChild(td);
        });
        tbody.appendChild(tr);
    });

    // Add totals
    if (data.totals) {
        const totalsRow = document.createElement('tr');
        Object.keys(headerMap).forEach(key => {
            const td = document.createElement('td');
            td.textContent = data.totals[key] || '';
            if (typeof data.totals[key] === 'number') {
                td.style.textAlign = '';
                // Check if this column should have currency formatting
                if (currencyHeaders.some(header => key.toLowerCase().includes(header))) {
                    td.textContent = data.totals[key].toLocaleString('en-IN', {
                        style: 'currency',
                        currency: 'INR'
                    });
                } else {
                    td.textContent = data.totals[key].toLocaleString('en-IN');
                }
            }
            totalsRow.appendChild(td);
        });
        tfoot.appendChild(totalsRow);
    }
}

function updateChart(data) {
    const ctx = document.getElementById('reportChart').getContext('2d');
    const reportType = document.getElementById('reportType').value;
    
    // Clear existing chart
    if (reportChart) {
        reportChart.destroy();
    }

    // Skip chart for demographics and certain other reports
    if (reportType === 'demographics' || reportType === 'pharmacy_stock' || reportType === 'pharmacy_expiry') {
        document.querySelector('.chart-container').style.display = 'none';
        return;
    }

    document.querySelector('.chart-container').style.display = 'block';

    // If no data or sections-based data, return
    if (!data.rows || data.sections) {
        return;
    }

    // Define color palettes
    const colorPalettes = {
        line: {
            backgroundColor: 'rgba(52, 152, 219, 0.2)',
            borderColor: 'rgba(52, 152, 219, 1)'
        },
        bar: {
            backgroundColor: 'rgba(46, 204, 113, 0.2)',
            borderColor: 'rgba(46, 204, 113, 1)'
        },
        pie: {
            backgrounds: [
                'rgba(52, 152, 219, 0.6)',
                'rgba(46, 204, 113, 0.6)',
                'rgba(155, 89, 182, 0.6)',
                'rgba(241, 196, 15, 0.6)',
                'rgba(230, 126, 34, 0.6)',
                'rgba(231, 76, 60, 0.6)',
                'rgba(149, 165, 166, 0.6)',
                'rgba(41, 128, 185, 0.6)',
                'rgba(39, 174, 96, 0.6)',
                'rgba(142, 68, 173, 0.6)'
            ],
            borders: [
                'rgba(52, 152, 219, 1)',
                'rgba(46, 204, 113, 1)',
                'rgba(155, 89, 182, 1)',
                'rgba(241, 196, 15, 1)',
                'rgba(230, 126, 34, 1)',
                'rgba(231, 76, 60, 1)',
                'rgba(149, 165, 166, 1)',
                'rgba(41, 128, 185, 1)',
                'rgba(39, 174, 96, 1)',
                'rgba(142, 68, 173, 1)'
            ]
        }
    };

    const chartType = getChartType();
    const colors = colorPalettes[chartType];

    const chartConfig = {
        type: chartType,
        data: {
            labels: data.rows.map(row => row.label || row.date || row.name || row.month || row.department),
            datasets: [{
                label: Object.values(data.headers)[Object.keys(data.headers).length - 1] || 'Amount',
                data: data.rows.map(row => row.amount || row.collection || row.sales_amount || row.count || row.total),
                backgroundColor: chartType === 'pie' ? colors.backgrounds : colors.backgroundColor,
                borderColor: chartType === 'pie' ? colors.borders : colors.borderColor,
                borderWidth: 1,
                pointRadius: chartType === 'line' ? 6 : 0,
                pointBackgroundColor: chartType === 'line' ? colors.borderColor : undefined,
                pointBorderColor: chartType === 'line' ? '#fff' : undefined,
                pointHoverRadius: chartType === 'line' ? 8 : 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                tooltip: {
                    enabled: true
                },
                datalabels: chartType === 'line' ? {
                    align: 'top',
                    anchor: 'bottom',
                    color: '#333',
                    font: {
                        weight: 'bold',
                        size: 11
                    },
                    formatter: function(value) {
                        return '₹' + value.toLocaleString('en-IN');
                    },
                    padding: 6
                } : false
            },
            scales: chartType !== 'pie' ? {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '₹' + value.toLocaleString('en-IN');
                        }
                    }
                }
            } : {}
        }
    };

    reportChart = new Chart(ctx, chartConfig);
}

function getChartType() {
    const reportType = document.getElementById('reportType').value;
    switch (reportType) {
        case 'daily':
            return 'line';
        case 'monthly':
            return 'bar';
        case 'payment_mode':
        case 'department':
        case 'doctor':
            return 'pie';
        case 'demographics':
            return null; // No chart for demographics
        default:
            return 'bar';
    }
}

async function exportToExcel() {
    const formData = new FormData(document.getElementById('reportForm'));
    const params = new URLSearchParams(formData);
    
    try {
        const response = await fetch('../../includes/helpers/reports_gen_helper.php?' + params.toString());
        const data = await response.json();

        if (data.error) {
            alert(data.error);
            return;
        }

        const workbook = new ExcelJS.Workbook();
        const worksheet = workbook.addWorksheet('Report');

        // Add headers
        const headers = Object.values(data.headers);
        worksheet.addRow(headers);

        // Style headers
        worksheet.getRow(1).font = { bold: true };
        worksheet.getRow(1).fill = {
            type: 'pattern',
            pattern: 'solid',
            fgColor: { argb: 'FFE0E0E0' }
        };

        // Add data rows
        data.rows.forEach(row => {
            const values = Object.keys(data.headers).map(key => {
                if (typeof row[key] === 'number') {
                    return row[key];
                }
                return row[key] || '';
            });
            worksheet.addRow(values);
        });

        // Add totals if exists
        if (data.totals) {
            const totalsRow = Object.keys(data.headers).map(key => data.totals[key] || '');
            const totalRowNum = worksheet.rowCount + 1;
            worksheet.addRow(totalsRow);
            
            // Style totals row
            worksheet.getRow(totalRowNum).font = { bold: true };
            worksheet.getRow(totalRowNum).fill = {
                type: 'pattern',
                pattern: 'solid',
                fgColor: { argb: 'FFE0E0E0' }
            };
        }

        // Format number columns
        const numberColumns = [];
        Object.keys(data.headers).forEach((key, index) => {
            if (data.rows.length > 0 && typeof data.rows[0][key] === 'number') {
                numberColumns.push(index + 1);
            }
            worksheet.getColumn(index + 1).width = 15;
        });

        numberColumns.forEach(colIndex => {
            worksheet.getColumn(colIndex).numFmt = '₹#,##0.00';
        });

        // Generate filename
        const reportType = document.getElementById('reportType').value;
        const startDate = document.getElementById('startDate').value;
        const endDate = document.getElementById('endDate').value;
        const filename = `financial_report_${reportType}_${startDate}_${endDate}.xlsx`;

        // Save the file
        const buffer = await workbook.xlsx.writeBuffer();
        const blob = new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
        const url = window.URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        link.click();
        window.URL.revokeObjectURL(url);
    } catch (error) {
        console.error('Error exporting to Excel:', error);
        alert('Error generating Excel file. Please try again.');
    }
}

// Add PDF export functionality
function exportToPDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF();

    // Get report title
    const reportType = document.getElementById('reportType').value;
    const startDate = document.getElementById('startDate').value;
    const endDate = document.getElementById('endDate').value;
    
    // Add title
    doc.setFontSize(16);
    doc.text('Financial Report', 14, 15);
    doc.setFontSize(12);
    doc.text(`Type: ${reportType.replace(/_/g, ' ').toUpperCase()}`, 14, 25);
    doc.text(`Period: ${startDate} to ${endDate}`, 14, 35);

    // Get table data
    const table = document.getElementById('reportTable');
    const headers = Array.from(table.querySelectorAll('thead th')).map(th => th.textContent);
    const rows = Array.from(table.querySelectorAll('tbody tr')).map(tr => 
        Array.from(tr.querySelectorAll('td')).map(td => td.textContent)
    );
    const footers = Array.from(table.querySelectorAll('tfoot td')).map(td => td.textContent);

    // Add table
    doc.autoTable({
        head: [headers],
        body: rows,
        foot: [footers],
        startY: 45,
        theme: 'grid',
        styles: {
            fontSize: 10,
            cellPadding: 3
        },
        headStyles: {
            fillColor: [66, 139, 202],
            textColor: 255
        },
        footStyles: {
            fillColor: [240, 240, 240],
            textColor: 0,
            fontStyle: 'bold'
        }
    });

    // Add chart if exists
    if (reportChart) {
        const chartCanvas = document.getElementById('reportChart');
        const chartImage = chartCanvas.toDataURL('image/png');
        const chartWidth = 180;
        const chartHeight = 90;
        
        doc.addPage();
        doc.text('Chart Visualization', 14, 15);
        doc.addImage(chartImage, 'PNG', 15, 25, chartWidth, chartHeight);
    }

    // Save the PDF
    const filename = `financial_report_${reportType}_${startDate}_${endDate}.pdf`;
    doc.save(filename);
}

// Update button text to reflect CSV format
document.getElementById('exportExcel').textContent = 'Export to CSV';

// Set default dates using local timezone
document.addEventListener('DOMContentLoaded', function() {
    const today = new Date();
    const firstDayOfMonth = new Date(today.getFullYear(), today.getMonth(), 1);
    
    document.getElementById('startDate').value = formatDate(firstDayOfMonth);
    document.getElementById('endDate').value = formatDate(today);
});
