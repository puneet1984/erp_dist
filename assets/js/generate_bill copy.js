// Global variables
let services = [];
const taxCalculator = new TaxCalculator();

// Document ready handler with initialization code
$(document).ready(function () {
  // Update bill date constraints
  const billDate = document.getElementById("billDate");
  if (billDate) {
    // Get current date in IST
    const now = new Date();
    const istTime = new Date(now.getTime() + 5.5 * 60 * 60 * 1000); // Add 5:30 hours for IST
    const today = istTime.toISOString().split("T")[0];

    // Set max date to today
    billDate.max = today;

    // Set min date to 15 days ago
    const minDate = new Date(istTime);
    minDate.setDate(minDate.getDate() - 15);
    billDate.min = minDate.toISOString().split("T")[0];

    // Set default value to today
    billDate.value = today;

    // Validate date on change
    billDate.addEventListener("change", function () {
      const selectedDate = new Date(this.value);
      const maxDate = new Date(today);
      const minDate = new Date(billDate.min);

      if (selectedDate > maxDate) {
        alert("Bill date cannot be in the future");
        this.value = today;
      } else if (selectedDate < minDate) {
        alert("Bill date cannot be more than 15 days old");
        this.value = today;
      }
    });
  }

  // Initialize service selection
  $("#serviceSelect")
    .select2({
      placeholder: "Select a service",
      ajax: {
        url: "../../includes/helpers/get_services.php",
        dataType: "json",
        delay: 250,
        data: function (params) {
          return {
            term: params.term, // search term
            dept_id: $("#dept_id").val(), // department ID
          };
        },
        processResults: function (data) {
          return {
            results: data,
          };
        },
        cache: true,
      },
      minimumInputLength: 3,
      templateResult: function (service) {
        if (service.loading) {
          return service.text;
        }
        // For consultation services, don't show the price
        if (service.category === "CONSULTATION") {
          return service.label;
        }
        // For other services, show price
        return `${service.label} (₹${service.price})`;
      },
      templateSelection: formatServiceSelection,
    })
    .on("select2:opening", function () {
      // Use setTimeout to ensure DOM is ready after dropdown opens
      setTimeout(() => {
        const searchField = document.querySelector(".select2-search__field");
        if (searchField) {
          searchField.focus();
        }
      }, 500);
    });

  // Format the service result
  function formatService(service) {
    if (service.loading) {
      return service.text;
    }
    return `${service.label} (₹${service.price})`;
  }

  // Format the selected service
  function formatServiceSelection(service) {
    return service.label || service.text;
  }

  // Handle service selection
  $("#serviceSelect").on("select2:select", function (e) {
    const service = e.params.data;
    addServiceToList(service);
    $(this).val(null).trigger("change"); // Reset selection
  });

  // Form submission
  $("#billForm").on("submit", function (e) {
    e.preventDefault();

    // Disable submit button for 3 seconds
    const submitButton = this.querySelector('button[type="submit"]');
    submitButton.disabled = true;
    setTimeout(() => {
      submitButton.disabled = false;
    }, 3000);

    if (services.length === 0) {
      alert("Please add at least one service!");
      return;
    }

    const formData = new FormData(this);
    formData.append("services", JSON.stringify(services));

    // Add card fee to total amount for card payments
    const paymentType = formData.get("payment_type_id");
    if (paymentType === "6") {
      // CARD
      const { fee, finalAmount } = calculateCardFee();
      formData.append("card_fee", fee);
      formData.append("final_with_fee", finalAmount);
    }

    // Handle split payment amounts
    if (paymentType === "8") {
      // SPLIT
      const cash = parseFloat(formData.get("split_cash")) || 0;
      const card = parseFloat(formData.get("split_card")) || 0;
      const upi = parseFloat(formData.get("split_upi")) || 0;
      const cardType = formData.get("split_card_type");

      const cardFeePercentage = cardType === "credit" ? 0.035 : 0.015;
      const cardFee = card * cardFeePercentage;

      formData.append("split_card_fee", cardFee);
      formData.append("split_total", cash + card + upi);
      formData.append("split_total_with_fees", cash + card + upi + cardFee);
    }

    // Update fetch request handling
    fetch("generate_bill.php", {
      method: "POST",
      body: formData,
      headers: {
        Accept: "application/json",
      },
    })
      .then((response) => {
        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json().catch((err) => {
          throw new Error("Invalid JSON response from server");
        });
      })
      .then((data) => {
        if (data.status === "success") {
          const successMsg = document.createElement("div");
          successMsg.className = "success-message";
          successMsg.innerHTML = `
                    <p>Bill generated successfully!</p>
                    <button type="button" onclick="printBill('${data.bill_id}')" class="btn-print">
                        Print Bill
                    </button>
                `;
          const form = document.getElementById("billForm");
          form.insertAdjacentElement("afterend", successMsg);

          // Delay form reset by 5 seconds
          setTimeout(() => {
            resetForm();
          }, 5000);
        } else {
          throw new Error(data.message || "Failed to generate bill");
        }
      })
      .catch((error) => {
        console.error("Error:", error);
        alert("Failed to generate bill. Please try again.\n" + error.message);
      });
  });

  // Set default payment type as cash on page load
  handlePaymentType(1); // Assuming 1 is the payment_type_id for CASH
});

