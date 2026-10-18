function updateCurrentDateTime() {
    const currentDateTimeContainer = document.getElementById('current-date-time');
    const now = new Date();

    // Format the date as DD-MM-YYYY
    const year = now.getFullYear();
    const month = (now.getMonth() + 1).toString().padStart(2, '0'); // Month is zero-based
    const day = now.getDate().toString().padStart(2, '0');

    // Format the time as HH:MM:SS AM/PM
    const hours = now.getHours();
    const minutes = now.getMinutes().toString().padStart(2, '0');
    const seconds = now.getSeconds().toString().padStart(2, '0');
    const ampm = hours >= 12 ? 'PM' : 'AM';
    const formattedHours = (hours % 12 || 12).toString().padStart(2, '0'); // Convert to 12-hour format

    const formattedDate = `${day}-${month}-${year}`; // Changed order to DD-MM-YYYY
    const formattedTime = `${formattedHours}:${minutes}:${seconds} ${ampm}`;
    const formattedDateTime = `${formattedDate} ${formattedTime}`;

    currentDateTimeContainer.textContent = formattedDateTime;
}

// Update immediately when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    updateCurrentDateTime(); // Update immediately
    setInterval(updateCurrentDateTime, 1000); // Then update every second
});
