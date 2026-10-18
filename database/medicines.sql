
CREATE TABLE `medicines` (
  `id` int NOT NULL,
  `name` varchar(255) NOT NULL,
  `active_ingredient` varchar(2048) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `dosage_form` varchar(100) DEFAULT NULL,
  `dosage` varchar(512) DEFAULT NULL,
  `manufacturer` varchar(512) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


ALTER TABLE `medicines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_name` (`name`);

  
ALTER TABLE `medicines`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;