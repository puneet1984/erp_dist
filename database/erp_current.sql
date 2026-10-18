SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+05:30";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;


CREATE TABLE `appointments` (
  `id` int NOT NULL,
  `patient_name` varchar(100) DEFAULT NULL,
  `age` int DEFAULT NULL,
  `mobile` varchar(10) DEFAULT NULL,
  `doctor_id` int DEFAULT NULL,
  `appointment_date` date DEFAULT NULL,
  `appointment_time` time DEFAULT NULL,
  `booking_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `fee_paid` decimal(10,2) DEFAULT NULL,
  `notification_sent` tinyint(1) DEFAULT '0',
  `email_notification_sent` tinyint(1) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Online-appointment doctor schedule (separate from the ERP `doctors` table)
CREATE TABLE `appointment_doctors` (
  `id` int NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `morning_start` time DEFAULT NULL,
  `morning_end` time DEFAULT NULL,
  `evening_start` time DEFAULT NULL,
  `evening_end` time DEFAULT NULL,
  `regular_fee` decimal(10,2) DEFAULT NULL,
  `emergency_fee` decimal(10,2) DEFAULT NULL,
  `working_days` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `appointment_doctor_off_days` (
  `id` int NOT NULL,
  `doctor_id` int NOT NULL,
  `off_date` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `bed_allocation` (
  `id` int NOT NULL,
  `ward_id` int NOT NULL,
  `bed_number` varchar(10) NOT NULL,
  `admission_id` int DEFAULT NULL,
  `patient_id` bigint UNSIGNED DEFAULT NULL,
  `status` enum('Available','Occupied') DEFAULT 'Available',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `billing_audit` (
  `audit_id` int NOT NULL,
  `bill_id` varchar(50) DEFAULT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `modified_by` int NOT NULL,
  `modified_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `changes` json NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `bills` (
  `id` int NOT NULL,
  `bill_id` varchar(50) NOT NULL,
  `transaction_id` varchar(50) DEFAULT NULL,
  `bill_date` date NOT NULL,
  `FY` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `patient_id` bigint UNSIGNED NOT NULL,
  `admission_id` int DEFAULT NULL,
  `doctor_id` int NOT NULL,
  `dept_id` int NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `discount` decimal(10,2) DEFAULT '0.00',
  `final_amount` decimal(10,2) NOT NULL,
  `payment_type_id` int NOT NULL,
  `payment_details` json DEFAULT NULL,
  `payment_status` varchar(20) DEFAULT 'PENDING',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `status` varchar(20) DEFAULT 'ACTIVE',
  `remarks` varchar(200) DEFAULT NULL,
  `bill_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `cancellation_reason` varchar(50) DEFAULT NULL,
  `cancellation_notes` text,
  `refund_reference` varchar(100) DEFAULT NULL,
  `cancelled_by` int DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `company_id` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `bill_items` (
  `id` int NOT NULL,
  `bill_id` varchar(50) DEFAULT NULL,
  `service_id` int NOT NULL,
  `quantity` int NOT NULL DEFAULT '1',
  `unit_price` decimal(10,2) NOT NULL,
  `total` decimal(10,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `certificates` (
  `id` int NOT NULL,
  `certificate_number` varchar(50) NOT NULL,
  `patient_id` bigint UNSIGNED NOT NULL,
  `doctor_id` int NOT NULL,
  `certificate_type` varchar(50) NOT NULL,
  `issue_date` date NOT NULL,
  `diagnosis` text,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `details` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `company_accounts` (
  `company_id` int NOT NULL,
  `company_name` varchar(100) NOT NULL,
  `contact_person` varchar(50) DEFAULT NULL,
  `contact_number` varchar(15) DEFAULT NULL,
  `credit_limit` decimal(10,2) DEFAULT '0.00',
  `current_balance` decimal(10,2) DEFAULT '0.00',
  `payment_days` int NOT NULL DEFAULT '30',
  `late_fee_percentage` decimal(5,2) DEFAULT '0.00',
  `terms_valid_from` date DEFAULT NULL,
  `terms_valid_till` date DEFAULT NULL,
  `status` enum('ACTIVE','BLOCKED','INACTIVE') DEFAULT 'ACTIVE',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `company_advances` (
  `txn_id` int NOT NULL AUTO_INCREMENT,
  `company_id` int NOT NULL,
  `txn_type` enum('RECEIPT','ADJUSTMENT','REFUND') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_type_id` int DEFAULT NULL,
  `reference_no` varchar(50) DEFAULT NULL,
  `bill_id` varchar(50) DEFAULT NULL,
  `txn_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `notes` text,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`txn_id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_bill` (`bill_id`),
  CONSTRAINT `company_advances_ibfk_1`
    FOREIGN KEY (`company_id`) REFERENCES `company_accounts` (`company_id`)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `company_advances_ibfk_2`
    FOREIGN KEY (`payment_type_id`) REFERENCES `payment_types` (`payment_type_id`),
  CONSTRAINT `company_advances_ibfk_3`
    FOREIGN KEY (`bill_id`) REFERENCES `bills` (`bill_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


CREATE TABLE `departments` (
  `dept_id` int NOT NULL,
  `name` varchar(50) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `doctors` (
  `doctor_id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `dept_id` int NOT NULL,
  `specialization` varchar(100) DEFAULT NULL,
  `contact` varchar(15) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `status` enum('ACTIVE','INACTIVE') DEFAULT 'ACTIVE',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `degree` varchar(50) DEFAULT NULL,
  `registration_no` varchar(50) DEFAULT NULL,
  `charge_per_visit` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `doctor_patients` (
  `id` int NOT NULL,
  `doctor_id` int NOT NULL,
  `patient_id` bigint UNSIGNED NOT NULL,
  `assigned_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `insurance_claims` (
  `claim_id` int NOT NULL,
  `claim_number` varchar(50) DEFAULT NULL,
  `bill_id` varchar(50) DEFAULT NULL,
  `patient_id` bigint UNSIGNED NOT NULL,
  `provider_id` int DEFAULT NULL,
  `member_id` varchar(50) DEFAULT NULL,
  `doctor_id` int DEFAULT NULL,
  `dept_id` int DEFAULT NULL,
  `policy_number` varchar(50) DEFAULT NULL,
  `approval_number` varchar(50) DEFAULT NULL,
  `total_amount` decimal(10,2) DEFAULT NULL,
  `approved_amount` decimal(10,2) DEFAULT NULL,
  `copay_amount` decimal(10,2) DEFAULT NULL,
  `discount_amount` decimal(10,2) DEFAULT NULL,
  `deduction_amount` decimal(10,2) DEFAULT '0.00',
  `final_approved` decimal(10,2) DEFAULT NULL,
  `patient_responsibility` decimal(10,2) DEFAULT NULL,
  `status` enum('PENDING','PARTIALLY_APPROVED','FULLY_APPROVED','REJECTED') DEFAULT NULL,
  `processed_by` int DEFAULT NULL,
  `approval_details` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `insurance_providers` (
  `provider_id` int NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `phone` varchar(15) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `TPA_name` varchar(100) NOT NULL,
  `insurance_comp_name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `ipd_admissions` (
  `admission_id` int NOT NULL,
  `patient_id` bigint UNSIGNED NOT NULL,
  `admission_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ward_id` int NOT NULL,
  `bed_number` varchar(10) NOT NULL,
  `doctor_id` int NOT NULL,
  `payment_type_id` int NOT NULL DEFAULT '1',
  `diagnosis` text,
  `presenting_complaints` text,
  `examination_details` text,
  `treatment_plan` text,
  `surgery_details` text,
  `surgery_date` date DEFAULT NULL,
  `status` enum('Admitted','Discharged') DEFAULT 'Admitted',
  `discharge_date` datetime DEFAULT NULL,
  `discharge_notes` text,
  `discharge_condition` text,
  `discharge_medications` json DEFAULT NULL COMMENT '\r\n    Format: [\r\n        {\r\n            "medicine": "Medicine Name",\r\n            "dosage": "1-0-1",\r\n            "duration": "7 days",\r\n            "timing": "After food",\r\n            "notes": "Special instructions"\r\n        }\r\n    ]',
  `followup_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `patients` (
  `patient_id` bigint UNSIGNED NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `age` tinyint UNSIGNED DEFAULT NULL,
  `gender` enum('M','F','O') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `occupation` varchar(50) DEFAULT NULL,
  `contact` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `city` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `state` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `aadhar_number` char(12) DEFAULT NULL,
  `abha_id` char(14) DEFAULT NULL,
  `ayushmaan_id` char(20) DEFAULT NULL,
  `payment_type_id` int NOT NULL DEFAULT '1',
  `company_name` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `sms_sent` tinyint(1) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `payment_collections` (
  `collection_id` int NOT NULL,
  `ledger_id` int NOT NULL,
  `bill_id` varchar(50) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_type_id` int NOT NULL,
  `payment_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `payment_details` json DEFAULT NULL,
  `reference_no` varchar(50) DEFAULT NULL,
  `collected_by` int DEFAULT NULL,
  `notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `payment_ledger` (
  `ledger_id` int NOT NULL,
  `bill_id` varchar(50) DEFAULT NULL,
  `transaction_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `transaction_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `amount_due` decimal(10,2) NOT NULL,
  `amount_paid` decimal(10,2) NOT NULL DEFAULT '0.00',
  `balance_amount` decimal(10,2) GENERATED ALWAYS AS ((`amount_due` - `amount_paid`)) VIRTUAL,
  `payment_status` varchar(20) DEFAULT 'PENDING',
  `payment_type_id` int NOT NULL,
  `payment_due_date` date DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `company_id` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `payment_types` (
  `payment_type_id` int NOT NULL,
  `type_name` varchar(50) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pharmacy_batches` (
  `batch_id` int NOT NULL,
  `item_id` int NOT NULL,
  `batch_number` varchar(50) NOT NULL,
  `initial_quantity` int NOT NULL,
  `current_quantity` int NOT NULL,
  `purchase_rate` decimal(10,2) NOT NULL,
  `retail_price` decimal(10,2) NOT NULL,
  `expiry_date` date NOT NULL,
  `invoice_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `supplier_id` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pharmacy_items` (
  `item_id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `category` enum('MEDICINE','CONSUMABLE') NOT NULL DEFAULT 'MEDICINE',
  `active_ingredient` varchar(512) DEFAULT NULL,
  `dosage_form` varchar(50) DEFAULT NULL,
  `manufacturer` varchar(100) DEFAULT NULL,
  `reorder_level` int DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `is_multidose` tinyint(1) DEFAULT '0',
  `doses_per_unit` int DEFAULT '1',
  `dose_unit` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pharmacy_purchases` (
  `purchase_id` int NOT NULL,
  `transaction_id` varchar(50) DEFAULT NULL,
  `invoice_number` varchar(50) NOT NULL,
  `supplier_id` int NOT NULL,
  `invoice_date` date NOT NULL,
  `purchase_date` date NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `tax_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `final_amount` decimal(10,2) NOT NULL,
  `payment_status` varchar(20) DEFAULT 'PENDING',
  `payment_details` json DEFAULT NULL,
  `notes` text,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pharmacy_sales` (
  `sale_id` int NOT NULL,
  `transaction_id` varchar(50) DEFAULT NULL,
  `bill_id` varchar(50) NOT NULL,
  `FY` varchar(15) DEFAULT NULL,
  `patient_id` bigint UNSIGNED DEFAULT NULL,
  `doctor_id` int DEFAULT NULL,
  `sale_date` date NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `discount_type` varchar(20) DEFAULT 'AMOUNT',
  `tax_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `final_amount` decimal(10,2) NOT NULL,
  `payment_type_id` int NOT NULL,
  `payment_details` json DEFAULT NULL,
  `payment_status` varchar(20) DEFAULT 'PENDING',
  `admission_id` int DEFAULT NULL,
  `notes` text,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pharmacy_transaction_items` (
  `item_id` int NOT NULL,
  `transaction_id` varchar(50) NOT NULL,
  `batch_id` int NOT NULL,
  `quantity` int NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `tax_percentage` decimal(5,2) DEFAULT '0.00',
  `tax_amount` decimal(10,2) DEFAULT '0.00',
  `discount_amount` decimal(10,2) DEFAULT '0.00',
  `final_amount` decimal(10,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pharmacy_stock_adjustments` (
  `adjustment_id` int NOT NULL AUTO_INCREMENT,
  `adjustment_code` varchar(50) NOT NULL,
  `financial_year` varchar(15) NOT NULL,
  `adjustment_type` enum('YEAR_END','EXPIRY_WRITEOFF','DAMAGE','LOSS','FREE_ISSUE') NOT NULL DEFAULT 'YEAR_END',
  `adjustment_date` date NOT NULL,
  `reference_name` varchar(150) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_reverted` tinyint(1) NOT NULL DEFAULT '0',
  `reverted_at` datetime DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`adjustment_id`),
  UNIQUE KEY `uq_pharmacy_stock_adjustment_code` (`adjustment_code`),
  KEY `idx_pharmacy_stock_adjustment_fy` (`financial_year`),
  KEY `idx_pharmacy_stock_adjustment_type` (`adjustment_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pharmacy_stock_adjustment_items` (
  `adjustment_item_id` int NOT NULL AUTO_INCREMENT,
  `adjustment_id` int NOT NULL,
  `batch_id` int NOT NULL,
  `item_id` int NOT NULL,
  `batch_number` varchar(50) NOT NULL,
  `system_quantity` int NOT NULL,
  `entered_quantity` int NOT NULL,
  `final_quantity` int NOT NULL,
  `adjustment_quantity` int NOT NULL,
  `purchase_rate` decimal(10,2) NOT NULL DEFAULT '0.00',
  `retail_price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `expiry_date` date NOT NULL,
  `line_note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`adjustment_item_id`),
  UNIQUE KEY `uq_pharmacy_stock_adjustment_batch` (`adjustment_id`,`batch_id`),
  KEY `idx_pharmacy_stock_adjustment_item` (`item_id`),
  CONSTRAINT `fk_psa_items_adjustment` FOREIGN KEY (`adjustment_id`) REFERENCES `pharmacy_stock_adjustments` (`adjustment_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_psa_items_batch` FOREIGN KEY (`batch_id`) REFERENCES `pharmacy_batches` (`batch_id`),
  CONSTRAINT `fk_psa_items_item` FOREIGN KEY (`item_id`) REFERENCES `pharmacy_items` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


CREATE TABLE `pharmacy_supplier_returns` (
  return_id INT NOT NULL AUTO_INCREMENT,
  return_number VARCHAR(50) NOT NULL COMMENT 'Unique return identifier',
  supplier_id INT NOT NULL,
  purchase_id INT NOT NULL,
  return_date DATE NOT NULL,
  notes TEXT NULL,
  created_by INT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (return_id),
  UNIQUE KEY uniq_return_number (return_number),
  KEY idx_supplier_date (supplier_id, return_date),
  KEY idx_purchase_id (purchase_id),
  CONSTRAINT fk_psr_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (supplier_id),
  CONSTRAINT fk_psr_purchase FOREIGN KEY (purchase_id) REFERENCES pharmacy_purchases (purchase_id),
  CONSTRAINT fk_psr_user FOREIGN KEY (created_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Supplier returns header - totals calculated from line items';

CREATE TABLE `pharmacy_supplier_return_items` (
  item_id INT NOT NULL,
  return_id INT NOT NULL,
  batch_id INT NOT NULL,
  quantity INT NOT NULL,
  unit_rate DECIMAL(10,2) NOT NULL,
  deduction_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Deduction in rupees (damages/quality/etc)',
  tax_percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Tax % (GST/VAT/etc)',
  notes TEXT NULL COMMENT 'Return notes, deduction reasons, and other details',
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (return_id, item_id, batch_id),
  KEY idx_item_id (item_id),
  KEY idx_return_id (return_id),
  KEY idx_batch_id (batch_id),
  CONSTRAINT fk_psri_return FOREIGN KEY (return_id) REFERENCES `pharmacy_supplier_returns` (return_id) ON DELETE CASCADE,
  CONSTRAINT fk_psri_item FOREIGN KEY (item_id) REFERENCES pharmacy_items (item_id),
  CONSTRAINT fk_psri_batch FOREIGN KEY (batch_id) REFERENCES pharmacy_batches (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci 
COMMENT='Return line items - amounts calculated as: base=qty×rate, deduction in rupees, taxable=base-deduction, tax=taxable×tax_percentage, final=taxable+tax';


CREATE TABLE `roles` (
  `role_id` int NOT NULL,
  `role_name` varchar(50) NOT NULL,
  `permissions` json NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `services` (
  `service_id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `base_charge` decimal(10,2) NOT NULL,
  `category` varchar(50) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `sms_logs` (
  `log_id` bigint UNSIGNED NOT NULL,
  `patient_id` bigint UNSIGNED DEFAULT NULL,
  `mobile_number` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `template_id` int DEFAULT NULL,
  `review_sent` varchar(100) DEFAULT 'PENDING',
  `status` varchar(50) DEFAULT 'PENDING',
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `message_type` varchar(50) DEFAULT 'WHATSAPP',
  `sms_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `sms_templates` (
  `template_id` int NOT NULL,
  `template_name` varchar(50) NOT NULL,
  `template_text` text NOT NULL,
  `variables` json NOT NULL COMMENT 'List of variables used in template',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `split_payments` (
  `split_id` int NOT NULL,
  `collection_id` int NOT NULL,
  `payment_type_id` int NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_details` json DEFAULT NULL,
  `reference_no` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `stock_alert_logs` (
  `id` int NOT NULL,
  `item_id` int NOT NULL,
  `alert_type` enum('LOW_STOCK','EXPIRING_SOON') NOT NULL,
  `message` text NOT NULL,
  `mobile_number` varchar(15) NOT NULL,
  `status` enum('PENDING','SUCCESS','FAILED') DEFAULT 'PENDING',
  `sent_at` timestamp NULL DEFAULT NULL,
  `next_alert_after` timestamp GENERATED ALWAYS AS ((`sent_at` + interval 14 day)) STORED NULL,
  `error_message` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `suppliers` (
  `supplier_id` int NOT NULL,
  `supplier_name` varchar(100) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `system_settings` (
  `category` varchar(50) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `tokens` (
  `id` int NOT NULL,
  `reconstructed_id` varchar(50) NOT NULL,
  `patient_name` varchar(100) NOT NULL,
  `contact` varchar(15) DEFAULT NULL,
  `doctor_name` varchar(100) NOT NULL,
  `doctor_id` int DEFAULT NULL,
  `token_number` int NOT NULL,
  `date` date NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `users` (
  `user_id` int NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `email` varchar(100) NOT NULL,
  `role_id` int NOT NULL,
  `status` enum('ACTIVE','INACTIVE') DEFAULT 'ACTIVE',
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `wards` (
  `id` int NOT NULL,
  `ward_name` varchar(50) NOT NULL,
  `ward_type_id` int NOT NULL,
  `total_beds` int NOT NULL,
  `charges_per_day` decimal(10,2) NOT NULL,
  `status` enum('Active','Inactive') DEFAULT 'Active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `ward_types` (
  `ward_type_id` int NOT NULL,
  `type_name` varchar(30) NOT NULL,
  `description` text,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


ALTER TABLE `appointments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `doctor_id` (`doctor_id`),
  ADD KEY `appointment_date` (`appointment_date`);

ALTER TABLE `appointment_doctors`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `appointment_doctor_off_days`
  ADD PRIMARY KEY (`id`),
  ADD KEY `doctor_id` (`doctor_id`);

ALTER TABLE `bed_allocation`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_ward_bed` (`ward_id`,`bed_number`),
  ADD KEY `admission_id` (`admission_id`),
  ADD KEY `patient_id` (`patient_id`),
  ADD KEY `idx_bed_tracking` (`ward_id`,`status`);

ALTER TABLE `billing_audit`
  ADD PRIMARY KEY (`audit_id`),
  ADD KEY `bill_id` (`bill_id`),
  ADD KEY `modified_by` (`modified_by`);

ALTER TABLE `bills`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `bill_id` (`bill_id`),
  ADD KEY `patient_id` (`patient_id`),
  ADD KEY `idx_bill_doctor` (`doctor_id`),
  ADD KEY `idx_bill_dept` (`dept_id`),
  ADD KEY `idx_bill_fy` (`bill_id`),
  ADD KEY `idx_bill_payment` (`payment_status`),
  ADD KEY `idx_bill_created` (`created_at`),
  ADD KEY `idx_bill_search` (`bill_date`,`patient_id`,`payment_status`),
  ADD KEY `idx_bill_doctor_dept` (`doctor_id`,`dept_id`),
  ADD KEY `idx_bills_company` (`company_id`),
  ADD KEY `idx_bill_type_status` (`payment_type_id`,`status`);

ALTER TABLE `bill_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `bill_id` (`bill_id`),
  ADD KEY `service_id` (`service_id`);

ALTER TABLE `certificates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `certificate_number` (`certificate_number`),
  ADD KEY `patient_id` (`patient_id`),
  ADD KEY `doctor_id` (`doctor_id`);

ALTER TABLE `company_accounts`
  ADD PRIMARY KEY (`company_id`),
  ADD UNIQUE KEY `company_name` (`company_name`);

ALTER TABLE `departments`
  ADD PRIMARY KEY (`dept_id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD KEY `idx_dept_search` (`name`);

ALTER TABLE `doctors`
  ADD PRIMARY KEY (`doctor_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_doctor_dept` (`dept_id`),
  ADD KEY `idx_doctor_search` (`name`,`status`),
  ADD KEY `idx_doctor_dept_status` (`dept_id`,`status`);

ALTER TABLE `doctor_patients`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_doctor_patient` (`doctor_id`,`patient_id`),
  ADD KEY `patient_id` (`patient_id`),
  ADD KEY `idx_doctor_patients` (`doctor_id`);

ALTER TABLE `insurance_claims`
  ADD PRIMARY KEY (`claim_id`),
  ADD UNIQUE KEY `claim_number` (`claim_number`),
  ADD KEY `processed_by` (`processed_by`),
  ADD KEY `bill_id` (`bill_id`),
  ADD KEY `patient_id` (`patient_id`),
  ADD KEY `provider_id` (`provider_id`),
  ADD KEY `doctor_id` (`doctor_id`),
  ADD KEY `dept_id` (`dept_id`),
  ADD KEY `idx_claim_search` (`claim_number`,`bill_id`,`patient_id`),
  ADD KEY `idx_claim_tracking` (`status`,`provider_id`);

ALTER TABLE `insurance_providers`
  ADD PRIMARY KEY (`provider_id`),
  ADD UNIQUE KEY `unique_tpa_insurance` (`TPA_name`,`insurance_comp_name`),
  ADD KEY `idx_provider_search` (`TPA_name`,`insurance_comp_name`);

ALTER TABLE `ipd_admissions`
  ADD PRIMARY KEY (`admission_id`),
  ADD KEY `ward_id` (`ward_id`),
  ADD KEY `payment_type_id` (`payment_type_id`),
  ADD KEY `idx_admission_search` (`patient_id`,`status`,`admission_date`),
  ADD KEY `idx_admission_doctor_ward` (`doctor_id`,`ward_id`);

ALTER TABLE `patients`
  ADD PRIMARY KEY (`patient_id`),
  ADD UNIQUE KEY `aadhar_number` (`aadhar_number`),
  ADD UNIQUE KEY `abha_id` (`abha_id`),
  ADD UNIQUE KEY `ayushmaan_id` (`ayushmaan_id`),
  ADD KEY `idx_patient_search` (`name`,`contact`),
  ADD KEY `idx_patient_id_search` (`patient_id`),
  ADD KEY `idx_patient_payment_type` (`payment_type_id`,`company_name`),
  ADD KEY `idx_patient_identifiers` (`aadhar_number`,`abha_id`,`ayushmaan_id`);

ALTER TABLE `payment_collections`
  ADD PRIMARY KEY (`collection_id`),
  ADD KEY `ledger_id` (`ledger_id`),
  ADD KEY `bill_id` (`bill_id`),
  ADD KEY `payment_type_id` (`payment_type_id`),
  ADD KEY `collected_by` (`collected_by`),
  ADD KEY `idx_collection_search` (`payment_date`,`payment_type_id`,`collected_by`);

ALTER TABLE `payment_ledger`
  ADD PRIMARY KEY (`ledger_id`),
  ADD KEY `payment_type_id` (`payment_type_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `company_id` (`company_id`),
  ADD KEY `idx_ledger_tracking` (`bill_id`,`payment_status`,`payment_due_date`);

ALTER TABLE `payment_types`
  ADD PRIMARY KEY (`payment_type_id`),
  ADD UNIQUE KEY `type_name` (`type_name`);

ALTER TABLE `pharmacy_batches`
  ADD PRIMARY KEY (`batch_id`),
  ADD UNIQUE KEY `item_id` (`item_id`,`batch_number`),
  ADD KEY `idx_batch_tracking` (`item_id`,`batch_number`,`expiry_date`,`current_quantity`),
  ADD KEY `idx_stock_tracking` (`item_id`,`current_quantity`,`expiry_date`),
  ADD KEY `pharmacy_batches_ibfk_2` (`supplier_id`);

ALTER TABLE `pharmacy_items`
  ADD PRIMARY KEY (`item_id`),
  ADD KEY `idx_item_search` (`name`,`category`);

ALTER TABLE `pharmacy_purchases`
  ADD PRIMARY KEY (`purchase_id`),
  ADD UNIQUE KEY `transaction_id` (`transaction_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `pharmacy_purchases_ibfk_2` (`supplier_id`);

ALTER TABLE `pharmacy_sales`
  ADD PRIMARY KEY (`sale_id`),
  ADD UNIQUE KEY `transaction_id` (`transaction_id`),
  ADD KEY `patient_id` (`patient_id`),
  ADD KEY `doctor_id` (`doctor_id`),
  ADD KEY `payment_type_id` (`payment_type_id`),
  ADD KEY `admission_id` (`admission_id`),
  ADD KEY `created_by` (`created_by`);

ALTER TABLE `pharmacy_transaction_items`
  ADD PRIMARY KEY (`item_id`),
  ADD KEY `batch_id` (`batch_id`),
  ADD KEY `transaction_id` (`transaction_id`);

ALTER TABLE `roles`
  ADD PRIMARY KEY (`role_id`),
  ADD UNIQUE KEY `role_name` (`role_name`);

ALTER TABLE `services`
  ADD PRIMARY KEY (`service_id`),
  ADD UNIQUE KEY `name` (`name`);

ALTER TABLE `sms_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `template_id` (`template_id`),
  ADD KEY `idx_sms_tracking` (`patient_id`,`status`,`sms_type`),
  ADD KEY `idx_sms_search` (`mobile_number`,`created_at`);

ALTER TABLE `sms_templates`
  ADD PRIMARY KEY (`template_id`),
  ADD UNIQUE KEY `template_name` (`template_name`),
  ADD KEY `idx_template_lookup` (`template_name`,`template_id`);

ALTER TABLE `split_payments`
  ADD PRIMARY KEY (`split_id`),
  ADD KEY `collection_id` (`collection_id`),
  ADD KEY `payment_mode_id` (`payment_type_id`);

ALTER TABLE `stock_alert_logs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_pending_alert` (`item_id`,`alert_type`,`mobile_number`,`status`),
  ADD KEY `idx_pending_alerts` (`status`,`next_alert_after`),
  ADD KEY `idx_item_alert` (`item_id`,`alert_type`);

ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`supplier_id`),
  ADD UNIQUE KEY `unique_supplier_name` (`supplier_name`);

ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`category`,`setting_key`);

ALTER TABLE `tokens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `doctor_id` (`doctor_id`),
  ADD KEY `date` (`date`);

ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `role_id` (`role_id`);

ALTER TABLE `wards`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ward_type_id` (`ward_type_id`),
  ADD KEY `idx_ward_availability` (`status`,`total_beds`);

ALTER TABLE `ward_types`
  ADD PRIMARY KEY (`ward_type_id`),
  ADD UNIQUE KEY `type_name` (`type_name`);


ALTER TABLE `appointments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `appointment_doctors`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `appointment_doctor_off_days`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `bed_allocation`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `billing_audit`
  MODIFY `audit_id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `bills`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `bill_items`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `certificates`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `company_accounts`
  MODIFY `company_id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `departments`
  MODIFY `dept_id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `doctors`
  MODIFY `doctor_id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `doctor_patients`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `insurance_claims`
  MODIFY `claim_id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `insurance_providers`
  MODIFY `provider_id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `ipd_admissions`
  MODIFY `admission_id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `payment_collections`
  MODIFY `collection_id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `payment_ledger`
  MODIFY `ledger_id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `payment_types`
  MODIFY `payment_type_id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `pharmacy_batches`
  MODIFY `batch_id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `pharmacy_items`
  MODIFY `item_id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `pharmacy_purchases`
  MODIFY `purchase_id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `pharmacy_sales`
  MODIFY `sale_id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `pharmacy_transaction_items`
  MODIFY `item_id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `roles`
  MODIFY `role_id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `services`
  MODIFY `service_id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `sms_logs`
  MODIFY `log_id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `sms_templates`
  MODIFY `template_id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `split_payments`
  MODIFY `split_id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `stock_alert_logs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `suppliers`
  MODIFY `supplier_id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `tokens`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `users`
  MODIFY `user_id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `wards`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `ward_types`
  MODIFY `ward_type_id` int NOT NULL AUTO_INCREMENT;


ALTER TABLE `appointments`
  ADD CONSTRAINT `appointments_ibfk_1` FOREIGN KEY (`doctor_id`) REFERENCES `appointment_doctors` (`id`) ON DELETE SET NULL;

ALTER TABLE `appointment_doctor_off_days`
  ADD CONSTRAINT `appointment_doctor_off_days_ibfk_1` FOREIGN KEY (`doctor_id`) REFERENCES `appointment_doctors` (`id`) ON DELETE CASCADE;

ALTER TABLE `bed_allocation`
  ADD CONSTRAINT `bed_allocation_ibfk_1` FOREIGN KEY (`ward_id`) REFERENCES `wards` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `bed_allocation_ibfk_2` FOREIGN KEY (`admission_id`) REFERENCES `ipd_admissions` (`admission_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `bed_allocation_ibfk_3` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) ON DELETE SET NULL;

ALTER TABLE `billing_audit`
  ADD CONSTRAINT `billing_audit_ibfk_1` FOREIGN KEY (`bill_id`) REFERENCES `bills` (`bill_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `billing_audit_ibfk_2` FOREIGN KEY (`modified_by`) REFERENCES `users` (`user_id`);

ALTER TABLE `bills`
  ADD CONSTRAINT `bills_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`),
  ADD CONSTRAINT `bills_ibfk_2` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`doctor_id`),
  ADD CONSTRAINT `bills_ibfk_3` FOREIGN KEY (`dept_id`) REFERENCES `departments` (`dept_id`),
  ADD CONSTRAINT `bills_company_fk` FOREIGN KEY (`company_id`) REFERENCES `company_accounts` (`company_id`),
  ADD CONSTRAINT `bills_ibfk_4` FOREIGN KEY (`payment_type_id`) REFERENCES `payment_types` (`payment_type_id`);

ALTER TABLE `bill_items`
  ADD CONSTRAINT `bill_items_ibfk_1` FOREIGN KEY (`bill_id`) REFERENCES `bills` (`bill_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `bill_items_ibfk_2` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) ON DELETE CASCADE;

ALTER TABLE `certificates`
  ADD CONSTRAINT `certificates_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`),
  ADD CONSTRAINT `certificates_ibfk_2` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`doctor_id`);

ALTER TABLE `doctors`
  ADD CONSTRAINT `doctors_ibfk_1` FOREIGN KEY (`dept_id`) REFERENCES `departments` (`dept_id`) ON DELETE CASCADE;

ALTER TABLE `doctor_patients`
  ADD CONSTRAINT `doctor_patients_ibfk_1` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`doctor_id`),
  ADD CONSTRAINT `doctor_patients_ibfk_2` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`);

ALTER TABLE `insurance_claims`
  ADD CONSTRAINT `insurance_claims_ibfk_1` FOREIGN KEY (`processed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `insurance_claims_ibfk_2` FOREIGN KEY (`bill_id`) REFERENCES `bills` (`bill_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `insurance_claims_ibfk_3` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`),
  ADD CONSTRAINT `insurance_claims_ibfk_4` FOREIGN KEY (`provider_id`) REFERENCES `insurance_providers` (`provider_id`),
  ADD CONSTRAINT `insurance_claims_ibfk_5` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`doctor_id`),
  ADD CONSTRAINT `insurance_claims_ibfk_6` FOREIGN KEY (`dept_id`) REFERENCES `departments` (`dept_id`);

ALTER TABLE `ipd_admissions`
  ADD CONSTRAINT `ipd_admissions_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `ipd_admissions_ibfk_2` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`doctor_id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `ipd_admissions_ibfk_3` FOREIGN KEY (`ward_id`) REFERENCES `wards` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `ipd_admissions_ibfk_4` FOREIGN KEY (`payment_type_id`) REFERENCES `payment_types` (`payment_type_id`) ON DELETE RESTRICT;

ALTER TABLE `patients`
  ADD CONSTRAINT `patients_ibfk_1` FOREIGN KEY (`payment_type_id`) REFERENCES `payment_types` (`payment_type_id`);

ALTER TABLE `payment_collections`
  ADD CONSTRAINT `payment_collections_ibfk_1` FOREIGN KEY (`ledger_id`) REFERENCES `payment_ledger` (`ledger_id`),
  ADD CONSTRAINT `payment_collections_ibfk_2` FOREIGN KEY (`bill_id`) REFERENCES `bills` (`bill_id`),
  ADD CONSTRAINT `payment_collections_ibfk_3` FOREIGN KEY (`payment_type_id`) REFERENCES `payment_types` (`payment_type_id`),
  ADD CONSTRAINT `payment_collections_ibfk_4` FOREIGN KEY (`collected_by`) REFERENCES `users` (`user_id`);

ALTER TABLE `payment_ledger`
  ADD CONSTRAINT `payment_ledger_ibfk_1` FOREIGN KEY (`bill_id`) REFERENCES `bills` (`bill_id`),
  ADD CONSTRAINT `payment_ledger_ibfk_2` FOREIGN KEY (`payment_type_id`) REFERENCES `payment_types` (`payment_type_id`),
  ADD CONSTRAINT `payment_ledger_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `payment_ledger_ibfk_4` FOREIGN KEY (`company_id`) REFERENCES `company_accounts` (`company_id`);

ALTER TABLE `pharmacy_batches`
  ADD CONSTRAINT `pharmacy_batches_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `pharmacy_items` (`item_id`),
  ADD CONSTRAINT `pharmacy_batches_ibfk_2` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`);

ALTER TABLE `pharmacy_purchases`
  ADD CONSTRAINT `pharmacy_purchases_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `pharmacy_purchases_ibfk_2` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`);

ALTER TABLE `pharmacy_sales`
  ADD CONSTRAINT `pharmacy_sales_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`),
  ADD CONSTRAINT `pharmacy_sales_ibfk_2` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`doctor_id`),
  ADD CONSTRAINT `pharmacy_sales_ibfk_3` FOREIGN KEY (`payment_type_id`) REFERENCES `payment_types` (`payment_type_id`),
  ADD CONSTRAINT `pharmacy_sales_ibfk_4` FOREIGN KEY (`admission_id`) REFERENCES `ipd_admissions` (`admission_id`),
  ADD CONSTRAINT `pharmacy_sales_ibfk_5` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`);

ALTER TABLE `pharmacy_transaction_items`
  ADD CONSTRAINT `pharmacy_transaction_items_ibfk_1` FOREIGN KEY (`batch_id`) REFERENCES `pharmacy_batches` (`batch_id`);

ALTER TABLE `sms_logs`
  ADD CONSTRAINT `sms_logs_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `sms_logs_ibfk_2` FOREIGN KEY (`template_id`) REFERENCES `sms_templates` (`template_id`) ON DELETE SET NULL;

ALTER TABLE `split_payments`
  ADD CONSTRAINT `split_payments_ibfk_1` FOREIGN KEY (`collection_id`) REFERENCES `payment_collections` (`collection_id`),
  ADD CONSTRAINT `split_payments_ibfk_2` FOREIGN KEY (`payment_type_id`) REFERENCES `payment_types` (`payment_type_id`);

ALTER TABLE `stock_alert_logs`
  ADD CONSTRAINT `stock_alert_logs_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `pharmacy_items` (`item_id`);

ALTER TABLE `tokens`
  ADD CONSTRAINT `fk_tokens_doctor_id` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`doctor_id`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`);

ALTER TABLE `wards`
  ADD CONSTRAINT `wards_ibfk_1` FOREIGN KEY (`ward_type_id`) REFERENCES `ward_types` (`ward_type_id`);

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
