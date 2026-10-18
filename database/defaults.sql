INSERT INTO payment_types (id, name) VALUES
(1, 'CASH'),
(2, 'COMPANY_CREDIT'),
(3, 'BNP'),
(4, 'MEDICLAIM'),
(5, 'ESI'),
(6, 'CARD'),
(7, 'UPI'),
(8, 'SPLIT'),
(9, 'IPD');


INSERT INTO `sms_templates` (`template_name`, `template_text`, `variables`) VALUES
('patient_registration_H', 'स्वागत है {patient_name}! आपका अस्पताल आईडी {patient_id} है। अपॉइंटमेंट के लिए 7354346361 पर कॉल करें। इस नंबर को सहेज लें।', '["patient_name", "patient_id"]'),
('patient_registration_E', 'Welcome {patient_name}! Your hospital ID is {patient_id}. For appointments call 7354346361. Save this number.', '["patient_name", "patient_id"]'),
('appointment_booking_H', 'अपॉइंटमेंट पुष्टि: {patient_name} की डॉ. {doctor_name} के साथ {appointment_date} को {appointment_time} पर। अस्पताल आईडी: {patient_id}', '["patient_name", "doctor_name", "appointment_date", "appointment_time", "patient_id"]'),
('appointment_booking_E', 'Appointment confirmed for {patient_name} with Dr. {doctor_name} on {appointment_date} at {appointment_time}. Hospital ID: {patient_id}', '["patient_name", "doctor_name", "appointment_date", "appointment_time", "patient_id"]'),
('appointment_reminder_H', 'अनुस्मारक: आपकी अपॉइंटमेंट डॉ. {doctor_name} के साथ कल {appointment_time} पर है। अस्पताल आईडी: {patient_id}', '["patient_name", "doctor_name", "appointment_time", "patient_id"]'),
('appointment_reminder_E', 'Reminder: Your appointment with Dr. {doctor_name} is tomorrow at {appointment_time}. Hospital ID: {patient_id}', '["patient_name", "doctor_name", "appointment_time", "patient_id"]'),
('appointment_cancellation_H', 'आपकी अपॉइंटमेंट डॉ. {doctor_name} के साथ {appointment_date} के लिए रद्द कर दी गई है। पुनः अपॉइंटमेंट के लिए 7354346361 पर कॉल करें।', '["patient_name", "doctor_name", "appointment_date"]'),
('appointment_cancellation_E', 'Your appointment with Dr. {doctor_name} for {appointment_date} has been cancelled. Call 7354346361 to reschedule.', '["patient_name", "doctor_name", "appointment_date"]'),
('bill_generated_H', '{patient_name} के लिए बिल जनरेट किया गया है। राशि: ₹{amount}, बिल नंबर: {bill_id}। भुगतान के लिए रिसेप्शन पर जाएं।', '["patient_name", "amount", "bill_id"]'),
('bill_generated_E', 'Bill generated for {patient_name}. Amount: ₹{amount}. Bill No: {bill_id}. Visit reception for payment.', '["patient_name", "amount", "bill_id"]'),
('payment_received_H', 'भुगतान प्राप्त: ₹{amount}, बिल नंबर {bill_id}। शेष राशि: ₹{balance}। माहेश्वरी नर्सिंग होम चुनने के लिए धन्यवाद।', '["amount", "bill_id", "balance"]'),
('payment_received_E', 'Payment received: ₹{amount} for Bill {bill_id}. Balance: ₹{balance}. Thank you for choosing Maheshwari Nursing Home.', '["amount", "bill_id", "balance"]'),
('payment_reminder_H', 'बकाया राशि ₹{amount} (बिल {bill_id})। कृपया जल्द से जल्द भुगतान करें।', '["amount", "bill_id"]'),
('payment_reminder_E', 'Reminder: Outstanding balance ₹{amount} for Bill {bill_id}. Please clear your dues.', '["amount", "bill_id"]'),
('prescription_ready_H', 'आपका प्रिस्क्रिप्शन तैयार है। कुल राशि: ₹{amount}। कृपया फार्मेसी से कलेक्ट करें। वैधता: {valid_date}', '["patient_name", "amount", "valid_date"]'),
('prescription_ready_E', 'Your prescription is ready. Total amount: ₹{amount}. Please collect from pharmacy. Valid till {valid_date}', '["patient_name", "amount", "valid_date"]'),
('medicine_reminder_H', 'दवा अनुस्मारक: {patient_name}, {medicine_name} लेने का समय हो गया है। अगली खुराक {next_time} पर है।', '["patient_name", "medicine_name", "next_time"]'),
('medicine_reminder_E', 'Medicine reminder for {patient_name}: Time to take {medicine_name}. Next dose at {next_time}', '["patient_name", "medicine_name", "next_time"]'),
('test_results_ready_H', 'आपकी टेस्ट रिपोर्ट तैयार है। कृपया रिसेप्शन से कलेक्ट करें। मरीज आईडी: {patient_id}', '["patient_name", "patient_id"]'),
('test_results_ready_E', 'Your test results are ready. Please collect from reception. Patient ID: {patient_id}', '["patient_name", "patient_id"]'),
('birthday_wish_H', '🎂 जन्मदिन की शुभकामनाएँ {patient_name}! आपको अच्छे स्वास्थ्य की शुभकामनाएँ। - टीम माहेश्वरी नर्सिंग होम', '["patient_name"]'),
('birthday_wish_E', '🎂 Happy Birthday {patient_name}! Wishing you good health. - Team Maheshwari Nursing Home', '["patient_name"]'),
('followup_reminder_H', '📅 फॉलो-अप अनुस्मारक: कृपया डॉ. {doctor_name} से {appointment_date} को मिलें। पुष्टि के लिए 7354346361 पर कॉल करें।', '["patient_name", "doctor_name", "appointment_date"]'),
('followup_reminder_E', '📅 Follow-up reminder: Please visit Dr. {doctor_name} on {appointment_date}. Call 7354346361 to confirm.', '["patient_name", "doctor_name", "appointment_date"]'),
('stock_low_alert', 'Alert: Low stock for {item_name}. Current quantity: {quantity}. Please reorder.', '["item_name", "quantity"]'),
('stock_expiry_alert', 'Alert: {item_name} Batch:{batch_number} with quantity {quantity} is expiring on {expiry_date}. Please take action.', '["item_name", "batch_number", "quantity", "expiry_date"]');


