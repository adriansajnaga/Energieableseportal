-- Struktura bazy ASCOMM Energie (MySQL 8 / MariaDB 10.4+)
-- Tylko gdy na serwerze nie da się uruchomić: php artisan migrate --force
-- Import: phpMyAdmin -> wybierz NOWĄ, PUSTĄ bazę -> Import -> ten plik.
SET NAMES utf8mb4;

CREATE TABLE `migrations` (`id` int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `migration` varchar(255) NOT NULL, `batch` int NOT NULL) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
create table `users` (`id` bigint unsigned not null auto_increment primary key, `name` varchar(255) not null, `email` varchar(255) not null, `email_verified_at` timestamp null, `password` varchar(255) not null, `remember_token` varchar(100) null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `users` add unique `users_email_unique`(`email`);
create table `password_reset_tokens` (`email` varchar(255) not null, `token` varchar(255) not null, `created_at` timestamp null, primary key (`email`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
create table `sessions` (`id` varchar(255) not null, `user_id` bigint unsigned null, `ip_address` varchar(45) null, `user_agent` text null, `payload` longtext not null, `last_activity` int not null, primary key (`id`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `sessions` add index `sessions_user_id_index`(`user_id`);
alter table `sessions` add index `sessions_last_activity_index`(`last_activity`);
create table `cache` (`key` varchar(255) not null, `value` mediumtext not null, `expiration` int not null, primary key (`key`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
create table `cache_locks` (`key` varchar(255) not null, `owner` varchar(255) not null, `expiration` int not null, primary key (`key`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
create table `jobs` (`id` bigint unsigned not null auto_increment primary key, `queue` varchar(255) not null, `payload` longtext not null, `attempts` tinyint unsigned not null, `reserved_at` int unsigned null, `available_at` int unsigned not null, `created_at` int unsigned not null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `jobs` add index `jobs_queue_index`(`queue`);
create table `job_batches` (`id` varchar(255) not null, `name` varchar(255) not null, `total_jobs` int not null, `pending_jobs` int not null, `failed_jobs` int not null, `failed_job_ids` longtext not null, `options` mediumtext null, `cancelled_at` int null, `created_at` int not null, `finished_at` int null, primary key (`id`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
create table `failed_jobs` (`id` bigint unsigned not null auto_increment primary key, `uuid` varchar(255) not null, `connection` text not null, `queue` text not null, `payload` longtext not null, `exception` longtext not null, `failed_at` timestamp not null default CURRENT_TIMESTAMP) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `failed_jobs` add unique `failed_jobs_uuid_unique`(`uuid`);
alter table `users` add `username` varchar(255) null after `name`;
alter table `users` add `role` varchar(20) not null default 'viewer' after `password`;
alter table `users` add `locale` varchar(5) not null default 'de' after `role`;
alter table `users` add `is_active` tinyint(1) not null default '1' after `locale`;
alter table `users` add `working_month` date null after `is_active`;
alter table `users` add `legacy_id` int unsigned null;
alter table `users` add unique `users_username_unique`(`username`);
alter table `users` add unique `users_legacy_id_unique`(`legacy_id`);
create table `settings` (`key` varchar(255) not null, `value` text null, `created_at` timestamp null, `updated_at` timestamp null, primary key (`key`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
create table `tenants` (`id` bigint unsigned not null auto_increment primary key, `name` varchar(255) not null, `debtor_number` int unsigned null, `street` varchar(255) null, `zip` varchar(10) null, `city` varchar(255) null, `phone` varchar(255) null, `email` varchar(255) null, `price_factor` decimal(5, 3) null, `send_invoices_by_email` tinyint(1) not null default '0', `is_active` tinyint(1) not null default '1', `active_from` date null, `legacy_id` int unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `tenants` add index `tenants_debtor_number_index`(`debtor_number`);
alter table `tenants` add unique `tenants_legacy_id_unique`(`legacy_id`);
create table `meters` (`id` bigint unsigned not null auto_increment primary key, `number` varchar(255) not null, `location` varchar(255) null, `factor` smallint unsigned not null default '1', `is_main` tinyint(1) not null default '0', `parent_id` bigint unsigned null, `tenant_id` bigint unsigned null, `is_active` tinyint(1) not null default '1', `qr_token` varchar(64) not null, `legacy_hash` char(32) null, `replaced_by_id` bigint unsigned null, `installed_on` date null, `removed_on` date null, `legacy_id` int unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `meters` add constraint `meters_parent_id_foreign` foreign key (`parent_id`) references `meters` (`id`) on delete set null;
alter table `meters` add constraint `meters_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete set null;
alter table `meters` add constraint `meters_replaced_by_id_foreign` foreign key (`replaced_by_id`) references `meters` (`id`) on delete set null;
alter table `meters` add unique `meters_qr_token_unique`(`qr_token`);
alter table `meters` add unique `meters_legacy_hash_unique`(`legacy_hash`);
alter table `meters` add unique `meters_legacy_id_unique`(`legacy_id`);
create table `meter_assignments` (`id` bigint unsigned not null auto_increment primary key, `meter_id` bigint unsigned not null, `tenant_id` bigint unsigned not null, `starts_on` date not null, `ends_on` date null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `meter_assignments` add constraint `meter_assignments_meter_id_foreign` foreign key (`meter_id`) references `meters` (`id`) on delete cascade;
alter table `meter_assignments` add constraint `meter_assignments_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `meter_assignments` add index `meter_assignments_meter_id_starts_on_index`(`meter_id`, `starts_on`);
create table `readings` (`id` bigint unsigned not null auto_increment primary key, `meter_id` bigint unsigned not null, `tenant_id` bigint unsigned null, `value` bigint unsigned not null, `read_on` date not null, `is_base` tinyint(1) not null default '0', `source` varchar(20) not null default 'admin', `reader_name` varchar(255) null, `photo_path` varchar(255) null, `status` varchar(20) not null default 'approved', `check_note` varchar(255) null, `created_by` bigint unsigned null, `updated_by` bigint unsigned null, `legacy_id` int unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `readings` add constraint `readings_meter_id_foreign` foreign key (`meter_id`) references `meters` (`id`) on delete cascade;
alter table `readings` add constraint `readings_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete set null;
alter table `readings` add constraint `readings_created_by_foreign` foreign key (`created_by`) references `users` (`id`) on delete set null;
alter table `readings` add constraint `readings_updated_by_foreign` foreign key (`updated_by`) references `users` (`id`) on delete set null;
alter table `readings` add index `readings_meter_id_read_on_index`(`meter_id`, `read_on`);
alter table `readings` add index `readings_status_index`(`status`);
alter table `readings` add unique `readings_legacy_id_unique`(`legacy_id`);
create table `electricity_prices` (`id` bigint unsigned not null auto_increment primary key, `meter_id` bigint unsigned not null, `month` date not null, `supplier_invoice_number` varchar(255) null, `consumption_kwh` decimal(12, 2) not null default '0', `net_amount` decimal(12, 2) not null default '0', `net_price` decimal(10, 5) not null, `updated_by` bigint unsigned null, `legacy_id` int unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `electricity_prices` add constraint `electricity_prices_meter_id_foreign` foreign key (`meter_id`) references `meters` (`id`) on delete cascade;
alter table `electricity_prices` add constraint `electricity_prices_updated_by_foreign` foreign key (`updated_by`) references `users` (`id`) on delete set null;
alter table `electricity_prices` add unique `electricity_prices_meter_id_month_unique`(`meter_id`, `month`);
alter table `electricity_prices` add unique `electricity_prices_legacy_id_unique`(`legacy_id`);
create table `settlements` (`id` bigint unsigned not null auto_increment primary key, `type` varchar(20) not null default 'invoice', `invoice_number` int unsigned null, `invoice_date` date null, `period` date not null, `tenant_id` bigint unsigned not null, `meter_id` bigint unsigned not null, `main_meter_id` bigint unsigned null, `start_reading_id` bigint unsigned null, `end_reading_id` bigint unsigned null, `sample_reading_id` bigint unsigned null, `starts_on` date not null, `ends_on` date not null, `consumption_kwh` int not null, `meter_factor` smallint unsigned not null default '1', `billed_kwh` int not null, `base_price` decimal(10, 5) not null, `price_factor` decimal(5, 3) not null, `unit_price` decimal(10, 2) not null, `net_amount` decimal(12, 2) not null, `vat_rate` decimal(5, 2) not null, `vat_amount` decimal(12, 2) not null, `gross_amount` decimal(12, 2) not null, `is_invoiced` tinyint(1) not null default '1', `cancels_id` bigint unsigned null, `cancelled_at` timestamp null, `emailed_at` timestamp null, `created_by` bigint unsigned null, `legacy_id` int unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `settlements` add constraint `settlements_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete restrict;
alter table `settlements` add constraint `settlements_meter_id_foreign` foreign key (`meter_id`) references `meters` (`id`) on delete restrict;
alter table `settlements` add constraint `settlements_main_meter_id_foreign` foreign key (`main_meter_id`) references `meters` (`id`) on delete set null;
alter table `settlements` add constraint `settlements_start_reading_id_foreign` foreign key (`start_reading_id`) references `readings` (`id`) on delete set null;
alter table `settlements` add constraint `settlements_end_reading_id_foreign` foreign key (`end_reading_id`) references `readings` (`id`) on delete set null;
alter table `settlements` add constraint `settlements_sample_reading_id_foreign` foreign key (`sample_reading_id`) references `readings` (`id`) on delete set null;
alter table `settlements` add constraint `settlements_cancels_id_foreign` foreign key (`cancels_id`) references `settlements` (`id`) on delete set null;
alter table `settlements` add constraint `settlements_created_by_foreign` foreign key (`created_by`) references `users` (`id`) on delete set null;
alter table `settlements` add index `settlements_period_meter_id_index`(`period`, `meter_id`);
alter table `settlements` add unique `settlements_invoice_number_unique`(`invoice_number`);
alter table `settlements` add unique `settlements_legacy_id_unique`(`legacy_id`);
create table `activity_log` (`id` bigint unsigned not null auto_increment primary key, `user_id` bigint unsigned null, `action` varchar(20) not null, `subject_type` varchar(255) not null, `subject_id` bigint unsigned not null, `changes` json null, `created_at` timestamp not null default CURRENT_TIMESTAMP) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `activity_log` add constraint `activity_log_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete set null;
alter table `activity_log` add index `activity_log_subject_type_subject_id_index`(`subject_type`, `subject_id`);

INSERT INTO `migrations` (`migration`, `batch`) VALUES
('0001_01_01_000000_create_users_table', 1),
('0001_01_01_000001_create_cache_table', 1),
('0001_01_01_000002_create_jobs_table', 1),
('2026_09_29_000001_add_role_fields_to_users_table', 1),
('2026_09_29_000002_create_energy_tables', 1);