// ===== Patient and Doctor Management =====
function updateDepartment(doctorId) {
  const option = document.querySelector(
    `select[name="doctor_id"] option[value="${doctorId}"]`
  );
  document.getElementById("dept_id").value = option.dataset.dept;
}

function searchPatient() {
  const searchInput = document.getElementById("patient_search");
  if (!searchInput) {
    console.error("Patient search input not found");
    return;
  }

  const searchTerm = searchInput.value.trim();
  if (!searchTerm) {
    return;
  }

  $.get("../../includes/helpers/get_patient.php", {
    term: searchTerm,
    module: "billing",
  })
    .then((response) => {
      if (response.status === "success") {
        const patient = response.patient;
        $("#patient_id").val(patient.id);
        showPatientInfo(patient);
        enableFormElements(true);
      } else {
        alert(response.message || "Patient not found");
        $("#patient_id").val("");
        $("#patient_details").html("");
        enableFormElements(false);
      }
    })
    .catch((error) => {
      console.error("Error:", error);
      alert("Failed to fetch patient details");
    });
}

function showPatientInfo(patient) {
  const patientNameField = document.getElementById("patientName");
  if (patientNameField) {
    patientNameField.value = patient.name;
  }

  // Create patient details card
  const detailsHtml = `
        <div class="patient-details-card">
            <div class="details-row">
                <label>ID:</label>
                <span>${patient.id}</span>
            </div>
            <div class="details-row">
                <label>Age/Gender:</label>
                <span>${patient.age} years / ${formatGender(
    patient.gender
  )}</span>
            </div>
            ${
              patient.payment_type
                ? `
            <div class="details-row">
                <label>Payment Type:</label>
                <span>${patient.payment_type}</span>
            </div>
            `
                : ""
            }
        </div>
    `;

  const detailsContainer = document.getElementById("patient_details");
  if (detailsContainer) {
    detailsContainer.innerHTML = detailsHtml;
  }
}

function formatGender(gender) {
  const genderMap = {
    M: "Male",
    F: "Female",
    O: "Others",
  };
  return genderMap[gender] || gender;
}

function enableFormElements(enable) {
  const elements = [
    'select[name="doctor_id"]',
    "#serviceSelect",
    'input[name="discount"]',
    'input[name="discount_percent"]',
    'select[name="payment_type_id"]',
  ];

  elements.forEach((selector) => {
    const element = $(selector);
    if (element.length) {
      element.prop("disabled", !enable);
    }
  });
}