-- Insert default templates with correct variables
ON DUPLICATE KEY UPDATE template_text = VALUES(template_text), variables = VALUES(variables);

-- First, update existing roles with new permissions
UPDATE roles SET permissions = '{
    "dashboard": ["view"],
    "bills": ["create", "view", "edit", "delete", "print"],
    "inventory": ["view", "add", "edit", "delete"],
    "doctors": ["create", "view", "edit", "delete"],
    "patients": ["create", "view", "edit", "delete", "print"],
    "reports": ["view", "generate", "print"],
    "settings": ["view", "edit"],
    "ipd": ["admit", "view", "edit", "discharge", "transfer", "print"],
    "pharmacy": ["view", "sell", "return", "adjust", "print"],
    "purchases": ["create", "view", "edit", "delete", "print"]
}'
WHERE role_name = 'Admin';

UPDATE roles SET permissions = '{
    "dashboard": ["view"],
    "patients": ["view", "edit"],
    "prescriptions": ["create", "view", "edit"],
    "appointments": ["view", "edit"],
    "reports": ["view"],
    "bills": ["view"],
    "pharmacy": ["view"],
    "ipd": ["view", "edit"]
}'
WHERE role_name = 'doctor';

UPDATE roles SET permissions = '{
    "dashboard": ["view"],
    "patients": ["create", "view", "edit", "print"],
    "appointments": ["create", "view", "edit"],
    "bills": ["create", "view", "print"],
    "pharmacy": ["view", "sell"],
    "reports": ["view"],
    "ipd": ["view", "admit", "print"],
    "purchases": ["view"]
}'
WHERE role_name = 'Receptionist';

