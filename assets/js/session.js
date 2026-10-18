let sessionTimeout;
const checkInterval = 30000; // 30 seconds
const warningTime = 540000; // 9 minutes (warn 1 minute before timeout)

function resetSessionTimer() {
    clearTimeout(sessionTimeout);
    sessionTimeout = setTimeout(showTimeoutWarning, warningTime);
}

function showTimeoutWarning() {
    const stay = confirm('Your session will expire in 1 minute. Do you want to stay logged in?');
    if (stay) {
        // Make AJAX call to refresh session
        fetch('/erp/includes/refresh_session.php')
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    resetSessionTimer();
                } else {
                    window.location.href = '/erp/modules/auth/login.php?timeout=1';
                }
            });
    } else {
        window.location.href = '/erp/modules/auth/logout.php';
    }
}

// Monitor user activity
document.addEventListener('mousemove', resetSessionTimer);
document.addEventListener('keypress', resetSessionTimer);
document.addEventListener('click', resetSessionTimer);

// Initial setup
resetSessionTimer();

// Periodic check
setInterval(() => {
    fetch('/erp/includes/check_session.php')
        .then(response => response.json())
        .then(data => {
            if (!data.valid) {
                window.location.href = '/erp/modules/auth/login.php?timeout=1';
            }
        });
}, checkInterval);