// ===== Service Management =====
function addServiceToList(service) {
  console.log("Processing service:", service); // Debug log

  // Only check for duplicates for manually added services
  if (service.id !== "WARD" && service.id !== "DOCTOR_VISIT") {
    if (services.some((s) => s.service_id === service.id)) {
      alert("Service already added!");
      return;
    }
  }

  // Handle specific consultation services
  if (service.category === "CONSULTATION") {
    // Only override price for regular consultation charges
    if (service.label === "Consultation Charges") {
      const doctorSelect = document.querySelector('select[name="doctor_id"]');

      if (!doctorSelect.value) {
        alert("Please select a doctor first for consultation charges!");
        return;
      }

      const selectedOption = doctorSelect.selectedOptions[0];
      const doctorCharge = parseFloat(selectedOption.dataset.charge);

      if (!doctorCharge) {
        alert("Selected doctor has no consultation charge set!");
        return;
      }

      console.log(`Using doctor's consultation charge: ₹${doctorCharge}`); // Debug log
      service.price = doctorCharge;
    }
    // For 'Emergency Consultation Charges' use the price from database
  }

  const newService = {
    service_id: service.id,
    name: service.label,
    category: service.category,
    quantity: 1,
    price: parseFloat(service.price),
    total: parseFloat(service.price),
  };

  services.push(newService);
  updateServicesList();
  calculateSubtotal();
}

function updateServicesList() {
  const list = document.getElementById("servicesList");
  list.innerHTML = services
    .map(
      (service, index) => `
    <div class="service-item">
        <span>${service.name}</span>
        <input type="number" value="${
          service.quantity
        }" min="1" style="width: 60px;"
               onchange="updateQuantity(${index}, this.value)">
        <input type="number" value="${service.price.toFixed(
          2
        )}" min="0" step="0.01" style="width: 100px;"
               onchange="updatePrice(${index}, this.value)">
        <span>₹${service.total.toFixed(2)}</span>
        <button type="button" class="btn-remove" onclick="removeService(${index})">Remove</button>
    </div>
`
    )
    .join("");
}

function updatePrice(index, price) {
  price = parseFloat(price) || 0;
  services[index].price = price;
  services[index].total = price * services[index].quantity;
  updateServicesList();
  calculateSubtotal();
}

function updateQuantity(index, quantity) {
  quantity = parseInt(quantity) || 1;
  services[index].quantity = quantity;
  services[index].total = services[index].price * quantity;
  updateServicesList();
  calculateSubtotal();
}

function removeService(index) {
  services.splice(index, 1);
  updateServicesList();
  calculateSubtotal();
}

// ===== Calculation Functions =====
function calculateSubtotal() {
  const totals = services.reduce(
    (acc, service) => {
      acc.subtotal += service.price * service.quantity;
      return acc;
    },
    { subtotal: 0 }
  );

  const subtotal = totals.subtotal;
  document.getElementById("subtotal").textContent = subtotal.toFixed(2);

  // Only calculate tax in pharmacy section
  const totalTax = taxCalculator.calculateTotalTax(services);
  const taxSection = document.getElementById("taxSection");

  if (taxSection && taxCalculator.shouldShowTax()) {
    taxSection.style.display = "block";
    document.getElementById("totalTax").textContent =
      taxCalculator.formatTaxDisplay(totalTax);
  } else if (taxSection) {
    taxSection.style.display = "none";
  }

  calculateTotal(subtotal, totalTax);
}

function calculateTotal(subtotal = 0, tax = 0) {
  if (!subtotal) {
    subtotal = parseFloat(document.getElementById("subtotal").textContent) || 0;
  }

  const discountTypeElement = document.querySelector(
    'select[name="discount_type"]'
  );
  const discountInput = document.querySelector('input[name="discount"]');
  const discountType = discountTypeElement
    ? discountTypeElement.value
    : "amount";
  const discountValue = parseFloat(discountInput?.value) || 0;

  let discount = 0;
  if (discountType === "percentage") {
    discount = subtotal * (discountValue / 100);
  } else {
    discount = discountValue;
  }

  const total = Math.max(0, Math.ceil(subtotal + tax - discount));
  document.getElementById("totalAmount").textContent = total.toFixed(2);

  // Update amount due in split payment if visible
  if (document.getElementById("split_details")?.style.display === "block") {
    document.getElementById("amount_due").textContent = total.toFixed(2);
    updateSplitTotal(); // Recalculate split totals when total changes
  }

  // Update card fee if needed
  if (document.getElementById("card_details")?.style.display === "block") {
    calculateCardFee();
  }
}

function calculateFromAmount(amount) {
  const subtotal = parseFloat(document.getElementById("subtotal").textContent);
  const discount = parseFloat(amount) || 0;
  document.querySelector('input[name="discount_percent"]').value = (
    (discount / subtotal) *
    100
  ).toFixed(2);
  calculateTotal(discount);
}

