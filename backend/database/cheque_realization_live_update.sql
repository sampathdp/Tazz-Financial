-- Cheque realization — schema changes for the live server.
-- Generated from migration 2026_07_29_000001_add_realized_at_to_cheque_tables.
-- Run this whole script in one transaction against the production database,
-- THEN insert the matching row into `migrations` (see bottom) so
-- `php artisan migrate` on that server doesn't try to re-run this file.

START TRANSACTION;

-- 2026_07_29_000001_add_realized_at_to_cheque_tables
ALTER TABLE `loan_payments` ADD `realized_at` date NULL AFTER `cheque_date`;

ALTER TABLE `loan_post_dated_cheques` ADD `realized_at` date NULL AFTER `handed_at`;
ALTER TABLE `loan_post_dated_cheques` ADD `loan_payment_id` bigint unsigned NULL AFTER `realized_at`;
ALTER TABLE `loan_post_dated_cheques` ADD CONSTRAINT `loan_post_dated_cheques_loan_payment_id_foreign` FOREIGN KEY (`loan_payment_id`) REFERENCES `loan_payments` (`id`) ON DELETE SET NULL;

-- Keep Laravel's migration history in sync so `php artisan migrate` on this
-- server doesn't try to re-run this file (and so `migrate:rollback` can undo
-- it later). Adjust the batch number if this server is already past 20.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2026_07_29_000001_add_realized_at_to_cheque_tables', 20);

COMMIT;
