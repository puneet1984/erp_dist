// Function to convert number to words in Indian numbering system
const units = [
    "",
    "One",
    "Two",
    "Three",
    "Four",
    "Five",
    "Six",
    "Seven",
    "Eight",
    "Nine",
    "Ten",
    "Eleven",
    "Twelve",
    "Thirteen",
    "Fourteen",
    "Fifteen",
    "Sixteen",
    "Seventeen",
    "Eighteen",
    "Nineteen",
];
const tens = [
    "",
    "",
    "Twenty",
    "Thirty",
    "Forty",
    "Fifty",
    "Sixty",
    "Seventy",
    "Eighty",
    "Ninety",
];

function convertToIndianWords(num) {
    if (num === 0) return "Zero";
    
    // Handle numbers less than 100
    if (num < 20) return units[num];
    if (num < 100) return tens[Math.floor(num/10)] + (num % 10 ? " " + units[num % 10] : "");
    
    // Handle hundreds
    if (num < 1000) {
        return units[Math.floor(num/100)] + " Hundred" + 
               (num % 100 ? " and " + convertToIndianWords(num % 100) : "");
    }
    
    // Handle thousands
    if (num < 100000) {
        return convertToIndianWords(Math.floor(num/1000)) + " Thousand " + 
               (num % 1000 ? convertToIndianWords(num % 1000) : "");
    }
    
    // Handle lakhs
    if (num < 10000000) {
        return convertToIndianWords(Math.floor(num/100000)) + " Lakh " + 
               (num % 100000 ? convertToIndianWords(num % 100000) : "");
    }
    
    // Handle crores
    return convertToIndianWords(Math.floor(num/10000000)) + " Crore " + 
           (num % 10000000 ? convertToIndianWords(num % 10000000) : "");
}

function updateAmountInWords(e) {
    const input = e.target;
    const form = input.closest('form');
    const amountWords = form.querySelector('.amount-words');
    
    // Add .00 if no decimal point exists
    const value = input.value.includes('.') ? input.value : input.value + '.00';
    
    const [rupees, paise] = value.split('.').map((val, idx) => 
        idx === 0 ? parseInt(val) || 0 : parseInt(val?.slice(0, 2).padEnd(2, '0')) || 0
    );
    
    if (rupees >= 0) {
        let words = rupees > 0 ? convertToIndianWords(rupees) + " Rupees" : "";
        
        if (paise > 0) {
            words += (words ? " and " : "") + convertToIndianWords(paise) + " Paise";
        }
        
        if (rupees === 0 && paise === 0) {
            words = "Zero Rupees";
        }
        
        amountWords.textContent = "₹ " + words + " Only";
        amountWords.style.display = 'block';
    } else {
        amountWords.textContent = "Please enter a valid amount.";
        amountWords.style.display = 'none';
    }
}

// Only add event listeners if elements exist
if (document.getElementById('loanAmount')) {
    document.getElementById('loanAmount').addEventListener("input", updateAmountInWords);
}
if (document.getElementById('paymentAmount')) {
    document.getElementById('paymentAmount').addEventListener("input", updateAmountInWords);
}