function calculateFromPercent(percent) {
  const subtotal = parseFloat(document.getElementById("subtotal").textContent);
  const discountPercent = parseFloat(percent) || 0;
  const discount = subtotal ? subtotal * (discountPercent / 100) : 0;

  // Update the amount discount field
  const discountInput = document.querySelector('input[name="discount"]');
  if (discountInput) {
    discountInput.value = discount.toFixed(2);
  }

  // Calculate total with subtotal and tax
  const tax = parseFloat(document.getElementById("totalTax")?.textContent) || 0;
  calculateTotal(subtotal, tax);
}

function calculateCardFee() {
  const totalAmount =
    parseFloat(document.getElementById("totalAmount")?.textContent) || 0;
  const cardType =
    document.querySelector('select[name="card_type"]')?.value || "debit";
  const feePercentage = cardType === "credit" ? 0.035 : 0.015;

  // Calculate fee based on original amount only
  const fee = totalAmount * feePercentage;
  const finalAmount = totalAmount + fee;

  const feeElement = document.getElementById("convenience_fee");
  const finalElement = document.getElementById("final_with_fee");

  if (feeElement) feeElement.textContent = fee.toFixed(2);
  if (finalElement) finalElement.textContent = finalAmount.toFixed(2);

  return { fee, finalAmount };
}

// ===== Payment Handling =====
function handlePaymentType(typeId) {
  // First hide all payment details sections
  document.querySelectorAll(".payment-details").forEach((div) => {
    div.style.display = "none";
    // Remove required attribute from all inputs in payment details
    div.querySelectorAll("input, select").forEach((input) => {
      input.required = false;
    });
  });

  // Show only the relevant section based on payment type
  switch (parseInt(typeId)) {
    case 2: // COMPANY_CREDIT
      document.getElementById("company_details").style.display = "block";
      document.getElementById("company_name").required = true;
      addVerificationSection("company_details");
      break;
    case 6: // CARD
      const cardDetails = document.getElementById("card_details");
      cardDetails.style.display = "block";
      cardDetails.innerHTML = `
                <div class="card-payment-row">
                    <div class="form-group">
                        <label>Card Type:</label>
                        <select name="card_type" onchange="calculateCardFee()">
                            <option value="debit">Debit Card (1.5% fee)</option>
                            <option value="credit">Credit Card (3.5% fee)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Card Reference:</label>
                        <input type="text" name="card_reference" required placeholder="Enter card reference number">
                    </div>
                </div>
                <div class="card-details-row">
                    <div class="form-group">
                        <label>Convenience Fee:</label>
                        <span id="convenience_fee">0.00</span>
                    </div>
                    <div class="form-group">
                        <label>Final Amount (with fee):</label>
                        <span id="final_with_fee">0.00</span>
                    </div>
                </div>
                <div class="verification-section">
                    <label>Amount Received:</label>
                    <input type="number" id="received_amount" step="0.01" onchange="verifyReceivedAmount()" placeholder="Enter received amount">
                    <div class="verification-status" id="verification_status"></div>
                </div>
            `;
      document.querySelector('select[name="card_type"]').required = true;
      document.querySelector('input[name="card_reference"]').required = true;
      calculateCardFee();
      break;
    case 7: // UPI
      document.getElementById("upi_details").style.display = "block";
      document.querySelector('input[name="upi_ref"]').required = true;
      addVerificationSection("upi_details");
      break;
    case 4: // MEDICLAIM
      document.getElementById("insurance_details").style.display = "block";
      document.getElementById("policy_number").required = true;
      addVerificationSection("insurance_details");
      break;
    case 8: // SPLIT
      document.getElementById("split_details").style.display = "block";
      initializeSplitPayments();
      // Make reference fields required only if amount > 0
      const splitCardAmount =
        parseFloat(document.querySelector('input[name="split_card"]')?.value) ||
        0;
      const splitUpiAmount =
        parseFloat(document.querySelector('input[name="split_upi"]')?.value) ||
        0;

      if (splitCardAmount > 0) {
        document.querySelector(
          'input[name="split_card_reference"]'
        ).required = true;
      }
      if (splitUpiAmount > 0) {
        document.querySelector('input[name="split_upi_ref"]').required = true;
      }
      break;
    default: // CASH
      addVerificationSection("payment-section");
      break;
  }
}

