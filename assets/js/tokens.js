/**
 * Tokens Module JavaScript
 * Handles token generation, patient lookup, printing, and WhatsApp messaging
 */

let selectedDoctor = null;
let patientContact = '';

// ===== Doctor Selection =====
function selectDoctor(element, doctorCode) {
    selectedDoctor = doctorCode;
    
    // Clear patient fields
    document.getElementById('patientId').value = '';
    document.getElementById('patientName').value = '';
    document.getElementById('patientContact').value = '';
    document.getElementById('printReconstructedId').innerText = '---';
    document.getElementById('printPatientName').innerText = '---';
    
    // Update selected state
    const doctorItems = document.querySelectorAll('.doctor-item');
    doctorItems.forEach(item => item.classList.remove('selected'));
    element.classList.add('selected');
}

// ===== Patient Lookup =====
function fetchPatientDetails() {
    const patientId = document.getElementById('patientId').value;

    if (!patientId) {
        alert('Please enter a Patient ID');
        return;
    }

    if (!window.tokenConfig) {
        alert('Configuration error');
        return;
    }

    // Use the default get_patient.php helper with GET request
    const url = window.tokenConfig.apiGetPatient + '?term=' + encodeURIComponent(patientId) + '&module=tokens';

    fetch(url)
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            const patient = data.patient;
            const reconstructedId = 'MNH' + String(patient.id).padStart(8, '0');
            document.getElementById('patientName').value = patient.name || '';
            patientContact = patient.contact || '';
            document.getElementById('printReconstructedId').innerText = reconstructedId;
            document.getElementById('printPatientName').innerText = patient.name;
            document.getElementById('patientContact').value = patient.contact || '';
        } else {
            alert(data.message);
            document.getElementById('patientName').value = '';
            document.getElementById('printReconstructedId').innerText = '---';
            document.getElementById('printPatientName').innerText = '---';
            document.getElementById('patientContact').value = '';
            patientContact = '';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while fetching patient details.');
    });
}

// ===== Token Generation =====
function generateToken() {
    if (!selectedDoctor) {
        alert('Please select a doctor');
        return;
    }

    const reconstructedId = document.getElementById('printReconstructedId').innerText;
    const patientName = document.getElementById('patientName').value;

    if (!reconstructedId || reconstructedId === '---' || !patientName) {
        alert('Please ensure both Patient ID and Name are filled');
        return;
    }

    if (!window.tokenConfig) {
        alert('Configuration error');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'generate_token');
    formData.append('reconstructedId', reconstructedId);
    formData.append('patientName', patientName);
    formData.append('doctorId', selectedDoctor);
    formData.append('doctorName', getSelectedDoctorName());
    formData.append('contact', patientContact);

    fetch(window.tokenConfig.apiGenerateToken, {
        method: 'POST',
        body: formData,
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const today = formatDateToDMY(new Date());
            const currentTime = new Date().toLocaleTimeString();

            document.getElementById('tokenNumber').innerText = data.token_number;
            document.getElementById('printTokenNumber').innerText = data.token_number;
            document.getElementById('printDoctorName').innerText = getSelectedDoctorName();
            document.getElementById('printDate').innerText = today;
            document.getElementById('printTime').innerText = currentTime;

            alert('Token generated successfully: #' + data.token_number);
        } else {
            alert(data.message || 'Error generating token');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while generating the token');
    });
}

// ===== Token Printing =====
function printToken(token = null) {
    // list_tokens.php flow: print specific token row
    if (token && typeof token === 'object') {
        const reconstructedIdEl = document.getElementById('printReconstructedId');
        const patientNameEl = document.getElementById('printPatientName');
        const doctorNameEl = document.getElementById('printDoctorName');
        const tokenNumberEl = document.getElementById('printTokenNumber');
        const printDateEl = document.getElementById('printDate');

        if (reconstructedIdEl) reconstructedIdEl.innerText = token.reconstructed_id || '---';
        if (patientNameEl) patientNameEl.innerText = token.patient_name || '---';
        if (doctorNameEl) doctorNameEl.innerText = token.doctor_name || '---';
        if (tokenNumberEl) tokenNumberEl.innerText = token.token_number || '---';
        if (printDateEl) printDateEl.innerText = formatDateToDMY(token.date);

        window.print();
        return;
    }

    // index.php flow: print last generated token
    const tokenNumberEl = document.getElementById('tokenNumber');
    if (!tokenNumberEl || tokenNumberEl.innerText === '---') {
        alert('Please generate a token before printing');
        return;
    }

    window.print();
}

