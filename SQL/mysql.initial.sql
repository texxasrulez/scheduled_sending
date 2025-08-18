CREATE TABLE `scheduled_queue` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `identity_id` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `scheduled_at` datetime NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'queued',
  `raw_mime` mediumtext DEFAULT NULL,
  `meta_json` text DEFAULT NULL,
  `dedupe_key` varchar(64) DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `scheduled_queue`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_scheduled_dedupe` (`dedupe_key`),
  ADD KEY `idx_sched_at` (`scheduled_at`),
  ADD KEY `idx_status` (`status`);

ALTER TABLE `scheduled_queue`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;
COMMIT;