function addVerificationSection(parentId) {
  const container = document.getElementById(parentId);
  if (!container) return;

  const verificationDiv = document.createElement("div");
  verificationDiv.className = "verification-section";
  verificationDiv.innerHTML = `
        <label>Amount Received:</label>
        <input type="number" id="received_amount" step="0.01" onchange="verifyReceivedAmount()" placeholder="Enter received amount">
        <div class="verification-status" id="verification_status"></div>
    `;
  container.appendChild(verificationDiv);
}

function verifyReceivedAmount() {
  const receivedAmount =
    parseFloat(document.getElementById("received_amount")?.value) || 0;
  const paymentType = document.querySelector(
    'select[name="payment_type_id"]'
  )?.value;
  let expectedAmount =
    parseFloat(document.getElementById("totalAmount")?.textContent) || 0;

  // For card payment, add fee to expected amount
  if (paymentType === "6") {
    // Card payment
    const { fee } = calculateCardFee();
    expectedAmount += fee;
  }
  // For UPI payment, use total amount
  else if (paymentType === "7") {
    // UPI payment
    expectedAmount =
      parseFloat(document.getElementById("totalAmount")?.textContent) || 0;
  }
  // For split payment, add card fee if any card payment
  else if (paymentType === "8") {
    const splitCardAmount =
      parseFloat(document.querySelector('input[name="split_card"]')?.value) ||
      0;
    if (splitCardAmount > 0) {
      const cardType = document.querySelector(
        'select[name="split_card_type"]'
      )?.value;
      const cardFeePercentage = cardType === "credit" ? 0.035 : 0.015;
      const cardFee = splitCardAmount * cardFeePercentage;
      expectedAmount += cardFee;
    }
  }

  const status = document.getElementById("verification_status");
  const difference = Math.abs(receivedAmount - expectedAmount);

  if (difference < 0.01) {
    status.textContent = "✓ Amount Matches";
    status.className = "verification-status match";
  } else {
    const shortOrExtra = receivedAmount > expectedAmount ? "extra" : "short";
    status.textContent = `⚠ Amount ${shortOrExtra} by ₹${Math.abs(
      receivedAmount - expectedAmount
    ).toFixed(2)}`;
    status.className = "verification-status mismatch";
  }
}

// ===== Split Payment Functions =====
function initializeSplitPayments() {
  const splitContainer = document.querySelector(".split-container");
  if (!splitContainer) return;

  splitContainer.innerHTML = `
        <div class="split-info">
            <div class="amount-due">Amount Due: ₹<span id="amount_due">0.00</span></div>
            <div class="remaining">Remaining: ₹<span id="remaining_amount">0.00</span></div>
        </div>
        <div class="form-group">
            <label>Cash Payment</label>
            <input type="number" name="split_cash" value="0" min="0" step="0.01" onchange="updateSplitTotal()">
        </div>
        <div class="form-group">
            <label>Card Payment</label>
            <div class="card-section">
                <select name="split_card_type" onchange="updateSplitTotal()">
                    <option value="debit">Debit Card (1.5% fee)</option>
                    <option value="credit">Credit Card (3.5% fee)</option>
                </select>
                <input type="number" name="split_card" value="0" min="0" step="0.01" onchange="updateSplitTotal()">
                <input type="text" name="split_card_reference" placeholder="Card Reference Number">
                <div class="fee-info">Card Fee: <span class="split-card-fee">0</span></div>
            </div>
        </div>
        <div class="form-group">
            <label>UPI Payment</label>
            <input type="number" name="split_upi" value="0" min="0" step="0.01" onchange="updateSplitTotal()">
            <input type="text" name="split_upi_ref" placeholder="UPI Reference">
        </div>
        <div class="total-group">
            <div class="split-totals">
                <div>Split Total: <span id="split_total">0.00</span></div>
                <div>With Fees: <span id="split_total_with_fees">0</span></div>
            </div>
            <div class="verification-section">
                <label>Amount Received:</label>
                <input type="number" id="received_amount" step="0.01" onchange="verifyReceivedAmount()" placeholder="Enter received amount">
                <div class="verification-status" id="verification_status"></div>
            </div>
        </div>
    `;

  // Initialize amount due
  const totalAmount =
    parseFloat(document.getElementById("totalAmount").textContent) || 0;
  document.getElementById("amount_due").textContent = totalAmount.toFixed(2);
  document.getElementById("remaining_amount").textContent =
    totalAmount.toFixed(2);
}

