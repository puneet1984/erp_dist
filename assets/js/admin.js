// Common admin functionality
$(document).ready(function() {
    // Handle form validation feedback
    function showFormError(message) {
        alert(message);
    }

    function showFormSuccess(message) {
        alert(message);
    }

    // Handle AJAX errors globally
    $(document).ajaxError(function(event, jqXHR, settings, error) {
        showFormError('Error: ' + (jqXHR.responseJSON?.message || 'Something went wrong'));
    });

    // Add loading indicator for buttons
    $('button[type="submit"]').on('click', function() {
        const $btn = $(this);
        const originalText = $btn.text();
        $btn.prop('disabled', true).text('Please wait...');
        
        setTimeout(() => {
            $btn.prop('disabled', false).text(originalText);
        }, 3000); // Reset after 3 seconds if no response
    });

    // Initialize tooltips only if Bootstrap is loaded
    if (typeof $.fn.tooltip === 'function') {
        $('[data-toggle="tooltip"]').tooltip();
    }

    // Handle session timeout warnings
    let sessionTimeout;
    function resetSessionTimer() {
        clearTimeout(sessionTimeout);
        sessionTimeout = setTimeout(function() {
            alert('Your session will expire soon. Please save your work.');
        }, 25 * 60 * 1000); // 25 minutes
    }

    $(document).on('mousemove keypress', resetSessionTimer);
    resetSessionTimer();
});