// ===== Utility Functions =====
function getSelectedDoctorName() {
    if (!selectedDoctor) return '---';
    const elements = document.querySelectorAll('.doctor-item');
    for (const element of elements) {
        if (element.classList.contains('selected')) {
            return element.innerText;
        }
    }
    return '---';
}

function formatDateToDMY(dateInput) {
    if (dateInput instanceof Date) {
        const dd = String(dateInput.getDate()).padStart(2, '0');
        const mm = String(dateInput.getMonth() + 1).padStart(2, '0');
        const yyyy = dateInput.getFullYear();

        return `${dd}-${mm}-${yyyy}`;
    }

    const value = String(dateInput || '').trim();
    const match = value.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!match) {
        return value;
    }

    return `${match[3]}-${match[2]}-${match[1]}`;
}

// ===== Token List Actions =====
function sendWhatsApp(token) {
    if (!token || !token.contact) {
        alert('No contact number available');
        return;
    }

    if (!window.tokenConfig || !window.tokenConfig.apiListTokens) {
        alert('Configuration error');
        return;
    }

    if (!confirm('Send token #' + token.token_number + ' via WhatsApp to ' + token.contact + '?')) {
        return;
    }

    const formData = new FormData();
    formData.append('action', 'send_whatsapp');
    formData.append('contact', token.contact);
    formData.append('tokenNumber', token.token_number);
    formData.append('doctorName', token.doctor_name);
    formData.append('patientName', token.patient_name);
    formData.append('date', token.date);

    fetch(window.tokenConfig.apiListTokens, {
        method: 'POST',
        body: formData,
    })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('Token sent via WhatsApp');
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(err => alert('Error: ' + err));
}

function cancelToken(tokenId, patientName) {
    if (!window.tokenConfig || !window.tokenConfig.apiListTokens) {
        alert('Configuration error');
        return;
    }

    if (!confirm('Cancel token for ' + patientName + '?')) {
        return;
    }

    const formData = new FormData();
    formData.append('action', 'cancel_token');
    formData.append('tokenId', tokenId);

    fetch(window.tokenConfig.apiListTokens, {
        method: 'POST',
        body: formData,
    })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(err => alert('Error: ' + err));
}

function showDoctorTokens(doctorName, clickedTab) {
    const tokenGroups = document.querySelectorAll('.token-group');
    const tabs = document.querySelectorAll('.doctor-tab');
    const tokenCountSummary = document.getElementById('tokenCountSummary');

    tabs.forEach(tab => tab.classList.remove('active'));
    if (clickedTab) {
        clickedTab.classList.add('active');
    }

    tokenGroups.forEach(group => {
        const groupDoctor = group.getAttribute('data-doctor');
        if (doctorName === 'all' || groupDoctor === doctorName) {
            group.style.display = '';
        } else {
            group.style.display = 'none';
        }
    });

    if (tokenCountSummary) {
        tokenCountSummary.style.display = doctorName === 'all' ? '' : 'none';
    }

    try {
        localStorage.setItem('tokensActiveDoctorTab', doctorName);
    } catch (e) {
        // Ignore storage errors in private/incognito mode.
    }
}

function initDoctorTabsAutoRefresh() {
    const hasDoctorTabs = document.querySelector('.doctor-tab') !== null;
    if (!hasDoctorTabs) {
        return;
    }

    let activeDoctor = 'all';
    try {
        const savedDoctor = localStorage.getItem('tokensActiveDoctorTab');
        if (savedDoctor) {
            activeDoctor = savedDoctor;
        }
    } catch (e) {
        activeDoctor = 'all';
    }

    let targetTab = null;
    try {
        targetTab = document.querySelector('.doctor-tab[data-doctor="' + CSS.escape(activeDoctor) + '"]');
    } catch (e) {
        targetTab = null;
    }

    if (targetTab) {
        showDoctorTokens(activeDoctor, targetTab);
    } else {
        const defaultTab = document.querySelector('.doctor-tab[data-doctor="all"]');
        if (defaultTab) {
            showDoctorTokens('all', defaultTab);
        }
    }

    // Auto-refresh every 3 minutes and restore same selected doctor tab.
    setInterval(function () {
        window.location.reload();
    }, 180000);
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    initDoctorTabsAutoRefresh();
    console.log('Tokens module loaded');
});