-- Then insert if not exists

-- Insert default roles with permissions
INSERT INTO roles (role_name, permissions) VALUES
('Admin', '{
    "dashboard": ["view"],
    "bills": ["create", "view", "edit", "delete", "print"],
    "inventory": ["view", "add", "edit", "delete"],
    "doctors": ["create", "view", "edit", "delete"],
    "patients": ["create", "view", "edit", "delete", "print"],
    "reports": ["view", "generate", "print"],
    "settings": ["view", "edit"],
    "ipd": ["admit", "view", "edit", "discharge", "transfer", "print"],
    "pharmacy": ["view", "sell", "return", "adjust", "print"],
    "purchases": ["create", "view", "edit", "delete", "print"]
}'),
('doctor', '{
    "dashboard": ["view"],
    "patients": ["view", "edit"],
    "prescriptions": ["create", "view", "edit"],
    "appointments": ["view", "edit"],
    "reports": ["view"],
    "bills": ["view"],
    "pharmacy": ["view"],
    "ipd": ["view", "edit"]
}'),
('Receptionist', '{
    "dashboard": ["view"],
    "patients": ["create", "view", "edit", "print"],
    "appointments": ["create", "view", "edit"],
    "bills": ["create", "view", "print"],
    "pharmacy": ["view", "sell"],
    "reports": ["view"],
    "ipd": ["view", "admit", "print"],
    "purchases": ["view"]
}'),
('Manager', '{
    "ipd": ["view"],
    "bills": ["view"],
    "patients": ["view"],
    "pharmacy": ["view"],
    "inventory": ["view"],
    "purchases": ["create", "view", "edit"]
}'),
('Accounts', '{
    "bills": ["view"],
    "pharmacy": ["view"],
    "purchases": ["view"]
}') ON DUPLICATE KEY UPDATE role_name = role_name;




-- Insert default users with hashed default password '<username>9#'
INSERT INTO users (username, password_hash, email, role_id, status) VALUES
('admin', '$2y$10$lEXVaZm/XS7nIFR3ru3QtuDswoiK8SwcyfBhBKQCJEgMtIDT49a0a', 'admin@localhost', 
(SELECT role_id FROM roles WHERE role_name = 'Admin'), 'ACTIVE'),
('doctor', '$2y$10$xF7BBvsFnBZG2ueshqfSc.KinNgKrL5TRx1pFc57TAgNsqlgOsyn6', 'doctor@localhost',
(SELECT role_id FROM roles WHERE role_name = 'doctor'), 'ACTIVE'),
('reception', '$2y$10$bFVxQummr/EYhxALQ1MAuOD5lhLQPGyPDoBgoe5OidPeaFiCOlBW6', 'reception@localhost',
(SELECT role_id FROM roles WHERE role_name = 'Receptionist'), 'ACTIVE'),
('manager', '$2y$10$vx9.hm9bYxQP8v0iTePFVe9R9B4K3C/onHFQIZ6eihqYcG/TKRKEO', 'manager@localhost',
(SELECT role_id FROM roles WHERE role_name = 'Manager'), 'ACTIVE'),
('accounts', '$2y$10$WflG9Hwo03jO0R5Ok4bUeucdHz7sos/4c1pvMYIxNqjoT5SbhI416', 'accounts@localhost',
(SELECT role_id FROM roles WHERE role_name = 'Accounts'), 'ACTIVE')
ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash);



-- Add some default services
INSERT INTO services (name, category, base_charge) VALUES 
('Consultation', 'CONSULTATION', 0),
('Dressing - Small', 'PROCEDURE', 100),
('Dressing - Large', 'PROCEDURE', 200),
('X-Ray - extremities', 'RADIOLOGY', 500),
('X-Ray - spine', 'RADIOLOGY', 1000),
('Plaster Application', 'PROCEDURE', 0),
('Blood Sugar Test', 'PATHOLOGY', 100),
('Urine Pregnancy Test', 'PATHOLOGY', 100),
('ECG', 'DIAGNOSTIC', 300);