function updateSplitTotal() {
  const cash =
    parseFloat(document.querySelector('input[name="split_cash"]')?.value) || 0;
  const card =
    parseFloat(document.querySelector('input[name="split_card"]')?.value) || 0;
  const upi =
    parseFloat(document.querySelector('input[name="split_upi"]')?.value) || 0;
  const cardType =
    document.querySelector('select[name="split_card_type"]')?.value || "debit";

  const billTotal =
    parseFloat(document.getElementById("amount_due")?.textContent) || 0;

  // Calculate card fee based on card amount portion only
  const cardFeePercentage = cardType === "credit" ? 0.035 : 0.015;
  const cardFee = card * cardFeePercentage;

  const subtotal = cash + card + upi;
  const totalWithFees = subtotal + cardFee;

  // Calculate remaining based on total bill amount (not including fee)
  const remaining = Math.max(0, billTotal - subtotal);

  // Update displays
  document.getElementById("remaining_amount").textContent =
    remaining.toFixed(2);
  document.getElementById("split_total").textContent = subtotal.toFixed(2);
  document.getElementById("split_total_with_fees").textContent =
    totalWithFees.toFixed(2);
  document.querySelector(".split-card-fee").textContent = cardFee.toFixed(2);

  // Update verification amount to include fee
  const receivedInput = document.getElementById("received_amount");
  if (receivedInput) {
    verifyReceivedAmount();
  }

  // Update color based on total match (excluding fee in comparison)
  const splitTotal = document.getElementById("split_total");
  if (splitTotal) {
    splitTotal.style.color =
      Math.abs(subtotal - billTotal) > 0.01 ? "#dc3545" : "#28a745";
  }
}

// ===== Discount Management =====
function updateDiscountType(type) {
  const discountSymbol = document.getElementById("discount-symbol");
  const discountInput = document.querySelector('input[name="discount"]');

  if (type === "percentage") {
    discountSymbol.textContent = "%";
    discountInput.max = "100";
    discountInput.step = "0.01";
    discountInput.value = "0";
  } else {
    discountSymbol.textContent = "₹";
    discountInput.max = "";
    discountInput.step = "1";
    discountInput.value = "0";
  }
  calculateTotal();
}

// ===== Utility Functions =====
function printBill(billId) {
  const type = billId.includes("/P/") ? "pharmacy" : "hospital";
  window.open(
    `print_bill.php?id=${billId}&type=${type}&trigger_print=1`,
    "_blank"
  );
}

function resetForm() {
  // Clear services array
  services = [];
  updateServicesList();

  // Reset the form fields
  const form = document.getElementById("billForm");
  form.reset();

  // Reset Select2 fields
  $("#serviceSelect").val(null).trigger("change");

  // Reset patient name field
  document.getElementById("patientName").value = "";

  // Reset totals
  document.getElementById("subtotal").textContent = "0.00";
  document.getElementById("totalAmount").textContent = "0.00";

  // Hide tax section
  document.getElementById("taxSection").style.display = "none";

  // Reset all payment detail sections
  document.querySelectorAll(".payment-details").forEach((div) => {
    div.style.display = "none";
  });

  // Reset verification status if exists
  const verificationStatus = document.getElementById("verification_status");
  if (verificationStatus) {
    verificationStatus.textContent = "";
    verificationStatus.className = "verification-status";
  }

  // Set default payment type as cash
  handlePaymentType(1);

  // Remove any existing success messages
  const existingMessages = document.querySelectorAll(".success-message");
  existingMessages.forEach((msg) => msg.remove());

  // Enable submit button
  form.querySelector('button[type="submit"]').disabled = false;
}
