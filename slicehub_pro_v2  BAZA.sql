-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 19, 2026 at 09:13 PM
-- Wersja serwera: 10.4.32-MariaDB
-- Wersja PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `slicehub_pro_v2`
--

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_ai_jobs`
--

CREATE TABLE `sh_ai_jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `job_kind` enum('style_transform','background_remove','enhance','generate_variant','generate_placeholder') NOT NULL,
  `input_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'asset_id, target_style, prompt, settings' CHECK (json_valid(`input_json`)),
  `output_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'result asset_id, generated metadata' CHECK (json_valid(`output_json`)),
  `status` enum('queued','running','done','failed','cancelled') NOT NULL DEFAULT 'queued',
  `progress_percent` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `provider` varchar(32) DEFAULT NULL COMMENT 'replicate / cloudinary / openai / self_hosted',
  `provider_job_id` varchar(128) DEFAULT NULL,
  `cost_zl` decimal(10,4) DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `error_msg` text DEFAULT NULL,
  `retry_count` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='M022: queue zada?? AI (Faza 4 runner)';

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_assets`
--

CREATE TABLE `sh_assets` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = globalny asset współdzielony; >0 = tenant-scoped',
  `ascii_key` varchar(191) NOT NULL COMMENT 'Unikalny per tenant. Konwencje prefixów w komentarzu migracji.',
  `display_name` varchar(128) DEFAULT NULL COMMENT 'm032 ?? Czytelna nazwa dla managera (np. ''Pieczarki plastry''). NULL = jeszcze nie podpisane.',
  `tags_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'M032 ?? array stringow (np. [wloska, miesiste])' CHECK (json_valid(`tags_json`)),
  `storage_url` varchar(1024) NOT NULL COMMENT 'Pełny URL (zaczyna się http(s)://) lub ścieżka relatywna od DOCROOT',
  `storage_bucket` varchar(32) NOT NULL DEFAULT 'legacy' COMMENT 'library / hero / surface / companion / brand / variant / legacy',
  `mime_type` varchar(64) DEFAULT 'image/webp',
  `width_px` int(10) UNSIGNED DEFAULT NULL,
  `height_px` int(10) UNSIGNED DEFAULT NULL,
  `filesize_bytes` bigint(20) UNSIGNED DEFAULT NULL,
  `has_alpha` tinyint(1) NOT NULL DEFAULT 1,
  `checksum_sha256` char(64) DEFAULT NULL COMMENT 'SHA-256 pliku; NULL dla backfillu — wypełniane przy re-uploadzie dla dedup',
  `role_hint` varchar(32) DEFAULT NULL COMMENT 'hero / layer / surface / companion / icon / logo / thumbnail / poster / og',
  `category` varchar(32) DEFAULT NULL COMMENT 'board / base / sauce / cheese / meat / veg / herb / drink / surface / brand / misc',
  `sub_type` varchar(64) DEFAULT NULL COMMENT 'Np. tomato, mozzarella, pepperoni, marble_white, pepsi_330',
  `cook_state` enum('either','raw','cooked','charred') NOT NULL DEFAULT 'either' COMMENT 'M031 ?? stan pieczenia: either=domy??lny, raw=surowy (hero/card), cooked=upieczony (layer_top_down pizzy), charred=mocno przypalony (promocje)',
  `z_order_hint` int(11) NOT NULL DEFAULT 50 COMMENT 'Domyślny z-index dla warstw (niższy = głębiej)',
  `variant_of` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'FK → sh_assets.id, jeśli to pochodna innego zasobu',
  `variant_kind` varchar(32) DEFAULT NULL COMMENT 'thumbnail / poster / webp_lq / webp_hq / og_share / avif',
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'LUT preset, lighting, EXIF, target_px, custom tags itd.' CHECK (json_valid(`metadata_json`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL COMMENT 'Soft-delete (Prawo Czwartego Wymiaru) — NIGDY hard-delete',
  `created_by_user` varchar(64) DEFAULT NULL COMMENT 'User ID/login kto wgrał; NULL dla backfillowanych'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Kanoniczny rejestr wszystkich plików/obrazów (od m021).';

--
-- Dumping data for table `sh_assets`
--

INSERT INTO `sh_assets` (`id`, `tenant_id`, `ascii_key`, `display_name`, `tags_json`, `storage_url`, `storage_bucket`, `mime_type`, `width_px`, `height_px`, `filesize_bytes`, `has_alpha`, `checksum_sha256`, `role_hint`, `category`, `sub_type`, `cook_state`, `z_order_hint`, `variant_of`, `variant_kind`, `metadata_json`, `is_active`, `created_at`, `updated_at`, `deleted_at`, `created_by_user`) VALUES
(1, 1, 'base_dough_008fe6', 'Dough', NULL, 'uploads/global_assets/base_dough_008fe6.webp', 'library', 'image/webp', 800, 770, 87308, 1, NULL, 'layer', 'base', 'dough', 'either', 10, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 1}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(2, 1, 'base_dough_08bbae', 'Dough', NULL, 'uploads/global_assets/base_dough_08bbae.webp', 'library', 'image/webp', 800, 790, 65258, 1, NULL, 'layer', 'base', 'dough', 'either', 10, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 2}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(3, 1, 'base_dough_2244b4', 'Dough', NULL, 'uploads/global_assets/base_dough_2244b4.webp', 'library', 'image/webp', 789, 800, 51234, 1, NULL, 'layer', 'base', 'dough', 'either', 10, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 3}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(4, 1, 'base_dough_294218', 'Dough', NULL, 'uploads/global_assets/base_dough_294218.webp', 'library', 'image/webp', 787, 800, 80178, 1, NULL, 'layer', 'base', 'dough', 'either', 10, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 4}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(5, 1, 'base_dough_531bae', 'Dough', NULL, 'uploads/global_assets/base_dough_531bae.webp', 'library', 'image/webp', 800, 798, 103652, 1, NULL, 'layer', 'base', 'dough', 'either', 10, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 5}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(6, 1, 'base_dough_532c44', 'Dough', NULL, 'uploads/global_assets/base_dough_532c44.webp', 'library', 'image/webp', 788, 800, 56148, 1, NULL, 'layer', 'base', 'dough', 'either', 10, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 6}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(7, 1, 'base_dough_5fb69d', 'Dough', NULL, 'uploads/global_assets/base_dough_5fb69d.webp', 'library', 'image/webp', 800, 797, 60736, 1, NULL, 'layer', 'base', 'dough', 'either', 10, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 7}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(8, 1, 'base_dough_69ec5a', 'Dough', NULL, 'uploads/global_assets/base_dough_69ec5a.webp', 'library', 'image/webp', 800, 780, 89136, 1, NULL, 'layer', 'base', 'dough', 'either', 10, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 8}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(9, 1, 'base_dough_a5aa21', 'Dough', NULL, 'uploads/global_assets/base_dough_a5aa21.webp', 'library', 'image/webp', 797, 800, 42260, 1, NULL, 'layer', 'base', 'dough', 'either', 10, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 9}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(10, 1, 'base_dough_ac3afd', 'Dough', NULL, 'uploads/global_assets/base_dough_ac3afd.webp', 'library', 'image/webp', 800, 765, 51622, 1, NULL, 'layer', 'base', 'dough', 'either', 10, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 10}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(11, 1, 'base_dough_b455ef', 'Dough', NULL, 'uploads/global_assets/base_dough_b455ef.webp', 'library', 'image/webp', 800, 800, 76590, 1, NULL, 'layer', 'base', 'dough', 'either', 10, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 11}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(12, 1, 'base_dough_ce7a24', 'Dough', NULL, 'uploads/global_assets/base_dough_ce7a24.webp', 'library', 'image/webp', 791, 800, 61880, 1, NULL, 'layer', 'base', 'dough', 'either', 10, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 12}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(13, 1, 'base_dough_dc694c', 'Dough', NULL, 'uploads/global_assets/base_dough_dc694c.webp', 'library', 'image/webp', 799, 800, 81160, 1, NULL, 'layer', 'base', 'dough', 'either', 10, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 13}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(14, 1, 'base_dough_dc8dc5', 'Dough', NULL, 'uploads/global_assets/base_dough_dc8dc5.webp', 'library', 'image/webp', 800, 764, 65108, 1, NULL, 'layer', 'base', 'dough', 'either', 10, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 14}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(15, 1, 'base_dough_df4ba6', 'Dough', NULL, 'uploads/global_assets/base_dough_df4ba6.webp', 'library', 'image/webp', 800, 776, 106368, 1, NULL, 'layer', 'base', 'dough', 'either', 10, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 15}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(16, 1, 'base_dough_f1b7c2', 'Dough', NULL, 'uploads/global_assets/base_dough_f1b7c2.webp', 'library', 'image/webp', 798, 800, 83184, 1, NULL, 'layer', 'base', 'dough', 'either', 10, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 16}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(17, 1, 'base_dough_fdfba2', 'Dough', NULL, 'uploads/global_assets/base_dough_fdfba2.webp', 'library', 'image/webp', 799, 800, 48024, 1, NULL, 'layer', 'base', 'dough', 'either', 10, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 17}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(18, 1, 'board_plate_0fa8df', 'Deska', NULL, 'uploads/global_assets/board_plate_0fa8df.webp', 'library', 'image/webp', 795, 800, 91628, 1, NULL, 'layer', 'board', 'plate', 'either', 0, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 18}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(19, 1, 'board_plate_4212b3', 'Deska', NULL, 'uploads/global_assets/board_plate_4212b3.webp', 'library', 'image/webp', 788, 800, 80394, 1, NULL, 'layer', 'board', 'plate', 'either', 0, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 19}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(20, 1, 'board_plate_9dab6f', 'Deska', NULL, 'uploads/global_assets/board_plate_9dab6f.webp', 'library', 'image/webp', 800, 778, 66994, 1, NULL, 'layer', 'board', 'plate', 'either', 0, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 20}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(21, 1, 'board_plate_b229dc', 'Deska', NULL, 'uploads/global_assets/board_plate_b229dc.webp', 'library', 'image/webp', 800, 797, 81778, 1, NULL, 'layer', 'board', 'plate', 'either', 0, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 21}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(22, 1, 'board_plate_d0b2bd', 'Deska', NULL, 'uploads/global_assets/board_plate_d0b2bd.webp', 'library', 'image/webp', 797, 800, 79922, 1, NULL, 'layer', 'board', 'plate', 'either', 0, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 22}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(23, 1, 'cheese_cheese_007121', 'Cheese', NULL, 'uploads/global_assets/cheese_cheese_007121.webp', 'library', 'image/webp', 800, 731, 141360, 1, NULL, 'layer', 'cheese', 'cheese', 'either', 30, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 23}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(24, 1, 'cheese_cheese_1bf0ab', 'Cheese', NULL, 'uploads/global_assets/cheese_cheese_1bf0ab.webp', 'library', 'image/webp', 262, 800, 21276, 1, NULL, 'layer', 'cheese', 'cheese', 'either', 30, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 24}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(25, 1, 'cheese_cheese_2b4e70', 'Cheese', NULL, 'uploads/global_assets/cheese_cheese_2b4e70.webp', 'library', 'image/webp', 273, 800, 23452, 1, NULL, 'layer', 'cheese', 'cheese', 'either', 30, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 25}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(26, 1, 'cheese_cheese_45dea6', 'Cheese', NULL, 'uploads/global_assets/cheese_cheese_45dea6.webp', 'library', 'image/webp', 800, 744, 129398, 1, NULL, 'layer', 'cheese', 'cheese', 'either', 30, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 26}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(27, 1, 'cheese_cheese_82f9f8', 'Cheese', NULL, 'uploads/global_assets/cheese_cheese_82f9f8.webp', 'library', 'image/webp', 800, 751, 217004, 1, NULL, 'layer', 'cheese', 'cheese', 'either', 30, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 27}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(28, 1, 'cheese_cheese_bec540', 'Cheese', NULL, 'uploads/global_assets/cheese_cheese_bec540.webp', 'library', 'image/webp', 800, 680, 111736, 1, NULL, 'layer', 'cheese', 'cheese', 'either', 30, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 28}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(29, 1, 'extra_item_1509c1', 'Item', NULL, 'uploads/global_assets/extra_item_1509c1.webp', 'library', 'image/webp', 800, 767, 46252, 1, NULL, 'layer', 'extra', 'item', 'either', 70, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 29}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(30, 1, 'extra_item_2f98f2', 'Item', NULL, 'uploads/global_assets/extra_item_2f98f2.webp', 'library', 'image/webp', 216, 800, 23628, 1, NULL, 'layer', 'extra', 'item', 'either', 70, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 30}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(31, 1, 'extra_item_385ef3', 'Item', NULL, 'uploads/global_assets/extra_item_385ef3.webp', 'library', 'image/webp', 243, 800, 55274, 1, NULL, 'layer', 'extra', 'item', 'either', 70, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 31}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(32, 1, 'extra_item_3a3e53', 'Item', NULL, 'uploads/global_assets/extra_item_3a3e53.webp', 'library', 'image/webp', 736, 800, 45194, 1, NULL, 'layer', 'extra', 'item', 'either', 70, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 32}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(33, 1, 'extra_item_67088e', 'Item', NULL, 'uploads/global_assets/extra_item_67088e.webp', 'library', 'image/webp', 800, 795, 39888, 1, NULL, 'layer', 'extra', 'item', 'either', 70, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 33}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(34, 1, 'extra_item_699410', 'Item', NULL, 'uploads/global_assets/extra_item_699410.webp', 'library', 'image/webp', 797, 800, 36594, 1, NULL, 'layer', 'extra', 'item', 'either', 70, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 34}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(35, 1, 'extra_item_6cdb4b', 'Item', NULL, 'uploads/global_assets/extra_item_6cdb4b.webp', 'library', 'image/webp', 727, 800, 48266, 1, NULL, 'layer', 'extra', 'item', 'either', 70, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 35}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(36, 1, 'extra_item_80323c', 'Item', NULL, 'uploads/global_assets/extra_item_80323c.webp', 'library', 'image/webp', 800, 767, 35230, 1, NULL, 'layer', 'extra', 'item', 'either', 70, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 36}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(37, 1, 'extra_item_8a61a2', 'Item', NULL, 'uploads/global_assets/extra_item_8a61a2.webp', 'library', 'image/webp', 800, 664, 50568, 1, NULL, 'layer', 'extra', 'item', 'either', 70, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 37}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(38, 1, 'extra_item_ab6c35', 'Item', NULL, 'uploads/global_assets/extra_item_ab6c35.webp', 'library', 'image/webp', 153, 800, 19480, 1, NULL, 'layer', 'extra', 'item', 'either', 70, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 38}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(39, 1, 'extra_item_abf7eb', 'Item', NULL, 'uploads/global_assets/extra_item_abf7eb.webp', 'library', 'image/webp', 800, 786, 60180, 1, NULL, 'layer', 'extra', 'item', 'either', 70, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 39}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(40, 1, 'extra_item_cf4703', 'Item', NULL, 'uploads/global_assets/extra_item_cf4703.webp', 'library', 'image/webp', 133, 800, 22486, 1, NULL, 'layer', 'extra', 'item', 'either', 70, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 40}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(41, 1, 'extra_item_de8d98', 'Item', NULL, 'uploads/global_assets/extra_item_de8d98.webp', 'library', 'image/webp', 800, 732, 60112, 1, NULL, 'layer', 'extra', 'item', 'either', 70, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 41}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(42, 1, 'herb_herb_03a199', 'Herb', NULL, 'uploads/global_assets/herb_herb_03a199.webp', 'library', 'image/webp', 533, 800, 35478, 1, NULL, 'layer', 'herb', 'herb', 'either', 60, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 42}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(43, 1, 'herb_herb_048c5c', 'Herb', NULL, 'uploads/global_assets/herb_herb_048c5c.webp', 'library', 'image/webp', 800, 619, 36814, 1, NULL, 'layer', 'herb', 'herb', 'either', 60, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 43}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(44, 1, 'herb_herb_23c73a', 'Herb', NULL, 'uploads/global_assets/herb_herb_23c73a.webp', 'library', 'image/webp', 764, 800, 60798, 1, NULL, 'layer', 'herb', 'herb', 'either', 60, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 44}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(45, 1, 'herb_herb_2d76bf', 'Herb', NULL, 'uploads/global_assets/herb_herb_2d76bf.webp', 'library', 'image/webp', 800, 793, 72338, 1, NULL, 'layer', 'herb', 'herb', 'either', 60, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 45}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(46, 1, 'herb_herb_2dcb13', 'Herb', NULL, 'uploads/global_assets/herb_herb_2dcb13.webp', 'library', 'image/webp', 800, 786, 43414, 1, NULL, 'layer', 'herb', 'herb', 'either', 60, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 46}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(47, 1, 'herb_herb_2fb5ae', 'Herb', NULL, 'uploads/global_assets/herb_herb_2fb5ae.webp', 'library', 'image/webp', 800, 786, 60046, 1, NULL, 'layer', 'herb', 'herb', 'either', 60, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 47}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(48, 1, 'herb_herb_34bb12', 'Herb', NULL, 'uploads/global_assets/herb_herb_34bb12.webp', 'library', 'image/webp', 800, 728, 44062, 1, NULL, 'layer', 'herb', 'herb', 'either', 60, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 48}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(49, 1, 'herb_herb_430c4f', 'Herb', NULL, 'uploads/global_assets/herb_herb_430c4f.webp', 'library', 'image/webp', 800, 588, 46040, 1, NULL, 'layer', 'herb', 'herb', 'either', 60, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 49}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(50, 1, 'herb_herb_43e257', 'Herb', NULL, 'uploads/global_assets/herb_herb_43e257.webp', 'library', 'image/webp', 800, 772, 74056, 1, NULL, 'layer', 'herb', 'herb', 'either', 60, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 50}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(51, 1, 'herb_herb_5f754b', 'Herb', NULL, 'uploads/global_assets/herb_herb_5f754b.webp', 'library', 'image/webp', 721, 800, 44058, 1, NULL, 'layer', 'herb', 'herb', 'either', 60, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 51}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(52, 1, 'herb_herb_6c7a40', 'Herb', NULL, 'uploads/global_assets/herb_herb_6c7a40.webp', 'library', 'image/webp', 800, 639, 49038, 1, NULL, 'layer', 'herb', 'herb', 'either', 60, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 52}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(53, 1, 'herb_herb_7d1d07', 'Herb', NULL, 'uploads/global_assets/herb_herb_7d1d07.webp', 'library', 'image/webp', 800, 765, 69410, 1, NULL, 'layer', 'herb', 'herb', 'either', 60, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 53}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(54, 1, 'herb_herb_82d8f7', 'Herb', NULL, 'uploads/global_assets/herb_herb_82d8f7.webp', 'library', 'image/webp', 800, 795, 49206, 1, NULL, 'layer', 'herb', 'herb', 'either', 60, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 54}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(55, 1, 'herb_herb_858be3', 'Herb', NULL, 'uploads/global_assets/herb_herb_858be3.webp', 'library', 'image/webp', 800, 535, 34144, 1, NULL, 'layer', 'herb', 'herb', 'either', 60, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 55}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(56, 1, 'herb_herb_85bcf0', 'Herb', NULL, 'uploads/global_assets/herb_herb_85bcf0.webp', 'library', 'image/webp', 779, 800, 55458, 1, NULL, 'layer', 'herb', 'herb', 'either', 60, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 56}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(57, 1, 'herb_herb_869de4', 'Herb', NULL, 'uploads/global_assets/herb_herb_869de4.webp', 'library', 'image/webp', 800, 718, 52134, 1, NULL, 'layer', 'herb', 'herb', 'either', 60, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 57}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(58, 1, 'herb_herb_8aaf11', 'Herb', NULL, 'uploads/global_assets/herb_herb_8aaf11.webp', 'library', 'image/webp', 800, 765, 86602, 1, NULL, 'layer', 'herb', 'herb', 'either', 60, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 58}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(59, 1, 'herb_herb_8d67c0', 'Herb', NULL, 'uploads/global_assets/herb_herb_8d67c0.webp', 'library', 'image/webp', 800, 713, 49270, 1, NULL, 'layer', 'herb', 'herb', 'either', 60, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 59}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(60, 1, 'herb_herb_92ca71', 'Herb', NULL, 'uploads/global_assets/herb_herb_92ca71.webp', 'library', 'image/webp', 587, 800, 44120, 1, NULL, 'layer', 'herb', 'herb', 'either', 60, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 60}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(61, 1, 'herb_herb_9a60c7', 'Herb', NULL, 'uploads/global_assets/herb_herb_9a60c7.webp', 'library', 'image/webp', 800, 684, 47952, 1, NULL, 'layer', 'herb', 'herb', 'either', 60, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 61}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(62, 1, 'herb_herb_a417bd', 'Herb', NULL, 'uploads/global_assets/herb_herb_a417bd.webp', 'library', 'image/webp', 800, 662, 89240, 1, NULL, 'layer', 'herb', 'herb', 'either', 60, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 62}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(63, 1, 'herb_herb_a6bef4', 'Herb', NULL, 'uploads/global_assets/herb_herb_a6bef4.webp', 'library', 'image/webp', 765, 800, 55526, 1, NULL, 'layer', 'herb', 'herb', 'either', 60, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 63}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(64, 1, 'herb_herb_a9b2d4', 'Herb', NULL, 'uploads/global_assets/herb_herb_a9b2d4.webp', 'library', 'image/webp', 800, 710, 111040, 1, NULL, 'layer', 'herb', 'herb', 'either', 60, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 64}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(65, 1, 'herb_herb_aa4f58', 'Herb', NULL, 'uploads/global_assets/herb_herb_aa4f58.webp', 'library', 'image/webp', 800, 683, 62308, 1, NULL, 'layer', 'herb', 'herb', 'either', 60, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 65}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(66, 1, 'herb_herb_aec4b2', 'Herb', NULL, 'uploads/global_assets/herb_herb_aec4b2.webp', 'library', 'image/webp', 800, 762, 47364, 1, NULL, 'layer', 'herb', 'herb', 'either', 60, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 66}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(67, 1, 'herb_herb_ba2541', 'Herb', NULL, 'uploads/global_assets/herb_herb_ba2541.webp', 'library', 'image/webp', 786, 800, 51218, 1, NULL, 'layer', 'herb', 'herb', 'either', 60, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 67}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(68, 1, 'herb_herb_bb8976', 'Herb', NULL, 'uploads/global_assets/herb_herb_bb8976.webp', 'library', 'image/webp', 800, 706, 38038, 1, NULL, 'layer', 'herb', 'herb', 'either', 60, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 68}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(69, 1, 'herb_herb_c46077', 'Herb', NULL, 'uploads/global_assets/herb_herb_c46077.webp', 'library', 'image/webp', 800, 708, 77204, 1, NULL, 'layer', 'herb', 'herb', 'either', 60, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 69}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(70, 1, 'herb_herb_c55221', 'Herb', NULL, 'uploads/global_assets/herb_herb_c55221.webp', 'library', 'image/webp', 634, 800, 38070, 1, NULL, 'layer', 'herb', 'herb', 'either', 60, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 70}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(71, 1, 'herb_herb_cb22b5', 'Herb', NULL, 'uploads/global_assets/herb_herb_cb22b5.webp', 'library', 'image/webp', 800, 783, 68680, 1, NULL, 'layer', 'herb', 'herb', 'either', 60, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 71}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(72, 1, 'herb_herb_db5c21', 'Herb', NULL, 'uploads/global_assets/herb_herb_db5c21.webp', 'library', 'image/webp', 800, 764, 48912, 1, NULL, 'layer', 'herb', 'herb', 'either', 60, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 72}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(73, 1, 'herb_herb_e32660', 'Herb', NULL, 'uploads/global_assets/herb_herb_e32660.webp', 'library', 'image/webp', 800, 798, 54702, 1, NULL, 'layer', 'herb', 'herb', 'either', 60, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 73}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(74, 1, 'herb_herb_f4be20', 'Herb', NULL, 'uploads/global_assets/herb_herb_f4be20.webp', 'library', 'image/webp', 800, 683, 83186, 1, NULL, 'layer', 'herb', 'herb', 'either', 60, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 74}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(75, 1, 'meat_meat_11187d', 'Meat', NULL, 'uploads/global_assets/meat_meat_11187d.webp', 'library', 'image/webp', 800, 781, 59874, 1, NULL, 'layer', 'meat', 'meat', 'either', 40, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 75}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(76, 1, 'meat_meat_32ca21', 'Meat', NULL, 'uploads/global_assets/meat_meat_32ca21.webp', 'library', 'image/webp', 800, 773, 58376, 1, NULL, 'layer', 'meat', 'meat', 'either', 40, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 76}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(77, 1, 'meat_meat_a7aa4e', 'Meat', NULL, 'uploads/global_assets/meat_meat_a7aa4e.webp', 'library', 'image/webp', 800, 768, 107136, 1, NULL, 'layer', 'meat', 'meat', 'either', 40, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 77}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(78, 1, 'meat_meat_d74071', 'Meat', NULL, 'uploads/global_assets/meat_meat_d74071.webp', 'library', 'image/webp', 799, 800, 95726, 1, NULL, 'layer', 'meat', 'meat', 'either', 40, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 78}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(79, 1, 'meat_meat_f3ec38', 'Meat', NULL, 'uploads/global_assets/meat_meat_f3ec38.webp', 'library', 'image/webp', 800, 763, 123102, 1, NULL, 'layer', 'meat', 'meat', 'either', 40, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 79}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(80, 1, 'meat_meat_fb0f62', 'Meat', NULL, 'uploads/global_assets/meat_meat_fb0f62.webp', 'library', 'image/webp', 773, 800, 100632, 1, NULL, 'layer', 'meat', 'meat', 'either', 40, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 80}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(81, 1, 'meat_meat_ffda1a', 'Meat', NULL, 'uploads/global_assets/meat_meat_ffda1a.webp', 'library', 'image/webp', 781, 800, 64124, 1, NULL, 'layer', 'meat', 'meat', 'either', 40, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 81}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(82, 1, 'sauce_sauce_063306', 'Sauce', NULL, 'uploads/global_assets/sauce_sauce_063306.webp', 'library', 'image/webp', 800, 790, 301872, 1, NULL, 'layer', 'sauce', 'sauce', 'either', 20, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 82}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(83, 1, 'sauce_sauce_1120f2', 'Sauce', NULL, 'uploads/global_assets/sauce_sauce_1120f2.webp', 'library', 'image/webp', 800, 789, 309592, 1, NULL, 'layer', 'sauce', 'sauce', 'either', 20, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 83}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(84, 1, 'sauce_sauce_11a09c', 'Sauce', NULL, 'uploads/global_assets/sauce_sauce_11a09c.webp', 'library', 'image/webp', 800, 779, 208008, 1, NULL, 'layer', 'sauce', 'sauce', 'either', 20, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 84}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(85, 1, 'sauce_sauce_24b8b3', 'Sauce', NULL, 'uploads/global_assets/sauce_sauce_24b8b3.webp', 'library', 'image/webp', 800, 735, 261490, 1, NULL, 'layer', 'sauce', 'sauce', 'either', 20, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 85}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(86, 1, 'sauce_sauce_27811d', 'Sauce', NULL, 'uploads/global_assets/sauce_sauce_27811d.webp', 'library', 'image/webp', 800, 705, 175560, 1, NULL, 'layer', 'sauce', 'sauce', 'either', 20, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 86}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(87, 1, 'sauce_sauce_28f490', 'Sauce', NULL, 'uploads/global_assets/sauce_sauce_28f490.webp', 'library', 'image/webp', 800, 745, 261350, 1, NULL, 'layer', 'sauce', 'sauce', 'either', 20, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 87}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(88, 1, 'sauce_sauce_304f5a', 'Sauce', NULL, 'uploads/global_assets/sauce_sauce_304f5a.webp', 'library', 'image/webp', 800, 781, 221014, 1, NULL, 'layer', 'sauce', 'sauce', 'either', 20, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 88}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(89, 1, 'sauce_sauce_3ebc9a', 'Sauce', NULL, 'uploads/global_assets/sauce_sauce_3ebc9a.webp', 'library', 'image/webp', 800, 774, 174826, 1, NULL, 'layer', 'sauce', 'sauce', 'either', 20, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 89}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(90, 1, 'sauce_sauce_482f83', 'Sauce', NULL, 'uploads/global_assets/sauce_sauce_482f83.webp', 'library', 'image/webp', 800, 680, 252806, 1, NULL, 'layer', 'sauce', 'sauce', 'either', 20, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 90}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(91, 1, 'sauce_sauce_4a3f9e', 'Sauce', NULL, 'uploads/global_assets/sauce_sauce_4a3f9e.webp', 'library', 'image/webp', 800, 733, 215172, 1, NULL, 'layer', 'sauce', 'sauce', 'either', 20, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 91}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(92, 1, 'sauce_sauce_4acd7c', 'Sauce', NULL, 'uploads/global_assets/sauce_sauce_4acd7c.webp', 'library', 'image/webp', 800, 747, 278412, 1, NULL, 'layer', 'sauce', 'sauce', 'either', 20, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 92}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(93, 1, 'sauce_sauce_4ed0d1', 'Sauce', NULL, 'uploads/global_assets/sauce_sauce_4ed0d1.webp', 'library', 'image/webp', 800, 764, 271968, 1, NULL, 'layer', 'sauce', 'sauce', 'either', 20, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 93}', 1, '2026-04-17 04:51:49', '2026-04-19 10:29:22', NULL, NULL),
(94, 1, 'sauce_sauce_63e6f3', 'Sauce', NULL, 'uploads/global_assets/sauce_sauce_63e6f3.webp', 'library', 'image/webp', 800, 703, 214218, 1, NULL, 'layer', 'sauce', 'sauce', 'either', 20, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 94}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(95, 1, 'sauce_sauce_6457cd', 'Sauce', NULL, 'uploads/global_assets/sauce_sauce_6457cd.webp', 'library', 'image/webp', 800, 783, 314862, 1, NULL, 'layer', 'sauce', 'sauce', 'either', 20, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 95}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(96, 1, 'sauce_sauce_664f5f', 'Sauce', NULL, 'uploads/global_assets/sauce_sauce_664f5f.webp', 'library', 'image/webp', 800, 735, 201834, 1, NULL, 'layer', 'sauce', 'sauce', 'either', 20, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 96}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(97, 1, 'sauce_sauce_6f245d', 'Sauce', NULL, 'uploads/global_assets/sauce_sauce_6f245d.webp', 'library', 'image/webp', 800, 740, 192538, 1, NULL, 'layer', 'sauce', 'sauce', 'either', 20, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 97}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(98, 1, 'sauce_sauce_7671e6', 'Sauce', NULL, 'uploads/global_assets/sauce_sauce_7671e6.webp', 'library', 'image/webp', 800, 711, 321342, 1, NULL, 'layer', 'sauce', 'sauce', 'either', 20, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 98}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(99, 1, 'sauce_sauce_857d17', 'Sauce', NULL, 'uploads/global_assets/sauce_sauce_857d17.webp', 'library', 'image/webp', 800, 759, 255256, 1, NULL, 'layer', 'sauce', 'sauce', 'either', 20, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 99}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(100, 1, 'sauce_sauce_92673a', 'Sauce', NULL, 'uploads/global_assets/sauce_sauce_92673a.webp', 'library', 'image/webp', 800, 697, 162128, 1, NULL, 'layer', 'sauce', 'sauce', 'either', 20, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 100}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(101, 1, 'sauce_sauce_9b9fd9', 'Sauce', NULL, 'uploads/global_assets/sauce_sauce_9b9fd9.webp', 'library', 'image/webp', 742, 800, 261518, 1, NULL, 'layer', 'sauce', 'sauce', 'either', 20, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 101}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(102, 1, 'sauce_sauce_a935c0', 'Sauce', NULL, 'uploads/global_assets/sauce_sauce_a935c0.webp', 'library', 'image/webp', 795, 800, 251198, 1, NULL, 'layer', 'sauce', 'sauce', 'either', 20, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 102}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(103, 1, 'sauce_sauce_b7d179', 'Sauce', NULL, 'uploads/global_assets/sauce_sauce_b7d179.webp', 'library', 'image/webp', 800, 786, 312826, 1, NULL, 'layer', 'sauce', 'sauce', 'either', 20, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 103}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(104, 1, 'sauce_sauce_b92494', 'Sauce', NULL, 'uploads/global_assets/sauce_sauce_b92494.webp', 'library', 'image/webp', 800, 740, 278296, 1, NULL, 'layer', 'sauce', 'sauce', 'either', 20, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 104}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(105, 1, 'sauce_sauce_ba3a64', 'Sauce', NULL, 'uploads/global_assets/sauce_sauce_ba3a64.webp', 'library', 'image/webp', 800, 756, 305496, 1, NULL, 'layer', 'sauce', 'sauce', 'either', 20, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 105}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(106, 1, 'sauce_sauce_bdcf26', 'Sauce', NULL, 'uploads/global_assets/sauce_sauce_bdcf26.webp', 'library', 'image/webp', 800, 791, 416732, 1, NULL, 'layer', 'sauce', 'sauce', 'either', 20, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 106}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(107, 1, 'sauce_sauce_c05e7d', 'Sauce', NULL, 'uploads/global_assets/sauce_sauce_c05e7d.webp', 'library', 'image/webp', 800, 779, 398720, 1, NULL, 'layer', 'sauce', 'sauce', 'either', 20, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 107}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(108, 1, 'sauce_sauce_c55316', 'Sauce', NULL, 'uploads/global_assets/sauce_sauce_c55316.webp', 'library', 'image/webp', 800, 783, 239022, 1, NULL, 'layer', 'sauce', 'sauce', 'either', 20, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 108}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(109, 1, 'sauce_sauce_e428b7', 'Sauce', NULL, 'uploads/global_assets/sauce_sauce_e428b7.webp', 'library', 'image/webp', 800, 763, 141060, 1, NULL, 'layer', 'sauce', 'sauce', 'either', 20, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 109}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(110, 1, 'sauce_sauce_e98aa3', 'Sauce', NULL, 'uploads/global_assets/sauce_sauce_e98aa3.webp', 'library', 'image/webp', 800, 701, 372250, 1, NULL, 'layer', 'sauce', 'sauce', 'either', 20, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 110}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(111, 1, 'sauce_sauce_ecff9f', 'Sauce', NULL, 'uploads/global_assets/sauce_sauce_ecff9f.webp', 'library', 'image/webp', 800, 678, 300516, 1, NULL, 'layer', 'sauce', 'sauce', 'either', 20, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 111}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(112, 1, 'veg_veg_0bd47b', 'Veg', NULL, 'uploads/global_assets/veg_veg_0bd47b.webp', 'library', 'image/webp', 800, 718, 53130, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 112}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(113, 1, 'veg_veg_126e8f', 'Veg', NULL, 'uploads/global_assets/veg_veg_126e8f.webp', 'library', 'image/webp', 800, 727, 66256, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 113}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(114, 1, 'veg_veg_19e6d4', 'Veg', NULL, 'uploads/global_assets/veg_veg_19e6d4.webp', 'library', 'image/webp', 520, 800, 40662, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 114}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(115, 1, 'veg_veg_1a9a10', 'Veg', NULL, 'uploads/global_assets/veg_veg_1a9a10.webp', 'library', 'image/webp', 525, 800, 37392, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 115}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(116, 1, 'veg_veg_1ec940', 'Veg', NULL, 'uploads/global_assets/veg_veg_1ec940.webp', 'library', 'image/webp', 778, 800, 73556, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 116}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(117, 1, 'veg_veg_20c5fc', 'Veg', NULL, 'uploads/global_assets/veg_veg_20c5fc.webp', 'library', 'image/webp', 800, 740, 73652, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 117}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(118, 1, 'veg_veg_216c28', 'Veg', NULL, 'uploads/global_assets/veg_veg_216c28.webp', 'library', 'image/webp', 800, 764, 75200, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 118}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(119, 1, 'veg_veg_28ee20', 'Veg', NULL, 'uploads/global_assets/veg_veg_28ee20.webp', 'library', 'image/webp', 800, 596, 38760, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 119}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(120, 1, 'veg_veg_31d16a', 'Veg', NULL, 'uploads/global_assets/veg_veg_31d16a.webp', 'library', 'image/webp', 800, 784, 35844, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 120}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(121, 1, 'veg_veg_32d306', 'Veg', NULL, 'uploads/global_assets/veg_veg_32d306.webp', 'library', 'image/webp', 800, 363, 22472, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 121}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(122, 1, 'veg_veg_4071d9', 'Veg', NULL, 'uploads/global_assets/veg_veg_4071d9.webp', 'library', 'image/webp', 800, 613, 50826, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 122}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(123, 1, 'veg_veg_42859f', 'Veg', NULL, 'uploads/global_assets/veg_veg_42859f.webp', 'library', 'image/webp', 800, 760, 71722, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 123}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(124, 1, 'veg_veg_469551', 'Veg', NULL, 'uploads/global_assets/veg_veg_469551.webp', 'library', 'image/webp', 800, 700, 38736, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 124}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(125, 1, 'veg_veg_46b9fa', 'Veg', NULL, 'uploads/global_assets/veg_veg_46b9fa.webp', 'library', 'image/webp', 800, 696, 40088, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 125}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(126, 1, 'veg_veg_474002', 'Veg', NULL, 'uploads/global_assets/veg_veg_474002.webp', 'library', 'image/webp', 800, 645, 45800, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 126}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(127, 1, 'veg_veg_49ed87', 'Veg', NULL, 'uploads/global_assets/veg_veg_49ed87.webp', 'library', 'image/webp', 800, 765, 36662, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 127}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(128, 1, 'veg_veg_4bc7a2', 'Veg', NULL, 'uploads/global_assets/veg_veg_4bc7a2.webp', 'library', 'image/webp', 664, 800, 50918, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 128}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(129, 1, 'veg_veg_4d89f7', 'Veg', NULL, 'uploads/global_assets/veg_veg_4d89f7.webp', 'library', 'image/webp', 789, 800, 39672, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 129}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(130, 1, 'veg_veg_597ef5', 'Veg', NULL, 'uploads/global_assets/veg_veg_597ef5.webp', 'library', 'image/webp', 747, 800, 39570, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 130}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(131, 1, 'veg_veg_5bbf68', 'Veg', NULL, 'uploads/global_assets/veg_veg_5bbf68.webp', 'library', 'image/webp', 800, 790, 74222, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 131}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(132, 1, 'veg_veg_5d6a1d', 'Veg', NULL, 'uploads/global_assets/veg_veg_5d6a1d.webp', 'library', 'image/webp', 513, 800, 26950, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 132}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(133, 1, 'veg_veg_616339', 'Veg', NULL, 'uploads/global_assets/veg_veg_616339.webp', 'library', 'image/webp', 750, 800, 59384, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 133}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(134, 1, 'veg_veg_620d10', 'Veg', NULL, 'uploads/global_assets/veg_veg_620d10.webp', 'library', 'image/webp', 799, 800, 49070, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 134}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(135, 1, 'veg_veg_649e1e', 'Veg', NULL, 'uploads/global_assets/veg_veg_649e1e.webp', 'library', 'image/webp', 800, 794, 58426, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 135}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(136, 1, 'veg_veg_6655f6', 'Veg', NULL, 'uploads/global_assets/veg_veg_6655f6.webp', 'library', 'image/webp', 800, 606, 22312, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 136}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(137, 1, 'veg_veg_70c17d', 'Veg', NULL, 'uploads/global_assets/veg_veg_70c17d.webp', 'library', 'image/webp', 758, 800, 65376, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 137}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(138, 1, 'veg_veg_723df7', 'Veg', NULL, 'uploads/global_assets/veg_veg_723df7.webp', 'library', 'image/webp', 800, 786, 41758, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 138}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(139, 1, 'veg_veg_72cf73', 'Veg', NULL, 'uploads/global_assets/veg_veg_72cf73.webp', 'library', 'image/webp', 800, 683, 66596, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 139}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(140, 1, 'veg_veg_7645fb', 'Veg', NULL, 'uploads/global_assets/veg_veg_7645fb.webp', 'library', 'image/webp', 800, 705, 48886, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 140}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(141, 1, 'veg_veg_76d3ae', 'Veg', NULL, 'uploads/global_assets/veg_veg_76d3ae.webp', 'library', 'image/webp', 800, 796, 41396, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 141}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(142, 1, 'veg_veg_7c8858', 'Veg', NULL, 'uploads/global_assets/veg_veg_7c8858.webp', 'library', 'image/webp', 800, 723, 31386, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 142}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(143, 1, 'veg_veg_825ea7', 'Veg', NULL, 'uploads/global_assets/veg_veg_825ea7.webp', 'library', 'image/webp', 727, 800, 39162, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 143}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(144, 1, 'veg_veg_890792', 'Veg', NULL, 'uploads/global_assets/veg_veg_890792.webp', 'library', 'image/webp', 800, 573, 36616, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 144}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(145, 1, 'veg_veg_8a05aa', 'Veg', NULL, 'uploads/global_assets/veg_veg_8a05aa.webp', 'library', 'image/webp', 800, 789, 81296, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 145}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(146, 1, 'veg_veg_8cda35', 'Veg', NULL, 'uploads/global_assets/veg_veg_8cda35.webp', 'library', 'image/webp', 800, 787, 131098, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 146}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(147, 1, 'veg_veg_91039c', 'Veg', NULL, 'uploads/global_assets/veg_veg_91039c.webp', 'library', 'image/webp', 800, 585, 34576, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 147}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL);
INSERT INTO `sh_assets` (`id`, `tenant_id`, `ascii_key`, `display_name`, `tags_json`, `storage_url`, `storage_bucket`, `mime_type`, `width_px`, `height_px`, `filesize_bytes`, `has_alpha`, `checksum_sha256`, `role_hint`, `category`, `sub_type`, `cook_state`, `z_order_hint`, `variant_of`, `variant_kind`, `metadata_json`, `is_active`, `created_at`, `updated_at`, `deleted_at`, `created_by_user`) VALUES
(148, 1, 'veg_veg_916abc', 'Veg', NULL, 'uploads/global_assets/veg_veg_916abc.webp', 'library', 'image/webp', 746, 800, 68190, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 148}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(149, 1, 'veg_veg_9dc047', 'Veg', NULL, 'uploads/global_assets/veg_veg_9dc047.webp', 'library', 'image/webp', 800, 565, 33904, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 149}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(150, 1, 'veg_veg_a4aa2e', 'Veg', NULL, 'uploads/global_assets/veg_veg_a4aa2e.webp', 'library', 'image/webp', 728, 800, 35362, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 150}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(151, 1, 'veg_veg_b6a03d', 'Veg', NULL, 'uploads/global_assets/veg_veg_b6a03d.webp', 'library', 'image/webp', 758, 800, 63288, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 151}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(152, 1, 'veg_veg_b6d8a7', 'Veg', NULL, 'uploads/global_assets/veg_veg_b6d8a7.webp', 'library', 'image/webp', 800, 719, 38344, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 152}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(153, 1, 'veg_veg_b8a8a0', 'Veg', NULL, 'uploads/global_assets/veg_veg_b8a8a0.webp', 'library', 'image/webp', 800, 431, 19864, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 153}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(154, 1, 'veg_veg_b9536c', 'Veg', NULL, 'uploads/global_assets/veg_veg_b9536c.webp', 'library', 'image/webp', 800, 789, 65466, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 154}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(155, 1, 'veg_veg_bcd19c', 'Veg', NULL, 'uploads/global_assets/veg_veg_bcd19c.webp', 'library', 'image/webp', 800, 433, 19650, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 155}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(156, 1, 'veg_veg_be4c65', 'Veg', NULL, 'uploads/global_assets/veg_veg_be4c65.webp', 'library', 'image/webp', 800, 800, 130514, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 156}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(157, 1, 'veg_veg_c52e77', 'Veg', NULL, 'uploads/global_assets/veg_veg_c52e77.webp', 'library', 'image/webp', 784, 800, 65508, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 157}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(158, 1, 'veg_veg_c5cc4b', 'Veg', NULL, 'uploads/global_assets/veg_veg_c5cc4b.webp', 'library', 'image/webp', 504, 800, 27494, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 158}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(159, 1, 'veg_veg_d13b84', 'Veg', NULL, 'uploads/global_assets/veg_veg_d13b84.webp', 'library', 'image/webp', 800, 766, 78512, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 159}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(160, 1, 'veg_veg_d32f2f', 'Veg', NULL, 'uploads/global_assets/veg_veg_d32f2f.webp', 'library', 'image/webp', 489, 800, 28210, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 160}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(161, 1, 'veg_veg_db22c7', 'Veg', NULL, 'uploads/global_assets/veg_veg_db22c7.webp', 'library', 'image/webp', 381, 800, 21728, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 161}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(162, 1, 'veg_veg_db3185', 'Veg', NULL, 'uploads/global_assets/veg_veg_db3185.webp', 'library', 'image/webp', 312, 800, 27092, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 162}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(163, 1, 'veg_veg_def2d6', 'Veg', NULL, 'uploads/global_assets/veg_veg_def2d6.webp', 'library', 'image/webp', 719, 800, 31542, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 163}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(164, 1, 'veg_veg_e51a6c', 'Veg', NULL, 'uploads/global_assets/veg_veg_e51a6c.webp', 'library', 'image/webp', 800, 724, 52566, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 164}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(165, 1, 'veg_veg_f16843', 'Veg', NULL, 'uploads/global_assets/veg_veg_f16843.webp', 'library', 'image/webp', 758, 800, 59478, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 165}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(166, 1, 'veg_veg_f2f26c', 'Veg', NULL, 'uploads/global_assets/veg_veg_f2f26c.webp', 'library', 'image/webp', 800, 772, 41878, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 166}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(167, 1, 'veg_veg_f71cf5', 'Veg', NULL, 'uploads/global_assets/veg_veg_f71cf5.webp', 'library', 'image/webp', 758, 800, 86772, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 167}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(168, 1, 'veg_veg_f7fec4', 'Veg', NULL, 'uploads/global_assets/veg_veg_f7fec4.webp', 'library', 'image/webp', 789, 800, 58234, 1, NULL, 'layer', 'veg', 'veg', 'either', 50, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 168}', 1, '2026-04-17 04:51:50', '2026-04-19 10:29:22', NULL, NULL),
(169, 1, 'surface_wood_plank_v1', 'Surface', NULL, 'uploads/global_assets/surface_wood_plank_v1.jpg', 'library', 'image/webp', 5184, 3456, 18425460, 1, NULL, 'layer', 'misc', 'surface', 'either', 999, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 169}', 0, '2026-04-17 04:51:50', '2026-04-19 11:43:39', '2026-04-19 11:43:39', NULL),
(170, 1, 'board___0010_13_wynik_be6f85', '__0010_13_wynik', NULL, 'uploads/global_assets/board___0010_13_wynik_be6f85.webp', 'library', 'image/webp', 1500, 1000, 396566, 1, NULL, 'layer', 'base', '__0010_13_wynik', 'either', 0, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 170}', 1, '2026-04-17 04:55:56', '2026-04-19 10:29:22', NULL, NULL),
(171, 1, 'extra_sos_czosnkowy_thermomaniakpl_removebg_preview_wynik_177650', 'Sos_czosnkowy_thermomaniakpl_removebg_preview_wynik', NULL, 'uploads/global_assets/extra_sos_czosnkowy_thermomaniakpl_removebg_preview_wynik_177650.webp', 'library', 'image/webp', 1500, 1123, 50560, 1, NULL, 'layer', 'extra', 'sos_czosnkowy_thermomaniakpl_removebg_preview_wynik', 'either', 70, NULL, NULL, '{\"target_px\": 500, \"backfilled_from\": \"sh_global_assets\", \"orig_id\": 171}', 1, '2026-04-17 13:30:02', '2026-04-19 10:29:22', NULL, NULL),
(266, 0, 'bg_rustic_wood_warm', 'Deska', NULL, 'uploads/assets/0/library/bg_rustic_wood_warm.svg', 'library', 'image/svg+xml', 400, 400, 755, 1, '9f9155d980549bba7f23c7984beb562768344dca4d55e8d653dfb920deb9123e', 'surface', 'board', 'wood_warm', 'either', 10, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(267, 0, 'bg_rustic_wood_dark', 'Deska', NULL, 'uploads/assets/0/library/bg_rustic_wood_dark.svg', 'library', 'image/svg+xml', 400, 400, 755, 1, '9ff024a7b0eb30546e34b81e8a58616c2881f9e6e05ce60c7af85550d0945295', 'surface', 'board', 'wood_dark', 'either', 10, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(268, 0, 'bg_marble_white', 'Deska', NULL, 'uploads/assets/0/library/bg_marble_white.svg', 'library', 'image/svg+xml', 400, 400, 747, 1, '96687e3a9539224d30c6498a72c90bf3faff24c64e31e640303bc42d81618ba0', 'surface', 'board', 'marble_white', 'either', 10, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(269, 0, 'bg_marble_black', 'Deska', NULL, 'uploads/assets/0/library/bg_marble_black.svg', 'library', 'image/svg+xml', 400, 400, 747, 1, 'e7c21806eb6900f3cfca9394b497428c5286fc05caf803d8f7bf08eeaeb1c2f4', 'surface', 'board', 'marble_black', 'either', 10, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(270, 0, 'bg_slate_dark', 'Deska', NULL, 'uploads/assets/0/library/bg_slate_dark.svg', 'library', 'image/svg+xml', 400, 400, 743, 1, 'a5ebdd7b51f63581735071ffe5f417706b193b2f3c02b6caec4e8c02ce0c8a93', 'surface', 'board', 'slate_dark', 'either', 10, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(271, 0, 'bg_linen_beige', 'Deska', NULL, 'uploads/assets/0/library/bg_linen_beige.svg', 'library', 'image/svg+xml', 400, 400, 745, 1, '6c37981fa1fe20aa483bd6708feda1b84cb0b2d8153bb3fbdadc3f9397f7482b', 'surface', 'board', 'linen_beige', 'either', 10, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(272, 0, 'bg_concrete_gray', 'Deska', NULL, 'uploads/assets/0/library/bg_concrete_gray.svg', 'library', 'image/svg+xml', 400, 400, 749, 1, '7b3530127bb958afc5d59fd89190666fb6042926b0a2d942c151e2bfe7acbf5d', 'surface', 'board', 'concrete', 'either', 10, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(273, 0, 'bg_metal_brushed', 'Deska', NULL, 'uploads/assets/0/library/bg_metal_brushed.svg', 'library', 'image/svg+xml', 400, 400, 749, 1, 'bab3a29fe4ecbe4410f4ea56b82f7ed11605dda86f2ab0a3450a39b7d9abf92b', 'surface', 'board', 'metal', 'either', 10, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(274, 0, 'bg_chalkboard', 'Deska', NULL, 'uploads/assets/0/library/bg_chalkboard.svg', 'library', 'image/svg+xml', 400, 400, 743, 1, 'b9fe9755015d92fe5fca890bd2157a42d9b25d9a554f6ef3270aaa1d0e84c68b', 'surface', 'board', 'chalkboard', 'either', 10, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(275, 0, 'bg_newspaper_vintage', 'Deska', NULL, 'uploads/assets/0/library/bg_newspaper_vintage.svg', 'library', 'image/svg+xml', 400, 400, 757, 1, '87cca58d274bb7200820561918cc2f6304de395a0fba5f9f83e6dd14ea83d500', 'surface', 'board', 'newspaper', 'either', 10, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(276, 0, 'prop_bottle_oil', 'Bottle_oil', NULL, 'uploads/assets/0/library/prop_bottle_oil.svg', 'library', 'image/svg+xml', 400, 400, 822, 1, 'fea816abd84a039e66582f88be15bb6536e1190e27d4cd3afb6576cf76a10b59', 'companion', 'misc', 'bottle_oil', 'either', 60, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(277, 0, 'prop_bottle_vinegar', 'Bottle_vinegar', NULL, 'uploads/assets/0/library/prop_bottle_vinegar.svg', 'library', 'image/svg+xml', 400, 400, 830, 1, 'e3f01766ad1441f56245dacae9ec2fc2d77cd87e9ad4d7983a60cada7e5c3381', 'companion', 'misc', 'bottle_vinegar', 'either', 60, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(278, 0, 'prop_bottle_tabasco', 'Bottle_tabasco', NULL, 'uploads/assets/0/library/prop_bottle_tabasco.svg', 'library', 'image/svg+xml', 400, 400, 830, 1, 'cf395ece69e976124846bab1647a08caa7fb003a8e95a16125fe0e1e18577f47', 'companion', 'misc', 'bottle_tabasco', 'either', 60, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(279, 0, 'prop_bottle_coca_cola', 'Bottle_cola', NULL, 'uploads/assets/0/library/prop_bottle_coca_cola.svg', 'library', 'image/svg+xml', 400, 400, 834, 1, 'f067ea2b267fc3c28b5e1226509eb509176d1273d6b632597af40cc62d63bb27', 'companion', 'drink', 'bottle_cola', 'either', 65, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(280, 0, 'prop_bottle_water', 'Bottle_water', NULL, 'uploads/assets/0/library/prop_bottle_water.svg', 'library', 'image/svg+xml', 400, 400, 826, 1, '36c56722226a169c2d656f8506ab909104206d38663e3ae62dda83e0f68af543', 'companion', 'drink', 'bottle_water', 'either', 65, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(281, 0, 'prop_fork_silver', 'Cutlery_fork', NULL, 'uploads/assets/0/library/prop_fork_silver.svg', 'library', 'image/svg+xml', 400, 400, 824, 1, 'ec45d98ba6bc7ea1af0ae1da16f14161d1ae3edd7aa2a14c5f9894e91ff2387e', 'companion', 'misc', 'cutlery_fork', 'either', 55, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(282, 0, 'prop_knife_silver', 'Cutlery_knife', NULL, 'uploads/assets/0/library/prop_knife_silver.svg', 'library', 'image/svg+xml', 400, 400, 826, 1, 'b8b4ebb03221f6bd38f384d39bd652e0f9e654000ce350a4fabd71e985033820', 'companion', 'misc', 'cutlery_knife', 'either', 55, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(283, 0, 'prop_spoon_silver', 'Cutlery_spoon', NULL, 'uploads/assets/0/library/prop_spoon_silver.svg', 'library', 'image/svg+xml', 400, 400, 826, 1, '07a53648be864b631830cf1f79d0ca9038c8d6f62587cd54979358800b237482', 'companion', 'misc', 'cutlery_spoon', 'either', 55, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(284, 0, 'prop_chopsticks_wood', 'Cutlery_chopstk', NULL, 'uploads/assets/0/library/prop_chopsticks_wood.svg', 'library', 'image/svg+xml', 400, 400, 832, 1, 'f41a3689942b5c911041ee09240326ca666df3442f2fef0b2fcb3a574c110067', 'companion', 'misc', 'cutlery_chopstk', 'either', 55, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(285, 0, 'prop_chopsticks_metal', 'Cutlery_chopstk', NULL, 'uploads/assets/0/library/prop_chopsticks_metal.svg', 'library', 'image/svg+xml', 400, 400, 834, 1, 'dc765c46e7217ca167c94fc86f12d6f66d027292d02d85349ae6154cb2ff30ff', 'companion', 'misc', 'cutlery_chopstk', 'either', 55, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(286, 0, 'prop_napkin_white', 'Napkin_white', NULL, 'uploads/assets/0/library/prop_napkin_white.svg', 'library', 'image/svg+xml', 400, 400, 826, 1, '868a2194d0164da80d924999585926d33a981d5f071d76ee57f07cc80426bb4c', 'companion', 'misc', 'napkin_white', 'either', 40, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(287, 0, 'prop_napkin_kraft', 'Napkin_kraft', NULL, 'uploads/assets/0/library/prop_napkin_kraft.svg', 'library', 'image/svg+xml', 400, 400, 826, 1, '458e4d1041d1d517d7cb596a826cd3187c4c1073894f97f62634de43760cf3d4', 'companion', 'misc', 'napkin_kraft', 'either', 40, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(288, 0, 'prop_napkin_linen', 'Napkin_linen', NULL, 'uploads/assets/0/library/prop_napkin_linen.svg', 'library', 'image/svg+xml', 400, 400, 826, 1, '8bafe671956bdde4132e6d56fa74d51b302925353daab5cedfcd8d10f52193ee', 'companion', 'misc', 'napkin_linen', 'either', 40, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(289, 0, 'prop_napkin_red_check', 'Napkin_check', NULL, 'uploads/assets/0/library/prop_napkin_red_check.svg', 'library', 'image/svg+xml', 400, 400, 834, 1, '84519074be3de0b6401c0f2d029c0bb380f5822b866962da5855627132528f2f', 'companion', 'misc', 'napkin_check', 'either', 40, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(290, 0, 'prop_towel_waffle', 'Towel_waffle', NULL, 'uploads/assets/0/library/prop_towel_waffle.svg', 'library', 'image/svg+xml', 400, 400, 826, 1, '9af9a1533e27fa8ad7dc37335ce349ae7c53af8e67259227a423eee45c9ec9cf', 'companion', 'misc', 'towel_waffle', 'either', 40, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(291, 0, 'prop_glass_tall', 'Glass_tall', NULL, 'uploads/assets/0/library/prop_glass_tall.svg', 'library', 'image/svg+xml', 400, 400, 822, 1, '76d9bcb07fd769288af13f940fdfcabdfc0212e6cc047c62dfb8c33fef833c04', 'companion', 'drink', 'glass_tall', 'either', 55, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(292, 0, 'prop_glass_low', 'Glass_low', NULL, 'uploads/assets/0/library/prop_glass_low.svg', 'library', 'image/svg+xml', 400, 400, 820, 1, 'd3ec45b7cbd36f11eb918abb11a88e0fbb9d8523df76fbaac2c219cd8ebc0eb5', 'companion', 'drink', 'glass_low', 'either', 55, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(293, 0, 'prop_wine_glass', 'Glass_wine', NULL, 'uploads/assets/0/library/prop_wine_glass.svg', 'library', 'image/svg+xml', 400, 400, 822, 1, '88558ea4b7201968bc0a7d8457704e13cd31a12f56659a49051ae6e825d3e467', 'companion', 'drink', 'glass_wine', 'either', 55, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(294, 0, 'prop_coffee_cup', 'Cup_coffee', NULL, 'uploads/assets/0/library/prop_coffee_cup.svg', 'library', 'image/svg+xml', 400, 400, 822, 1, 'd303a3626f4b1173a8a19fe889f65e4942226392452584dfe70316bd5a0ec976', 'companion', 'drink', 'cup_coffee', 'either', 55, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(295, 0, 'prop_beer_bottle', 'Bottle_beer', NULL, 'uploads/assets/0/library/prop_beer_bottle.svg', 'library', 'image/svg+xml', 400, 400, 824, 1, 'b9b7cc5b5d899ce5b9bd0e3a00b66c08b324fb446fbd53eaf4f32688b80cd95c', 'companion', 'drink', 'bottle_beer', 'either', 65, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(296, 0, 'prop_basil_leaf', 'Bazylia', NULL, 'uploads/assets/0/library/prop_basil_leaf.svg', 'library', 'image/svg+xml', 400, 400, 822, 1, '4f6ecc666abdbd02dffae8f460738f5ea1159fafdeaff4fa2c0d8d73648103d0', 'companion', 'herb', 'herb_basil', 'either', 50, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(297, 0, 'prop_rosemary_sprig', 'Herb_rosemary', NULL, 'uploads/assets/0/library/prop_rosemary_sprig.svg', 'library', 'image/svg+xml', 400, 400, 830, 1, '36bccec06ec459bdd62f6b400181ec263f4c5a1c1c07f9b88ffe2e71c1ce1535', 'companion', 'herb', 'herb_rosemary', 'either', 50, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(298, 0, 'prop_pepper_shaker', 'Shaker_pepper', NULL, 'uploads/assets/0/library/prop_pepper_shaker.svg', 'library', 'image/svg+xml', 400, 400, 828, 1, '9c8e2c7cec14ec318e1b3ec7bc8f892de8de143429af003789554c79ec137d8a', 'companion', 'misc', 'shaker_pepper', 'either', 55, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(299, 0, 'prop_salt_shaker', 'Shaker_salt', NULL, 'uploads/assets/0/library/prop_salt_shaker.svg', 'library', 'image/svg+xml', 400, 400, 824, 1, '12599a4f11c2a28906497422d9844258af0241bcda480ad81d031b5bf254e9cd', 'companion', 'misc', 'shaker_salt', 'either', 55, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(300, 0, 'prop_candle_glass', 'Candle_glass', NULL, 'uploads/assets/0/library/prop_candle_glass.svg', 'library', 'image/svg+xml', 400, 400, 826, 1, '3b2efa376055479ea572afb08105681ff395b8eaf874b03eb6e096083f103c96', 'companion', 'misc', 'candle_glass', 'either', 55, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(301, 0, 'prop_board_round', 'Deska', NULL, 'uploads/assets/0/library/prop_board_round.svg', 'library', 'image/svg+xml', 400, 400, 752, 1, '45c4a775f1b9fd51ed2de91a432290dee406c02981e78b47f1c3be3b9b36acb6', 'companion', 'board', 'plate_round', 'either', 15, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(302, 0, 'prop_board_rect', 'Deska', NULL, 'uploads/assets/0/library/prop_board_rect.svg', 'library', 'image/svg+xml', 400, 400, 750, 1, '59fbc35c28facbda73d5b535c5543da1c61b6fc180592c7e906e3547be5ff7d8', 'companion', 'board', 'plate_rect', 'either', 15, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(303, 0, 'prop_board_bamboo', 'Deska', NULL, 'uploads/assets/0/library/prop_board_bamboo.svg', 'library', 'image/svg+xml', 400, 400, 749, 1, '68c9d4dc8a5a599377cf58166fa8755eb5aae0a3ba0815dcdc03ba9e6f3711df', 'companion', 'board', 'plate_bamboo', 'either', 15, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(304, 0, 'prop_board_slate', 'Deska', NULL, 'uploads/assets/0/library/prop_board_slate.svg', 'library', 'image/svg+xml', 400, 400, 747, 1, '1757ba9d4213a1072e18f6a15039df71e29d9b2f8f7b0bc840c74c1a02dc7dcb', 'companion', 'board', 'plate_slate', 'either', 15, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(305, 0, 'prop_tray_metal', 'Deska', NULL, 'uploads/assets/0/library/prop_tray_metal.svg', 'library', 'image/svg+xml', 400, 400, 745, 1, '7e4174cd15833e55b6d0822e405e4499e454c685f86620e61643ae24e654371d', 'companion', 'board', 'plate_metal', 'either', 15, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(306, 0, 'light_warm_top', 'Light_warm_top', NULL, 'uploads/assets/0/library/light_warm_top.svg', 'library', 'image/svg+xml', 400, 400, 819, 1, 'b16f834bd2058cb7733801de5ff3b8588cd9d76516d12d0fc672ea5d8536d0c0', 'layer', 'misc', 'light_warm_top', 'either', 90, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(307, 0, 'light_warm_rim', 'Light_warm_rim', NULL, 'uploads/assets/0/library/light_warm_rim.svg', 'library', 'image/svg+xml', 400, 400, 819, 1, 'd70f18cc6804a7ccd867e4ec6ff4a172d2e23ff7c717b13febe9414ba24f8d89', 'layer', 'misc', 'light_warm_rim', 'either', 90, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(308, 0, 'light_cold_side', 'Light_cold_side', NULL, 'uploads/assets/0/library/light_cold_side.svg', 'library', 'image/svg+xml', 400, 400, 821, 1, 'fec5318f91b4b95e85f991be7163411147f23488731a0c0d403f98dc95d05ef7', 'layer', 'misc', 'light_cold_side', 'either', 90, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(309, 0, 'light_soft_box', 'Light_soft_box', NULL, 'uploads/assets/0/library/light_soft_box.svg', 'library', 'image/svg+xml', 400, 400, 819, 1, '183e9fe11c37a5a73dfc22d8de21d629f16f8e0d7d8a4faa4c362b8c586177a0', 'layer', 'misc', 'light_soft_box', 'either', 90, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(310, 0, 'light_candle_glow', 'Light_candle', NULL, 'uploads/assets/0/library/light_candle_glow.svg', 'library', 'image/svg+xml', 400, 400, 825, 1, '5889d0d9dd5005bd404a64d50a92148a43fc290a6eb9ec5fd79a81ed281264f0', 'layer', 'misc', 'light_candle', 'either', 90, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(311, 0, 'light_golden_hour', 'Light_golden_hour', NULL, 'uploads/assets/0/library/light_golden_hour.svg', 'library', 'image/svg+xml', 400, 400, 825, 1, 'c0bc96cd645984b4d6a9c94e556f1f59bb4f3d977cc80627a8d5c2a7f3005239', 'layer', 'misc', 'light_golden_hour', 'either', 90, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(312, 0, 'light_dramatic_rim', 'Light_dramatic', NULL, 'uploads/assets/0/library/light_dramatic_rim.svg', 'library', 'image/svg+xml', 400, 400, 827, 1, '39e1f2ce23163ca4d62c02fbf8293ff58fc4735609b44a2308ffb77780d8b1fa', 'layer', 'misc', 'light_dramatic', 'either', 90, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(313, 0, 'light_neon_pink', 'Light_neon_pink', NULL, 'uploads/assets/0/library/light_neon_pink.svg', 'library', 'image/svg+xml', 400, 400, 821, 1, '2899df041d1884b6ffd93409e439b944bf51fc512fa705a0f0425de083843d36', 'layer', 'misc', 'light_neon_pink', 'either', 90, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(314, 0, 'badge_amber_discount', 'Badge_discount', NULL, 'uploads/assets/0/library/badge_amber_discount.svg', 'library', 'image/svg+xml', 400, 400, 821, 1, 'd7374c0843698d1b13d387adcc609e1e0ec51e8c8eb7df58230a24aa39eb90af', 'icon', 'misc', 'badge_discount', 'either', 95, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(315, 0, 'badge_gold_limited', 'Badge_limited', NULL, 'uploads/assets/0/library/badge_gold_limited.svg', 'library', 'image/svg+xml', 400, 400, 822, 1, 'af5c30fc94386fc4153c72582e717a7a4c734b6c0395debb016ec6c3afffbe04', 'icon', 'misc', 'badge_limited', 'either', 95, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(316, 0, 'badge_red_burst', 'Badge_burst', NULL, 'uploads/assets/0/library/badge_red_burst.svg', 'library', 'image/svg+xml', 400, 400, 816, 1, 'a16411b99d38bc765a30b6013a29685079e6ad2980e6945fe76322e914b97d2c', 'icon', 'misc', 'badge_burst', 'either', 95, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(317, 0, 'badge_neon_pink', 'Badge_neon', NULL, 'uploads/assets/0/library/badge_neon_pink.svg', 'library', 'image/svg+xml', 400, 400, 815, 1, 'e58470227f30543eab9857d3d3e80f1e4bb7dea95869d9ff736009e42049bb7c', 'icon', 'misc', 'badge_neon', 'either', 95, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(318, 0, 'badge_vintage_stamp', 'Badge_vintage', NULL, 'uploads/assets/0/library/badge_vintage_stamp.svg', 'library', 'image/svg+xml', 400, 400, 824, 1, 'a1d0ee049c4ff0b5b419ebc39961d1baa9a29f4736f90005e0c4947ef0726de9', 'icon', 'misc', 'badge_vintage', 'either', 95, NULL, NULL, NULL, 1, '2026-04-18 01:46:51', '2026-04-19 10:29:22', NULL, 'scene_kit_seeder'),
(325, 1, 'hero_pizza_base_gluten_new_removebg_preview_dc1adb5e', 'Pizza_base_gluten_new_removebg_preview', NULL, 'uploads/assets/1/hero/hero_pizza_base_gluten_new_removebg_preview_dc1adb5e.png', 'hero', 'image/png', 500, 500, 334289, 1, 'dc1adb5e93c15be3427fffd761c38bc330c91d21da6ae3ea0ad15b0f3d90a51d', 'hero', 'hero', 'pizza_base_gluten_new_removebg_preview', 'either', 90, NULL, NULL, '{\"uploaded_via\":\"asset_studio_ui\",\"orig_name\":\"pizza_base_gluten_new-removebg-preview.png\"}', 1, '2026-04-19 02:09:39', '2026-04-19 10:29:22', NULL, '2');

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_asset_links`
--

CREATE TABLE `sh_asset_links` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `asset_id` bigint(20) UNSIGNED NOT NULL,
  `entity_type` varchar(32) NOT NULL COMMENT 'menu_item / modifier / visual_layer / board_companion / atelier_scene / scene_layer / tenant_brand / surface_library',
  `entity_ref` varchar(255) NOT NULL COMMENT 'ascii_key albo composite ref — semantyka w komentarzu migracji',
  `role` varchar(32) NOT NULL COMMENT 'hero / layer_top_down / product_shot / surface_bg / companion_icon / modifier_icon / tenant_logo / thumbnail / poster / og_image / ambient_texture',
  `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT 'Kolejność wyświetlania gdy wiele zasobów w tej samej roli',
  `display_params_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Per-link overrides: calScale, calRotate, offsetX/Y, zIndex, isBase, visualKind itd.' CHECK (json_valid(`display_params_json`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  `created_by_user` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='n:m mapowanie encji na zasoby (sh_assets) z rolami (od m021).';

--
-- Dumping data for table `sh_asset_links`
--

INSERT INTO `sh_asset_links` (`id`, `tenant_id`, `asset_id`, `entity_type`, `entity_ref`, `role`, `sort_order`, `display_params_json`, `is_active`, `created_at`, `updated_at`, `deleted_at`, `created_by_user`) VALUES
(1, 1, 8, 'visual_layer', 'PIZZA_MARGHERITA::base_dough_69ec5a', 'layer_top_down', 30, '{\"calScale\": 1.00, \"calRotate\": 0, \"offsetX\": 0.000, \"offsetY\": 0.000, \"zIndex\": 30, \"isBase\": 1, \"libCategory\": \"base\", \"libSubType\": \"dough\"}', 1, '2026-04-17 05:17:29', NULL, NULL, NULL),
(2, 1, 18, 'visual_layer', 'PIZZA_BBQ_CHICKEN::board_plate_0fa8df', 'layer_top_down', 0, '{\"calScale\": 1.00, \"calRotate\": 0, \"offsetX\": 0.000, \"offsetY\": 0.000, \"zIndex\": 0, \"isBase\": 1, \"libCategory\": \"board\", \"libSubType\": \"plate\"}', 1, '2026-04-17 05:25:22', NULL, NULL, NULL),
(3, 1, 18, 'visual_layer', 'PIZZA_CALZONE::board_plate_0fa8df', 'layer_top_down', 0, '{\"calScale\": 1.00, \"calRotate\": 0, \"offsetX\": 0.000, \"offsetY\": 0.000, \"zIndex\": 0, \"isBase\": 1, \"libCategory\": \"board\", \"libSubType\": \"plate\"}', 1, '2026-04-17 05:25:22', NULL, NULL, NULL),
(4, 1, 18, 'visual_layer', 'PIZZA_CAPRICCIOSA::board_plate_0fa8df', 'layer_top_down', 0, '{\"calScale\": 1.00, \"calRotate\": 0, \"offsetX\": 0.000, \"offsetY\": 0.000, \"zIndex\": 0, \"isBase\": 1, \"libCategory\": \"board\", \"libSubType\": \"plate\"}', 1, '2026-04-17 05:25:22', NULL, NULL, NULL),
(5, 1, 18, 'visual_layer', 'PIZZA_DIAVOLA::board_plate_0fa8df', 'layer_top_down', 0, '{\"calScale\": 1.00, \"calRotate\": 0, \"offsetX\": 0.000, \"offsetY\": 0.000, \"zIndex\": 0, \"isBase\": 1, \"libCategory\": \"board\", \"libSubType\": \"plate\"}', 1, '2026-04-17 05:25:22', NULL, NULL, NULL),
(6, 1, 18, 'visual_layer', 'PIZZA_HAWAJSKA::board_plate_0fa8df', 'layer_top_down', 0, '{\"calScale\": 1.00, \"calRotate\": 0, \"offsetX\": 0.000, \"offsetY\": 0.000, \"zIndex\": 0, \"isBase\": 1, \"libCategory\": \"board\", \"libSubType\": \"plate\"}', 1, '2026-04-17 05:25:22', NULL, NULL, NULL),
(7, 1, 18, 'visual_layer', 'PIZZA_PEPPERONI::board_plate_0fa8df', 'layer_top_down', 0, '{\"calScale\": 1.00, \"calRotate\": 0, \"offsetX\": 0.000, \"offsetY\": 0.000, \"zIndex\": 0, \"isBase\": 1, \"libCategory\": \"board\", \"libSubType\": \"plate\"}', 1, '2026-04-17 05:25:22', NULL, NULL, NULL),
(8, 1, 18, 'visual_layer', 'PIZZA_PROSC_FUNGHI::board_plate_0fa8df', 'layer_top_down', 0, '{\"calScale\": 1.00, \"calRotate\": 0, \"offsetX\": 0.000, \"offsetY\": 0.000, \"zIndex\": 0, \"isBase\": 1, \"libCategory\": \"board\", \"libSubType\": \"plate\"}', 1, '2026-04-17 05:25:22', NULL, NULL, NULL),
(9, 1, 18, 'visual_layer', 'PIZZA_4FORMAGGI::board_plate_0fa8df', 'layer_top_down', 0, '{\"calScale\": 1.00, \"calRotate\": 0, \"offsetX\": 0.000, \"offsetY\": 0.000, \"zIndex\": 0, \"isBase\": 1, \"libCategory\": \"board\", \"libSubType\": \"plate\"}', 1, '2026-04-17 05:25:22', NULL, NULL, NULL),
(10, 1, 18, 'visual_layer', 'PIZZA_VEGETARIANA::board_plate_0fa8df', 'layer_top_down', 0, '{\"calScale\": 1.00, \"calRotate\": 0, \"offsetX\": 0.000, \"offsetY\": 0.000, \"zIndex\": 0, \"isBase\": 1, \"libCategory\": \"board\", \"libSubType\": \"plate\"}', 1, '2026-04-17 05:25:22', NULL, NULL, NULL),
(11, 1, 18, 'visual_layer', 'SET_LUNCH_PIZZA::board_plate_0fa8df', 'layer_top_down', 0, '{\"calScale\": 1.00, \"calRotate\": 0, \"offsetX\": 0.000, \"offsetY\": 0.000, \"zIndex\": 0, \"isBase\": 1, \"libCategory\": \"board\", \"libSubType\": \"plate\"}', 1, '2026-04-17 05:25:22', NULL, NULL, NULL),
(12, 1, 27, 'visual_layer', 'PIZZA_MARGHERITA::cheese_cheese_82f9f8', 'layer_top_down', 50, '{\"calScale\": 1.00, \"calRotate\": 0, \"offsetX\": 0.000, \"offsetY\": 0.000, \"zIndex\": 50, \"isBase\": 0, \"libCategory\": \"cheese\", \"libSubType\": \"cheese\"}', 1, '2026-04-17 05:17:29', NULL, NULL, NULL),
(13, 1, 89, 'visual_layer', 'PIZZA_MARGHERITA::sauce_sauce_3ebc9a', 'layer_top_down', 40, '{\"calScale\": 1.00, \"calRotate\": 0, \"offsetX\": 0.000, \"offsetY\": 0.000, \"zIndex\": 40, \"isBase\": 0, \"libCategory\": \"sauce\", \"libSubType\": \"sauce\"}', 1, '2026-04-17 05:17:29', NULL, NULL, NULL),
(14, 1, 170, 'visual_layer', 'PIZZA_MARGHERITA::board___0010_13_wynik_be6f85', 'layer_top_down', 10, '{\"calScale\": 1.00, \"calRotate\": 0, \"offsetX\": 0.000, \"offsetY\": 0.000, \"zIndex\": 10, \"isBase\": 1, \"libCategory\": \"board\", \"libSubType\": \"__0010_13_wynik\"}', 1, '2026-04-17 05:17:29', NULL, NULL, NULL),
(32, 1, 325, 'menu_item', 'PIZZA_MARGHERITA', 'hero', 0, NULL, 1, '2026-04-19 02:17:30', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_atelier_scenes`
--

CREATE TABLE `sh_atelier_scenes` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `item_sku` varchar(64) NOT NULL,
  `spec_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`spec_json`)),
  `version` int(11) NOT NULL DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `scene_kind` enum('item','category') NOT NULL DEFAULT 'item' COMMENT 'M022: typ sceny',
  `template_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'M022: FK do sh_scene_templates',
  `parent_category_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'M022: dla scene_kind=category — FK do sh_categories.id',
  `active_style_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'M022: FK do sh_style_presets',
  `active_camera_preset` varchar(64) DEFAULT NULL COMMENT 'M022: top_down / hero_three_quarter / macro_close / wide_establishing / dutch_angle / rack_focus',
  `active_lut` varchar(64) DEFAULT NULL COMMENT 'M022: warm_summer_evening / golden_hour / film_noir_bw / etc.',
  `atmospheric_effects_enabled_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'M022: tablica włączonych atmospheric effects' CHECK (json_valid(`atmospheric_effects_enabled_json`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sh_atelier_scenes`
--

INSERT INTO `sh_atelier_scenes` (`id`, `tenant_id`, `item_sku`, `spec_json`, `version`, `updated_at`, `scene_kind`, `template_id`, `parent_category_id`, `active_style_id`, `active_camera_preset`, `active_lut`, `atmospheric_effects_enabled_json`) VALUES
(1, 1, 'PIZZA_MARGHERITA', '{\"pizza\":{\"layers\":[{\"layerSku\":\"BASE_hero_pizza_base_gluten_new_removebg_preview_dc1adb5e\",\"assetUrl\":\"/slicehub/uploads/assets/1/hero/hero_pizza_base_gluten_new_removebg_preview_dc1adb5e.png\",\"zIndex\":0,\"isBase\":true,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"visible\":true,\"source\":\"auto_hero\"}]},\"meta\":{\"generatedAt\":\"2026-04-19T03:37:22+00:00\",\"generatedBy\":\"menu_studio_autogen\",\"sourceLayerCount\":1,\"hasHero\":true,\"modifierCount\":0}}', 101, '2026-04-19 03:39:42', 'item', NULL, NULL, NULL, NULL, NULL, NULL),
(2, 1, 'PIZZA_PEPPERONI', '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":35,\"lightY\":15,\"grainIntensity\":8,\"vignetteIntensity\":40,\"lutName\":\"darkvinyl\",\"letterbox\":3},\"pizza\":{\"x\":70,\"y\":60,\"scale\":1.4,\"rotation\":3,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_a5aa21\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_a5aa21.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"meat_meat_a7aa4e\",\"assetUrl\":\"/slicehub/uploads/global_assets/meat_meat_a7aa4e.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.016644114987711997,\"offsetY\":-0.018235036757778406,\"blendMode\":\"normal\",\"alpha\":0.994,\"feather\":5,\"brightness\":0.98,\"saturation\":1.05,\"hueRotate\":0,\"shadowStrength\":0.55,\"isPopUp\":true,\"visible\":true,\"locked\":false}]},\"companions\":[{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":75,\"y\":18,\"scale\":0.75,\"rotation\":-3,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":19.86598581281736,\"y\":64.31010313858773,\"scale\":0.85,\"rotation\":2,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":24,\"y\":15,\"scale\":0.95,\"rotation\":-5,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_JUICE_ORANGE\",\"name\":\"Sok pomarańczowy\",\"assetUrl\":\"\",\"x\":69,\"y\":83,\"scale\":0.75,\"rotation\":4,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":3,\"y\":8,\"w\":40,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":18,\"seed\":\"meat\"},\"steam\":{\"count\":4,\"intensity\":55},\"oilSheen\":{\"enabled\":true,\"x\":40,\"y\":40}}}', 16, '2026-04-19 09:17:16', 'item', NULL, NULL, NULL, NULL, NULL, NULL),
(3, 1, 'SAUCE_GARLIC', '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":25,\"y\":55,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":42,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Sos czosnkowy\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 2, '2026-04-19 08:38:58', 'item', NULL, NULL, NULL, NULL, NULL, NULL),
(4, 1, 'PIZZA_4FORMAGGI', '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":25,\"y\":55,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":42,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Quattro Formaggi\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 2, '2026-04-17 05:18:16', 'item', NULL, NULL, NULL, NULL, NULL, NULL),
(5, 1, 'SIDE_GARLIC_SAUCE', '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":25,\"y\":55,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":42,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Sos czosnkowy\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 2, '2026-04-17 15:17:10', 'item', NULL, NULL, NULL, NULL, NULL, NULL),
(6, 1, 'PIZZA_CAPRICCIOSA', '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":25,\"y\":55,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":42,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Capricciosa\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 3, '2026-04-19 08:20:48', 'item', NULL, NULL, NULL, NULL, NULL, NULL),
(7, 1, 'PIZZA_HAWAJSKA', '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":25,\"y\":55,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":42,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Hawajska\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 1, '2026-04-19 00:05:31', 'item', NULL, NULL, NULL, NULL, NULL, NULL),
(8, 1, 'DRINK_WATER_500', '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":25,\"y\":55,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":42,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Woda mineralna 0.5L\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 1, '2026-04-19 03:39:17', 'item', NULL, NULL, NULL, NULL, NULL, NULL),
(9, 1, 'DRINK_PEPSI_500', '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":25,\"y\":55,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":42,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Pepsi 0.5L\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 1, '2026-04-19 03:39:30', 'item', NULL, NULL, NULL, NULL, NULL, NULL),
(10, 1, 'PIZZA_VEGETARIANA', '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":22.074448402669905,\"y\":55.828865560121365,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[]},\"companions\":[{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":75,\"y\":18,\"scale\":0.75,\"rotation\":-3,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":85,\"y\":60,\"scale\":0.85,\"rotation\":2,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":24,\"y\":15,\"scale\":0.95,\"rotation\":-5,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_JUICE_ORANGE\",\"name\":\"Sok pomarańczowy\",\"assetUrl\":\"\",\"x\":69,\"y\":83,\"scale\":0.75,\"rotation\":4,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 2, '2026-04-19 03:45:58', 'item', NULL, NULL, NULL, NULL, NULL, NULL),
(11, 1, 'PIZZA_DIAVOLA', '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":25,\"y\":55,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":42,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Diavola\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 1, '2026-04-19 03:46:01', 'item', NULL, NULL, NULL, NULL, NULL, NULL),
(12, 1, 'DRINK_WATER_05', '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":25,\"y\":55,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":42,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Woda mineralna 0.5L\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 1, '2026-04-19 08:20:57', 'item', NULL, NULL, NULL, NULL, NULL, NULL),
(13, 1, 'DESSERT_PANNA_COTTA', '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":25,\"y\":55,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":42,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Panna Cotta\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 1, '2026-04-19 09:16:16', 'item', NULL, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_atelier_scene_history`
--

CREATE TABLE `sh_atelier_scene_history` (
  `id` int(11) NOT NULL,
  `scene_id` int(11) NOT NULL,
  `spec_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`spec_json`)),
  `snapshot_label` varchar(120) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sh_atelier_scene_history`
--

INSERT INTO `sh_atelier_scene_history` (`id`, `scene_id`, `spec_json`, `snapshot_label`, `created_at`) VALUES
(1, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":25,\"y\":55,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":42,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Margherita\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 04:56:16'),
(2, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":14.287175481441405,\"y\":54.835953457064385,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":43.42904494210878,\"h\":39.292945423812704,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Margherita\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 04:56:21'),
(3, 2, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":25,\"y\":55,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":42,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Pepperoni\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 04:56:28'),
(4, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":14.287175481441405,\"y\":54.835953457064385,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":43.42904494210878,\"h\":39.292945423812704,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Margherita\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 04:56:40'),
(5, 3, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":25,\"y\":55,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":42,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Sos czosnkowy\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 04:56:56'),
(6, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":14.287175481441405,\"y\":54.835953457064385,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":43.42904494210878,\"h\":39.292945423812704,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Margherita\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 04:57:00'),
(7, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":14.287175481441405,\"y\":54.835953457064385,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":43.42904494210878,\"h\":39.292945423812704,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Margherita\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 04:58:20'),
(8, 4, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":25,\"y\":55,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":42,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Quattro Formaggi\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 05:02:49'),
(9, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":14.287175481441405,\"y\":54.619262058088594,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":43.42904494210878,\"h\":39.292945423812704,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Margherita\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 05:08:01'),
(10, 4, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":25,\"y\":55,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":42,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Quattro Formaggi\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 05:18:16'),
(11, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":14.287175481441405,\"y\":54.619262058088594,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":43.42904494210878,\"h\":39.292945423812704,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Margherita\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 11:20:21'),
(12, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":14.287175481441405,\"y\":54.619262058088594,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_008fe6\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_008fe6.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":43.42904494210878,\"h\":39.292945423812704,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Margherita\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 11:20:49'),
(13, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":14.287175481441405,\"y\":54.619262058088594,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":43.42904494210878,\"h\":39.292945423812704,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Margherita\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 11:20:56'),
(14, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":14.287175481441405,\"y\":54.619262058088594,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_ce7a24\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_ce7a24.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":43.42904494210878,\"h\":39.292945423812704,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Margherita\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 11:21:02'),
(15, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":14.287175481441405,\"y\":54.619262058088594,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":43.42904494210878,\"h\":39.292945423812704,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Margherita\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 11:21:04'),
(16, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":14.287175481441405,\"y\":54.619262058088594,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_ce7a24\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_ce7a24.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":43.42904494210878,\"h\":39.292945423812704,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Margherita\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 11:21:08'),
(17, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":14.287175481441405,\"y\":54.619262058088594,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_ce7a24\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_ce7a24.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":37.946310686113044,\"h\":34.332376335054654,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Margherita\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 11:21:17'),
(18, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":14.287175481441405,\"y\":54.619262058088594,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_ce7a24\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_ce7a24.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_4a3f9e\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_4a3f9e.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.00665770559320128,\"offsetY\":-0.0368376105679702,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":37.946310686113044,\"h\":34.332376335054654,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Margherita\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 11:21:34'),
(19, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":14.287175481441405,\"y\":54.619262058088594,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_ce7a24\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_ce7a24.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_4a3f9e\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_4a3f9e.webp\",\"zIndex\":23,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.00665770559320128,\"offsetY\":-0.0368376105679702,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":37.946310686113044,\"h\":34.332376335054654,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Margherita\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 11:21:43'),
(20, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":14.287175481441405,\"y\":54.619262058088594,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_ce7a24\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_ce7a24.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_4a3f9e\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_4a3f9e.webp\",\"zIndex\":23,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.00665770559320128,\"offsetY\":-0.0368376105679702,\"blendMode\":\"overlay\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":37.946310686113044,\"h\":34.332376335054654,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Margherita\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 11:21:49'),
(21, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":14.287175481441405,\"y\":54.619262058088594,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_ce7a24\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_ce7a24.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_4a3f9e\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_4a3f9e.webp\",\"zIndex\":23,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.00665770559320128,\"offsetY\":-0.0368376105679702,\"blendMode\":\"color-burn\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":37.946310686113044,\"h\":34.332376335054654,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Margherita\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 11:21:57'),
(22, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":14.287175481441405,\"y\":54.619262058088594,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_ce7a24\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_ce7a24.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_4a3f9e\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_4a3f9e.webp\",\"zIndex\":23,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.00665770559320128,\"offsetY\":-0.0368376105679702,\"blendMode\":\"darken\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":37.946310686113044,\"h\":34.332376335054654,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Margherita\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 11:22:00'),
(23, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":14.287175481441405,\"y\":54.619262058088594,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_ce7a24\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_ce7a24.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_4a3f9e\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_4a3f9e.webp\",\"zIndex\":23,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.00665770559320128,\"offsetY\":-0.0368376105679702,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":37.946310686113044,\"h\":34.332376335054654,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Margherita\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 11:22:05'),
(24, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":14.287175481441405,\"y\":54.619262058088594,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_ce7a24\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_ce7a24.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_4a3f9e\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_4a3f9e.webp\",\"zIndex\":23,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.00665770559320128,\"offsetY\":-0.0368376105679702,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":true,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":37.946310686113044,\"h\":34.332376335054654,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Margherita\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 11:22:11'),
(25, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":14.287175481441405,\"y\":54.619262058088594,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_ce7a24\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_ce7a24.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_4a3f9e\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_4a3f9e.webp\",\"zIndex\":23,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.00665770559320128,\"offsetY\":-0.0368376105679702,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.4,\"isPopUp\":true,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":37.946310686113044,\"h\":34.332376335054654,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Margherita\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 11:22:23'),
(26, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":14.287175481441405,\"y\":54.619262058088594,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_ce7a24\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_ce7a24.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_4a3f9e\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_4a3f9e.webp\",\"zIndex\":23,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.00665770559320128,\"offsetY\":-0.0368376105679702,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.4,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":37.946310686113044,\"h\":34.332376335054654,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Margherita\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 11:22:27'),
(27, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":14.287175481441405,\"y\":54.619262058088594,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_ce7a24\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_ce7a24.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"darken\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_4a3f9e\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_4a3f9e.webp\",\"zIndex\":23,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.00665770559320128,\"offsetY\":-0.0368376105679702,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.4,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":37.946310686113044,\"h\":34.332376335054654,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Margherita\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 11:22:38'),
(28, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":14.287175481441405,\"y\":54.619262058088594,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_ce7a24\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_ce7a24.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_4a3f9e\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_4a3f9e.webp\",\"zIndex\":23,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.00665770559320128,\"offsetY\":-0.0368376105679702,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.4,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":37.946310686113044,\"h\":34.332376335054654,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Margherita\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 11:22:41'),
(29, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":14.287175481441405,\"y\":54.619262058088594,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_ce7a24\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_ce7a24.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_4a3f9e\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_4a3f9e.webp\",\"zIndex\":23,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.00665770559320128,\"offsetY\":-0.0368376105679702,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.4,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"cheese_cheese_007121\",\"assetUrl\":\"/slicehub/uploads/global_assets/cheese_cheese_007121.webp\",\"zIndex\":33,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":37.946310686113044,\"h\":34.332376335054654,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Margherita\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 11:22:57'),
(30, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":14.287175481441405,\"y\":54.619262058088594,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_ce7a24\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_ce7a24.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_4a3f9e\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_4a3f9e.webp\",\"zIndex\":23,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.00665770559320128,\"offsetY\":-0.0368376105679702,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.4,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"cheese_cheese_007121\",\"assetUrl\":\"/slicehub/uploads/global_assets/cheese_cheese_007121.webp\",\"zIndex\":33,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":0.7,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":37.946310686113044,\"h\":34.332376335054654,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Margherita\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 11:23:06'),
(31, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":14.287175481441405,\"y\":54.619262058088594,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_ce7a24\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_ce7a24.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_4a3f9e\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_4a3f9e.webp\",\"zIndex\":23,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.00665770559320128,\"offsetY\":-0.0368376105679702,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.4,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"cheese_cheese_007121\",\"assetUrl\":\"/slicehub/uploads/global_assets/cheese_cheese_007121.webp\",\"zIndex\":33,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":0.75,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":37.946310686113044,\"h\":34.332376335054654,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Margherita\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 11:23:08'),
(32, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":14.287175481441405,\"y\":54.619262058088594,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_ce7a24\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_ce7a24.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_4a3f9e\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_4a3f9e.webp\",\"zIndex\":23,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.00665770559320128,\"offsetY\":-0.0368376105679702,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.4,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"cheese_cheese_007121\",\"assetUrl\":\"/slicehub/uploads/global_assets/cheese_cheese_007121.webp\",\"zIndex\":33,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":0.75,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"veg_veg_4071d9\",\"assetUrl\":\"/slicehub/uploads/global_assets/veg_veg_4071d9.webp\",\"zIndex\":43,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":37.946310686113044,\"h\":34.332376335054654,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Margherita\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 11:23:20'),
(33, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":14.287175481441405,\"y\":54.619262058088594,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_ce7a24\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_ce7a24.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_4a3f9e\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_4a3f9e.webp\",\"zIndex\":23,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.00665770559320128,\"offsetY\":-0.0368376105679702,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.4,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"cheese_cheese_007121\",\"assetUrl\":\"/slicehub/uploads/global_assets/cheese_cheese_007121.webp\",\"zIndex\":33,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":0.75,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"veg_veg_4071d9\",\"assetUrl\":\"/slicehub/uploads/global_assets/veg_veg_4071d9.webp\",\"zIndex\":43,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[{\"sku\":\"SAUCE_GARLIC\",\"name\":\"Sos czosnkowy\",\"assetUrl\":\"\",\"x\":59.79430574265845,\"y\":63.69833129655494,\"width\":14,\"scale\":1,\"rotation\":0,\"tilt\":0,\"visible\":true,\"locked\":false,\"labelVisible\":true}],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":37.946310686113044,\"h\":34.332376335054654,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Margherita\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 11:23:37'),
(34, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":14.287175481441405,\"y\":54.619262058088594,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_ce7a24\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_ce7a24.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_4a3f9e\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_4a3f9e.webp\",\"zIndex\":23,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.00665770559320128,\"offsetY\":-0.0368376105679702,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.4,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"cheese_cheese_007121\",\"assetUrl\":\"/slicehub/uploads/global_assets/cheese_cheese_007121.webp\",\"zIndex\":33,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":0.75,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"veg_veg_4071d9\",\"assetUrl\":\"/slicehub/uploads/global_assets/veg_veg_4071d9.webp\",\"zIndex\":43,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":37.946310686113044,\"h\":34.332376335054654,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Margherita\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 11:24:05'),
(35, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":14.287175481441405,\"y\":54.619262058088594,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_ce7a24\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_ce7a24.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_4a3f9e\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_4a3f9e.webp\",\"zIndex\":23,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.00665770559320128,\"offsetY\":-0.0368376105679702,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.4,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"cheese_cheese_007121\",\"assetUrl\":\"/slicehub/uploads/global_assets/cheese_cheese_007121.webp\",\"zIndex\":33,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":0.75,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"veg_veg_4071d9\",\"assetUrl\":\"/slicehub/uploads/global_assets/veg_veg_4071d9.webp\",\"zIndex\":43,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":37.946310686113044,\"h\":34.332376335054654,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Margherita\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 11:47:55');
INSERT INTO `sh_atelier_scene_history` (`id`, `scene_id`, `spec_json`, `snapshot_label`, `created_at`) VALUES
(36, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":14.287175481441405,\"y\":54.619262058088594,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_ce7a24\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_ce7a24.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_4a3f9e\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_4a3f9e.webp\",\"zIndex\":23,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.00665770559320128,\"offsetY\":-0.0368376105679702,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.4,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"cheese_cheese_007121\",\"assetUrl\":\"/slicehub/uploads/global_assets/cheese_cheese_007121.webp\",\"zIndex\":33,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":0.75,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"veg_veg_4071d9\",\"assetUrl\":\"/slicehub/uploads/global_assets/veg_veg_4071d9.webp\",\"zIndex\":43,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.003328778298955029,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":37.946310686113044,\"h\":34.332376335054654,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Margherita\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 11:47:59'),
(37, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":14.287175481441405,\"y\":54.619262058088594,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_ce7a24\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_ce7a24.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_4a3f9e\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_4a3f9e.webp\",\"zIndex\":23,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.00665770559320128,\"offsetY\":-0.0368376105679702,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.4,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"cheese_cheese_007121\",\"assetUrl\":\"/slicehub/uploads/global_assets/cheese_cheese_007121.webp\",\"zIndex\":33,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":0.75,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"veg_veg_4071d9\",\"assetUrl\":\"/slicehub/uploads/global_assets/veg_veg_4071d9.webp\",\"zIndex\":43,\"calScale\":1,\"calRotate\":0,\"offsetX\":0.09943137437056035,\"offsetY\":0.0646514357809841,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":37.946310686113044,\"h\":34.332376335054654,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Margherita\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 11:48:12'),
(38, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":-5,\"y\":55,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_ce7a24\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_ce7a24.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_4a3f9e\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_4a3f9e.webp\",\"zIndex\":23,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.00665770559320128,\"offsetY\":-0.0368376105679702,\"blendMode\":\"multiply\",\"alpha\":0.92,\"feather\":35,\"brightness\":1,\"saturation\":1.05,\"hueRotate\":0,\"shadowStrength\":0,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"cheese_cheese_007121\",\"assetUrl\":\"/slicehub/uploads/global_assets/cheese_cheese_007121.webp\",\"zIndex\":33,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"overlay\",\"alpha\":0.88,\"feather\":20,\"brightness\":1.02,\"saturation\":1.08,\"hueRotate\":0,\"shadowStrength\":0.1,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"veg_veg_4071d9\",\"assetUrl\":\"/slicehub/uploads/global_assets/veg_veg_4071d9.webp\",\"zIndex\":43,\"calScale\":1,\"calRotate\":0,\"offsetX\":0.09943137437056035,\"offsetY\":0.0646514357809841,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":8,\"brightness\":1,\"saturation\":1.1,\"hueRotate\":0,\"shadowStrength\":0.4,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":75,\"y\":18,\"scale\":0.75,\"rotation\":-3,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":85,\"y\":60,\"scale\":0.85,\"rotation\":2,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":24,\"y\":15,\"scale\":0.95,\"rotation\":-5,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_BEER_TYSKIE\",\"name\":\"Piwo Tyskie 0.5L\",\"assetUrl\":\"\",\"x\":69,\"y\":83,\"scale\":0.75,\"rotation\":4,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 11:48:30'),
(39, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":-5,\"y\":55,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_ce7a24\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_ce7a24.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_4a3f9e\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_4a3f9e.webp\",\"zIndex\":23,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.00665770559320128,\"offsetY\":-0.0368376105679702,\"blendMode\":\"multiply\",\"alpha\":0.92,\"feather\":35,\"brightness\":1,\"saturation\":1.05,\"hueRotate\":0,\"shadowStrength\":0,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"cheese_cheese_007121\",\"assetUrl\":\"/slicehub/uploads/global_assets/cheese_cheese_007121.webp\",\"zIndex\":33,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"overlay\",\"alpha\":0.88,\"feather\":20,\"brightness\":1.02,\"saturation\":1.08,\"hueRotate\":0,\"shadowStrength\":0.1,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"veg_veg_4071d9\",\"assetUrl\":\"/slicehub/uploads/global_assets/veg_veg_4071d9.webp\",\"zIndex\":43,\"calScale\":1,\"calRotate\":0,\"offsetX\":0.16822704468659347,\"offsetY\":-0.04973201708065565,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":8,\"brightness\":1,\"saturation\":1.1,\"hueRotate\":0,\"shadowStrength\":0.4,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":75,\"y\":18,\"scale\":0.75,\"rotation\":-3,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":85,\"y\":60,\"scale\":0.85,\"rotation\":2,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":24,\"y\":15,\"scale\":0.95,\"rotation\":-5,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_BEER_TYSKIE\",\"name\":\"Piwo Tyskie 0.5L\",\"assetUrl\":\"\",\"x\":69,\"y\":83,\"scale\":0.75,\"rotation\":4,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 11:48:35'),
(40, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":-5,\"y\":55,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_ce7a24\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_ce7a24.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_4a3f9e\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_4a3f9e.webp\",\"zIndex\":23,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.00665770559320128,\"offsetY\":-0.0368376105679702,\"blendMode\":\"multiply\",\"alpha\":0.92,\"feather\":35,\"brightness\":1,\"saturation\":1.05,\"hueRotate\":0,\"shadowStrength\":0,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"cheese_cheese_007121\",\"assetUrl\":\"/slicehub/uploads/global_assets/cheese_cheese_007121.webp\",\"zIndex\":33,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"overlay\",\"alpha\":0.88,\"feather\":20,\"brightness\":1.02,\"saturation\":1.08,\"hueRotate\":0,\"shadowStrength\":0.1,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"veg_veg_4071d9\",\"assetUrl\":\"/slicehub/uploads/global_assets/veg_veg_4071d9.webp\",\"zIndex\":43,\"calScale\":1,\"calRotate\":0,\"offsetX\":0.16822704468659347,\"offsetY\":-0.04973201708065565,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":8,\"brightness\":1,\"saturation\":1.1,\"hueRotate\":0,\"shadowStrength\":0.4,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_JUICE_ORANGE\",\"name\":\"Sok pomarańczowy\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 11:48:40'),
(41, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":-5,\"y\":55,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_ce7a24\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_ce7a24.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_4a3f9e\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_4a3f9e.webp\",\"zIndex\":23,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.00665770559320128,\"offsetY\":-0.0368376105679702,\"blendMode\":\"multiply\",\"alpha\":0.92,\"feather\":35,\"brightness\":1,\"saturation\":1.05,\"hueRotate\":0,\"shadowStrength\":0,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"cheese_cheese_007121\",\"assetUrl\":\"/slicehub/uploads/global_assets/cheese_cheese_007121.webp\",\"zIndex\":33,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"overlay\",\"alpha\":0.88,\"feather\":20,\"brightness\":1.02,\"saturation\":1.08,\"hueRotate\":0,\"shadowStrength\":0.1,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"veg_veg_4071d9\",\"assetUrl\":\"/slicehub/uploads/global_assets/veg_veg_4071d9.webp\",\"zIndex\":43,\"calScale\":1,\"calRotate\":0,\"offsetX\":0.16822704468659347,\"offsetY\":-0.04973201708065565,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":8,\"brightness\":1,\"saturation\":1.1,\"hueRotate\":0,\"shadowStrength\":0.4,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_JUICE_ORANGE\",\"name\":\"Sok pomarańczowy\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 11:48:45'),
(42, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":-5,\"y\":55,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_ce7a24\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_ce7a24.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_4a3f9e\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_4a3f9e.webp\",\"zIndex\":23,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.00665770559320128,\"offsetY\":-0.0368376105679702,\"blendMode\":\"multiply\",\"alpha\":0.92,\"feather\":35,\"brightness\":1,\"saturation\":1.05,\"hueRotate\":0,\"shadowStrength\":0,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"cheese_cheese_007121\",\"assetUrl\":\"/slicehub/uploads/global_assets/cheese_cheese_007121.webp\",\"zIndex\":33,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"overlay\",\"alpha\":0.88,\"feather\":20,\"brightness\":1.02,\"saturation\":1.08,\"hueRotate\":0,\"shadowStrength\":0.1,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"veg_veg_4071d9\",\"assetUrl\":\"/slicehub/uploads/global_assets/veg_veg_4071d9.webp\",\"zIndex\":43,\"calScale\":1,\"calRotate\":0,\"offsetX\":0.2925031976251941,\"offsetY\":-0.0298392547675261,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":8,\"brightness\":1,\"saturation\":1.1,\"hueRotate\":0,\"shadowStrength\":0.4,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_JUICE_ORANGE\",\"name\":\"Sok pomarańczowy\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 11:48:48'),
(43, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":-5,\"y\":55,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_ce7a24\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_ce7a24.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_4a3f9e\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_4a3f9e.webp\",\"zIndex\":23,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.00665770559320128,\"offsetY\":-0.0368376105679702,\"blendMode\":\"multiply\",\"alpha\":0.92,\"feather\":35,\"brightness\":1,\"saturation\":1.05,\"hueRotate\":0,\"shadowStrength\":0,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"cheese_cheese_007121\",\"assetUrl\":\"/slicehub/uploads/global_assets/cheese_cheese_007121.webp\",\"zIndex\":33,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"overlay\",\"alpha\":0.88,\"feather\":20,\"brightness\":1.02,\"saturation\":1.08,\"hueRotate\":0,\"shadowStrength\":0.1,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"veg_veg_4071d9\",\"assetUrl\":\"/slicehub/uploads/global_assets/veg_veg_4071d9.webp\",\"zIndex\":43,\"calScale\":1,\"calRotate\":0,\"offsetX\":0.2925031976251941,\"offsetY\":-0.0298392547675261,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":8,\"brightness\":1,\"saturation\":1.1,\"hueRotate\":0,\"shadowStrength\":0.4,\"isPopUp\":true,\"visible\":true,\"locked\":false}]},\"companions\":[{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_JUICE_ORANGE\",\"name\":\"Sok pomarańczowy\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 11:48:55'),
(44, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":-5,\"y\":55,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_ce7a24\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_ce7a24.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_4a3f9e\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_4a3f9e.webp\",\"zIndex\":23,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.00665770559320128,\"offsetY\":-0.0368376105679702,\"blendMode\":\"multiply\",\"alpha\":0.92,\"feather\":35,\"brightness\":1,\"saturation\":1.05,\"hueRotate\":0,\"shadowStrength\":0,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"cheese_cheese_007121\",\"assetUrl\":\"/slicehub/uploads/global_assets/cheese_cheese_007121.webp\",\"zIndex\":33,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"overlay\",\"alpha\":0.88,\"feather\":20,\"brightness\":1.02,\"saturation\":1.08,\"hueRotate\":0,\"shadowStrength\":0.1,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"veg_veg_4071d9\",\"assetUrl\":\"/slicehub/uploads/global_assets/veg_veg_4071d9.webp\",\"zIndex\":43,\"calScale\":1,\"calRotate\":0,\"offsetX\":0.2925031976251941,\"offsetY\":-0.0298392547675261,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":8,\"brightness\":1,\"saturation\":1.1,\"hueRotate\":0,\"shadowStrength\":0.4,\"isPopUp\":true,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 12:11:11'),
(45, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":-5,\"y\":55,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_ce7a24\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_ce7a24.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_4a3f9e\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_4a3f9e.webp\",\"zIndex\":23,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.00665770559320128,\"offsetY\":-0.0368376105679702,\"blendMode\":\"multiply\",\"alpha\":0.92,\"feather\":35,\"brightness\":1,\"saturation\":1.05,\"hueRotate\":0,\"shadowStrength\":0,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"cheese_cheese_007121\",\"assetUrl\":\"/slicehub/uploads/global_assets/cheese_cheese_007121.webp\",\"zIndex\":33,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"overlay\",\"alpha\":0.88,\"feather\":20,\"brightness\":1.02,\"saturation\":1.08,\"hueRotate\":0,\"shadowStrength\":0.1,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"veg_veg_4071d9\",\"assetUrl\":\"/slicehub/uploads/global_assets/veg_veg_4071d9.webp\",\"zIndex\":43,\"calScale\":1,\"calRotate\":0,\"offsetX\":0.2925031976251941,\"offsetY\":-0.0298392547675261,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":8,\"brightness\":1,\"saturation\":1.1,\"hueRotate\":0,\"shadowStrength\":0.4,\"isPopUp\":true,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Save 14:11:32', '2026-04-17 12:11:32'),
(46, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":-5,\"y\":55,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_ce7a24\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_ce7a24.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_4a3f9e\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_4a3f9e.webp\",\"zIndex\":23,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.00665770559320128,\"offsetY\":-0.0368376105679702,\"blendMode\":\"multiply\",\"alpha\":0.92,\"feather\":35,\"brightness\":1,\"saturation\":1.05,\"hueRotate\":0,\"shadowStrength\":0,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"cheese_cheese_007121\",\"assetUrl\":\"/slicehub/uploads/global_assets/cheese_cheese_007121.webp\",\"zIndex\":33,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"overlay\",\"alpha\":0.88,\"feather\":20,\"brightness\":1.02,\"saturation\":1.08,\"hueRotate\":0,\"shadowStrength\":0.1,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"veg_veg_4071d9\",\"assetUrl\":\"/slicehub/uploads/global_assets/veg_veg_4071d9.webp\",\"zIndex\":43,\"calScale\":1,\"calRotate\":0,\"offsetX\":0.2925031976251941,\"offsetY\":-0.0298392547675261,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":8,\"brightness\":1,\"saturation\":1.1,\"hueRotate\":0,\"shadowStrength\":0.4,\"isPopUp\":true,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 15:11:59'),
(47, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":23.627903108064114,\"y\":56.160407888745794,\"scale\":0.9136207801365358,\"rotation\":-4,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 15:12:17'),
(48, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":23.738867351205656,\"y\":55.99463533321068,\"scale\":0.9136207801365358,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"board_plate_4212b3\",\"assetUrl\":\"/slicehub/uploads/global_assets/board_plate_4212b3.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 15:12:24'),
(49, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":23.738867351205656,\"y\":55.99463533321068,\"scale\":0.9136207801365358,\"rotation\":-4,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 15:12:29'),
(50, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":23.738867351205656,\"y\":55.99463533321068,\"scale\":0.9136207801365358,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_df4ba6\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_df4ba6.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 15:12:40'),
(51, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":23.738867351205656,\"y\":55.99463533321068,\"scale\":0.9136207801365358,\"rotation\":-4,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 15:12:44'),
(52, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":23.738867351205656,\"y\":55.99463533321068,\"scale\":0.9136207801365358,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_2244b4\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_2244b4.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 15:12:49'),
(53, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":23.738867351205656,\"y\":55.99463533321068,\"scale\":0.9136207801365358,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_2244b4\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_2244b4.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_3ebc9a\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_3ebc9a.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 15:12:58'),
(54, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":23.738867351205656,\"y\":55.99463533321068,\"scale\":0.9136207801365358,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_2244b4\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_2244b4.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_3ebc9a\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_3ebc9a.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":8,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 15:13:03'),
(55, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":23.738867351205656,\"y\":55.99463533321068,\"scale\":0.9136207801365358,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_2244b4\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_2244b4.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_3ebc9a\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_3ebc9a.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":1,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 15:13:06'),
(56, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":23.738867351205656,\"y\":55.99463533321068,\"scale\":0.9136207801365358,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_2244b4\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_2244b4.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_3ebc9a\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_3ebc9a.webp\",\"zIndex\":77,\"calScale\":1,\"calRotate\":1,\"offsetX\":0,\"offsetY\":-0.02,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 15:13:29'),
(57, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":23.738867351205656,\"y\":55.99463533321068,\"scale\":0.9136207801365358,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_2244b4\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_2244b4.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_3ebc9a\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_3ebc9a.webp\",\"zIndex\":77,\"calScale\":1,\"calRotate\":1,\"offsetX\":0,\"offsetY\":-0.02,\"blendMode\":\"multiply\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 15:13:32'),
(58, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":23.738867351205656,\"y\":55.99463533321068,\"scale\":0.9136207801365358,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_2244b4\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_2244b4.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_3ebc9a\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_3ebc9a.webp\",\"zIndex\":77,\"calScale\":1,\"calRotate\":1,\"offsetX\":0,\"offsetY\":-0.02,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 15:13:57'),
(59, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":23.738867351205656,\"y\":55.99463533321068,\"scale\":0.9136207801365358,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_2244b4\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_2244b4.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_3ebc9a\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_3ebc9a.webp\",\"zIndex\":77,\"calScale\":1,\"calRotate\":1,\"offsetX\":0,\"offsetY\":-0.02,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.7,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 15:14:08'),
(60, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":23.738867351205656,\"y\":55.99463533321068,\"scale\":0.9136207801365358,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_2244b4\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_2244b4.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_3ebc9a\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_3ebc9a.webp\",\"zIndex\":77,\"calScale\":1,\"calRotate\":1,\"offsetX\":0,\"offsetY\":-0.02,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.35,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 15:14:15');
INSERT INTO `sh_atelier_scene_history` (`id`, `scene_id`, `spec_json`, `snapshot_label`, `created_at`) VALUES
(61, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":23.738867351205656,\"y\":55.99463533321068,\"scale\":0.9136207801365358,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_2244b4\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_2244b4.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_3ebc9a\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_3ebc9a.webp\",\"zIndex\":77,\"calScale\":1,\"calRotate\":1,\"offsetX\":0,\"offsetY\":-0.02,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.4,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 15:14:20'),
(62, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":23.738867351205656,\"y\":55.99463533321068,\"scale\":0.9136207801365358,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_2244b4\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_2244b4.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 15:14:30'),
(63, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":23.738867351205656,\"y\":55.99463533321068,\"scale\":0.9136207801365358,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_2244b4\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_2244b4.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_92673a\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_92673a.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":0.005548063161785867,\"offsetY\":-0.01989276231312955,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 15:14:41'),
(64, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":23.738867351205656,\"y\":55.99463533321068,\"scale\":0.9136207801365358,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_2244b4\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_2244b4.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_92673a\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_92673a.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":0.005548063161785867,\"offsetY\":-0.01989276231312955,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"meat_meat_d74071\",\"assetUrl\":\"/slicehub/uploads/global_assets/meat_meat_d74071.webp\",\"zIndex\":30,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 15:14:48'),
(65, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":23.738867351205656,\"y\":55.99463533321068,\"scale\":0.9136207801365358,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_2244b4\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_2244b4.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_92673a\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_92673a.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":0.005548063161785867,\"offsetY\":-0.01989276231312955,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"meat_meat_d74071\",\"assetUrl\":\"/slicehub/uploads/global_assets/meat_meat_d74071.webp\",\"zIndex\":30,\"calScale\":0.85,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 15:14:53'),
(66, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":23.738867351205656,\"y\":55.99463533321068,\"scale\":0.9136207801365358,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_2244b4\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_2244b4.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_92673a\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_92673a.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":0.005548063161785867,\"offsetY\":-0.01989276231312955,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"meat_meat_d74071\",\"assetUrl\":\"/slicehub/uploads/global_assets/meat_meat_d74071.webp\",\"zIndex\":30,\"calScale\":0.85,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"veg_veg_31d16a\",\"assetUrl\":\"/slicehub/uploads/global_assets/veg_veg_31d16a.webp\",\"zIndex\":40,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 15:15:05'),
(67, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":23.738867351205656,\"y\":55.99463533321068,\"scale\":0.9136207801365358,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_2244b4\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_2244b4.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_92673a\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_92673a.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":0.005548063161785867,\"offsetY\":-0.01989276231312955,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"meat_meat_d74071\",\"assetUrl\":\"/slicehub/uploads/global_assets/meat_meat_d74071.webp\",\"zIndex\":30,\"calScale\":0.85,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"veg_veg_31d16a\",\"assetUrl\":\"/slicehub/uploads/global_assets/veg_veg_31d16a.webp\",\"zIndex\":40,\"calScale\":0.75,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 15:15:08'),
(68, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":23.738867351205656,\"y\":55.99463533321068,\"scale\":0.9136207801365358,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_2244b4\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_2244b4.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_92673a\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_92673a.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":0.005548063161785867,\"offsetY\":-0.01989276231312955,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"meat_meat_d74071\",\"assetUrl\":\"/slicehub/uploads/global_assets/meat_meat_d74071.webp\",\"zIndex\":30,\"calScale\":0.75,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"veg_veg_31d16a\",\"assetUrl\":\"/slicehub/uploads/global_assets/veg_veg_31d16a.webp\",\"zIndex\":40,\"calScale\":0.75,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 15:15:15'),
(69, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":23.738867351205656,\"y\":55.99463533321068,\"scale\":0.9136207801365358,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_2244b4\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_2244b4.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_92673a\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_92673a.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":0.005548063161785867,\"offsetY\":-0.01989276231312955,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"meat_meat_d74071\",\"assetUrl\":\"/slicehub/uploads/global_assets/meat_meat_d74071.webp\",\"zIndex\":30,\"calScale\":0.75,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"veg_veg_31d16a\",\"assetUrl\":\"/slicehub/uploads/global_assets/veg_veg_31d16a.webp\",\"zIndex\":40,\"calScale\":0.75,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"cheese_cheese_45dea6\",\"assetUrl\":\"/slicehub/uploads/global_assets/cheese_cheese_45dea6.webp\",\"zIndex\":50,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 15:15:31'),
(70, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":23.738867351205656,\"y\":55.99463533321068,\"scale\":0.9136207801365358,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_2244b4\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_2244b4.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_92673a\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_92673a.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":0.005548063161785867,\"offsetY\":-0.01989276231312955,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"meat_meat_d74071\",\"assetUrl\":\"/slicehub/uploads/global_assets/meat_meat_d74071.webp\",\"zIndex\":30,\"calScale\":0.75,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"veg_veg_31d16a\",\"assetUrl\":\"/slicehub/uploads/global_assets/veg_veg_31d16a.webp\",\"zIndex\":40,\"calScale\":0.75,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"cheese_cheese_45dea6\",\"assetUrl\":\"/slicehub/uploads/global_assets/cheese_cheese_45dea6.webp\",\"zIndex\":50,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":0.7,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 15:15:38'),
(71, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":23.738867351205656,\"y\":55.99463533321068,\"scale\":0.9136207801365358,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_2244b4\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_2244b4.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_92673a\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_92673a.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":0.005548063161785867,\"offsetY\":-0.01989276231312955,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"meat_meat_d74071\",\"assetUrl\":\"/slicehub/uploads/global_assets/meat_meat_d74071.webp\",\"zIndex\":30,\"calScale\":0.75,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"veg_veg_31d16a\",\"assetUrl\":\"/slicehub/uploads/global_assets/veg_veg_31d16a.webp\",\"zIndex\":40,\"calScale\":0.75,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 15:15:45'),
(72, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":23.738867351205656,\"y\":55.99463533321068,\"scale\":0.9136207801365358,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_2244b4\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_2244b4.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_92673a\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_92673a.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":0.005548063161785867,\"offsetY\":-0.01989276231312955,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"meat_meat_d74071\",\"assetUrl\":\"/slicehub/uploads/global_assets/meat_meat_d74071.webp\",\"zIndex\":30,\"calScale\":0.75,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"veg_veg_31d16a\",\"assetUrl\":\"/slicehub/uploads/global_assets/veg_veg_31d16a.webp\",\"zIndex\":40,\"calScale\":0.75,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 15:15:53'),
(73, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":23.738867351205656,\"y\":55.99463533321068,\"scale\":0.9136207801365358,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_2244b4\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_2244b4.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_92673a\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_92673a.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":0.005548063161785867,\"offsetY\":-0.01989276231312955,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"meat_meat_d74071\",\"assetUrl\":\"/slicehub/uploads/global_assets/meat_meat_d74071.webp\",\"zIndex\":30,\"calScale\":0.75,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"veg_veg_31d16a\",\"assetUrl\":\"/slicehub/uploads/global_assets/veg_veg_31d16a.webp\",\"zIndex\":40,\"calScale\":0.75,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 15:15:59'),
(74, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":23.738867351205656,\"y\":55.99463533321068,\"scale\":0.9136207801365358,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_2244b4\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_2244b4.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_92673a\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_92673a.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":0.005548063161785867,\"offsetY\":-0.01989276231312955,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"meat_meat_d74071\",\"assetUrl\":\"/slicehub/uploads/global_assets/meat_meat_d74071.webp\",\"zIndex\":30,\"calScale\":0.75,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"veg_veg_31d16a\",\"assetUrl\":\"/slicehub/uploads/global_assets/veg_veg_31d16a.webp\",\"zIndex\":40,\"calScale\":0.75,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"cheese_cheese_82f9f8\",\"assetUrl\":\"/slicehub/uploads/global_assets/cheese_cheese_82f9f8.webp\",\"zIndex\":50,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 15:16:03'),
(75, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":23.738867351205656,\"y\":55.99463533321068,\"scale\":0.9136207801365358,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_2244b4\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_2244b4.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_92673a\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_92673a.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":0.005548063161785867,\"offsetY\":-0.01989276231312955,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"meat_meat_d74071\",\"assetUrl\":\"/slicehub/uploads/global_assets/meat_meat_d74071.webp\",\"zIndex\":30,\"calScale\":0.75,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"veg_veg_31d16a\",\"assetUrl\":\"/slicehub/uploads/global_assets/veg_veg_31d16a.webp\",\"zIndex\":40,\"calScale\":0.75,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"cheese_cheese_82f9f8\",\"assetUrl\":\"/slicehub/uploads/global_assets/cheese_cheese_82f9f8.webp\",\"zIndex\":50,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.9,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 15:16:09'),
(76, 5, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":25,\"y\":55,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":42,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Sos czosnkowy\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 15:17:06'),
(77, 5, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":25,\"y\":55,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":42,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Sos czosnkowy\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 15:17:10'),
(78, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":23.738867351205656,\"y\":55.99463533321068,\"scale\":0.9136207801365358,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_2244b4\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_2244b4.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_92673a\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_92673a.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":0.005548063161785867,\"offsetY\":-0.01989276231312955,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"meat_meat_d74071\",\"assetUrl\":\"/slicehub/uploads/global_assets/meat_meat_d74071.webp\",\"zIndex\":30,\"calScale\":0.75,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"veg_veg_31d16a\",\"assetUrl\":\"/slicehub/uploads/global_assets/veg_veg_31d16a.webp\",\"zIndex\":40,\"calScale\":0.75,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"cheese_cheese_82f9f8\",\"assetUrl\":\"/slicehub/uploads/global_assets/cheese_cheese_82f9f8.webp\",\"zIndex\":21,\"calScale\":1.9,\"calRotate\":-228,\"offsetX\":-0.27,\"offsetY\":-0.07,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":45,\"brightness\":1.14,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.9,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-17 16:06:40'),
(79, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":23.738867351205656,\"y\":55.99463533321068,\"scale\":0.9136207801365358,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_2244b4\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_2244b4.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_92673a\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_92673a.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":0.005548063161785867,\"offsetY\":-0.01989276231312955,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"meat_meat_d74071\",\"assetUrl\":\"/slicehub/uploads/global_assets/meat_meat_d74071.webp\",\"zIndex\":30,\"calScale\":0.75,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"veg_veg_31d16a\",\"assetUrl\":\"/slicehub/uploads/global_assets/veg_veg_31d16a.webp\",\"zIndex\":40,\"calScale\":0.75,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"cheese_cheese_82f9f8\",\"assetUrl\":\"/slicehub/uploads/global_assets/cheese_cheese_82f9f8.webp\",\"zIndex\":21,\"calScale\":1.9,\"calRotate\":-228,\"offsetX\":-0.27,\"offsetY\":-0.07,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":45,\"brightness\":1.14,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.9,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-18 02:40:11'),
(80, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":23.738867351205656,\"y\":55.99463533321068,\"scale\":0.9136207801365358,\"rotation\":-4,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-18 02:40:16'),
(81, 6, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":25,\"y\":55,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":42,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Capricciosa\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-18 02:40:34'),
(82, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":23.738867351205656,\"y\":55.99463533321068,\"scale\":0.9136207801365358,\"rotation\":-4,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-18 02:41:15'),
(83, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":-5,\"y\":55,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[]},\"companions\":[{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":75,\"y\":18,\"scale\":0.75,\"rotation\":-3,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":85,\"y\":60,\"scale\":0.85,\"rotation\":2,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":24,\"y\":15,\"scale\":0.95,\"rotation\":-5,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_BEER_TYSKIE\",\"name\":\"Piwo Tyskie 0.5L\",\"assetUrl\":\"\",\"x\":69,\"y\":83,\"scale\":0.75,\"rotation\":4,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-18 02:43:01'),
(84, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":-5,\"y\":55,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[]},\"companions\":[{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":75,\"y\":18,\"scale\":0.75,\"rotation\":-3,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":85,\"y\":60,\"scale\":0.85,\"rotation\":2,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":24,\"y\":15,\"scale\":0.95,\"rotation\":-5,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_JUICE_ORANGE\",\"name\":\"Sok pomarańczowy\",\"assetUrl\":\"\",\"x\":69,\"y\":83,\"scale\":0.75,\"rotation\":4,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-18 02:43:06'),
(85, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":-5,\"y\":55,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[]},\"companions\":[{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_BEER_TYSKIE\",\"name\":\"Piwo Tyskie 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-18 02:43:12'),
(86, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":-5,\"y\":55,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[]},\"companions\":[{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_BEER_TYSKIE\",\"name\":\"Piwo Tyskie 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-18 02:43:16');
INSERT INTO `sh_atelier_scene_history` (`id`, `scene_id`, `spec_json`, `snapshot_label`, `created_at`) VALUES
(87, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":-5,\"y\":55,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[]},\"companions\":[{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":75,\"y\":18,\"scale\":0.75,\"rotation\":-3,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":85,\"y\":60,\"scale\":0.85,\"rotation\":2,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":24,\"y\":15,\"scale\":0.95,\"rotation\":-5,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_BEER_TYSKIE\",\"name\":\"Piwo Tyskie 0.5L\",\"assetUrl\":\"\",\"x\":69,\"y\":83,\"scale\":0.75,\"rotation\":4,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-18 02:44:03'),
(88, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":-5,\"y\":55,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[]},\"companions\":[{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":75,\"y\":18,\"scale\":0.75,\"rotation\":-3,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":85,\"y\":60,\"scale\":0.85,\"rotation\":2,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":24,\"y\":15,\"scale\":0.95,\"rotation\":-5,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_BEER_TYSKIE\",\"name\":\"Piwo Tyskie 0.5L\",\"assetUrl\":\"\",\"x\":69,\"y\":83,\"scale\":0.75,\"rotation\":4,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-18 02:44:09'),
(89, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":-5,\"y\":55,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[]},\"companions\":[{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":75,\"y\":18,\"scale\":0.75,\"rotation\":-3,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":85,\"y\":60,\"scale\":0.85,\"rotation\":2,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":24,\"y\":15,\"scale\":0.95,\"rotation\":-5,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_BEER_TYSKIE\",\"name\":\"Piwo Tyskie 0.5L\",\"assetUrl\":\"\",\"x\":69,\"y\":83,\"scale\":0.75,\"rotation\":4,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Save 04:44:12', '2026-04-18 02:44:12'),
(90, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":-5,\"y\":55,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[]},\"companions\":[{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":75,\"y\":18,\"scale\":0.75,\"rotation\":-3,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":85,\"y\":60,\"scale\":0.85,\"rotation\":2,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":24,\"y\":15,\"scale\":0.95,\"rotation\":-5,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_BEER_TYSKIE\",\"name\":\"Piwo Tyskie 0.5L\",\"assetUrl\":\"\",\"x\":69,\"y\":83,\"scale\":0.75,\"rotation\":4,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-18 08:29:29'),
(91, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":-5,\"y\":55,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[]},\"companions\":[{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_BEER_TYSKIE\",\"name\":\"Piwo Tyskie 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-18 08:32:03'),
(92, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":-5,\"y\":55,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"meat_meat_a7aa4e\",\"assetUrl\":\"/slicehub/uploads/global_assets/meat_meat_a7aa4e.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.029959451676468962,\"offsetY\":-0.01657731120242726,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_BEER_TYSKIE\",\"name\":\"Piwo Tyskie 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-18 08:32:27'),
(93, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":-5,\"y\":55,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"meat_meat_a7aa4e\",\"assetUrl\":\"/slicehub/uploads/global_assets/meat_meat_a7aa4e.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.04,\"offsetY\":-0.01657731120242726,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_BEER_TYSKIE\",\"name\":\"Piwo Tyskie 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-18 08:32:33'),
(94, 2, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":-5,\"y\":55,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"meat_meat_a7aa4e\",\"assetUrl\":\"/slicehub/uploads/global_assets/meat_meat_a7aa4e.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.04,\"offsetY\":-0.01657731120242726,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_BEER_TYSKIE\",\"name\":\"Piwo Tyskie 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-18 14:55:05'),
(95, 6, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":25,\"y\":55,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":42,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Capricciosa\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-18 14:55:10'),
(96, 7, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":25,\"y\":55,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":42,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Hawajska\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-19 00:05:31'),
(97, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":22.851175755367024,\"y\":52.84494843070613,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[]},\"companions\":[{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_BEER_TYSKIE\",\"name\":\"Piwo Tyskie 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-19 00:06:04'),
(98, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":22.851175755367024,\"y\":52.84494843070613,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_fdfba2\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_fdfba2.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_BEER_TYSKIE\",\"name\":\"Piwo Tyskie 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-19 00:06:13'),
(99, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":22.851175755367024,\"y\":52.84494843070613,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_fdfba2\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_fdfba2.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_3ebc9a\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_3ebc9a.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_BEER_TYSKIE\",\"name\":\"Piwo Tyskie 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-19 00:06:28'),
(100, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":22.851175755367024,\"y\":52.84494843070613,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_fdfba2\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_fdfba2.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_3ebc9a\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_3ebc9a.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"cheese_cheese_bec540\",\"assetUrl\":\"/slicehub/uploads/global_assets/cheese_cheese_bec540.webp\",\"zIndex\":30,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_BEER_TYSKIE\",\"name\":\"Piwo Tyskie 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-19 00:06:40'),
(101, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":22.851175755367024,\"y\":52.84494843070613,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_fdfba2\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_fdfba2.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_3ebc9a\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_3ebc9a.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_BEER_TYSKIE\",\"name\":\"Piwo Tyskie 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-19 00:06:43'),
(102, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":22.851175755367024,\"y\":52.84494843070613,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_fdfba2\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_fdfba2.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_3ebc9a\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_3ebc9a.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_BEER_TYSKIE\",\"name\":\"Piwo Tyskie 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-19 00:06:51'),
(103, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":22.851175755367024,\"y\":52.84494843070613,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_fdfba2\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_fdfba2.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_3ebc9a\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_3ebc9a.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"cheese_cheese_82f9f8\",\"assetUrl\":\"/slicehub/uploads/global_assets/cheese_cheese_82f9f8.webp\",\"zIndex\":30,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_BEER_TYSKIE\",\"name\":\"Piwo Tyskie 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-19 00:06:56'),
(104, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":22.851175755367024,\"y\":52.84494843070613,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_fdfba2\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_fdfba2.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_3ebc9a\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_3ebc9a.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"cheese_cheese_82f9f8\",\"assetUrl\":\"/slicehub/uploads/global_assets/cheese_cheese_82f9f8.webp\",\"zIndex\":30,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":62,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_BEER_TYSKIE\",\"name\":\"Piwo Tyskie 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-19 00:07:14'),
(105, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":22.851175755367024,\"y\":52.84494843070613,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_fdfba2\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_fdfba2.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_3ebc9a\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_3ebc9a.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"cheese_cheese_82f9f8\",\"assetUrl\":\"/slicehub/uploads/global_assets/cheese_cheese_82f9f8.webp\",\"zIndex\":30,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":62,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_BEER_TYSKIE\",\"name\":\"Piwo Tyskie 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Save 02:07:19', '2026-04-19 00:07:19'),
(106, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":22.851175755367024,\"y\":52.84494843070613,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_fdfba2\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_fdfba2.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_3ebc9a\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_3ebc9a.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"cheese_cheese_82f9f8\",\"assetUrl\":\"/slicehub/uploads/global_assets/cheese_cheese_82f9f8.webp\",\"zIndex\":30,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":62,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_BEER_TYSKIE\",\"name\":\"Piwo Tyskie 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-19 00:34:29'),
(107, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":22.851175755367024,\"y\":52.84494843070613,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_fdfba2\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_fdfba2.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_3ebc9a\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_3ebc9a.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"cheese_cheese_82f9f8\",\"assetUrl\":\"/slicehub/uploads/global_assets/cheese_cheese_82f9f8.webp\",\"zIndex\":30,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":62,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_BEER_TYSKIE\",\"name\":\"Piwo Tyskie 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-19 00:36:26'),
(108, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":6},\"pizza\":{\"x\":35,\"y\":45,\"scale\":2,\"rotation\":-6,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_fdfba2\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_fdfba2.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_3ebc9a\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_3ebc9a.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"cheese_cheese_82f9f8\",\"assetUrl\":\"/slicehub/uploads/global_assets/cheese_cheese_82f9f8.webp\",\"zIndex\":30,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":62,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_BEER_TYSKIE\",\"name\":\"Piwo Tyskie 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":65,\"y\":68,\"w\":32,\"h\":28,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-19 00:36:43'),
(109, 1, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":6},\"pizza\":{\"x\":35,\"y\":45,\"scale\":2,\"rotation\":-6,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_fdfba2\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_fdfba2.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"sauce_sauce_3ebc9a\",\"assetUrl\":\"/slicehub/uploads/global_assets/sauce_sauce_3ebc9a.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"cheese_cheese_82f9f8\",\"assetUrl\":\"/slicehub/uploads/global_assets/cheese_cheese_82f9f8.webp\",\"zIndex\":30,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":62,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_BEER_TYSKIE\",\"name\":\"Piwo Tyskie 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":65,\"y\":68,\"w\":32,\"h\":28,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Save 02:36:43', '2026-04-19 00:36:43'),
(110, 1, '{\"pizza\":{\"layers\":[{\"layerSku\":\"BASE_hero_pizza_base_gluten_new_removebg_preview_dc1adb5e\",\"assetUrl\":\"/slicehub/uploads/assets/1/hero/hero_pizza_base_gluten_new_removebg_preview_dc1adb5e.png\",\"zIndex\":0,\"isBase\":true,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"visible\":true,\"source\":\"auto_hero\"}]},\"meta\":{\"generatedAt\":\"2026-04-19T03:37:22+00:00\",\"generatedBy\":\"menu_studio_autogen\",\"sourceLayerCount\":1,\"hasHero\":true,\"modifierCount\":0}}', 'autogen_20260419_033722', '2026-04-19 03:37:22');
INSERT INTO `sh_atelier_scene_history` (`id`, `scene_id`, `spec_json`, `snapshot_label`, `created_at`) VALUES
(111, 8, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":25,\"y\":55,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":42,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Woda mineralna 0.5L\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-19 03:39:17'),
(112, 9, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":25,\"y\":55,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":42,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Pepsi 0.5L\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-19 03:39:30'),
(113, 1, '{\"pizza\":{\"layers\":[{\"layerSku\":\"BASE_hero_pizza_base_gluten_new_removebg_preview_dc1adb5e\",\"assetUrl\":\"/slicehub/uploads/assets/1/hero/hero_pizza_base_gluten_new_removebg_preview_dc1adb5e.png\",\"zIndex\":0,\"isBase\":true,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"visible\":true,\"source\":\"auto_hero\"}]},\"meta\":{\"generatedAt\":\"2026-04-19T03:37:22+00:00\",\"generatedBy\":\"menu_studio_autogen\",\"sourceLayerCount\":1,\"hasHero\":true,\"modifierCount\":0}}', 'Auto-save', '2026-04-19 03:39:42'),
(114, 2, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":-5,\"y\":55,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"meat_meat_a7aa4e\",\"assetUrl\":\"/slicehub/uploads/global_assets/meat_meat_a7aa4e.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.48303059895833333,\"offsetY\":0.12930298285980005,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_BEER_TYSKIE\",\"name\":\"Piwo Tyskie 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-19 03:39:50'),
(115, 10, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":-5,\"y\":55,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[]},\"companions\":[{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":75,\"y\":18,\"scale\":0.75,\"rotation\":-3,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":85,\"y\":60,\"scale\":0.85,\"rotation\":2,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":24,\"y\":15,\"scale\":0.95,\"rotation\":-5,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_JUICE_ORANGE\",\"name\":\"Sok pomarańczowy\",\"assetUrl\":\"\",\"x\":69,\"y\":83,\"scale\":0.75,\"rotation\":4,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-19 03:42:34'),
(116, 10, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":22.074448402669905,\"y\":55.828865560121365,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[]},\"companions\":[{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":75,\"y\":18,\"scale\":0.75,\"rotation\":-3,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":85,\"y\":60,\"scale\":0.85,\"rotation\":2,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":24,\"y\":15,\"scale\":0.95,\"rotation\":-5,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_JUICE_ORANGE\",\"name\":\"Sok pomarańczowy\",\"assetUrl\":\"\",\"x\":69,\"y\":83,\"scale\":0.75,\"rotation\":4,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-19 03:45:58'),
(117, 11, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":25,\"y\":55,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":42,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Diavola\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-19 03:46:01'),
(118, 2, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":23.627903108064114,\"y\":57.15505435173964,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[]},\"companions\":[{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_BEER_TYSKIE\",\"name\":\"Piwo Tyskie 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-19 03:46:15'),
(119, 2, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":23.627903108064114,\"y\":57.15505435173964,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_a5aa21\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_a5aa21.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_BEER_TYSKIE\",\"name\":\"Piwo Tyskie 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-19 03:46:24'),
(120, 2, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":23.627903108064114,\"y\":57.15505435173964,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_a5aa21\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_a5aa21.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"meat_meat_a7aa4e\",\"assetUrl\":\"/slicehub/uploads/global_assets/meat_meat_a7aa4e.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_BEER_TYSKIE\",\"name\":\"Piwo Tyskie 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-19 03:46:32'),
(121, 2, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":25,\"grainIntensity\":3,\"vignetteIntensity\":22,\"lutName\":\"ghibli\",\"letterbox\":3},\"pizza\":{\"x\":23.627903108064114,\"y\":57.15505435173964,\"scale\":1.25,\"rotation\":-4,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_a5aa21\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_a5aa21.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"meat_meat_a7aa4e\",\"assetUrl\":\"/slicehub/uploads/global_assets/meat_meat_a7aa4e.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.016644114987711997,\"offsetY\":-0.018235036757778406,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0.45,\"isPopUp\":false,\"visible\":true,\"locked\":false}]},\"companions\":[{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_BEER_TYSKIE\",\"name\":\"Piwo Tyskie 0.5L\",\"assetUrl\":\"\",\"x\":50,\"y\":50,\"scale\":0.85,\"rotation\":0,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":52,\"y\":8,\"w\":44,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":8,\"seed\":\"vege\"},\"steam\":{\"count\":2,\"intensity\":30},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-19 03:46:38'),
(122, 2, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":35,\"lightY\":15,\"grainIntensity\":8,\"vignetteIntensity\":40,\"lutName\":\"darkvinyl\",\"letterbox\":3},\"pizza\":{\"x\":70,\"y\":60,\"scale\":1.4,\"rotation\":3,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_a5aa21\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_a5aa21.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"meat_meat_a7aa4e\",\"assetUrl\":\"/slicehub/uploads/global_assets/meat_meat_a7aa4e.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.016644114987711997,\"offsetY\":-0.018235036757778406,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":5,\"brightness\":0.98,\"saturation\":1.05,\"hueRotate\":0,\"shadowStrength\":0.55,\"isPopUp\":true,\"visible\":true,\"locked\":false}]},\"companions\":[{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":75,\"y\":18,\"scale\":0.75,\"rotation\":-3,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":85,\"y\":60,\"scale\":0.85,\"rotation\":2,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":24,\"y\":15,\"scale\":0.95,\"rotation\":-5,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_JUICE_ORANGE\",\"name\":\"Sok pomarańczowy\",\"assetUrl\":\"\",\"x\":69,\"y\":83,\"scale\":0.75,\"rotation\":4,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":3,\"y\":8,\"w\":40,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":18,\"seed\":\"meat\"},\"steam\":{\"count\":4,\"intensity\":55},\"oilSheen\":{\"enabled\":true,\"x\":40,\"y\":40}}}', 'Auto-save', '2026-04-19 03:47:07'),
(123, 2, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":35,\"lightY\":15,\"grainIntensity\":8,\"vignetteIntensity\":40,\"lutName\":\"darkvinyl\",\"letterbox\":3},\"pizza\":{\"x\":70,\"y\":60,\"scale\":1.4,\"rotation\":3,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_a5aa21\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_a5aa21.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"meat_meat_a7aa4e\",\"assetUrl\":\"/slicehub/uploads/global_assets/meat_meat_a7aa4e.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.016644114987711997,\"offsetY\":-0.018235036757778406,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":5,\"brightness\":0.98,\"saturation\":1.05,\"hueRotate\":0,\"shadowStrength\":0.55,\"isPopUp\":true,\"visible\":true,\"locked\":false}]},\"companions\":[{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":75,\"y\":18,\"scale\":0.75,\"rotation\":-3,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":19.86598581281736,\"y\":64.31010313858773,\"scale\":0.85,\"rotation\":2,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":24,\"y\":15,\"scale\":0.95,\"rotation\":-5,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_JUICE_ORANGE\",\"name\":\"Sok pomarańczowy\",\"assetUrl\":\"\",\"x\":69,\"y\":83,\"scale\":0.75,\"rotation\":4,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":3,\"y\":8,\"w\":40,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":18,\"seed\":\"meat\"},\"steam\":{\"count\":4,\"intensity\":55},\"oilSheen\":{\"enabled\":true,\"x\":40,\"y\":40}}}', 'Save 05:47:12', '2026-04-19 03:47:12'),
(124, 2, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":35,\"lightY\":15,\"grainIntensity\":8,\"vignetteIntensity\":40,\"lutName\":\"darkvinyl\",\"letterbox\":3},\"pizza\":{\"x\":70,\"y\":60,\"scale\":1.4,\"rotation\":3,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_a5aa21\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_a5aa21.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"meat_meat_a7aa4e\",\"assetUrl\":\"/slicehub/uploads/global_assets/meat_meat_a7aa4e.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.016644114987711997,\"offsetY\":-0.018235036757778406,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":5,\"brightness\":0.98,\"saturation\":1.05,\"hueRotate\":0,\"shadowStrength\":0.55,\"isPopUp\":true,\"visible\":true,\"locked\":false}]},\"companions\":[{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":75,\"y\":18,\"scale\":0.75,\"rotation\":-3,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":19.86598581281736,\"y\":64.31010313858773,\"scale\":0.85,\"rotation\":2,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":24,\"y\":15,\"scale\":0.95,\"rotation\":-5,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_JUICE_ORANGE\",\"name\":\"Sok pomarańczowy\",\"assetUrl\":\"\",\"x\":69,\"y\":83,\"scale\":0.75,\"rotation\":4,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":3,\"y\":8,\"w\":40,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":18,\"seed\":\"meat\"},\"steam\":{\"count\":4,\"intensity\":55},\"oilSheen\":{\"enabled\":true,\"x\":40,\"y\":40}}}', 'Auto-save', '2026-04-19 03:47:13'),
(125, 6, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":25,\"y\":55,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":42,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Capricciosa\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-19 08:20:48'),
(126, 12, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":25,\"y\":55,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":42,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Woda mineralna 0.5L\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-19 08:20:57'),
(127, 3, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":25,\"y\":55,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":42,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Sos czosnkowy\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-19 08:38:58'),
(128, 2, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":35,\"lightY\":15,\"grainIntensity\":8,\"vignetteIntensity\":40,\"lutName\":\"darkvinyl\",\"letterbox\":3},\"pizza\":{\"x\":70,\"y\":60,\"scale\":1.4,\"rotation\":3,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_a5aa21\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_a5aa21.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"meat_meat_a7aa4e\",\"assetUrl\":\"/slicehub/uploads/global_assets/meat_meat_a7aa4e.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.016644114987711997,\"offsetY\":-0.018235036757778406,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":5,\"brightness\":0.98,\"saturation\":1.05,\"hueRotate\":0,\"shadowStrength\":0.55,\"isPopUp\":true,\"visible\":true,\"locked\":false}]},\"companions\":[{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":75,\"y\":18,\"scale\":0.75,\"rotation\":-3,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":19.86598581281736,\"y\":64.31010313858773,\"scale\":0.85,\"rotation\":2,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":24,\"y\":15,\"scale\":0.95,\"rotation\":-5,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_JUICE_ORANGE\",\"name\":\"Sok pomarańczowy\",\"assetUrl\":\"\",\"x\":69,\"y\":83,\"scale\":0.75,\"rotation\":4,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":3,\"y\":8,\"w\":40,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":18,\"seed\":\"meat\"},\"steam\":{\"count\":4,\"intensity\":55},\"oilSheen\":{\"enabled\":true,\"x\":40,\"y\":40}}}', 'Auto-save', '2026-04-19 08:39:04'),
(129, 2, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":35,\"lightY\":15,\"grainIntensity\":8,\"vignetteIntensity\":40,\"lutName\":\"darkvinyl\",\"letterbox\":3},\"pizza\":{\"x\":70,\"y\":60,\"scale\":1.4,\"rotation\":3,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_a5aa21\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_a5aa21.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"meat_meat_a7aa4e\",\"assetUrl\":\"/slicehub/uploads/global_assets/meat_meat_a7aa4e.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.016644114987711997,\"offsetY\":-0.018235036757778406,\"blendMode\":\"normal\",\"alpha\":0.6,\"feather\":5,\"brightness\":0.98,\"saturation\":1.05,\"hueRotate\":0,\"shadowStrength\":0.55,\"isPopUp\":true,\"visible\":true,\"locked\":false}]},\"companions\":[{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":75,\"y\":18,\"scale\":0.75,\"rotation\":-3,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":19.86598581281736,\"y\":64.31010313858773,\"scale\":0.85,\"rotation\":2,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":24,\"y\":15,\"scale\":0.95,\"rotation\":-5,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_JUICE_ORANGE\",\"name\":\"Sok pomarańczowy\",\"assetUrl\":\"\",\"x\":69,\"y\":83,\"scale\":0.75,\"rotation\":4,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":3,\"y\":8,\"w\":40,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":18,\"seed\":\"meat\"},\"steam\":{\"count\":4,\"intensity\":55},\"oilSheen\":{\"enabled\":true,\"x\":40,\"y\":40}}}', 'Auto-save', '2026-04-19 08:39:18'),
(130, 2, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":35,\"lightY\":15,\"grainIntensity\":8,\"vignetteIntensity\":40,\"lutName\":\"darkvinyl\",\"letterbox\":3},\"pizza\":{\"x\":70,\"y\":60,\"scale\":1.4,\"rotation\":3,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_a5aa21\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_a5aa21.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"meat_meat_a7aa4e\",\"assetUrl\":\"/slicehub/uploads/global_assets/meat_meat_a7aa4e.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.016644114987711997,\"offsetY\":-0.018235036757778406,\"blendMode\":\"normal\",\"alpha\":0.9,\"feather\":5,\"brightness\":0.98,\"saturation\":1.05,\"hueRotate\":0,\"shadowStrength\":0.55,\"isPopUp\":true,\"visible\":true,\"locked\":false}]},\"companions\":[{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":75,\"y\":18,\"scale\":0.75,\"rotation\":-3,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":19.86598581281736,\"y\":64.31010313858773,\"scale\":0.85,\"rotation\":2,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":24,\"y\":15,\"scale\":0.95,\"rotation\":-5,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_JUICE_ORANGE\",\"name\":\"Sok pomarańczowy\",\"assetUrl\":\"\",\"x\":69,\"y\":83,\"scale\":0.75,\"rotation\":4,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":3,\"y\":8,\"w\":40,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":18,\"seed\":\"meat\"},\"steam\":{\"count\":4,\"intensity\":55},\"oilSheen\":{\"enabled\":true,\"x\":40,\"y\":40}}}', 'Auto-save', '2026-04-19 08:39:31'),
(131, 2, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":35,\"lightY\":15,\"grainIntensity\":8,\"vignetteIntensity\":40,\"lutName\":\"darkvinyl\",\"letterbox\":3},\"pizza\":{\"x\":70,\"y\":60,\"scale\":1.4,\"rotation\":3,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_a5aa21\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_a5aa21.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"meat_meat_a7aa4e\",\"assetUrl\":\"/slicehub/uploads/global_assets/meat_meat_a7aa4e.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.016644114987711997,\"offsetY\":-0.018235036757778406,\"blendMode\":\"normal\",\"alpha\":0.975,\"feather\":5,\"brightness\":0.98,\"saturation\":1.05,\"hueRotate\":0,\"shadowStrength\":0.55,\"isPopUp\":true,\"visible\":true,\"locked\":false}]},\"companions\":[{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":75,\"y\":18,\"scale\":0.75,\"rotation\":-3,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":19.86598581281736,\"y\":64.31010313858773,\"scale\":0.85,\"rotation\":2,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":24,\"y\":15,\"scale\":0.95,\"rotation\":-5,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_JUICE_ORANGE\",\"name\":\"Sok pomarańczowy\",\"assetUrl\":\"\",\"x\":69,\"y\":83,\"scale\":0.75,\"rotation\":4,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":3,\"y\":8,\"w\":40,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":18,\"seed\":\"meat\"},\"steam\":{\"count\":4,\"intensity\":55},\"oilSheen\":{\"enabled\":true,\"x\":40,\"y\":40}}}', 'Auto-save', '2026-04-19 08:39:38'),
(132, 2, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":35,\"lightY\":15,\"grainIntensity\":8,\"vignetteIntensity\":40,\"lutName\":\"darkvinyl\",\"letterbox\":3},\"pizza\":{\"x\":70,\"y\":60,\"scale\":1.4,\"rotation\":3,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_a5aa21\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_a5aa21.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"meat_meat_a7aa4e\",\"assetUrl\":\"/slicehub/uploads/global_assets/meat_meat_a7aa4e.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.016644114987711997,\"offsetY\":-0.018235036757778406,\"blendMode\":\"normal\",\"alpha\":0.994,\"feather\":5,\"brightness\":0.98,\"saturation\":1.05,\"hueRotate\":0,\"shadowStrength\":0.55,\"isPopUp\":true,\"visible\":true,\"locked\":false}]},\"companions\":[{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":75,\"y\":18,\"scale\":0.75,\"rotation\":-3,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":19.86598581281736,\"y\":64.31010313858773,\"scale\":0.85,\"rotation\":2,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":24,\"y\":15,\"scale\":0.95,\"rotation\":-5,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_JUICE_ORANGE\",\"name\":\"Sok pomarańczowy\",\"assetUrl\":\"\",\"x\":69,\"y\":83,\"scale\":0.75,\"rotation\":4,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":3,\"y\":8,\"w\":40,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":18,\"seed\":\"meat\"},\"steam\":{\"count\":4,\"intensity\":55},\"oilSheen\":{\"enabled\":true,\"x\":40,\"y\":40}}}', 'Auto-save', '2026-04-19 08:39:41'),
(133, 13, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":50,\"lightY\":15,\"grainIntensity\":6,\"vignetteIntensity\":35,\"lutName\":\"none\",\"letterbox\":0},\"pizza\":{\"x\":25,\"y\":55,\"scale\":1.2,\"rotation\":0,\"visible\":true,\"layers\":[]},\"companions\":[],\"infoBlock\":{\"x\":55,\"y\":8,\"w\":42,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":0,\"seed\":\"Panna Cotta\"},\"steam\":{\"count\":0,\"intensity\":50},\"oilSheen\":{\"enabled\":false,\"x\":45,\"y\":35}}}', 'Auto-save', '2026-04-19 09:16:16'),
(134, 2, '{\"stage\":{\"boardUrl\":\"/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp\",\"aspect\":\"16/10\",\"lightX\":35,\"lightY\":15,\"grainIntensity\":8,\"vignetteIntensity\":40,\"lutName\":\"darkvinyl\",\"letterbox\":3},\"pizza\":{\"x\":70,\"y\":60,\"scale\":1.4,\"rotation\":3,\"visible\":true,\"layers\":[{\"layerSku\":\"base_dough_a5aa21\",\"assetUrl\":\"/slicehub/uploads/global_assets/base_dough_a5aa21.webp\",\"zIndex\":10,\"calScale\":1,\"calRotate\":0,\"offsetX\":0,\"offsetY\":0,\"blendMode\":\"normal\",\"alpha\":1,\"feather\":0,\"brightness\":1,\"saturation\":1,\"hueRotate\":0,\"shadowStrength\":0,\"isPopUp\":false,\"visible\":true,\"locked\":false},{\"layerSku\":\"meat_meat_a7aa4e\",\"assetUrl\":\"/slicehub/uploads/global_assets/meat_meat_a7aa4e.webp\",\"zIndex\":20,\"calScale\":1,\"calRotate\":0,\"offsetX\":-0.016644114987711997,\"offsetY\":-0.018235036757778406,\"blendMode\":\"normal\",\"alpha\":0.994,\"feather\":5,\"brightness\":0.98,\"saturation\":1.05,\"hueRotate\":0,\"shadowStrength\":0.55,\"isPopUp\":true,\"visible\":true,\"locked\":false}]},\"companions\":[{\"sku\":\"DRINK_SPRITE_05\",\"name\":\"Sprite 0.5L\",\"assetUrl\":\"\",\"x\":75,\"y\":18,\"scale\":0.75,\"rotation\":-3,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_WATER_05\",\"name\":\"Woda mineralna 0.5L\",\"assetUrl\":\"\",\"x\":19.86598581281736,\"y\":64.31010313858773,\"scale\":0.85,\"rotation\":2,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_COLA_05\",\"name\":\"Coca-Cola 0.5L\",\"assetUrl\":\"\",\"x\":24,\"y\":15,\"scale\":0.95,\"rotation\":-5,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false},{\"sku\":\"DRINK_JUICE_ORANGE\",\"name\":\"Sok pomarańczowy\",\"assetUrl\":\"\",\"x\":69,\"y\":83,\"scale\":0.75,\"rotation\":4,\"label\":\"+ Dodaj do pizzy\",\"labelVisible\":true,\"visible\":true,\"locked\":false}],\"infoBlock\":{\"x\":3,\"y\":8,\"w\":40,\"h\":38,\"theme\":\"glass-dark\",\"align\":\"left\",\"bgOpacity\":0.85,\"visible\":true,\"locked\":false},\"modifierGrid\":{\"position\":\"below-stage\",\"style\":\"chips\",\"density\":\"comfortable\",\"visible\":true},\"ambient\":{\"crumbs\":{\"count\":18,\"seed\":\"meat\"},\"steam\":{\"count\":4,\"intensity\":55},\"oilSheen\":{\"enabled\":true,\"x\":40,\"y\":40}}}', 'Auto-save', '2026-04-19 09:17:16');

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_board_companions`
--

CREATE TABLE `sh_board_companions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `item_sku` varchar(255) NOT NULL COMMENT 'Primary product ascii_key (e.g. PIZZA_DI_PARMA)',
  `companion_sku` varchar(255) NOT NULL COMMENT 'Cross-sell product ascii_key (e.g. COCA_COLA_330)',
  `companion_type` enum('sauce','drink','side','dessert','extra') NOT NULL DEFAULT 'extra',
  `board_slot` tinyint(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Position 0-5 around the pizza on the board',
  `asset_filename` varchar(255) DEFAULT NULL COMMENT 'DEPRECATED_M021: cień-zapis; canonical w sh_asset_links(board_companion,companion_icon).',
  `product_filename` varchar(255) DEFAULT NULL COMMENT 'DEPRECATED_M021: cień-zapis; canonical w sh_asset_links(board_companion,product_shot).',
  `display_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `cta_label` varchar(64) DEFAULT 'Dodaj' COMMENT 'M022: label przycisku CTA na scenie',
  `is_always_visible` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'M022: czy companion jest zawsze na stole, czy tylko warunkowo',
  `slot_class` varchar(32) DEFAULT 'companion' COMMENT 'M022: companion / promotion / recommendation'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sh_board_companions`
--

INSERT INTO `sh_board_companions` (`id`, `tenant_id`, `item_sku`, `companion_sku`, `companion_type`, `board_slot`, `asset_filename`, `product_filename`, `display_order`, `is_active`, `created_at`, `updated_at`, `cta_label`, `is_always_visible`, `slot_class`) VALUES
(307, 1, 'PIZZA_PEPPERONI', 'DRINK_PEPSI_500', 'drink', 0, NULL, NULL, 0, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(308, 1, 'PIZZA_PEPPERONI', 'SAUCE_GARLIC', 'sauce', 1, NULL, NULL, 1, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(309, 1, 'PIZZA_PEPPERONI', 'SIDE_FRIES', 'side', 2, NULL, NULL, 2, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(310, 1, 'PIZZA_PEPPERONI', 'DESSERT_TIRAMISU', 'dessert', 3, NULL, NULL, 3, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(311, 1, 'PIZZA_PEPPERONI', 'DRINK_WATER_500', 'drink', 4, NULL, NULL, 4, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(312, 1, 'PIZZA_PEPPERONI', 'SAUCE_BBQ', 'sauce', 5, NULL, NULL, 5, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(313, 1, 'PIZZA_CAPRICCIOSA', 'DRINK_PEPSI_500', 'drink', 0, NULL, NULL, 0, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(314, 1, 'PIZZA_CAPRICCIOSA', 'SAUCE_GARLIC', 'sauce', 1, NULL, NULL, 1, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(315, 1, 'PIZZA_CAPRICCIOSA', 'SIDE_FRIES', 'side', 2, NULL, NULL, 2, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(316, 1, 'PIZZA_CAPRICCIOSA', 'DESSERT_TIRAMISU', 'dessert', 3, NULL, NULL, 3, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(317, 1, 'PIZZA_CAPRICCIOSA', 'DRINK_WATER_500', 'drink', 4, NULL, NULL, 4, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(318, 1, 'PIZZA_CAPRICCIOSA', 'SAUCE_BBQ', 'sauce', 5, NULL, NULL, 5, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(319, 1, 'PIZZA_HAWAJSKA', 'DRINK_PEPSI_500', 'drink', 0, NULL, NULL, 0, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(320, 1, 'PIZZA_HAWAJSKA', 'SAUCE_GARLIC', 'sauce', 1, NULL, NULL, 1, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(321, 1, 'PIZZA_HAWAJSKA', 'SIDE_FRIES', 'side', 2, NULL, NULL, 2, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(322, 1, 'PIZZA_HAWAJSKA', 'DESSERT_TIRAMISU', 'dessert', 3, NULL, NULL, 3, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(323, 1, 'PIZZA_HAWAJSKA', 'DRINK_WATER_500', 'drink', 4, NULL, NULL, 4, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(324, 1, 'PIZZA_HAWAJSKA', 'SAUCE_BBQ', 'sauce', 5, NULL, NULL, 5, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(325, 1, 'PIZZA_4FORMAGGI', 'DRINK_PEPSI_500', 'drink', 0, NULL, NULL, 0, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(326, 1, 'PIZZA_4FORMAGGI', 'SAUCE_GARLIC', 'sauce', 1, NULL, NULL, 1, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(327, 1, 'PIZZA_4FORMAGGI', 'SIDE_FRIES', 'side', 2, NULL, NULL, 2, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(328, 1, 'PIZZA_4FORMAGGI', 'DESSERT_TIRAMISU', 'dessert', 3, NULL, NULL, 3, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(329, 1, 'PIZZA_4FORMAGGI', 'DRINK_WATER_500', 'drink', 4, NULL, NULL, 4, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(330, 1, 'PIZZA_4FORMAGGI', 'SAUCE_BBQ', 'sauce', 5, NULL, NULL, 5, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(331, 1, 'PIZZA_DIAVOLA', 'DRINK_PEPSI_500', 'drink', 0, NULL, NULL, 0, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(332, 1, 'PIZZA_DIAVOLA', 'SAUCE_GARLIC', 'sauce', 1, NULL, NULL, 1, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(333, 1, 'PIZZA_DIAVOLA', 'SIDE_FRIES', 'side', 2, NULL, NULL, 2, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(334, 1, 'PIZZA_DIAVOLA', 'DESSERT_TIRAMISU', 'dessert', 3, NULL, NULL, 3, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(335, 1, 'PIZZA_DIAVOLA', 'DRINK_WATER_500', 'drink', 4, NULL, NULL, 4, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(336, 1, 'PIZZA_DIAVOLA', 'SAUCE_BBQ', 'sauce', 5, NULL, NULL, 5, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(337, 1, 'PIZZA_VEGETARIANA', 'DRINK_PEPSI_500', 'drink', 0, NULL, NULL, 0, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(338, 1, 'PIZZA_VEGETARIANA', 'SAUCE_GARLIC', 'sauce', 1, NULL, NULL, 1, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(339, 1, 'PIZZA_VEGETARIANA', 'SIDE_FRIES', 'side', 2, NULL, NULL, 2, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(340, 1, 'PIZZA_VEGETARIANA', 'DESSERT_TIRAMISU', 'dessert', 3, NULL, NULL, 3, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(341, 1, 'PIZZA_VEGETARIANA', 'DRINK_WATER_500', 'drink', 4, NULL, NULL, 4, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(342, 1, 'PIZZA_VEGETARIANA', 'SAUCE_BBQ', 'sauce', 5, NULL, NULL, 5, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(343, 1, 'PIZZA_BBQ_CHICKEN', 'DRINK_PEPSI_500', 'drink', 0, NULL, NULL, 0, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(344, 1, 'PIZZA_BBQ_CHICKEN', 'SAUCE_GARLIC', 'sauce', 1, NULL, NULL, 1, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(345, 1, 'PIZZA_BBQ_CHICKEN', 'SIDE_FRIES', 'side', 2, NULL, NULL, 2, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(346, 1, 'PIZZA_BBQ_CHICKEN', 'DESSERT_TIRAMISU', 'dessert', 3, NULL, NULL, 3, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(347, 1, 'PIZZA_BBQ_CHICKEN', 'DRINK_WATER_500', 'drink', 4, NULL, NULL, 4, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(348, 1, 'PIZZA_BBQ_CHICKEN', 'SAUCE_BBQ', 'sauce', 5, NULL, NULL, 5, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(349, 1, 'PIZZA_PROSC_FUNGHI', 'DRINK_PEPSI_500', 'drink', 0, NULL, NULL, 0, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(350, 1, 'PIZZA_PROSC_FUNGHI', 'SAUCE_GARLIC', 'sauce', 1, NULL, NULL, 1, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(351, 1, 'PIZZA_PROSC_FUNGHI', 'SIDE_FRIES', 'side', 2, NULL, NULL, 2, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(352, 1, 'PIZZA_PROSC_FUNGHI', 'DESSERT_TIRAMISU', 'dessert', 3, NULL, NULL, 3, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(353, 1, 'PIZZA_PROSC_FUNGHI', 'DRINK_WATER_500', 'drink', 4, NULL, NULL, 4, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(354, 1, 'PIZZA_PROSC_FUNGHI', 'SAUCE_BBQ', 'sauce', 5, NULL, NULL, 5, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(355, 1, 'PIZZA_CALZONE', 'DRINK_PEPSI_500', 'drink', 0, NULL, NULL, 0, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(356, 1, 'PIZZA_CALZONE', 'SAUCE_GARLIC', 'sauce', 1, NULL, NULL, 1, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(357, 1, 'PIZZA_CALZONE', 'SIDE_FRIES', 'side', 2, NULL, NULL, 2, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(358, 1, 'PIZZA_CALZONE', 'DESSERT_TIRAMISU', 'dessert', 3, NULL, NULL, 3, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(359, 1, 'PIZZA_CALZONE', 'DRINK_WATER_500', 'drink', 4, NULL, NULL, 4, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion'),
(360, 1, 'PIZZA_CALZONE', 'SAUCE_BBQ', 'sauce', 5, NULL, NULL, 5, 1, '2026-04-16 05:35:24', NULL, 'Dodaj', 1, 'companion');

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_categories`
--

CREATE TABLE `sh_categories` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `is_menu` tinyint(1) NOT NULL DEFAULT 1,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `default_vat_dine_in` decimal(5,2) NOT NULL DEFAULT 8.00,
  `default_vat_takeaway` decimal(5,2) NOT NULL DEFAULT 5.00,
  `default_vat_delivery` decimal(5,2) NOT NULL DEFAULT 5.00,
  `default_composition_profile` varchar(64) DEFAULT 'static_hero' COMMENT 'M022: domyślny profil dla nowych dań w tej kategorii',
  `layout_mode` enum('grouped','individual','hybrid','legacy_list') NOT NULL DEFAULT 'legacy_list' COMMENT 'M022: tryb wyświetlania kategorii w The Table',
  `category_scene_id` int(11) DEFAULT NULL COMMENT 'M022: opcjonalna scena kategorii (sh_atelier_scenes.id)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sh_categories`
--

INSERT INTO `sh_categories` (`id`, `tenant_id`, `name`, `is_menu`, `display_order`, `is_deleted`, `default_vat_dine_in`, `default_vat_takeaway`, `default_vat_delivery`, `default_composition_profile`, `layout_mode`, `category_scene_id`) VALUES
(1, 1, 'Pizza', 1, 1, 0, 8.00, 5.00, 5.00, 'static_hero', 'legacy_list', NULL),
(2, 1, 'Burgery', 1, 2, 0, 8.00, 5.00, 5.00, 'static_hero', 'legacy_list', NULL),
(3, 1, 'Makarony', 1, 3, 0, 8.00, 5.00, 5.00, 'static_hero', 'legacy_list', NULL),
(4, 1, 'Sałatki', 1, 4, 0, 8.00, 5.00, 5.00, 'static_hero', 'legacy_list', NULL),
(5, 1, 'Napoje', 1, 5, 0, 8.00, 5.00, 5.00, 'static_hero', 'legacy_list', NULL),
(6, 1, 'Dodatki', 1, 6, 0, 8.00, 5.00, 5.00, 'static_hero', 'legacy_list', NULL),
(7, 1, 'Desery', 1, 7, 0, 8.00, 5.00, 5.00, 'static_hero', 'legacy_list', NULL),
(8, 1, 'Zestawy', 1, 8, 0, 8.00, 5.00, 5.00, 'static_hero', 'legacy_list', NULL),
(9, 1, 'Sosy', 1, 0, 0, 8.00, 5.00, 5.00, 'static_hero', 'legacy_list', NULL);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_category_styles`
--

CREATE TABLE `sh_category_styles` (
  `id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `category_id` bigint(20) UNSIGNED NOT NULL COMMENT 'FK logiczne do sh_categories.id',
  `style_preset_id` int(10) UNSIGNED NOT NULL COMMENT 'FK logiczne do sh_style_presets.id',
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  `applied_by_user_id` int(10) UNSIGNED DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `ai_cost_zl` decimal(10,4) NOT NULL DEFAULT 0.0000 COMMENT 'Audyt koszt??w AI dla tej aplikacji stylu'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='M022: aktywny styl per kategoria + historia';

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_checkout_locks`
--

CREATE TABLE `sh_checkout_locks` (
  `lock_token` char(36) NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `customer_phone` varchar(32) DEFAULT NULL,
  `cart_hash` char(64) NOT NULL COMMENT 'SHA-256 of canonicalized cart',
  `grand_total_grosze` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `channel` varchar(16) NOT NULL DEFAULT 'Delivery',
  `expires_at` datetime NOT NULL,
  `consumed_at` datetime DEFAULT NULL,
  `consumed_order_id` char(36) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sh_checkout_locks`
--

INSERT INTO `sh_checkout_locks` (`lock_token`, `tenant_id`, `customer_phone`, `cart_hash`, `grand_total_grosze`, `channel`, `expires_at`, `consumed_at`, `consumed_order_id`, `created_at`) VALUES
('3a46acd9-1607-415e-a72c-c72791ed24ca', 1, '+48500600700', 'c8ef759db1a5f0bb244a7a713e1525a5f328d1bd8dee0ec3944b89e01dfff6f8', 2592, 'Delivery', '2026-04-17 02:21:23', NULL, NULL, '2026-04-17 02:16:23');

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_course_sequences`
--

CREATE TABLE `sh_course_sequences` (
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `date` date NOT NULL,
  `seq` bigint(20) UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sh_course_sequences`
--

INSERT INTO `sh_course_sequences` (`tenant_id`, `date`, `seq`) VALUES
(1, '2026-04-13', 0),
(1, '2026-04-14', 0),
(1, '2026-04-16', 2),
(1, '2026-04-17', 0);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_deductions`
--

CREATE TABLE `sh_deductions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `type` varchar(64) NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_delivery_zones`
--

CREATE TABLE `sh_delivery_zones` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(128) DEFAULT NULL,
  `zone_polygon` polygon NOT NULL COMMENT 'Use SRID consistent with ST_GeomFromText in app (MySQL 8+ can add SRID 4326 via ALTER)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_dispatch_log`
--

CREATE TABLE `sh_dispatch_log` (
  `id` char(36) NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `course_id` varchar(32) NOT NULL,
  `driver_id` varchar(64) DEFAULT NULL,
  `order_ids_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`order_ids_json`)),
  `dispatched_by` bigint(20) UNSIGNED NOT NULL,
  `dispatched_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sh_dispatch_log`
--

INSERT INTO `sh_dispatch_log` (`id`, `tenant_id`, `course_id`, `driver_id`, `order_ids_json`, `dispatched_by`, `dispatched_at`) VALUES
('b1f2225a-c0b6-4d56-94e4-7091cf3581a4', 1, 'K1', '11', '[\"231d4921-b571-45a5-986f-5e0c59ae7d62\",\"cc17bad0-2714-47ed-9386-b96b58c450c2\",\"1aeb78f0-2f74-4ed4-8553-9f335d15650a\"]', 3, '2026-04-16 13:04:33'),
('decbcbd2-3414-43a2-aaaa-cd8362f09745', 1, 'K2', '10', '[\"3928ce3c-3c92-4884-914f-69148d4ff6d9\",\"fd0b1c4c-5c2a-4bd2-8a8d-8eaf01d1b2c1\",\"6a0a457e-0226-4a28-95f2-df12da98361e\"]', 2, '2026-04-16 13:16:52');

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_doc_sequences`
--

CREATE TABLE `sh_doc_sequences` (
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `doc_type` varchar(16) NOT NULL,
  `doc_date` date NOT NULL,
  `seq` bigint(20) UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sh_doc_sequences`
--

INSERT INTO `sh_doc_sequences` (`tenant_id`, `doc_type`, `doc_date`, `seq`) VALUES
(1, 'PZ', '2026-04-13', 3),
(1, 'RW', '2026-04-13', 1);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_drivers`
--

CREATE TABLE `sh_drivers` (
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'offline'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sh_drivers`
--

INSERT INTO `sh_drivers` (`user_id`, `tenant_id`, `status`) VALUES
(6, 1, 'available'),
(7, 1, 'available'),
(9, 1, 'available'),
(10, 1, 'busy'),
(11, 1, 'available');

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_driver_locations`
--

CREATE TABLE `sh_driver_locations` (
  `driver_id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `lat` decimal(10,7) NOT NULL,
  `lng` decimal(10,7) NOT NULL,
  `heading` smallint(6) DEFAULT NULL,
  `speed_kmh` decimal(5,1) DEFAULT NULL,
  `accuracy_m` decimal(6,1) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sh_driver_locations`
--

INSERT INTO `sh_driver_locations` (`driver_id`, `tenant_id`, `lat`, `lng`, `heading`, `speed_kmh`, `accuracy_m`, `updated_at`) VALUES
(6, 1, 52.4080000, 16.9210000, NULL, NULL, NULL, '2026-04-17 18:34:47'),
(7, 1, 52.4020000, 16.9300000, NULL, NULL, NULL, '2026-04-17 18:34:47'),
(9, 1, 52.4022000, 16.9116000, 149, 2.9, 3.0, '2026-04-16 05:19:06'),
(10, 1, 52.3996000, 16.9338000, NULL, 1.1, 6.0, '2026-04-16 13:20:40'),
(11, 1, 52.4155000, 16.9077000, 307, 2.2, 5.0, '2026-04-16 05:19:06');

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_driver_shifts`
--

CREATE TABLE `sh_driver_shifts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `driver_id` varchar(64) NOT NULL,
  `initial_cash` int(11) NOT NULL DEFAULT 0 COMMENT 'Grosze',
  `counted_cash` int(11) DEFAULT NULL,
  `variance` int(11) DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sh_driver_shifts`
--

INSERT INTO `sh_driver_shifts` (`id`, `tenant_id`, `driver_id`, `initial_cash`, `counted_cash`, `variance`, `status`, `created_at`) VALUES
(31, 1, '9', 11082, NULL, NULL, 'active', '2026-04-16 05:19:06'),
(32, 1, '10', 5166, NULL, NULL, 'active', '2026-04-16 05:19:06'),
(33, 1, '11', 8091, NULL, NULL, 'active', '2026-04-16 05:19:06'),
(35, 1, '6', 10000, NULL, NULL, 'active', '2026-04-17 03:11:28'),
(36, 1, '7', 10000, NULL, NULL, 'active', '2026-04-17 03:11:28');

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_event_outbox`
--

CREATE TABLE `sh_event_outbox` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `event_type` varchar(64) NOT NULL COMMENT 'Kanoniczne: order.created, order.accepted, order.preparing, order.ready, order.dispatched, order.in_delivery, order.delivered, order.completed, order.cancelled, order.edited, order.recalled',
  `aggregate_type` varchar(32) NOT NULL DEFAULT 'order' COMMENT 'order | payment | shift | driver — enum dla bramek routingu',
  `aggregate_id` varchar(64) NOT NULL COMMENT 'UUID zamówienia (dla order.*) lub ID innego aggregatu',
  `idempotency_key` varchar(128) DEFAULT NULL COMMENT 'Klucz anti-duplicate — np. {aggregate_id}:{event_type}:{status_transition}. Unikalny per tenant.',
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Snapshot danych eventu (order header + lines + context)' CHECK (json_valid(`payload`)),
  `source` varchar(32) NOT NULL DEFAULT 'internal' COMMENT 'online | pos | kiosk | gateway | kds | delivery | courses | admin',
  `actor_type` varchar(24) DEFAULT NULL COMMENT 'guest | staff | system | external_api',
  `actor_id` varchar(64) DEFAULT NULL COMMENT 'user_id / api_key_hash / null dla system',
  `status` enum('pending','dispatching','delivered','failed','dead') NOT NULL DEFAULT 'pending',
  `attempts` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `next_attempt_at` datetime DEFAULT NULL COMMENT 'Kiedy worker ma spróbować ponownie (exponential backoff)',
  `last_error` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `dispatched_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Transactional outbox — lifecycle events (m026)';

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_external_order_refs`
--

CREATE TABLE `sh_external_order_refs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `source` varchar(32) NOT NULL COMMENT 'web | aggregator_uber | aggregator_glovo | aggregator_pyszne | kiosk | pos_3rd | mobile_app | public_api',
  `external_id` varchar(128) NOT NULL COMMENT 'ID z systemu 3rd-party (np. Uber UUID, Glovo order_ref). Nigdy pusty gdy source != web.',
  `order_id` char(36) NOT NULL COMMENT 'UUID zamówienia w sh_orders',
  `api_key_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Który klucz został użyty — do audytu',
  `request_hash` char(64) DEFAULT NULL COMMENT 'SHA-256 oryginalnego payloadu — detect replay z różnymi danymi',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='External order ID → internal order_id map (idempotency, m027)';

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_gateway_api_keys`
--

CREATE TABLE `sh_gateway_api_keys` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `key_prefix` varchar(32) NOT NULL COMMENT 'Publiczna część klucza np. sh_live_a1b2c3d4. Indeksowalna, logowalna.',
  `key_secret_hash` char(64) NOT NULL COMMENT 'SHA-256(raw_secret) — nigdy plaintext. Weryfikacja: hash_equals(hash(secret), stored).',
  `name` varchar(128) NOT NULL COMMENT 'Human-readable label, np. "Uber Eats integration" / "Mobile App iOS"',
  `source` varchar(32) NOT NULL COMMENT 'web | aggregator | kiosk | pos_3rd | mobile_app | public_api | internal',
  `scopes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Uprawnienia: ["order:create","order:read","menu:read",...]. ["*"] = wszystkie.' CHECK (json_valid(`scopes`)),
  `rate_limit_per_min` int(10) UNSIGNED NOT NULL DEFAULT 60 COMMENT 'Max requestów na minutę dla tego klucza (sliding window 60s)',
  `rate_limit_per_day` int(10) UNSIGNED NOT NULL DEFAULT 10000 COMMENT 'Max requestów na dobę (UTC midnight reset)',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_used_at` datetime DEFAULT NULL,
  `last_used_ip` varchar(45) DEFAULT NULL COMMENT 'IPv4 lub IPv6 caller''a',
  `expires_at` datetime DEFAULT NULL COMMENT 'NULL = nigdy nie wygasa; opcjonalna rotacja sekretów',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'sh_users.id — kto w UI Settings wygenerował klucz',
  `revoked_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Gateway API keys per tenant × source (m027)';

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_global_assets`
--

CREATE TABLE `sh_global_assets` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = global shared asset',
  `ascii_key` varchar(255) NOT NULL COMMENT 'Technical key, e.g. meat_salami_layer_1',
  `category` enum('board','base','sauce','cheese','meat','veg','herb','extra','misc') NOT NULL DEFAULT 'misc',
  `sub_type` varchar(64) DEFAULT NULL COMMENT 'Ingredient sub-type: salami, bacon, mozzarella...',
  `filename` varchar(255) NOT NULL COMMENT 'DEPRECATED_M021: cień-zapis; canonical w sh_assets.storage_url. Do DROP po migracji API.',
  `url` varchar(512) DEFAULT NULL,
  `width` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `height` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `has_alpha` tinyint(1) NOT NULL DEFAULT 1,
  `filesize_bytes` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `z_order` int(11) NOT NULL DEFAULT 50 COMMENT 'Default stacking order (lower = behind)',
  `target_px` int(10) UNSIGNED NOT NULL DEFAULT 500 COMMENT 'Longest-edge target resolution',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sh_global_assets`
--

INSERT INTO `sh_global_assets` (`id`, `tenant_id`, `ascii_key`, `category`, `sub_type`, `filename`, `url`, `width`, `height`, `has_alpha`, `filesize_bytes`, `z_order`, `target_px`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 1, 'base_dough_008fe6', 'base', 'dough', 'base_dough_008fe6.webp', '/slicehub/uploads/global_assets/base_dough_008fe6.webp', 800, 770, 1, 87308, 10, 500, 1, '2026-04-17 04:51:49', NULL),
(2, 1, 'base_dough_08bbae', 'base', 'dough', 'base_dough_08bbae.webp', '/slicehub/uploads/global_assets/base_dough_08bbae.webp', 800, 790, 1, 65258, 10, 500, 1, '2026-04-17 04:51:49', NULL),
(3, 1, 'base_dough_2244b4', 'base', 'dough', 'base_dough_2244b4.webp', '/slicehub/uploads/global_assets/base_dough_2244b4.webp', 789, 800, 1, 51234, 10, 500, 1, '2026-04-17 04:51:49', NULL),
(4, 1, 'base_dough_294218', 'base', 'dough', 'base_dough_294218.webp', '/slicehub/uploads/global_assets/base_dough_294218.webp', 787, 800, 1, 80178, 10, 500, 1, '2026-04-17 04:51:49', NULL),
(5, 1, 'base_dough_531bae', 'base', 'dough', 'base_dough_531bae.webp', '/slicehub/uploads/global_assets/base_dough_531bae.webp', 800, 798, 1, 103652, 10, 500, 1, '2026-04-17 04:51:49', NULL),
(6, 1, 'base_dough_532c44', 'base', 'dough', 'base_dough_532c44.webp', '/slicehub/uploads/global_assets/base_dough_532c44.webp', 788, 800, 1, 56148, 10, 500, 1, '2026-04-17 04:51:49', NULL),
(7, 1, 'base_dough_5fb69d', 'base', 'dough', 'base_dough_5fb69d.webp', '/slicehub/uploads/global_assets/base_dough_5fb69d.webp', 800, 797, 1, 60736, 10, 500, 1, '2026-04-17 04:51:49', NULL),
(8, 1, 'base_dough_69ec5a', 'base', 'dough', 'base_dough_69ec5a.webp', '/slicehub/uploads/global_assets/base_dough_69ec5a.webp', 800, 780, 1, 89136, 10, 500, 1, '2026-04-17 04:51:49', NULL),
(9, 1, 'base_dough_a5aa21', 'base', 'dough', 'base_dough_a5aa21.webp', '/slicehub/uploads/global_assets/base_dough_a5aa21.webp', 797, 800, 1, 42260, 10, 500, 1, '2026-04-17 04:51:49', NULL),
(10, 1, 'base_dough_ac3afd', 'base', 'dough', 'base_dough_ac3afd.webp', '/slicehub/uploads/global_assets/base_dough_ac3afd.webp', 800, 765, 1, 51622, 10, 500, 1, '2026-04-17 04:51:49', NULL),
(11, 1, 'base_dough_b455ef', 'base', 'dough', 'base_dough_b455ef.webp', '/slicehub/uploads/global_assets/base_dough_b455ef.webp', 800, 800, 1, 76590, 10, 500, 1, '2026-04-17 04:51:49', NULL),
(12, 1, 'base_dough_ce7a24', 'base', 'dough', 'base_dough_ce7a24.webp', '/slicehub/uploads/global_assets/base_dough_ce7a24.webp', 791, 800, 1, 61880, 10, 500, 1, '2026-04-17 04:51:49', NULL),
(13, 1, 'base_dough_dc694c', 'base', 'dough', 'base_dough_dc694c.webp', '/slicehub/uploads/global_assets/base_dough_dc694c.webp', 799, 800, 1, 81160, 10, 500, 1, '2026-04-17 04:51:49', NULL),
(14, 1, 'base_dough_dc8dc5', 'base', 'dough', 'base_dough_dc8dc5.webp', '/slicehub/uploads/global_assets/base_dough_dc8dc5.webp', 800, 764, 1, 65108, 10, 500, 1, '2026-04-17 04:51:49', NULL),
(15, 1, 'base_dough_df4ba6', 'base', 'dough', 'base_dough_df4ba6.webp', '/slicehub/uploads/global_assets/base_dough_df4ba6.webp', 800, 776, 1, 106368, 10, 500, 1, '2026-04-17 04:51:49', NULL),
(16, 1, 'base_dough_f1b7c2', 'base', 'dough', 'base_dough_f1b7c2.webp', '/slicehub/uploads/global_assets/base_dough_f1b7c2.webp', 798, 800, 1, 83184, 10, 500, 1, '2026-04-17 04:51:49', NULL),
(17, 1, 'base_dough_fdfba2', 'base', 'dough', 'base_dough_fdfba2.webp', '/slicehub/uploads/global_assets/base_dough_fdfba2.webp', 799, 800, 1, 48024, 10, 500, 1, '2026-04-17 04:51:49', NULL),
(18, 1, 'board_plate_0fa8df', 'board', 'plate', 'board_plate_0fa8df.webp', '/slicehub/uploads/global_assets/board_plate_0fa8df.webp', 795, 800, 1, 91628, 0, 500, 1, '2026-04-17 04:51:49', NULL),
(19, 1, 'board_plate_4212b3', 'board', 'plate', 'board_plate_4212b3.webp', '/slicehub/uploads/global_assets/board_plate_4212b3.webp', 788, 800, 1, 80394, 0, 500, 1, '2026-04-17 04:51:49', NULL),
(20, 1, 'board_plate_9dab6f', 'board', 'plate', 'board_plate_9dab6f.webp', '/slicehub/uploads/global_assets/board_plate_9dab6f.webp', 800, 778, 1, 66994, 0, 500, 1, '2026-04-17 04:51:49', NULL),
(21, 1, 'board_plate_b229dc', 'board', 'plate', 'board_plate_b229dc.webp', '/slicehub/uploads/global_assets/board_plate_b229dc.webp', 800, 797, 1, 81778, 0, 500, 1, '2026-04-17 04:51:49', NULL),
(22, 1, 'board_plate_d0b2bd', 'board', 'plate', 'board_plate_d0b2bd.webp', '/slicehub/uploads/global_assets/board_plate_d0b2bd.webp', 797, 800, 1, 79922, 0, 500, 1, '2026-04-17 04:51:49', NULL),
(23, 1, 'cheese_cheese_007121', 'cheese', 'cheese', 'cheese_cheese_007121.webp', '/slicehub/uploads/global_assets/cheese_cheese_007121.webp', 800, 731, 1, 141360, 30, 500, 1, '2026-04-17 04:51:49', NULL),
(24, 1, 'cheese_cheese_1bf0ab', 'cheese', 'cheese', 'cheese_cheese_1bf0ab.webp', '/slicehub/uploads/global_assets/cheese_cheese_1bf0ab.webp', 262, 800, 1, 21276, 30, 500, 1, '2026-04-17 04:51:49', NULL),
(25, 1, 'cheese_cheese_2b4e70', 'cheese', 'cheese', 'cheese_cheese_2b4e70.webp', '/slicehub/uploads/global_assets/cheese_cheese_2b4e70.webp', 273, 800, 1, 23452, 30, 500, 1, '2026-04-17 04:51:49', NULL),
(26, 1, 'cheese_cheese_45dea6', 'cheese', 'cheese', 'cheese_cheese_45dea6.webp', '/slicehub/uploads/global_assets/cheese_cheese_45dea6.webp', 800, 744, 1, 129398, 30, 500, 1, '2026-04-17 04:51:49', NULL),
(27, 1, 'cheese_cheese_82f9f8', 'cheese', 'cheese', 'cheese_cheese_82f9f8.webp', '/slicehub/uploads/global_assets/cheese_cheese_82f9f8.webp', 800, 751, 1, 217004, 30, 500, 1, '2026-04-17 04:51:49', NULL),
(28, 1, 'cheese_cheese_bec540', 'cheese', 'cheese', 'cheese_cheese_bec540.webp', '/slicehub/uploads/global_assets/cheese_cheese_bec540.webp', 800, 680, 1, 111736, 30, 500, 1, '2026-04-17 04:51:49', NULL),
(29, 1, 'extra_item_1509c1', 'extra', 'item', 'extra_item_1509c1.webp', '/slicehub/uploads/global_assets/extra_item_1509c1.webp', 800, 767, 1, 46252, 70, 500, 1, '2026-04-17 04:51:49', NULL),
(30, 1, 'extra_item_2f98f2', 'extra', 'item', 'extra_item_2f98f2.webp', '/slicehub/uploads/global_assets/extra_item_2f98f2.webp', 216, 800, 1, 23628, 70, 500, 1, '2026-04-17 04:51:49', NULL),
(31, 1, 'extra_item_385ef3', 'extra', 'item', 'extra_item_385ef3.webp', '/slicehub/uploads/global_assets/extra_item_385ef3.webp', 243, 800, 1, 55274, 70, 500, 1, '2026-04-17 04:51:49', NULL),
(32, 1, 'extra_item_3a3e53', 'extra', 'item', 'extra_item_3a3e53.webp', '/slicehub/uploads/global_assets/extra_item_3a3e53.webp', 736, 800, 1, 45194, 70, 500, 1, '2026-04-17 04:51:49', NULL),
(33, 1, 'extra_item_67088e', 'extra', 'item', 'extra_item_67088e.webp', '/slicehub/uploads/global_assets/extra_item_67088e.webp', 800, 795, 1, 39888, 70, 500, 1, '2026-04-17 04:51:49', NULL),
(34, 1, 'extra_item_699410', 'extra', 'item', 'extra_item_699410.webp', '/slicehub/uploads/global_assets/extra_item_699410.webp', 797, 800, 1, 36594, 70, 500, 1, '2026-04-17 04:51:49', NULL),
(35, 1, 'extra_item_6cdb4b', 'extra', 'item', 'extra_item_6cdb4b.webp', '/slicehub/uploads/global_assets/extra_item_6cdb4b.webp', 727, 800, 1, 48266, 70, 500, 1, '2026-04-17 04:51:49', NULL),
(36, 1, 'extra_item_80323c', 'extra', 'item', 'extra_item_80323c.webp', '/slicehub/uploads/global_assets/extra_item_80323c.webp', 800, 767, 1, 35230, 70, 500, 1, '2026-04-17 04:51:49', NULL),
(37, 1, 'extra_item_8a61a2', 'extra', 'item', 'extra_item_8a61a2.webp', '/slicehub/uploads/global_assets/extra_item_8a61a2.webp', 800, 664, 1, 50568, 70, 500, 1, '2026-04-17 04:51:49', NULL),
(38, 1, 'extra_item_ab6c35', 'extra', 'item', 'extra_item_ab6c35.webp', '/slicehub/uploads/global_assets/extra_item_ab6c35.webp', 153, 800, 1, 19480, 70, 500, 1, '2026-04-17 04:51:49', NULL),
(39, 1, 'extra_item_abf7eb', 'extra', 'item', 'extra_item_abf7eb.webp', '/slicehub/uploads/global_assets/extra_item_abf7eb.webp', 800, 786, 1, 60180, 70, 500, 1, '2026-04-17 04:51:49', NULL),
(40, 1, 'extra_item_cf4703', 'extra', 'item', 'extra_item_cf4703.webp', '/slicehub/uploads/global_assets/extra_item_cf4703.webp', 133, 800, 1, 22486, 70, 500, 1, '2026-04-17 04:51:49', NULL),
(41, 1, 'extra_item_de8d98', 'extra', 'item', 'extra_item_de8d98.webp', '/slicehub/uploads/global_assets/extra_item_de8d98.webp', 800, 732, 1, 60112, 70, 500, 1, '2026-04-17 04:51:49', NULL),
(42, 1, 'herb_herb_03a199', 'herb', 'herb', 'herb_herb_03a199.webp', '/slicehub/uploads/global_assets/herb_herb_03a199.webp', 533, 800, 1, 35478, 60, 500, 1, '2026-04-17 04:51:49', NULL),
(43, 1, 'herb_herb_048c5c', 'herb', 'herb', 'herb_herb_048c5c.webp', '/slicehub/uploads/global_assets/herb_herb_048c5c.webp', 800, 619, 1, 36814, 60, 500, 1, '2026-04-17 04:51:49', NULL),
(44, 1, 'herb_herb_23c73a', 'herb', 'herb', 'herb_herb_23c73a.webp', '/slicehub/uploads/global_assets/herb_herb_23c73a.webp', 764, 800, 1, 60798, 60, 500, 1, '2026-04-17 04:51:49', NULL),
(45, 1, 'herb_herb_2d76bf', 'herb', 'herb', 'herb_herb_2d76bf.webp', '/slicehub/uploads/global_assets/herb_herb_2d76bf.webp', 800, 793, 1, 72338, 60, 500, 1, '2026-04-17 04:51:49', NULL),
(46, 1, 'herb_herb_2dcb13', 'herb', 'herb', 'herb_herb_2dcb13.webp', '/slicehub/uploads/global_assets/herb_herb_2dcb13.webp', 800, 786, 1, 43414, 60, 500, 1, '2026-04-17 04:51:49', NULL),
(47, 1, 'herb_herb_2fb5ae', 'herb', 'herb', 'herb_herb_2fb5ae.webp', '/slicehub/uploads/global_assets/herb_herb_2fb5ae.webp', 800, 786, 1, 60046, 60, 500, 1, '2026-04-17 04:51:49', NULL),
(48, 1, 'herb_herb_34bb12', 'herb', 'herb', 'herb_herb_34bb12.webp', '/slicehub/uploads/global_assets/herb_herb_34bb12.webp', 800, 728, 1, 44062, 60, 500, 1, '2026-04-17 04:51:49', NULL),
(49, 1, 'herb_herb_430c4f', 'herb', 'herb', 'herb_herb_430c4f.webp', '/slicehub/uploads/global_assets/herb_herb_430c4f.webp', 800, 588, 1, 46040, 60, 500, 1, '2026-04-17 04:51:49', NULL),
(50, 1, 'herb_herb_43e257', 'herb', 'herb', 'herb_herb_43e257.webp', '/slicehub/uploads/global_assets/herb_herb_43e257.webp', 800, 772, 1, 74056, 60, 500, 1, '2026-04-17 04:51:49', NULL),
(51, 1, 'herb_herb_5f754b', 'herb', 'herb', 'herb_herb_5f754b.webp', '/slicehub/uploads/global_assets/herb_herb_5f754b.webp', 721, 800, 1, 44058, 60, 500, 1, '2026-04-17 04:51:49', NULL),
(52, 1, 'herb_herb_6c7a40', 'herb', 'herb', 'herb_herb_6c7a40.webp', '/slicehub/uploads/global_assets/herb_herb_6c7a40.webp', 800, 639, 1, 49038, 60, 500, 1, '2026-04-17 04:51:49', NULL),
(53, 1, 'herb_herb_7d1d07', 'herb', 'herb', 'herb_herb_7d1d07.webp', '/slicehub/uploads/global_assets/herb_herb_7d1d07.webp', 800, 765, 1, 69410, 60, 500, 1, '2026-04-17 04:51:49', NULL),
(54, 1, 'herb_herb_82d8f7', 'herb', 'herb', 'herb_herb_82d8f7.webp', '/slicehub/uploads/global_assets/herb_herb_82d8f7.webp', 800, 795, 1, 49206, 60, 500, 1, '2026-04-17 04:51:49', NULL),
(55, 1, 'herb_herb_858be3', 'herb', 'herb', 'herb_herb_858be3.webp', '/slicehub/uploads/global_assets/herb_herb_858be3.webp', 800, 535, 1, 34144, 60, 500, 1, '2026-04-17 04:51:49', NULL),
(56, 1, 'herb_herb_85bcf0', 'herb', 'herb', 'herb_herb_85bcf0.webp', '/slicehub/uploads/global_assets/herb_herb_85bcf0.webp', 779, 800, 1, 55458, 60, 500, 1, '2026-04-17 04:51:49', NULL),
(57, 1, 'herb_herb_869de4', 'herb', 'herb', 'herb_herb_869de4.webp', '/slicehub/uploads/global_assets/herb_herb_869de4.webp', 800, 718, 1, 52134, 60, 500, 1, '2026-04-17 04:51:49', NULL),
(58, 1, 'herb_herb_8aaf11', 'herb', 'herb', 'herb_herb_8aaf11.webp', '/slicehub/uploads/global_assets/herb_herb_8aaf11.webp', 800, 765, 1, 86602, 60, 500, 1, '2026-04-17 04:51:49', NULL),
(59, 1, 'herb_herb_8d67c0', 'herb', 'herb', 'herb_herb_8d67c0.webp', '/slicehub/uploads/global_assets/herb_herb_8d67c0.webp', 800, 713, 1, 49270, 60, 500, 1, '2026-04-17 04:51:49', NULL),
(60, 1, 'herb_herb_92ca71', 'herb', 'herb', 'herb_herb_92ca71.webp', '/slicehub/uploads/global_assets/herb_herb_92ca71.webp', 587, 800, 1, 44120, 60, 500, 1, '2026-04-17 04:51:49', NULL),
(61, 1, 'herb_herb_9a60c7', 'herb', 'herb', 'herb_herb_9a60c7.webp', '/slicehub/uploads/global_assets/herb_herb_9a60c7.webp', 800, 684, 1, 47952, 60, 500, 1, '2026-04-17 04:51:49', NULL),
(62, 1, 'herb_herb_a417bd', 'herb', 'herb', 'herb_herb_a417bd.webp', '/slicehub/uploads/global_assets/herb_herb_a417bd.webp', 800, 662, 1, 89240, 60, 500, 1, '2026-04-17 04:51:49', NULL),
(63, 1, 'herb_herb_a6bef4', 'herb', 'herb', 'herb_herb_a6bef4.webp', '/slicehub/uploads/global_assets/herb_herb_a6bef4.webp', 765, 800, 1, 55526, 60, 500, 1, '2026-04-17 04:51:49', NULL),
(64, 1, 'herb_herb_a9b2d4', 'herb', 'herb', 'herb_herb_a9b2d4.webp', '/slicehub/uploads/global_assets/herb_herb_a9b2d4.webp', 800, 710, 1, 111040, 60, 500, 1, '2026-04-17 04:51:49', NULL),
(65, 1, 'herb_herb_aa4f58', 'herb', 'herb', 'herb_herb_aa4f58.webp', '/slicehub/uploads/global_assets/herb_herb_aa4f58.webp', 800, 683, 1, 62308, 60, 500, 1, '2026-04-17 04:51:49', NULL),
(66, 1, 'herb_herb_aec4b2', 'herb', 'herb', 'herb_herb_aec4b2.webp', '/slicehub/uploads/global_assets/herb_herb_aec4b2.webp', 800, 762, 1, 47364, 60, 500, 1, '2026-04-17 04:51:49', NULL),
(67, 1, 'herb_herb_ba2541', 'herb', 'herb', 'herb_herb_ba2541.webp', '/slicehub/uploads/global_assets/herb_herb_ba2541.webp', 786, 800, 1, 51218, 60, 500, 1, '2026-04-17 04:51:49', NULL),
(68, 1, 'herb_herb_bb8976', 'herb', 'herb', 'herb_herb_bb8976.webp', '/slicehub/uploads/global_assets/herb_herb_bb8976.webp', 800, 706, 1, 38038, 60, 500, 1, '2026-04-17 04:51:49', NULL),
(69, 1, 'herb_herb_c46077', 'herb', 'herb', 'herb_herb_c46077.webp', '/slicehub/uploads/global_assets/herb_herb_c46077.webp', 800, 708, 1, 77204, 60, 500, 1, '2026-04-17 04:51:49', NULL),
(70, 1, 'herb_herb_c55221', 'herb', 'herb', 'herb_herb_c55221.webp', '/slicehub/uploads/global_assets/herb_herb_c55221.webp', 634, 800, 1, 38070, 60, 500, 1, '2026-04-17 04:51:49', NULL),
(71, 1, 'herb_herb_cb22b5', 'herb', 'herb', 'herb_herb_cb22b5.webp', '/slicehub/uploads/global_assets/herb_herb_cb22b5.webp', 800, 783, 1, 68680, 60, 500, 1, '2026-04-17 04:51:49', NULL),
(72, 1, 'herb_herb_db5c21', 'herb', 'herb', 'herb_herb_db5c21.webp', '/slicehub/uploads/global_assets/herb_herb_db5c21.webp', 800, 764, 1, 48912, 60, 500, 1, '2026-04-17 04:51:49', NULL),
(73, 1, 'herb_herb_e32660', 'herb', 'herb', 'herb_herb_e32660.webp', '/slicehub/uploads/global_assets/herb_herb_e32660.webp', 800, 798, 1, 54702, 60, 500, 1, '2026-04-17 04:51:49', NULL),
(74, 1, 'herb_herb_f4be20', 'herb', 'herb', 'herb_herb_f4be20.webp', '/slicehub/uploads/global_assets/herb_herb_f4be20.webp', 800, 683, 1, 83186, 60, 500, 1, '2026-04-17 04:51:49', NULL),
(75, 1, 'meat_meat_11187d', 'meat', 'meat', 'meat_meat_11187d.webp', '/slicehub/uploads/global_assets/meat_meat_11187d.webp', 800, 781, 1, 59874, 40, 500, 1, '2026-04-17 04:51:49', NULL),
(76, 1, 'meat_meat_32ca21', 'meat', 'meat', 'meat_meat_32ca21.webp', '/slicehub/uploads/global_assets/meat_meat_32ca21.webp', 800, 773, 1, 58376, 40, 500, 1, '2026-04-17 04:51:49', NULL),
(77, 1, 'meat_meat_a7aa4e', 'meat', 'meat', 'meat_meat_a7aa4e.webp', '/slicehub/uploads/global_assets/meat_meat_a7aa4e.webp', 800, 768, 1, 107136, 40, 500, 1, '2026-04-17 04:51:49', NULL),
(78, 1, 'meat_meat_d74071', 'meat', 'meat', 'meat_meat_d74071.webp', '/slicehub/uploads/global_assets/meat_meat_d74071.webp', 799, 800, 1, 95726, 40, 500, 1, '2026-04-17 04:51:49', NULL),
(79, 1, 'meat_meat_f3ec38', 'meat', 'meat', 'meat_meat_f3ec38.webp', '/slicehub/uploads/global_assets/meat_meat_f3ec38.webp', 800, 763, 1, 123102, 40, 500, 1, '2026-04-17 04:51:49', NULL),
(80, 1, 'meat_meat_fb0f62', 'meat', 'meat', 'meat_meat_fb0f62.webp', '/slicehub/uploads/global_assets/meat_meat_fb0f62.webp', 773, 800, 1, 100632, 40, 500, 1, '2026-04-17 04:51:49', NULL),
(81, 1, 'meat_meat_ffda1a', 'meat', 'meat', 'meat_meat_ffda1a.webp', '/slicehub/uploads/global_assets/meat_meat_ffda1a.webp', 781, 800, 1, 64124, 40, 500, 1, '2026-04-17 04:51:49', NULL),
(82, 1, 'sauce_sauce_063306', 'sauce', 'sauce', 'sauce_sauce_063306.webp', '/slicehub/uploads/global_assets/sauce_sauce_063306.webp', 800, 790, 1, 301872, 20, 500, 1, '2026-04-17 04:51:49', NULL),
(83, 1, 'sauce_sauce_1120f2', 'sauce', 'sauce', 'sauce_sauce_1120f2.webp', '/slicehub/uploads/global_assets/sauce_sauce_1120f2.webp', 800, 789, 1, 309592, 20, 500, 1, '2026-04-17 04:51:49', NULL),
(84, 1, 'sauce_sauce_11a09c', 'sauce', 'sauce', 'sauce_sauce_11a09c.webp', '/slicehub/uploads/global_assets/sauce_sauce_11a09c.webp', 800, 779, 1, 208008, 20, 500, 1, '2026-04-17 04:51:49', NULL),
(85, 1, 'sauce_sauce_24b8b3', 'sauce', 'sauce', 'sauce_sauce_24b8b3.webp', '/slicehub/uploads/global_assets/sauce_sauce_24b8b3.webp', 800, 735, 1, 261490, 20, 500, 1, '2026-04-17 04:51:49', NULL),
(86, 1, 'sauce_sauce_27811d', 'sauce', 'sauce', 'sauce_sauce_27811d.webp', '/slicehub/uploads/global_assets/sauce_sauce_27811d.webp', 800, 705, 1, 175560, 20, 500, 1, '2026-04-17 04:51:49', NULL),
(87, 1, 'sauce_sauce_28f490', 'sauce', 'sauce', 'sauce_sauce_28f490.webp', '/slicehub/uploads/global_assets/sauce_sauce_28f490.webp', 800, 745, 1, 261350, 20, 500, 1, '2026-04-17 04:51:49', NULL),
(88, 1, 'sauce_sauce_304f5a', 'sauce', 'sauce', 'sauce_sauce_304f5a.webp', '/slicehub/uploads/global_assets/sauce_sauce_304f5a.webp', 800, 781, 1, 221014, 20, 500, 1, '2026-04-17 04:51:49', NULL),
(89, 1, 'sauce_sauce_3ebc9a', 'sauce', 'sauce', 'sauce_sauce_3ebc9a.webp', '/slicehub/uploads/global_assets/sauce_sauce_3ebc9a.webp', 800, 774, 1, 174826, 20, 500, 1, '2026-04-17 04:51:49', NULL),
(90, 1, 'sauce_sauce_482f83', 'sauce', 'sauce', 'sauce_sauce_482f83.webp', '/slicehub/uploads/global_assets/sauce_sauce_482f83.webp', 800, 680, 1, 252806, 20, 500, 1, '2026-04-17 04:51:49', NULL),
(91, 1, 'sauce_sauce_4a3f9e', 'sauce', 'sauce', 'sauce_sauce_4a3f9e.webp', '/slicehub/uploads/global_assets/sauce_sauce_4a3f9e.webp', 800, 733, 1, 215172, 20, 500, 1, '2026-04-17 04:51:49', NULL),
(92, 1, 'sauce_sauce_4acd7c', 'sauce', 'sauce', 'sauce_sauce_4acd7c.webp', '/slicehub/uploads/global_assets/sauce_sauce_4acd7c.webp', 800, 747, 1, 278412, 20, 500, 1, '2026-04-17 04:51:49', NULL),
(93, 1, 'sauce_sauce_4ed0d1', 'sauce', 'sauce', 'sauce_sauce_4ed0d1.webp', '/slicehub/uploads/global_assets/sauce_sauce_4ed0d1.webp', 800, 764, 1, 271968, 20, 500, 1, '2026-04-17 04:51:49', NULL),
(94, 1, 'sauce_sauce_63e6f3', 'sauce', 'sauce', 'sauce_sauce_63e6f3.webp', '/slicehub/uploads/global_assets/sauce_sauce_63e6f3.webp', 800, 703, 1, 214218, 20, 500, 1, '2026-04-17 04:51:50', NULL),
(95, 1, 'sauce_sauce_6457cd', 'sauce', 'sauce', 'sauce_sauce_6457cd.webp', '/slicehub/uploads/global_assets/sauce_sauce_6457cd.webp', 800, 783, 1, 314862, 20, 500, 1, '2026-04-17 04:51:50', NULL),
(96, 1, 'sauce_sauce_664f5f', 'sauce', 'sauce', 'sauce_sauce_664f5f.webp', '/slicehub/uploads/global_assets/sauce_sauce_664f5f.webp', 800, 735, 1, 201834, 20, 500, 1, '2026-04-17 04:51:50', NULL),
(97, 1, 'sauce_sauce_6f245d', 'sauce', 'sauce', 'sauce_sauce_6f245d.webp', '/slicehub/uploads/global_assets/sauce_sauce_6f245d.webp', 800, 740, 1, 192538, 20, 500, 1, '2026-04-17 04:51:50', NULL),
(98, 1, 'sauce_sauce_7671e6', 'sauce', 'sauce', 'sauce_sauce_7671e6.webp', '/slicehub/uploads/global_assets/sauce_sauce_7671e6.webp', 800, 711, 1, 321342, 20, 500, 1, '2026-04-17 04:51:50', NULL),
(99, 1, 'sauce_sauce_857d17', 'sauce', 'sauce', 'sauce_sauce_857d17.webp', '/slicehub/uploads/global_assets/sauce_sauce_857d17.webp', 800, 759, 1, 255256, 20, 500, 1, '2026-04-17 04:51:50', NULL),
(100, 1, 'sauce_sauce_92673a', 'sauce', 'sauce', 'sauce_sauce_92673a.webp', '/slicehub/uploads/global_assets/sauce_sauce_92673a.webp', 800, 697, 1, 162128, 20, 500, 1, '2026-04-17 04:51:50', NULL),
(101, 1, 'sauce_sauce_9b9fd9', 'sauce', 'sauce', 'sauce_sauce_9b9fd9.webp', '/slicehub/uploads/global_assets/sauce_sauce_9b9fd9.webp', 742, 800, 1, 261518, 20, 500, 1, '2026-04-17 04:51:50', NULL),
(102, 1, 'sauce_sauce_a935c0', 'sauce', 'sauce', 'sauce_sauce_a935c0.webp', '/slicehub/uploads/global_assets/sauce_sauce_a935c0.webp', 795, 800, 1, 251198, 20, 500, 1, '2026-04-17 04:51:50', NULL),
(103, 1, 'sauce_sauce_b7d179', 'sauce', 'sauce', 'sauce_sauce_b7d179.webp', '/slicehub/uploads/global_assets/sauce_sauce_b7d179.webp', 800, 786, 1, 312826, 20, 500, 1, '2026-04-17 04:51:50', NULL),
(104, 1, 'sauce_sauce_b92494', 'sauce', 'sauce', 'sauce_sauce_b92494.webp', '/slicehub/uploads/global_assets/sauce_sauce_b92494.webp', 800, 740, 1, 278296, 20, 500, 1, '2026-04-17 04:51:50', NULL),
(105, 1, 'sauce_sauce_ba3a64', 'sauce', 'sauce', 'sauce_sauce_ba3a64.webp', '/slicehub/uploads/global_assets/sauce_sauce_ba3a64.webp', 800, 756, 1, 305496, 20, 500, 1, '2026-04-17 04:51:50', NULL),
(106, 1, 'sauce_sauce_bdcf26', 'sauce', 'sauce', 'sauce_sauce_bdcf26.webp', '/slicehub/uploads/global_assets/sauce_sauce_bdcf26.webp', 800, 791, 1, 416732, 20, 500, 1, '2026-04-17 04:51:50', NULL),
(107, 1, 'sauce_sauce_c05e7d', 'sauce', 'sauce', 'sauce_sauce_c05e7d.webp', '/slicehub/uploads/global_assets/sauce_sauce_c05e7d.webp', 800, 779, 1, 398720, 20, 500, 1, '2026-04-17 04:51:50', NULL),
(108, 1, 'sauce_sauce_c55316', 'sauce', 'sauce', 'sauce_sauce_c55316.webp', '/slicehub/uploads/global_assets/sauce_sauce_c55316.webp', 800, 783, 1, 239022, 20, 500, 1, '2026-04-17 04:51:50', NULL),
(109, 1, 'sauce_sauce_e428b7', 'sauce', 'sauce', 'sauce_sauce_e428b7.webp', '/slicehub/uploads/global_assets/sauce_sauce_e428b7.webp', 800, 763, 1, 141060, 20, 500, 1, '2026-04-17 04:51:50', NULL),
(110, 1, 'sauce_sauce_e98aa3', 'sauce', 'sauce', 'sauce_sauce_e98aa3.webp', '/slicehub/uploads/global_assets/sauce_sauce_e98aa3.webp', 800, 701, 1, 372250, 20, 500, 1, '2026-04-17 04:51:50', NULL),
(111, 1, 'sauce_sauce_ecff9f', 'sauce', 'sauce', 'sauce_sauce_ecff9f.webp', '/slicehub/uploads/global_assets/sauce_sauce_ecff9f.webp', 800, 678, 1, 300516, 20, 500, 1, '2026-04-17 04:51:50', NULL),
(112, 1, 'veg_veg_0bd47b', 'veg', 'veg', 'veg_veg_0bd47b.webp', '/slicehub/uploads/global_assets/veg_veg_0bd47b.webp', 800, 718, 1, 53130, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(113, 1, 'veg_veg_126e8f', 'veg', 'veg', 'veg_veg_126e8f.webp', '/slicehub/uploads/global_assets/veg_veg_126e8f.webp', 800, 727, 1, 66256, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(114, 1, 'veg_veg_19e6d4', 'veg', 'veg', 'veg_veg_19e6d4.webp', '/slicehub/uploads/global_assets/veg_veg_19e6d4.webp', 520, 800, 1, 40662, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(115, 1, 'veg_veg_1a9a10', 'veg', 'veg', 'veg_veg_1a9a10.webp', '/slicehub/uploads/global_assets/veg_veg_1a9a10.webp', 525, 800, 1, 37392, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(116, 1, 'veg_veg_1ec940', 'veg', 'veg', 'veg_veg_1ec940.webp', '/slicehub/uploads/global_assets/veg_veg_1ec940.webp', 778, 800, 1, 73556, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(117, 1, 'veg_veg_20c5fc', 'veg', 'veg', 'veg_veg_20c5fc.webp', '/slicehub/uploads/global_assets/veg_veg_20c5fc.webp', 800, 740, 1, 73652, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(118, 1, 'veg_veg_216c28', 'veg', 'veg', 'veg_veg_216c28.webp', '/slicehub/uploads/global_assets/veg_veg_216c28.webp', 800, 764, 1, 75200, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(119, 1, 'veg_veg_28ee20', 'veg', 'veg', 'veg_veg_28ee20.webp', '/slicehub/uploads/global_assets/veg_veg_28ee20.webp', 800, 596, 1, 38760, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(120, 1, 'veg_veg_31d16a', 'veg', 'veg', 'veg_veg_31d16a.webp', '/slicehub/uploads/global_assets/veg_veg_31d16a.webp', 800, 784, 1, 35844, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(121, 1, 'veg_veg_32d306', 'veg', 'veg', 'veg_veg_32d306.webp', '/slicehub/uploads/global_assets/veg_veg_32d306.webp', 800, 363, 1, 22472, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(122, 1, 'veg_veg_4071d9', 'veg', 'veg', 'veg_veg_4071d9.webp', '/slicehub/uploads/global_assets/veg_veg_4071d9.webp', 800, 613, 1, 50826, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(123, 1, 'veg_veg_42859f', 'veg', 'veg', 'veg_veg_42859f.webp', '/slicehub/uploads/global_assets/veg_veg_42859f.webp', 800, 760, 1, 71722, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(124, 1, 'veg_veg_469551', 'veg', 'veg', 'veg_veg_469551.webp', '/slicehub/uploads/global_assets/veg_veg_469551.webp', 800, 700, 1, 38736, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(125, 1, 'veg_veg_46b9fa', 'veg', 'veg', 'veg_veg_46b9fa.webp', '/slicehub/uploads/global_assets/veg_veg_46b9fa.webp', 800, 696, 1, 40088, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(126, 1, 'veg_veg_474002', 'veg', 'veg', 'veg_veg_474002.webp', '/slicehub/uploads/global_assets/veg_veg_474002.webp', 800, 645, 1, 45800, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(127, 1, 'veg_veg_49ed87', 'veg', 'veg', 'veg_veg_49ed87.webp', '/slicehub/uploads/global_assets/veg_veg_49ed87.webp', 800, 765, 1, 36662, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(128, 1, 'veg_veg_4bc7a2', 'veg', 'veg', 'veg_veg_4bc7a2.webp', '/slicehub/uploads/global_assets/veg_veg_4bc7a2.webp', 664, 800, 1, 50918, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(129, 1, 'veg_veg_4d89f7', 'veg', 'veg', 'veg_veg_4d89f7.webp', '/slicehub/uploads/global_assets/veg_veg_4d89f7.webp', 789, 800, 1, 39672, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(130, 1, 'veg_veg_597ef5', 'veg', 'veg', 'veg_veg_597ef5.webp', '/slicehub/uploads/global_assets/veg_veg_597ef5.webp', 747, 800, 1, 39570, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(131, 1, 'veg_veg_5bbf68', 'veg', 'veg', 'veg_veg_5bbf68.webp', '/slicehub/uploads/global_assets/veg_veg_5bbf68.webp', 800, 790, 1, 74222, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(132, 1, 'veg_veg_5d6a1d', 'veg', 'veg', 'veg_veg_5d6a1d.webp', '/slicehub/uploads/global_assets/veg_veg_5d6a1d.webp', 513, 800, 1, 26950, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(133, 1, 'veg_veg_616339', 'veg', 'veg', 'veg_veg_616339.webp', '/slicehub/uploads/global_assets/veg_veg_616339.webp', 750, 800, 1, 59384, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(134, 1, 'veg_veg_620d10', 'veg', 'veg', 'veg_veg_620d10.webp', '/slicehub/uploads/global_assets/veg_veg_620d10.webp', 799, 800, 1, 49070, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(135, 1, 'veg_veg_649e1e', 'veg', 'veg', 'veg_veg_649e1e.webp', '/slicehub/uploads/global_assets/veg_veg_649e1e.webp', 800, 794, 1, 58426, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(136, 1, 'veg_veg_6655f6', 'veg', 'veg', 'veg_veg_6655f6.webp', '/slicehub/uploads/global_assets/veg_veg_6655f6.webp', 800, 606, 1, 22312, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(137, 1, 'veg_veg_70c17d', 'veg', 'veg', 'veg_veg_70c17d.webp', '/slicehub/uploads/global_assets/veg_veg_70c17d.webp', 758, 800, 1, 65376, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(138, 1, 'veg_veg_723df7', 'veg', 'veg', 'veg_veg_723df7.webp', '/slicehub/uploads/global_assets/veg_veg_723df7.webp', 800, 786, 1, 41758, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(139, 1, 'veg_veg_72cf73', 'veg', 'veg', 'veg_veg_72cf73.webp', '/slicehub/uploads/global_assets/veg_veg_72cf73.webp', 800, 683, 1, 66596, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(140, 1, 'veg_veg_7645fb', 'veg', 'veg', 'veg_veg_7645fb.webp', '/slicehub/uploads/global_assets/veg_veg_7645fb.webp', 800, 705, 1, 48886, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(141, 1, 'veg_veg_76d3ae', 'veg', 'veg', 'veg_veg_76d3ae.webp', '/slicehub/uploads/global_assets/veg_veg_76d3ae.webp', 800, 796, 1, 41396, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(142, 1, 'veg_veg_7c8858', 'veg', 'veg', 'veg_veg_7c8858.webp', '/slicehub/uploads/global_assets/veg_veg_7c8858.webp', 800, 723, 1, 31386, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(143, 1, 'veg_veg_825ea7', 'veg', 'veg', 'veg_veg_825ea7.webp', '/slicehub/uploads/global_assets/veg_veg_825ea7.webp', 727, 800, 1, 39162, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(144, 1, 'veg_veg_890792', 'veg', 'veg', 'veg_veg_890792.webp', '/slicehub/uploads/global_assets/veg_veg_890792.webp', 800, 573, 1, 36616, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(145, 1, 'veg_veg_8a05aa', 'veg', 'veg', 'veg_veg_8a05aa.webp', '/slicehub/uploads/global_assets/veg_veg_8a05aa.webp', 800, 789, 1, 81296, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(146, 1, 'veg_veg_8cda35', 'veg', 'veg', 'veg_veg_8cda35.webp', '/slicehub/uploads/global_assets/veg_veg_8cda35.webp', 800, 787, 1, 131098, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(147, 1, 'veg_veg_91039c', 'veg', 'veg', 'veg_veg_91039c.webp', '/slicehub/uploads/global_assets/veg_veg_91039c.webp', 800, 585, 1, 34576, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(148, 1, 'veg_veg_916abc', 'veg', 'veg', 'veg_veg_916abc.webp', '/slicehub/uploads/global_assets/veg_veg_916abc.webp', 746, 800, 1, 68190, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(149, 1, 'veg_veg_9dc047', 'veg', 'veg', 'veg_veg_9dc047.webp', '/slicehub/uploads/global_assets/veg_veg_9dc047.webp', 800, 565, 1, 33904, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(150, 1, 'veg_veg_a4aa2e', 'veg', 'veg', 'veg_veg_a4aa2e.webp', '/slicehub/uploads/global_assets/veg_veg_a4aa2e.webp', 728, 800, 1, 35362, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(151, 1, 'veg_veg_b6a03d', 'veg', 'veg', 'veg_veg_b6a03d.webp', '/slicehub/uploads/global_assets/veg_veg_b6a03d.webp', 758, 800, 1, 63288, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(152, 1, 'veg_veg_b6d8a7', 'veg', 'veg', 'veg_veg_b6d8a7.webp', '/slicehub/uploads/global_assets/veg_veg_b6d8a7.webp', 800, 719, 1, 38344, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(153, 1, 'veg_veg_b8a8a0', 'veg', 'veg', 'veg_veg_b8a8a0.webp', '/slicehub/uploads/global_assets/veg_veg_b8a8a0.webp', 800, 431, 1, 19864, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(154, 1, 'veg_veg_b9536c', 'veg', 'veg', 'veg_veg_b9536c.webp', '/slicehub/uploads/global_assets/veg_veg_b9536c.webp', 800, 789, 1, 65466, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(155, 1, 'veg_veg_bcd19c', 'veg', 'veg', 'veg_veg_bcd19c.webp', '/slicehub/uploads/global_assets/veg_veg_bcd19c.webp', 800, 433, 1, 19650, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(156, 1, 'veg_veg_be4c65', 'veg', 'veg', 'veg_veg_be4c65.webp', '/slicehub/uploads/global_assets/veg_veg_be4c65.webp', 800, 800, 1, 130514, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(157, 1, 'veg_veg_c52e77', 'veg', 'veg', 'veg_veg_c52e77.webp', '/slicehub/uploads/global_assets/veg_veg_c52e77.webp', 784, 800, 1, 65508, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(158, 1, 'veg_veg_c5cc4b', 'veg', 'veg', 'veg_veg_c5cc4b.webp', '/slicehub/uploads/global_assets/veg_veg_c5cc4b.webp', 504, 800, 1, 27494, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(159, 1, 'veg_veg_d13b84', 'veg', 'veg', 'veg_veg_d13b84.webp', '/slicehub/uploads/global_assets/veg_veg_d13b84.webp', 800, 766, 1, 78512, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(160, 1, 'veg_veg_d32f2f', 'veg', 'veg', 'veg_veg_d32f2f.webp', '/slicehub/uploads/global_assets/veg_veg_d32f2f.webp', 489, 800, 1, 28210, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(161, 1, 'veg_veg_db22c7', 'veg', 'veg', 'veg_veg_db22c7.webp', '/slicehub/uploads/global_assets/veg_veg_db22c7.webp', 381, 800, 1, 21728, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(162, 1, 'veg_veg_db3185', 'veg', 'veg', 'veg_veg_db3185.webp', '/slicehub/uploads/global_assets/veg_veg_db3185.webp', 312, 800, 1, 27092, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(163, 1, 'veg_veg_def2d6', 'veg', 'veg', 'veg_veg_def2d6.webp', '/slicehub/uploads/global_assets/veg_veg_def2d6.webp', 719, 800, 1, 31542, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(164, 1, 'veg_veg_e51a6c', 'veg', 'veg', 'veg_veg_e51a6c.webp', '/slicehub/uploads/global_assets/veg_veg_e51a6c.webp', 800, 724, 1, 52566, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(165, 1, 'veg_veg_f16843', 'veg', 'veg', 'veg_veg_f16843.webp', '/slicehub/uploads/global_assets/veg_veg_f16843.webp', 758, 800, 1, 59478, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(166, 1, 'veg_veg_f2f26c', 'veg', 'veg', 'veg_veg_f2f26c.webp', '/slicehub/uploads/global_assets/veg_veg_f2f26c.webp', 800, 772, 1, 41878, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(167, 1, 'veg_veg_f71cf5', 'veg', 'veg', 'veg_veg_f71cf5.webp', '/slicehub/uploads/global_assets/veg_veg_f71cf5.webp', 758, 800, 1, 86772, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(168, 1, 'veg_veg_f7fec4', 'veg', 'veg', 'veg_veg_f7fec4.webp', '/slicehub/uploads/global_assets/veg_veg_f7fec4.webp', 789, 800, 1, 58234, 50, 500, 1, '2026-04-17 04:51:50', NULL),
(169, 1, 'surface_wood_plank_v1', 'misc', 'surface', 'surface_wood_plank_v1.jpg', '/slicehub/uploads/global_assets/surface_wood_plank_v1.jpg', 5184, 3456, 1, 18425460, 999, 500, 1, '2026-04-17 04:51:50', NULL),
(170, 1, 'board___0010_13_wynik_be6f85', 'base', '__0010_13_wynik', 'board___0010_13_wynik_be6f85.webp', '/slicehub/uploads/global_assets/board___0010_13_wynik_be6f85.webp', 1500, 1000, 1, 396566, 0, 500, 1, '2026-04-17 04:55:56', '2026-04-17 06:36:18'),
(171, 1, 'extra_sos_czosnkowy_thermomaniakpl_removebg_preview_wynik_177650', 'extra', 'sos_czosnkowy_thermomaniakpl_removebg_preview_wynik', 'extra_sos_czosnkowy_thermomaniakpl_removebg_preview_wynik_177650.webp', '/slicehub/uploads/global_assets/extra_sos_czosnkowy_thermomaniakpl_removebg_preview_wynik_177650.webp', 1500, 1123, 1, 50560, 70, 500, 1, '2026-04-17 13:30:02', NULL);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_integration_attempts`
--

CREATE TABLE `sh_integration_attempts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `delivery_id` bigint(20) UNSIGNED NOT NULL COMMENT 'FK → sh_integration_deliveries.id',
  `attempt_number` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `http_code` smallint(5) UNSIGNED DEFAULT NULL,
  `duration_ms` int(10) UNSIGNED DEFAULT NULL,
  `request_snippet` varchar(500) DEFAULT NULL COMMENT 'First 500 chars of request body (full snapshot w parent row)',
  `response_body` text DEFAULT NULL COMMENT 'Truncated 2KB',
  `error_message` text DEFAULT NULL,
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Per-attempt audit log (m028)';

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_integration_deliveries`
--

CREATE TABLE `sh_integration_deliveries` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `event_id` bigint(20) UNSIGNED NOT NULL COMMENT 'FK → sh_event_outbox.id',
  `integration_id` int(10) UNSIGNED NOT NULL COMMENT 'FK → sh_tenant_integrations.id',
  `provider` varchar(32) NOT NULL COMMENT 'Denormalized (papu|dotykacka|gastrosoft|custom) dla szybkich queries/filtrów',
  `aggregate_id` varchar(64) NOT NULL COMMENT 'Denormalized order UUID dla debugowania (join-free read)',
  `event_type` varchar(64) NOT NULL COMMENT 'Denormalized (order.created, order.accepted, ...)',
  `status` enum('pending','delivering','delivered','failed','dead') NOT NULL DEFAULT 'pending',
  `attempts` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `next_attempt_at` datetime DEFAULT NULL COMMENT 'Kiedy spróbować ponownie (exponential backoff)',
  `last_error` text DEFAULT NULL,
  `http_code` smallint(5) UNSIGNED DEFAULT NULL COMMENT 'Ostatni HTTP code z 3rd-party (debug)',
  `duration_ms` int(10) UNSIGNED DEFAULT NULL,
  `external_ref` varchar(128) DEFAULT NULL COMMENT 'ID zamówienia po stronie 3rd-party (zwrócone przy success) np. Papu.io order_id',
  `request_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Snapshot ostatniego request payloadu (per-adapter shape, NIE nasz envelope) — debug' CHECK (json_valid(`request_payload`)),
  `response_body` text DEFAULT NULL COMMENT 'Truncated do 2KB dla debugowania',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_attempted_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Per-(event,integration) delivery state — async 3rd-party adapters (m028)';

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_integration_logs`
--

CREATE TABLE `sh_integration_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `order_id` char(36) DEFAULT NULL,
  `provider` varchar(32) NOT NULL DEFAULT 'papu',
  `http_code` smallint(5) UNSIGNED DEFAULT NULL,
  `request_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`request_payload`)),
  `response_body` text DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_item_modifiers`
--

CREATE TABLE `sh_item_modifiers` (
  `item_id` bigint(20) UNSIGNED NOT NULL,
  `group_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sh_item_modifiers`
--

INSERT INTO `sh_item_modifiers` (`item_id`, `group_id`) VALUES
(1, 1),
(1, 2),
(2, 1),
(2, 2),
(3, 1),
(3, 2),
(4, 1),
(4, 2),
(5, 1),
(5, 2),
(6, 1),
(6, 2),
(7, 1),
(7, 2),
(8, 1),
(8, 2),
(9, 1),
(9, 2),
(10, 1),
(10, 2),
(11, 3),
(11, 4),
(12, 3),
(12, 4),
(13, 3),
(13, 4),
(14, 3),
(14, 4),
(15, 3),
(15, 4);

-- --------------------------------------------------------

--
-- Zastąpiona struktura widoku `sh_item_prices`
-- (See below for the actual view)
--
CREATE TABLE `sh_item_prices` (
`tenant_id` int(10) unsigned
,`item_sku` varchar(255)
,`channel` varchar(32)
,`price` decimal(10,2)
);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_kds_tickets`
--

CREATE TABLE `sh_kds_tickets` (
  `id` char(36) NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `order_id` char(36) NOT NULL,
  `station_id` varchar(64) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_meals`
--

CREATE TABLE `sh_meals` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `employee_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_menu_items`
--

CREATE TABLE `sh_menu_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `category_id` bigint(20) UNSIGNED DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `ascii_key` varchar(255) NOT NULL,
  `type` varchar(32) NOT NULL DEFAULT 'standard',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `vat_rate_dine_in` decimal(6,2) NOT NULL DEFAULT 0.00,
  `vat_rate_takeaway` decimal(6,2) NOT NULL DEFAULT 0.00,
  `kds_station_id` varchar(64) DEFAULT NULL,
  `publication_status` varchar(32) DEFAULT NULL,
  `valid_from` date DEFAULT NULL,
  `valid_to` date DEFAULT NULL,
  `description` text DEFAULT NULL,
  `image_url` varchar(512) DEFAULT NULL COMMENT 'DEPRECATED_M021: cień-zapis; canonical w sh_assets via sh_asset_links(menu_item,hero). Do DROP po migracji API.',
  `composition_profile` varchar(64) DEFAULT 'static_hero' COMMENT 'M022: FK logiczny do sh_scene_templates.ascii_key',
  `marketing_tags` varchar(512) DEFAULT NULL,
  `barcode_ean` varchar(64) DEFAULT NULL,
  `parent_sku` varchar(255) DEFAULT NULL,
  `allergens_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`allergens_json`)),
  `badge_type` varchar(32) DEFAULT NULL,
  `is_secret` tinyint(1) NOT NULL DEFAULT 0,
  `stock_count` int(11) DEFAULT 0,
  `is_locked_by_hq` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `printer_group` varchar(64) DEFAULT NULL,
  `plu_code` varchar(32) DEFAULT NULL,
  `available_days` varchar(32) DEFAULT '1,2,3,4,5,6,7',
  `available_start` time DEFAULT NULL,
  `available_end` time DEFAULT NULL,
  `driver_action_type` enum('none','pack_cold','pack_separate','check_id') NOT NULL DEFAULT 'none'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sh_menu_items`
--

INSERT INTO `sh_menu_items` (`id`, `tenant_id`, `category_id`, `name`, `ascii_key`, `type`, `is_active`, `is_deleted`, `display_order`, `vat_rate_dine_in`, `vat_rate_takeaway`, `kds_station_id`, `publication_status`, `valid_from`, `valid_to`, `description`, `image_url`, `composition_profile`, `marketing_tags`, `barcode_ean`, `parent_sku`, `allergens_json`, `badge_type`, `is_secret`, `stock_count`, `is_locked_by_hq`, `created_at`, `updated_at`, `printer_group`, `plu_code`, `available_days`, `available_start`, `available_end`, `driver_action_type`) VALUES
(1, 1, 1, 'Margherita', 'PIZZA_MARGHERITA', 'standard', 1, 0, 1, 8.00, 5.00, 'PIZZA', 'Draft', NULL, NULL, '', '', 'static_hero', '', NULL, NULL, '[]', 'none', 0, 0, 0, '2026-04-13 15:08:40', '2026-04-16 12:57:04', 'KITCHEN_1', NULL, '1,2,3,4,5,6,7', NULL, NULL, 'none'),
(2, 1, 1, 'Pepperoni', 'PIZZA_PEPPERONI', 'standard', 1, 0, 2, 8.00, 5.00, 'PIZZA', NULL, NULL, NULL, NULL, NULL, 'static_hero', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, '2026-04-13 15:08:40', NULL, NULL, NULL, '1,2,3,4,5,6,7', NULL, NULL, 'none'),
(3, 1, 1, 'Capricciosa', 'PIZZA_CAPRICCIOSA', 'standard', 1, 0, 3, 8.00, 5.00, 'PIZZA', NULL, NULL, NULL, NULL, NULL, 'static_hero', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, '2026-04-13 15:08:40', NULL, NULL, NULL, '1,2,3,4,5,6,7', NULL, NULL, 'none'),
(4, 1, 1, 'Hawajska', 'PIZZA_HAWAJSKA', 'standard', 1, 0, 4, 8.00, 5.00, 'PIZZA', NULL, NULL, NULL, NULL, NULL, 'static_hero', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, '2026-04-13 15:08:40', NULL, NULL, NULL, '1,2,3,4,5,6,7', NULL, NULL, 'none'),
(5, 1, 1, 'Quattro Formaggi', 'PIZZA_4FORMAGGI', 'standard', 1, 0, 5, 8.00, 5.00, 'PIZZA', NULL, NULL, NULL, NULL, NULL, 'static_hero', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, '2026-04-13 15:08:40', NULL, NULL, NULL, '1,2,3,4,5,6,7', NULL, NULL, 'none'),
(6, 1, 1, 'Diavola', 'PIZZA_DIAVOLA', 'standard', 1, 0, 6, 8.00, 5.00, 'PIZZA', NULL, NULL, NULL, NULL, NULL, 'static_hero', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, '2026-04-13 15:08:40', NULL, NULL, NULL, '1,2,3,4,5,6,7', NULL, NULL, 'none'),
(7, 1, 1, 'Vegetariana', 'PIZZA_VEGETARIANA', 'standard', 1, 0, 7, 8.00, 5.00, 'PIZZA', NULL, NULL, NULL, NULL, NULL, 'static_hero', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, '2026-04-13 15:08:40', NULL, NULL, NULL, '1,2,3,4,5,6,7', NULL, NULL, 'none'),
(8, 1, 1, 'BBQ Chicken', 'PIZZA_BBQ_CHICKEN', 'standard', 1, 0, 8, 8.00, 5.00, 'PIZZA', NULL, NULL, NULL, NULL, NULL, 'static_hero', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, '2026-04-13 15:08:40', NULL, NULL, NULL, '1,2,3,4,5,6,7', NULL, NULL, 'none'),
(9, 1, 1, 'Prosciutto e Funghi', 'PIZZA_PROSC_FUNGHI', 'standard', 1, 0, 9, 8.00, 5.00, 'PIZZA', NULL, NULL, NULL, NULL, NULL, 'static_hero', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, '2026-04-13 15:08:40', NULL, NULL, NULL, '1,2,3,4,5,6,7', NULL, NULL, 'none'),
(10, 1, 1, 'Calzone', 'PIZZA_CALZONE', 'standard', 1, 0, 10, 8.00, 5.00, 'PIZZA', NULL, NULL, NULL, NULL, NULL, 'static_hero', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, '2026-04-13 15:08:40', NULL, NULL, NULL, '1,2,3,4,5,6,7', NULL, NULL, 'none'),
(11, 1, 2, 'Classic Burger', 'BURGER_CLASSIC', 'standard', 1, 0, 11, 8.00, 5.00, 'GRILL', NULL, NULL, NULL, NULL, NULL, 'static_hero', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, '2026-04-13 15:08:40', NULL, NULL, NULL, '1,2,3,4,5,6,7', NULL, NULL, 'none'),
(12, 1, 2, 'Cheese Burger', 'BURGER_CHEESE', 'standard', 1, 0, 12, 8.00, 5.00, 'GRILL', NULL, NULL, NULL, NULL, NULL, 'static_hero', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, '2026-04-13 15:08:40', NULL, NULL, NULL, '1,2,3,4,5,6,7', NULL, NULL, 'none'),
(13, 1, 2, 'BBQ Burger', 'BURGER_BBQ', 'standard', 1, 0, 13, 8.00, 5.00, 'GRILL', NULL, NULL, NULL, NULL, NULL, 'static_hero', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, '2026-04-13 15:08:40', NULL, NULL, NULL, '1,2,3,4,5,6,7', NULL, NULL, 'none'),
(14, 1, 2, 'Chicken Burger', 'BURGER_CHICKEN', 'standard', 1, 0, 14, 8.00, 5.00, 'GRILL', NULL, NULL, NULL, NULL, NULL, 'static_hero', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, '2026-04-13 15:08:40', NULL, NULL, NULL, '1,2,3,4,5,6,7', NULL, NULL, 'none'),
(15, 1, 2, 'Veggie Burger', 'BURGER_VEGGIE', 'standard', 1, 0, 15, 8.00, 5.00, 'GRILL', NULL, NULL, NULL, NULL, NULL, 'static_hero', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, '2026-04-13 15:08:40', NULL, NULL, NULL, '1,2,3,4,5,6,7', NULL, NULL, 'none'),
(16, 1, 3, 'Spaghetti Bolognese', 'PASTA_BOLOGNESE', 'standard', 1, 0, 16, 8.00, 5.00, 'PASTA', NULL, NULL, NULL, NULL, NULL, 'static_hero', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, '2026-04-13 15:08:40', NULL, NULL, NULL, '1,2,3,4,5,6,7', NULL, NULL, 'none'),
(17, 1, 3, 'Penne Carbonara', 'PASTA_CARBONARA', 'standard', 1, 0, 17, 8.00, 5.00, 'PASTA', NULL, NULL, NULL, NULL, NULL, 'static_hero', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, '2026-04-13 15:08:40', NULL, NULL, NULL, '1,2,3,4,5,6,7', NULL, NULL, 'none'),
(18, 1, 3, 'Lasagne', 'PASTA_LASAGNE', 'standard', 1, 0, 18, 8.00, 5.00, 'PASTA', NULL, NULL, NULL, NULL, NULL, 'static_hero', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, '2026-04-13 15:08:40', NULL, NULL, NULL, '1,2,3,4,5,6,7', NULL, NULL, 'none'),
(19, 1, 4, 'Sałatka Cezar', 'SALAD_CAESAR', 'standard', 1, 0, 19, 8.00, 5.00, 'COLD', NULL, NULL, NULL, NULL, NULL, 'static_hero', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, '2026-04-13 15:08:40', NULL, NULL, NULL, '1,2,3,4,5,6,7', NULL, NULL, 'none'),
(20, 1, 4, 'Sałatka Grecka', 'SALAD_GREEK', 'standard', 1, 0, 20, 8.00, 5.00, 'COLD', NULL, NULL, NULL, NULL, NULL, 'static_hero', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, '2026-04-13 15:08:40', NULL, NULL, NULL, '1,2,3,4,5,6,7', NULL, NULL, 'none'),
(21, 1, 5, 'Coca-Cola 0.5L', 'DRINK_COLA_05', 'standard', 1, 0, 21, 23.00, 23.00, NULL, NULL, NULL, NULL, NULL, NULL, 'static_hero', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, '2026-04-13 15:08:40', NULL, NULL, NULL, '1,2,3,4,5,6,7', NULL, NULL, 'none'),
(22, 1, 5, 'Sprite 0.5L', 'DRINK_SPRITE_05', 'standard', 1, 0, 22, 23.00, 23.00, NULL, NULL, NULL, NULL, NULL, NULL, 'static_hero', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, '2026-04-13 15:08:40', NULL, NULL, NULL, '1,2,3,4,5,6,7', NULL, NULL, 'none'),
(23, 1, 5, 'Woda mineralna 0.5L', 'DRINK_WATER_05', 'standard', 1, 0, 23, 23.00, 23.00, NULL, NULL, NULL, NULL, NULL, NULL, 'static_hero', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, '2026-04-13 15:08:40', NULL, NULL, NULL, '1,2,3,4,5,6,7', NULL, NULL, 'none'),
(24, 1, 5, 'Sok pomarańczowy', 'DRINK_JUICE_ORANGE', 'standard', 1, 0, 24, 23.00, 23.00, NULL, NULL, NULL, NULL, NULL, NULL, 'static_hero', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, '2026-04-13 15:08:40', NULL, NULL, NULL, '1,2,3,4,5,6,7', NULL, NULL, 'none'),
(25, 1, 5, 'Piwo Tyskie 0.5L', 'DRINK_BEER_TYSKIE', 'standard', 1, 0, 25, 23.00, 23.00, NULL, NULL, NULL, NULL, NULL, NULL, 'static_hero', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, '2026-04-13 15:08:40', NULL, NULL, NULL, '1,2,3,4,5,6,7', NULL, NULL, 'none'),
(26, 1, 6, 'Frytki', 'SIDE_FRIES', 'standard', 1, 0, 26, 8.00, 5.00, 'GRILL', NULL, NULL, NULL, NULL, NULL, 'static_hero', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, '2026-04-13 15:08:40', NULL, NULL, NULL, '1,2,3,4,5,6,7', NULL, NULL, 'none'),
(27, 1, 6, 'Sos czosnkowy', 'SIDE_GARLIC_SAUCE', 'standard', 1, 0, 27, 8.00, 5.00, NULL, 'Draft', NULL, NULL, '', '', 'static_hero', '', NULL, NULL, '[]', 'none', 0, 0, 0, '2026-04-13 15:08:40', '2026-04-14 06:01:37', 'KITCHEN_1', NULL, '1,2,3,4,5,6,7', NULL, NULL, 'pack_cold'),
(28, 1, 6, 'Krążki cebulowe', 'SIDE_ONION_RINGS', 'standard', 1, 0, 28, 8.00, 5.00, 'GRILL', NULL, NULL, NULL, NULL, NULL, 'static_hero', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, '2026-04-13 15:08:40', NULL, NULL, NULL, '1,2,3,4,5,6,7', NULL, NULL, 'none'),
(29, 1, 6, 'Nuggetsy 6szt', 'SIDE_NUGGETS_6', 'standard', 1, 0, 29, 8.00, 5.00, 'GRILL', NULL, NULL, NULL, NULL, NULL, 'static_hero', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, '2026-04-13 15:08:40', NULL, NULL, NULL, '1,2,3,4,5,6,7', NULL, NULL, 'none'),
(30, 1, 7, 'Tiramisu', 'DESSERT_TIRAMISU', 'standard', 1, 0, 30, 8.00, 5.00, 'COLD', NULL, NULL, NULL, NULL, NULL, 'static_hero', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, '2026-04-13 15:08:40', NULL, NULL, NULL, '1,2,3,4,5,6,7', NULL, NULL, 'none'),
(31, 1, 7, 'Panna Cotta', 'DESSERT_PANNA_COTTA', 'standard', 1, 0, 31, 8.00, 5.00, 'COLD', NULL, NULL, NULL, NULL, NULL, 'static_hero', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, '2026-04-13 15:08:40', NULL, NULL, NULL, '1,2,3,4,5,6,7', NULL, NULL, 'none'),
(32, 1, 8, 'Zestaw Lunch (pizza+napój)', 'SET_LUNCH_PIZZA', 'standard', 1, 0, 32, 8.00, 5.00, 'PIZZA', NULL, NULL, NULL, NULL, NULL, 'static_hero', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, '2026-04-13 15:08:40', NULL, NULL, NULL, '1,2,3,4,5,6,7', NULL, NULL, 'none'),
(33, 1, 8, 'Zestaw Burger+Frytki+Napój', 'SET_BURGER_COMBO', 'standard', 1, 0, 33, 8.00, 5.00, 'GRILL', NULL, NULL, NULL, NULL, NULL, 'static_hero', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, '2026-04-13 15:08:40', NULL, NULL, NULL, '1,2,3,4,5,6,7', NULL, NULL, 'none'),
(34, 1, NULL, 'Pepsi 0.5L', 'DRINK_PEPSI_500', 'standard', 1, 0, 900, 0.00, 0.00, NULL, NULL, NULL, NULL, 'Companion product for Cinematic Board', NULL, 'static_hero', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, '2026-04-16 04:55:27', NULL, NULL, NULL, '1,2,3,4,5,6,7', NULL, NULL, 'none'),
(35, 1, NULL, 'Sos czosnkowy', 'SAUCE_GARLIC', 'standard', 1, 0, 901, 0.00, 0.00, NULL, NULL, NULL, NULL, 'Companion product for Cinematic Board', NULL, 'static_hero', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, '2026-04-16 04:55:27', NULL, NULL, NULL, '1,2,3,4,5,6,7', NULL, NULL, 'none'),
(38, 1, NULL, 'Woda mineralna 0.5L', 'DRINK_WATER_500', 'standard', 1, 0, 904, 0.00, 0.00, NULL, NULL, NULL, NULL, 'Companion product for Cinematic Board', NULL, 'static_hero', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, '2026-04-16 04:55:27', NULL, NULL, NULL, '1,2,3,4,5,6,7', NULL, NULL, 'none'),
(39, 1, NULL, 'Sos BBQ', 'SAUCE_BBQ', 'standard', 1, 0, 905, 0.00, 0.00, NULL, NULL, NULL, NULL, 'Companion product for Cinematic Board', NULL, 'static_hero', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, '2026-04-16 04:55:27', NULL, NULL, NULL, '1,2,3,4,5,6,7', NULL, NULL, 'none');

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_modifiers`
--

CREATE TABLE `sh_modifiers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `group_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `ascii_key` varchar(255) NOT NULL,
  `action_type` varchar(32) NOT NULL DEFAULT 'ADD',
  `linked_warehouse_sku` varchar(128) DEFAULT NULL,
  `linked_quantity` decimal(10,4) NOT NULL DEFAULT 0.0000,
  `linked_waste_percent` decimal(10,4) NOT NULL DEFAULT 0.0000,
  `price` decimal(10,2) DEFAULT NULL COMMENT 'Optional denorm; tiers are canonical',
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `has_visual_impact` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sh_modifiers`
--

INSERT INTO `sh_modifiers` (`id`, `group_id`, `name`, `ascii_key`, `action_type`, `linked_warehouse_sku`, `linked_quantity`, `linked_waste_percent`, `price`, `is_default`, `is_deleted`, `is_active`, `has_visual_impact`) VALUES
(1, 1, 'Mała (25cm)', 'SIZE_S', 'ADD', NULL, 0.0000, 0.0000, 0.00, 0, 0, 1, 1),
(2, 1, 'Średnia (32cm)', 'SIZE_M', 'ADD', NULL, 0.0000, 0.0000, 0.00, 1, 0, 1, 1),
(3, 1, 'Duża (40cm)', 'SIZE_L', 'ADD', NULL, 0.0000, 0.0000, 6.00, 0, 0, 1, 1),
(4, 1, 'Rodzinna (50cm)', 'SIZE_XL', 'ADD', NULL, 0.0000, 0.0000, 14.00, 0, 0, 1, 1),
(5, 2, 'Podwójny ser', 'EXTRA_CHEESE', 'ADD', 'SER_MOZZ', 0.1000, 0.0000, 4.00, 0, 0, 1, 1),
(6, 2, 'Jalapeno', 'EXTRA_JALAP', 'ADD', 'JALAPENO', 0.0300, 0.0000, 3.00, 0, 0, 1, 1),
(7, 2, 'Oliwki', 'EXTRA_OLIVES', 'ADD', 'OLIWKI_CZ', 0.0300, 0.0000, 3.00, 0, 0, 1, 1),
(8, 2, 'Szynka', 'EXTRA_HAM', 'ADD', 'SZYNKA_PARM', 0.0500, 0.0000, 5.00, 0, 0, 1, 1),
(9, 3, 'Czosnkowy', 'SAUCE_GARLIC', 'ADD', 'SOS_CZOSN', 0.0300, 0.0000, 2.00, 0, 0, 1, 1),
(10, 3, 'BBQ', 'SAUCE_BBQ', 'ADD', 'SOS_BBQ', 0.0300, 0.0000, 2.00, 0, 0, 1, 1),
(11, 3, 'Ostry', 'SAUCE_HOT', 'ADD', 'SOS_OSTRY', 0.0300, 0.0000, 2.00, 0, 0, 1, 1),
(12, 4, 'Standard', 'BURG_STD', 'ADD', NULL, 0.0000, 0.0000, 0.00, 1, 0, 1, 1),
(13, 4, 'Double', 'BURG_DBL', 'ADD', NULL, 0.0000, 0.0000, 8.00, 0, 0, 1, 1);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_modifier_groups`
--

CREATE TABLE `sh_modifier_groups` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `ascii_key` varchar(255) DEFAULT NULL,
  `min_selection` int(11) NOT NULL DEFAULT 0,
  `max_selection` int(11) NOT NULL DEFAULT 0,
  `free_limit` int(11) NOT NULL DEFAULT 0,
  `allow_multi_qty` tinyint(1) NOT NULL DEFAULT 0,
  `publication_status` varchar(32) DEFAULT NULL,
  `valid_from` date DEFAULT NULL,
  `valid_to` date DEFAULT NULL,
  `is_locked_by_hq` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sh_modifier_groups`
--

INSERT INTO `sh_modifier_groups` (`id`, `tenant_id`, `name`, `ascii_key`, `min_selection`, `max_selection`, `free_limit`, `allow_multi_qty`, `publication_status`, `valid_from`, `valid_to`, `is_locked_by_hq`, `is_active`, `is_deleted`) VALUES
(1, 1, 'Rozmiar pizzy', 'SIZE_PIZZA', 1, 1, 0, 0, NULL, NULL, NULL, 0, 1, 0),
(2, 1, 'Dodatki do pizzy', 'EXTRA_PIZZA', 0, 5, 0, 0, NULL, NULL, NULL, 0, 1, 0),
(3, 1, 'Sosy', 'SAUCES', 0, 3, 1, 0, NULL, NULL, NULL, 0, 1, 0),
(4, 1, 'Rozmiar burgera', 'SIZE_BURGER', 1, 1, 0, 0, NULL, NULL, NULL, 0, 1, 0);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_orders`
--

CREATE TABLE `sh_orders` (
  `id` char(36) NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `order_number` varchar(64) NOT NULL,
  `channel` varchar(32) NOT NULL,
  `order_type` varchar(32) NOT NULL,
  `table_id` bigint(20) UNSIGNED DEFAULT NULL,
  `waiter_id` bigint(20) UNSIGNED DEFAULT NULL,
  `guest_count` tinyint(3) UNSIGNED DEFAULT NULL,
  `split_type` varchar(32) DEFAULT NULL COMMENT 'equal|by_item|custom',
  `qr_session_token` varchar(128) DEFAULT NULL,
  `source` varchar(32) DEFAULT NULL,
  `gateway_source` varchar(32) DEFAULT NULL COMMENT 'Źródło gdy order przyszedł przez gateway (m027)',
  `gateway_external_id` varchar(128) DEFAULT NULL COMMENT 'external_id z 3rd-party systemu (m027)',
  `subtotal` int(11) NOT NULL DEFAULT 0 COMMENT 'Grosze',
  `discount_amount` int(11) NOT NULL DEFAULT 0,
  `delivery_fee` int(11) NOT NULL DEFAULT 0,
  `grand_total` int(11) NOT NULL DEFAULT 0,
  `status` varchar(32) NOT NULL DEFAULT 'new',
  `payment_status` varchar(32) NOT NULL DEFAULT 'unpaid',
  `payment_method` varchar(32) DEFAULT NULL,
  `loyalty_points_earned` int(11) NOT NULL DEFAULT 0,
  `customer_name` varchar(255) DEFAULT NULL,
  `customer_phone` varchar(32) DEFAULT NULL,
  `tracking_token` char(16) DEFAULT NULL COMMENT 'Guest tracker token (16 hex)',
  `delivery_address` text DEFAULT NULL,
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  `promised_time` datetime DEFAULT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `driver_id` varchar(64) DEFAULT NULL,
  `course_id` varchar(32) DEFAULT NULL,
  `stop_number` varchar(16) DEFAULT NULL,
  `tip_amount` int(11) NOT NULL DEFAULT 0,
  `edited_since_print` tinyint(1) NOT NULL DEFAULT 0,
  `kitchen_delta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`kitchen_delta`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `receipt_printed` tinyint(1) NOT NULL DEFAULT 0,
  `kitchen_ticket_printed` tinyint(1) NOT NULL DEFAULT 0,
  `kitchen_changes` text DEFAULT NULL,
  `cart_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`cart_json`)),
  `nip` varchar(32) DEFAULT NULL,
  `delivery_status` varchar(32) DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `_active_table_guard` bigint(20) UNSIGNED GENERATED ALWAYS AS (case when `status` not in ('completed','cancelled') and `table_id` is not null then `table_id` else NULL end) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sh_orders`
--

INSERT INTO `sh_orders` (`id`, `tenant_id`, `order_number`, `channel`, `order_type`, `table_id`, `waiter_id`, `guest_count`, `split_type`, `qr_session_token`, `source`, `gateway_source`, `gateway_external_id`, `subtotal`, `discount_amount`, `delivery_fee`, `grand_total`, `status`, `payment_status`, `payment_method`, `loyalty_points_earned`, `customer_name`, `customer_phone`, `tracking_token`, `delivery_address`, `lat`, `lng`, `promised_time`, `user_id`, `driver_id`, `course_id`, `stop_number`, `tip_amount`, `edited_since_print`, `kitchen_delta`, `created_at`, `updated_at`, `receipt_printed`, `kitchen_ticket_printed`, `kitchen_changes`, `cart_json`, `nip`, `delivery_status`, `cancellation_reason`) VALUES
('07ffa7b6-4341-43f7-8679-e31c9f9872cc', 1, 'ORD/20260416/0032', 'POS', 'dine_in', NULL, 3, NULL, NULL, NULL, 'local', NULL, NULL, 2400, 0, 0, 2400, 'pending', 'to_pay', 'unpaid', 0, '', '', NULL, '', NULL, NULL, '2026-04-16 14:06:00', 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-16 13:01:11', '2026-04-17 00:00:25', 0, 1, NULL, '[{\"cart_id\":\"L1776337268390_9pqbl\",\"line_id\":\"L1776337268390_9pqbl\",\"id\":\"BURGER_CHEESE\",\"ascii_key\":\"BURGER_CHEESE\",\"name\":\"Cheese Burger\",\"price\":\"24.00\",\"qty\":1,\"quantity\":1,\"vat_rate\":8,\"removed\":[],\"added\":[],\"comment\":\"\",\"is_half\":false,\"half_a\":null,\"half_b\":null}]', '', NULL, NULL),
('0a5dfced-8920-44ea-afc4-aa5f890cc95e', 1, 'S16', 'pos', 'dine_in', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 5700, 0, 0, 5700, 'ready', 'unpaid', 'card', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 03:12:04', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL),
('0adc30b4-d89b-4d19-b7a1-fced39a5deaf', 1, 'ORD/20260416/0019', 'Delivery', 'delivery', NULL, NULL, NULL, NULL, NULL, 'online', NULL, NULL, 5200, 0, 0, 5200, 'cancelled', 'unpaid', 'card', 0, 'Adam Szymański', '+48 600 300 500', NULL, 'ul. Grunwaldzka 182, Poznań', 52.4200000, 16.8850000, '2026-04-16 05:34:06', 9, NULL, NULL, NULL, 0, 0, NULL, '2026-04-16 05:19:06', '2026-04-16 12:39:58', 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('0b0728b6-f94a-4901-8815-99c61db77bbf', 1, 'D36', 'pos', 'delivery', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 6000, 0, 500, 6500, 'ready', 'paid', 'online', 0, 'Michał Dąbrowski', '781-567-890', NULL, 'ul. Winogrady 144/8, 61-626 Poznań', 52.4336000, 16.9245000, '2026-04-17 07:14:15', 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 06:22:15', NULL, 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('11f93faa-4fe8-45a4-9507-ca17a91e9fcb', 1, 'D46', 'pos', 'delivery', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 6600, 0, 500, 7100, 'ready', 'paid', 'online', 0, 'Tomasz Lewandowski', '512-345-678', NULL, 'os. Bohaterów II WŚ 15/4, 61-381 Poznań', 52.4218000, 16.9511000, '2026-04-17 19:10:47', 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 18:34:47', NULL, 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('1aeb78f0-2f74-4ed4-8553-9f335d15650a', 1, 'ORD/20260416/0028', 'Delivery', 'delivery', NULL, NULL, NULL, NULL, NULL, 'local', NULL, NULL, 3700, 0, 0, 3700, 'ready', 'to_pay', 'unpaid', 0, 'TESGDG', '8888888888888', NULL, 'matejki', NULL, NULL, '2026-04-16 14:22:00', 2, '11', 'K1', 'L3', 0, 1, NULL, '2026-04-16 12:59:02', '2026-04-17 00:00:25', 0, 1, 'DODANO: 1x Capricciosa | USUNIĘTO: 1x Capricciosa', '[{\"cart_id\":\"L1776337342769_mr3y8\",\"line_id\":\"L1776337342769_mr3y8\",\"id\":\"PIZZA_CAPRICCIOSA\",\"ascii_key\":\"PIZZA_CAPRICCIOSA\",\"name\":\"Capricciosa\",\"price\":\"37.00\",\"qty\":1,\"quantity\":1,\"vat_rate\":8,\"removed\":[{\"sku\":\"MKA_TIPO00\",\"name\":\"Mąka Caputo Tipo 00\"},{\"sku\":\"OPAK_PIZZA\",\"name\":\"Opakowanie karton pizza 32cm\"},{\"sku\":\"SER_MOZZ\",\"name\":\"Ser Mozzarella Fior di Latte\"}],\"added\":[{\"ascii_key\":\"EXTRA_OLIVES\",\"name\":\"Oliwki\",\"price\":\"3.00\"},{\"ascii_key\":\"EXTRA_CHEESE\",\"name\":\"Podwójny ser\",\"price\":\"4.00\"}],\"comment\":\"\",\"is_half\":false,\"half_a\":null,\"half_b\":null}]', '', 'in_delivery', NULL),
('1cd1050b-98b4-4d8c-a143-325b5a0dcbc4', 1, 'S3', 'pos', 'dine_in', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 3800, 0, 0, 3800, 'preparing', 'unpaid', 'cash', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 03:11:28', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL),
('2316316b-a1e7-493d-885f-a0c8a3372195', 1, 'D11', 'pos', 'delivery', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 5800, 0, 500, 6300, 'ready', 'unpaid', 'cash', 0, 'Agnieszka Kamińska', '693-456-789', NULL, 'ul. Głogowska 120, 60-243 Poznań', 52.3929000, 16.8873000, '2026-04-17 03:55:28', 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 03:11:28', NULL, 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('231d4921-b571-45a5-986f-5e0c59ae7d62', 1, 'ORD/20260416/0029', 'Delivery', 'delivery', NULL, NULL, NULL, NULL, NULL, 'local', NULL, NULL, 11300, 0, 0, 11300, 'ready', 'to_pay', 'unpaid', 0, 'sdfhfgjhd', '555555', NULL, 'dąbrowskiego 58, 64-980 trzcianka', NULL, NULL, '2026-04-16 14:19:00', 2, '11', 'K1', 'L1', 0, 0, NULL, '2026-04-16 12:59:56', '2026-04-17 00:00:25', 1, 1, NULL, '[{\"cart_id\":\"L1776337162985_z5r38\",\"line_id\":\"L1776337162985_z5r38\",\"id\":\"PIZZA_MARGHERITA\",\"ascii_key\":\"PIZZA_MARGHERITA\",\"name\":\"Margherita\",\"price\":\"27.00\",\"qty\":1,\"quantity\":1,\"vat_rate\":5,\"removed\":[],\"added\":[{\"ascii_key\":\"EXTRA_OLIVES\",\"name\":\"Oliwki\",\"price\":\"3.00\"}],\"comment\":\"\",\"is_half\":false,\"half_a\":null,\"half_b\":null},{\"cart_id\":\"L1776337164215_hydld\",\"line_id\":\"L1776337164215_hydld\",\"id\":\"PIZZA_PEPPERONI\",\"ascii_key\":\"PIZZA_PEPPERONI\",\"name\":\"Pepperoni\",\"price\":\"28.00\",\"qty\":1,\"quantity\":1,\"vat_rate\":5,\"removed\":[],\"added\":[],\"comment\":\"\",\"is_half\":false,\"half_a\":null,\"half_b\":null},{\"cart_id\":\"L1776337165264_91220\",\"line_id\":\"L1776337165264_91220\",\"id\":\"PIZZA_CAPRICCIOSA\",\"ascii_key\":\"PIZZA_CAPRICCIOSA\",\"name\":\"Capricciosa\",\"price\":\"30.00\",\"qty\":1,\"quantity\":1,\"vat_rate\":5,\"removed\":[],\"added\":[],\"comment\":\"\",\"is_half\":false,\"half_a\":null,\"half_b\":null},{\"cart_id\":\"L1776337166304_y9o69\",\"line_id\":\"L1776337166304_y9o69\",\"id\":\"PIZZA_HAWAJSKA\",\"ascii_key\":\"PIZZA_HAWAJSKA\",\"name\":\"Hawajska\",\"price\":\"28.00\",\"qty\":1,\"quantity\":1,\"vat_rate\":5,\"removed\":[],\"added\":[],\"comment\":\"\",\"is_half\":false,\"half_a\":null,\"half_b\":null}]', '', 'in_delivery', NULL),
('24b89d25-db01-456c-a77b-dbdf308f95b5', 1, 'D9', 'pos', 'delivery', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 4000, 0, 500, 4500, 'ready', 'unpaid', 'card', 0, 'Katarzyna Zielińska', '602-234-567', NULL, 'ul. Garbary 78/12, 61-758 Poznań', 52.4122000, 16.9387000, '2026-04-17 03:39:28', 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 03:11:28', NULL, 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('2606e5e7-0a8c-4e34-8139-56ff984899d6', 1, 'D8', 'pos', 'delivery', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 6600, 0, 500, 7100, 'cancelled', 'paid', 'online', 0, 'Tomasz Lewandowski', '512-345-678', NULL, 'os. Bohaterów II WŚ 15/4, 61-381 Poznań', 52.4218000, 16.9511000, '2026-04-16 05:55:04', 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-16 05:19:04', '2026-04-16 12:40:28', 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('26128f4b-ff53-41be-b2f4-e0c531babf92', 1, 'ORD/20260416/0033', 'Delivery', 'delivery', NULL, NULL, NULL, NULL, NULL, 'local', NULL, NULL, 2600, 0, 0, 2600, 'completed', 'cash', 'cash', 0, 'wefrt', '555555', NULL, 'dąbrowskiego 58, 64-980 trzcianka', NULL, NULL, '2026-04-16 14:06:00', 2, NULL, NULL, NULL, 0, 0, NULL, '2026-04-16 13:06:27', '2026-04-16 13:14:07', 0, 1, NULL, '[{\"cart_id\":\"L1776337577907_yazzj\",\"line_id\":\"L1776337577907_yazzj\",\"id\":\"BURGER_BBQ\",\"ascii_key\":\"BURGER_BBQ\",\"name\":\"BBQ Burger\",\"price\":\"26.00\",\"qty\":1,\"quantity\":1,\"vat_rate\":5,\"removed\":[],\"added\":[],\"comment\":\"\",\"is_half\":false,\"half_a\":null,\"half_b\":null}]', '', 'delivered', NULL),
('2aa9aaa2-571e-4286-befe-bc5664dc694c', 1, 'ORD/20260416/0022', 'Delivery', 'delivery', NULL, NULL, NULL, NULL, NULL, 'online', NULL, NULL, 6400, 0, 0, 6400, 'cancelled', 'unpaid', 'card', 0, 'Ewa Kozłowska', '+48 508 432 100', NULL, 'ul. Piątkowska 70, Poznań', 52.4280000, 16.8780000, '2026-04-16 05:46:06', 9, NULL, NULL, NULL, 0, 0, NULL, '2026-04-16 05:19:06', '2026-04-16 12:40:10', 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('32eeb1cd-ed58-4784-b1bd-9a5dc2aa235e', 1, 'ORD/20260416/0026', 'Delivery', 'delivery', NULL, NULL, NULL, NULL, NULL, 'POS', NULL, NULL, 6000, 0, 0, 6000, 'cancelled', 'to_pay', NULL, 0, 'Maria Wiśniewska', '+48600555666', NULL, 'ul. Nowy Świat 33, Warszawa', NULL, NULL, '2026-04-16 05:54:06', 1, NULL, NULL, NULL, 0, 0, NULL, '2026-04-16 05:19:06', '2026-04-16 12:40:17', 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('33b87d71-cd7c-4265-9b7f-389a2bfb6cf1', 1, 'D48', 'pos', 'delivery', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 6000, 0, 500, 6500, 'ready', 'paid', 'online', 0, 'Michał Dąbrowski', '781-567-890', NULL, 'ul. Winogrady 144/8, 61-626 Poznań', 52.4336000, 16.9245000, '2026-04-17 19:26:48', 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 18:34:48', NULL, 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('3463368c-d99c-42e9-99a9-d112c92c1f33', 1, 'D13', 'pos', 'delivery', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 3000, 0, 500, 3500, 'completed', 'unpaid', 'cash', 0, 'Jan Testowy', '600-111-222', NULL, 'ul. Ratajczaka 20, Poznań', 52.4050000, 16.9180000, '2026-04-17 02:11:28', 6, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 03:11:28', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL),
('38c45a98-2b44-40a2-953b-cc8d2509a3f9', 1, 'ORD/20260416/0013', 'Delivery', 'delivery', NULL, NULL, NULL, NULL, NULL, 'online', NULL, NULL, 15200, 0, 0, 15200, 'cancelled', 'unpaid', 'cash', 0, 'Jan Kowalski', '+48 500 100 200', NULL, 'ul. Półwiejska 42, Poznań', 52.4034000, 16.9340000, '2026-04-16 05:36:06', 9, NULL, NULL, NULL, 0, 0, NULL, '2026-04-16 05:19:06', '2026-04-16 12:40:03', 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('3928ce3c-3c92-4884-914f-69148d4ff6d9', 1, 'ORD/20260416/0036', 'Delivery', 'delivery', NULL, NULL, NULL, NULL, NULL, 'local', NULL, NULL, 2600, 0, 0, 2600, 'completed', 'cash', 'cash', 0, 'gfnfg', '8888888888888', NULL, 'dąbrowskiego 58, 64-980 trzcianka', NULL, NULL, '2026-04-16 14:16:00', 2, '10', 'K2', 'L1', 0, 0, NULL, '2026-04-16 13:16:31', '2026-04-16 13:22:00', 0, 1, NULL, '[{\"cart_id\":\"L1776338181663_z0ves\",\"line_id\":\"L1776338181663_z0ves\",\"id\":\"BURGER_BBQ\",\"ascii_key\":\"BURGER_BBQ\",\"name\":\"BBQ Burger\",\"price\":\"26.00\",\"qty\":1,\"quantity\":1,\"vat_rate\":5,\"removed\":[],\"added\":[],\"comment\":\"\",\"is_half\":false,\"half_a\":null,\"half_b\":null}]', '', 'delivered', NULL),
('39b70fae-7667-47c6-84eb-6d0cd8499fcd', 1, 'S5', 'pos', 'dine_in', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 3300, 0, 0, 3300, 'completed', 'paid', 'cash', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 03:11:28', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL),
('3c27c4fb-567d-4e40-9a5f-19d55a82069a', 1, 'D25', 'pos', 'delivery', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 3000, 0, 500, 3500, 'completed', 'unpaid', 'cash', 0, 'Jan Testowy', '600-111-222', NULL, 'ul. Ratajczaka 20, Poznań', 52.4050000, 16.9180000, '2026-04-17 02:12:04', 6, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 03:12:04', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL),
('3cf9427f-b9c2-46d9-b0b8-3a607f69c3b6', 1, 'D33', 'pos', 'delivery', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 4000, 0, 500, 4500, 'ready', 'unpaid', 'card', 0, 'Katarzyna Zielińska', '602-234-567', NULL, 'ul. Garbary 78/12, 61-758 Poznań', 52.4122000, 16.9387000, '2026-04-17 06:50:15', 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 06:22:15', NULL, 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('41aa3420-2a7b-4cd8-b29f-5c355bbbf581', 1, 'T42', 'online', 'takeaway', NULL, NULL, NULL, NULL, NULL, 'web', NULL, NULL, 3500, 0, 0, 3500, 'pending', 'unpaid', 'online', 0, 'Klient Online', '500-100-200', NULL, NULL, NULL, NULL, '2026-04-17 19:04:47', NULL, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 18:34:47', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL),
('425d27b7-7454-492e-8b74-e247c005dfac', 1, 'D22', 'pos', 'delivery', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 6600, 0, 500, 7100, 'ready', 'paid', 'online', 0, 'Tomasz Lewandowski', '512-345-678', NULL, 'os. Bohaterów II WŚ 15/4, 61-381 Poznań', 52.4218000, 16.9511000, '2026-04-17 03:48:04', 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 03:12:04', NULL, 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('4845480b-3214-4287-9e87-8b220357ef78', 1, 'ORD/20260416/0031', 'Takeaway', 'takeaway', NULL, NULL, NULL, NULL, NULL, 'local', NULL, NULL, 2000, 0, 0, 2000, 'cancelled', 'to_pay', 'unpaid', 0, '', '', NULL, '', NULL, NULL, '2026-04-16 13:45:00', 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-16 13:00:55', '2026-04-17 03:10:33', 0, 1, NULL, '[{\"cart_id\":\"L1776337250772_2drg0\",\"line_id\":\"L1776337250772_2drg0\",\"id\":\"SALAD_GREEK\",\"ascii_key\":\"SALAD_GREEK\",\"name\":\"Sałatka Grecka\",\"price\":\"20.00\",\"qty\":1,\"quantity\":1,\"vat_rate\":5,\"removed\":[],\"added\":[],\"comment\":\"\",\"is_half\":false,\"half_a\":null,\"half_b\":null}]', '', NULL, NULL),
('49d438fe-c22c-44ad-90c1-14aac07da9d5', 1, 'D12', 'pos', 'delivery', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 6000, 0, 500, 6500, 'ready', 'paid', 'online', 0, 'Michał Dąbrowski', '781-567-890', NULL, 'ul. Winogrady 144/8, 61-626 Poznań', 52.4336000, 16.9245000, '2026-04-17 04:03:28', 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 03:11:28', NULL, 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('4e7edd28-1b94-40aa-8da8-39c8229f53ca', 1, 'S41', 'pos', 'dine_in', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 3300, 0, 0, 3300, 'completed', 'paid', 'cash', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 18:34:47', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL),
('5299aba2-dbad-464f-9a97-f661e48177d9', 1, 'T6', 'online', 'takeaway', NULL, NULL, NULL, NULL, NULL, 'web', NULL, NULL, 3500, 0, 0, 3500, 'pending', 'unpaid', 'online', 0, 'Klient Online', '500-100-200', NULL, NULL, NULL, NULL, '2026-04-17 03:41:28', NULL, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 03:11:28', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL),
('581e533e-7e45-4072-a94e-0415513a4f1b', 1, 'S28', 'pos', 'dine_in', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 5700, 0, 0, 5700, 'ready', 'unpaid', 'card', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 06:22:15', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL),
('5d81a72e-9517-46c5-bf27-88ae7be1f3ff', 1, 'D10', 'pos', 'delivery', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 6000, 0, 500, 6500, 'cancelled', 'paid', 'online', 0, 'Michał Dąbrowski', '781-567-890', NULL, 'ul. Winogrady 144/8, 61-626 Poznań', 52.4336000, 16.9245000, '2026-04-16 06:11:04', 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-16 05:19:04', '2026-04-16 12:57:42', 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('5e6a8437-8c22-4d99-bb12-d85e4d423116', 1, 'D44', 'pos', 'delivery', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 3800, 0, 500, 4300, 'ready', 'unpaid', 'cash', 0, 'Piotr Wiśniewski', '501-123-456', NULL, 'ul. Święty Marcin 42/3, 61-807 Poznań', 52.4069000, 16.9163000, '2026-04-17 18:54:47', 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 18:34:47', NULL, 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('645db9b1-072c-4a60-86a8-a585536878dd', 1, 'D23', 'pos', 'delivery', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 5800, 0, 500, 6300, 'ready', 'unpaid', 'cash', 0, 'Agnieszka Kamińska', '693-456-789', NULL, 'ul. Głogowska 120, 60-243 Poznań', 52.3929000, 16.8873000, '2026-04-17 03:56:04', 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 03:12:04', NULL, 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('655dfde8-e921-4ce6-a94e-82901d788661', 1, 'ORD/20260416/0018', 'Delivery', 'delivery', NULL, NULL, NULL, NULL, NULL, 'POS', NULL, NULL, 2000, 0, 0, 2000, 'cancelled', 'unpaid', 'cash', 0, 'Katarzyna Zielińska', '+48 510 888 444', NULL, 'ul. Jeżycka 8, Poznań', 52.4155000, 16.9120000, '2026-04-16 05:35:06', 9, NULL, NULL, NULL, 0, 0, NULL, '2026-04-16 05:19:06', '2026-04-16 12:40:00', 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('6806b54c-8f40-4df5-a49f-fbffbbdc7cf4', 1, 'S1', 'pos', 'dine_in', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 3800, 0, 0, 3800, 'cancelled', 'unpaid', 'cash', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-16 05:19:04', '2026-04-16 12:57:55', 0, 0, NULL, NULL, NULL, NULL, NULL),
('6a0a457e-0226-4a28-95f2-df12da98361e', 1, 'ORD/20260416/0034', 'Delivery', 'delivery', NULL, NULL, NULL, NULL, NULL, 'local', NULL, NULL, 3000, 0, 0, 3000, 'ready', 'to_pay', 'unpaid', 0, 'wefrt', '555555', NULL, 'SLOWAKA', NULL, NULL, '2026-04-16 14:35:00', 2, '10', 'K2', 'L3', 0, 0, NULL, '2026-04-16 13:15:29', '2026-04-17 00:00:25', 0, 1, NULL, '[{\"cart_id\":\"L1776338121291_inzpu\",\"line_id\":\"L1776338121291_inzpu\",\"id\":\"PASTA_LASAGNE\",\"ascii_key\":\"PASTA_LASAGNE\",\"name\":\"Lasagne\",\"price\":\"30.00\",\"qty\":1,\"quantity\":1,\"vat_rate\":5,\"removed\":[],\"added\":[],\"comment\":\"\",\"is_half\":false,\"half_a\":null,\"half_b\":null}]', '', 'in_delivery', NULL),
('6e8bebdf-14de-496c-9a8f-c3fe0ee1a9eb', 1, 'ORD/20260416/0024', 'Delivery', 'delivery', NULL, NULL, NULL, NULL, NULL, 'POS', NULL, NULL, 4000, 0, 0, 4000, 'cancelled', 'online_paid', 'online', 0, 'Anna Nowak', '+48600111222', NULL, 'ul. Marszałkowska 12/5, Warszawa', NULL, NULL, '2026-04-16 05:54:06', 1, NULL, NULL, NULL, 0, 0, NULL, '2026-04-16 05:19:06', '2026-04-16 12:40:19', 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('6f84d5a6-44e8-4283-8953-93ec2f7503ce', 1, 'D6', 'pos', 'delivery', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 3800, 0, 500, 4300, 'cancelled', 'unpaid', 'cash', 0, 'Piotr Wiśniewski', '501-123-456', NULL, 'ul. Święty Marcin 42/3, 61-807 Poznań', 52.4069000, 16.9163000, '2026-04-16 05:39:04', 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-16 05:19:04', '2026-04-16 12:40:07', 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('74d47591-275e-4727-a23e-de09d3c63563', 1, 'D14', 'pos', 'delivery', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 3200, 0, 500, 3700, 'completed', 'unpaid', 'cash', 0, 'Maria Testowa', '600-333-444', NULL, 'ul. Półwiejska 8, Poznań', 52.4040000, 16.9200000, '2026-04-17 02:11:28', 6, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 03:11:28', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL),
('797998a1-f625-4e71-a774-38602b91f4a9', 1, 'D21', 'pos', 'delivery', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 4000, 0, 500, 4500, 'ready', 'unpaid', 'card', 0, 'Katarzyna Zielińska', '602-234-567', NULL, 'ul. Garbary 78/12, 61-758 Poznań', 52.4122000, 16.9387000, '2026-04-17 03:40:04', 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 03:12:04', NULL, 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('7bbf21fc-750c-4107-8c53-b81dc0be3080', 1, 'D37', 'pos', 'delivery', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 3000, 0, 500, 3500, 'completed', 'unpaid', 'cash', 0, 'Jan Testowy', '600-111-222', NULL, 'ul. Ratajczaka 20, Poznań', 52.4050000, 16.9180000, '2026-04-17 05:22:15', 6, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 06:22:15', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL),
('7bd2e3f4-d689-4c49-8415-75ed5e879bb5', 1, 'S17', 'pos', 'dine_in', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 3300, 0, 0, 3300, 'completed', 'paid', 'cash', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 03:12:04', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL),
('7c043a11-b5e3-41b6-8ca6-acf60b6cbd1a', 1, 'D24', 'pos', 'delivery', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 6000, 0, 500, 6500, 'ready', 'paid', 'online', 0, 'Michał Dąbrowski', '781-567-890', NULL, 'ul. Winogrady 144/8, 61-626 Poznań', 52.4336000, 16.9245000, '2026-04-17 04:04:04', 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 03:12:04', NULL, 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('7d535f2f-ad05-4aef-a667-877a8f1c673d', 1, 'T31', 'online', 'takeaway', NULL, NULL, NULL, NULL, NULL, 'web', NULL, NULL, 3200, 0, 0, 3200, 'ready', 'paid', 'online', 0, 'Klient Online', '500-100-200', NULL, NULL, NULL, NULL, '2026-04-17 06:52:15', NULL, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 06:22:15', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL),
('7e9f030d-5df5-4bce-b096-25c6cb407ab3', 1, 'D26', 'pos', 'delivery', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 3200, 0, 500, 3700, 'completed', 'unpaid', 'cash', 0, 'Maria Testowa', '600-333-444', NULL, 'ul. Półwiejska 8, Poznań', 52.4040000, 16.9200000, '2026-04-17 02:12:04', 6, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 03:12:04', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL),
('80fd22d8-ee3a-44b0-973c-6f65abbd54f2', 1, 'D20', 'pos', 'delivery', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 3800, 0, 500, 4300, 'ready', 'unpaid', 'cash', 0, 'Piotr Wiśniewski', '501-123-456', NULL, 'ul. Święty Marcin 42/3, 61-807 Poznań', 52.4069000, 16.9163000, '2026-04-17 03:32:04', 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 03:12:04', NULL, 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('81deb272-c484-4fed-8699-ad4cd3b82054', 1, 'D47', 'pos', 'delivery', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 5800, 0, 500, 6300, 'ready', 'unpaid', 'cash', 0, 'Agnieszka Kamińska', '693-456-789', NULL, 'ul. Głogowska 120, 60-243 Poznań', 52.3929000, 16.8873000, '2026-04-17 19:18:47', 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 18:34:47', NULL, 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('8506f171-dd58-4d6e-a268-64a9dface5f2', 1, 'D34', 'pos', 'delivery', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 6600, 0, 500, 7100, 'ready', 'paid', 'online', 0, 'Tomasz Lewandowski', '512-345-678', NULL, 'os. Bohaterów II WŚ 15/4, 61-381 Poznań', 52.4218000, 16.9511000, '2026-04-17 06:58:15', 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 06:22:15', NULL, 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('857d9ade-143e-4b03-9839-1da9b52d07e6', 1, 'S39', 'pos', 'dine_in', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 3800, 0, 0, 3800, 'preparing', 'unpaid', 'cash', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 18:34:47', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL),
('8653afa5-d893-47b2-b235-87c271b28d09', 1, 'T30', 'online', 'takeaway', NULL, NULL, NULL, NULL, NULL, 'web', NULL, NULL, 3500, 0, 0, 3500, 'pending', 'unpaid', 'online', 0, 'Klient Online', '500-100-200', NULL, NULL, NULL, NULL, '2026-04-17 06:52:15', NULL, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 06:22:15', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL),
('8ad39786-7dbd-4183-8219-34b796f5eefc', 1, 'D45', 'pos', 'delivery', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 4000, 0, 500, 4500, 'ready', 'unpaid', 'card', 0, 'Katarzyna Zielińska', '602-234-567', NULL, 'ul. Garbary 78/12, 61-758 Poznań', 52.4122000, 16.9387000, '2026-04-17 19:02:47', 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 18:34:47', NULL, 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('91253b46-5b47-4a55-afa6-a3ccf3247491', 1, 'S29', 'pos', 'dine_in', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 3300, 0, 0, 3300, 'completed', 'paid', 'cash', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 06:22:15', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL),
('92f626d9-538e-416d-a707-1f89d59fbdd8', 1, 'ORD/20260416/0021', 'Delivery', 'delivery', NULL, NULL, NULL, NULL, NULL, 'POS', NULL, NULL, 2600, 0, 0, 2600, 'cancelled', 'unpaid', 'cash', 0, 'Krzysztof Dąbrowski', '+48 722 555 666', NULL, 'ul. Winogrady 150, Poznań', 52.4230000, 16.9450000, '2026-04-16 06:10:06', 9, NULL, NULL, NULL, 0, 0, NULL, '2026-04-16 05:19:06', '2026-04-16 12:57:37', 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('98f2f88e-c53c-4d08-9c04-cbeeccaf3be6', 1, 'D50', 'pos', 'delivery', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 3200, 0, 500, 3700, 'completed', 'unpaid', 'cash', 0, 'Maria Testowa', '600-333-444', NULL, 'ul. Półwiejska 8, Poznań', 52.4040000, 16.9200000, '2026-04-17 17:34:48', 6, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 18:34:48', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL),
('9a0cf0da-22cb-4643-aa17-0b8f53347cdf', 1, 'T43', 'online', 'takeaway', NULL, NULL, NULL, NULL, NULL, 'web', NULL, NULL, 3200, 0, 0, 3200, 'ready', 'paid', 'online', 0, 'Klient Online', '500-100-200', NULL, NULL, NULL, NULL, '2026-04-17 19:04:47', NULL, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 18:34:47', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL),
('9fb40f85-91f8-45a6-8774-1c05c94ddf4c', 1, 'D10', 'pos', 'delivery', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 6600, 0, 500, 7100, 'ready', 'paid', 'online', 0, 'Tomasz Lewandowski', '512-345-678', NULL, 'os. Bohaterów II WŚ 15/4, 61-381 Poznań', 52.4218000, 16.9511000, '2026-04-17 03:47:28', 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 03:11:28', NULL, 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('9ff90ee9-89d9-49c9-b756-6d09ad154f0a', 1, 'ORD/20260416/0027', 'Delivery', 'delivery', NULL, NULL, NULL, NULL, NULL, 'POS', NULL, NULL, 10400, 0, 0, 10400, 'cancelled', 'unpaid', 'cash', 0, 'Agnieszka Mazur', '+48 660 345 890', NULL, 'ul. Solna 4/12, Poznań', 52.4085000, 16.9320000, '2026-04-16 06:11:06', 9, NULL, NULL, NULL, 0, 0, NULL, '2026-04-16 05:19:06', '2026-04-16 12:57:44', 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('a53b4c9b-5845-45b1-9f7c-69bcbc0ad695', 1, 'D9', 'pos', 'delivery', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 5800, 0, 500, 6300, 'cancelled', 'unpaid', 'cash', 0, 'Agnieszka Kamińska', '693-456-789', NULL, 'ul. Głogowska 120, 60-243 Poznań', 52.3929000, 16.8873000, '2026-04-16 06:03:04', 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-16 05:19:04', '2026-04-16 12:40:36', 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('a7f6931c-27fb-49e3-b6cd-a2a8006df4bf', 1, 'ORD/20260416/0017', 'Delivery', 'delivery', NULL, NULL, NULL, NULL, NULL, 'POS', NULL, NULL, 7200, 0, 0, 7200, 'cancelled', 'paid', 'online', 0, 'Tomasz Lewandowski', '+48 503 111 999', NULL, 'ul. Ratajczaka 20, Poznań', 52.4060000, 16.9270000, '2026-04-16 06:07:06', 9, NULL, NULL, NULL, 0, 0, NULL, '2026-04-16 05:19:06', '2026-04-16 12:57:35', 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('aba34c27-dc11-401c-bb4a-1065e2778641', 1, 'ORD/20260416/0023', 'Delivery', 'delivery', NULL, NULL, NULL, NULL, NULL, 'POS', NULL, NULL, 6800, 0, 0, 6800, 'cancelled', 'paid', 'online', 0, 'Michał Jankowski', '+48 530 901 234', NULL, 'ul. Dąbrowskiego 55, Poznań', 52.4000000, 16.9150000, '2026-04-16 06:17:06', 9, NULL, NULL, NULL, 0, 0, NULL, '2026-04-16 05:19:06', '2026-04-16 12:57:46', 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('ae252b3e-5e97-458d-8f9e-a57dac0b2304', 1, 'T19', 'online', 'takeaway', NULL, NULL, NULL, NULL, NULL, 'web', NULL, NULL, 3200, 0, 0, 3200, 'ready', 'paid', 'online', 0, 'Klient Online', '500-100-200', NULL, NULL, NULL, NULL, '2026-04-17 03:42:04', NULL, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 03:12:04', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL),
('ae3030b8-ef40-4ce2-9952-4567e26d1270', 1, 'ORD/20260416/0016', 'Delivery', 'delivery', NULL, NULL, NULL, NULL, NULL, 'online', NULL, NULL, 10000, 0, 0, 10000, 'cancelled', 'unpaid', 'cash', 0, 'Marta Kamińska', '+48 666 777 888', NULL, 'ul. Głogowska 112, Poznań', 52.3950000, 16.9010000, '2026-04-16 05:56:06', 9, NULL, NULL, NULL, 0, 0, NULL, '2026-04-16 05:19:06', '2026-04-16 12:40:31', 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('b33c4b1e-828c-4514-bd83-683316a7cfde', 1, 'D8', 'pos', 'delivery', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 3800, 0, 500, 4300, 'ready', 'unpaid', 'cash', 0, 'Piotr Wiśniewski', '501-123-456', NULL, 'ul. Święty Marcin 42/3, 61-807 Poznań', 52.4069000, 16.9163000, '2026-04-17 03:31:28', 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 03:11:28', NULL, 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('b74488fe-a664-40d1-992c-d62c3eeb7058', 1, 'T7', 'online', 'takeaway', NULL, NULL, NULL, NULL, NULL, 'web', NULL, NULL, 3200, 0, 0, 3200, 'ready', 'paid', 'online', 0, 'Klient Online', '500-100-200', NULL, NULL, NULL, NULL, '2026-04-17 03:41:28', NULL, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 03:11:28', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL),
('b75fa82b-0116-4250-bd8c-25fcb717f3b1', 1, 'S3', 'pos', 'dine_in', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 3300, 0, 0, 3300, 'completed', 'paid', 'cash', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-16 05:19:04', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL),
('ba039aa7-26df-47ff-baa3-a6031362e05a', 1, 'D11', 'pos', 'delivery', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 3000, 0, 500, 3500, 'completed', 'unpaid', 'cash', 0, 'Jan Testowy', '600-111-222', NULL, 'ul. Ratajczaka 20, Poznań', 52.4050000, 16.9180000, '2026-04-16 04:19:04', 6, NULL, NULL, NULL, 0, 0, NULL, '2026-04-16 05:19:04', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL),
('ba49298f-1763-419c-b495-d7afe0992db9', 1, 'D12', 'pos', 'delivery', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 3200, 0, 500, 3700, 'completed', 'unpaid', 'cash', 0, 'Maria Testowa', '600-333-444', NULL, 'ul. Półwiejska 8, Poznań', 52.4040000, 16.9200000, '2026-04-16 04:19:04', 6, NULL, NULL, NULL, 0, 0, NULL, '2026-04-16 05:19:04', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL),
('bfed4919-4c0a-4e47-bc36-058d226e1e33', 1, 'ORD/20260416/0015', 'Delivery', 'delivery', NULL, NULL, NULL, NULL, NULL, 'POS', NULL, NULL, 5400, 0, 0, 5400, 'cancelled', 'paid', 'online', 0, 'Piotr Wiśniewski', '+48 601 222 333', NULL, 'os. Piastowskie 16/4, Poznań', 52.4110000, 16.9385000, '2026-04-16 06:10:06', 9, NULL, NULL, NULL, 0, 0, NULL, '2026-04-16 05:19:06', '2026-04-16 12:57:40', 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('c365bf79-2443-4800-9037-f31b30ae5dd4', 1, 'T5', 'online', 'takeaway', NULL, NULL, NULL, NULL, NULL, 'web', NULL, NULL, 3200, 0, 0, 3200, 'cancelled', 'paid', 'online', 0, 'Klient Online', '500-100-200', NULL, NULL, NULL, NULL, '2026-04-16 05:49:04', NULL, NULL, NULL, NULL, 0, 0, NULL, '2026-04-16 05:19:04', '2026-04-16 12:57:50', 0, 0, NULL, NULL, NULL, NULL, NULL),
('c5574d32-b7c3-468d-b6ba-06c1dff48033', 1, 'ORD/20260417/0001', 'POS', 'dine_in', NULL, 9, NULL, NULL, NULL, 'POS', NULL, NULL, 0, 0, 0, 0, 'cancelled', 'to_pay', 'unpaid', 0, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-17 00:20:25', 9, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 00:00:25', '2026-04-17 03:10:36', 0, 0, NULL, '[]', NULL, NULL, NULL),
('cb3c200c-5769-4cdd-ab5f-4d310fb08f90', 1, 'S40', 'pos', 'dine_in', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 5700, 0, 0, 5700, 'ready', 'unpaid', 'card', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 18:34:47', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL),
('cc17bad0-2714-47ed-9386-b96b58c450c2', 1, 'ORD/20260416/0030', 'Delivery', 'delivery', NULL, NULL, NULL, NULL, NULL, 'local', NULL, NULL, 5600, 0, 0, 5600, 'ready', 'card', 'card', 0, 'sdfhfgjhd', '555555', NULL, 'SLOWAKA', NULL, NULL, '2026-04-16 14:20:00', 2, '11', 'K1', 'L2', 0, 0, NULL, '2026-04-16 13:00:16', '2026-04-17 00:00:25', 1, 1, NULL, '[{\"cart_id\":\"L1776337205665_qi0oq\",\"line_id\":\"L1776337205665_qi0oq\",\"id\":\"PIZZA_PEPPERONI\",\"ascii_key\":\"PIZZA_PEPPERONI\",\"name\":\"Pepperoni\",\"price\":\"28.00\",\"qty\":1,\"quantity\":1,\"vat_rate\":5,\"removed\":[],\"added\":[],\"comment\":\"\",\"is_half\":false,\"half_a\":null,\"half_b\":null},{\"cart_id\":\"L1776337206924_1xlld\",\"line_id\":\"L1776337206924_1xlld\",\"id\":\"PIZZA_HAWAJSKA\",\"ascii_key\":\"PIZZA_HAWAJSKA\",\"name\":\"Hawajska\",\"price\":\"28.00\",\"qty\":1,\"quantity\":1,\"vat_rate\":5,\"removed\":[],\"added\":[],\"comment\":\"\",\"is_half\":false,\"half_a\":null,\"half_b\":null}]', '', 'in_delivery', NULL),
('cd7bbc6e-772f-4be2-83a2-2b6bf523d700', 1, 'S2', 'pos', 'dine_in', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 5700, 0, 0, 5700, 'cancelled', 'unpaid', 'card', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-16 05:19:04', '2026-04-16 12:57:58', 0, 0, NULL, NULL, NULL, NULL, NULL),
('d6e82a8f-9fcb-4f27-a5e3-70b70242babb', 1, 'S15', 'pos', 'dine_in', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 3800, 0, 0, 3800, 'preparing', 'unpaid', 'cash', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 03:12:04', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL),
('d73e8154-501b-443b-9721-9482d6a436a3', 1, 'D32', 'pos', 'delivery', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 3800, 0, 500, 4300, 'ready', 'unpaid', 'cash', 0, 'Piotr Wiśniewski', '501-123-456', NULL, 'ul. Święty Marcin 42/3, 61-807 Poznań', 52.4069000, 16.9163000, '2026-04-17 06:42:15', 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 06:22:15', NULL, 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('d75352a4-6984-4452-9440-a5e39ad13ea7', 1, 'D49', 'pos', 'delivery', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 3000, 0, 500, 3500, 'completed', 'unpaid', 'cash', 0, 'Jan Testowy', '600-111-222', NULL, 'ul. Ratajczaka 20, Poznań', 52.4050000, 16.9180000, '2026-04-17 17:34:48', 6, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 18:34:48', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL),
('d76ff6f1-32cb-4604-a402-638533e1aa09', 1, 'D7', 'pos', 'delivery', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 4000, 0, 500, 4500, 'cancelled', 'unpaid', 'card', 0, 'Katarzyna Zielińska', '602-234-567', NULL, 'ul. Garbary 78/12, 61-758 Poznań', 52.4122000, 16.9387000, '2026-04-16 05:47:04', 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-16 05:19:04', '2026-04-16 12:40:15', 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('e028f5e8-c7fd-42eb-97e8-05ec94294d75', 1, 'T4', 'online', 'takeaway', NULL, NULL, NULL, NULL, NULL, 'web', NULL, NULL, 3500, 0, 0, 3500, 'cancelled', 'unpaid', 'online', 0, 'Klient Online', '500-100-200', NULL, NULL, NULL, NULL, '2026-04-16 05:49:04', NULL, NULL, NULL, NULL, 0, 0, NULL, '2026-04-16 05:19:04', '2026-04-16 12:57:53', 0, 0, NULL, NULL, NULL, NULL, NULL),
('e1054790-9dd8-476b-afa0-701e69ffdec0', 1, 'D35', 'pos', 'delivery', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 5800, 0, 500, 6300, 'ready', 'unpaid', 'cash', 0, 'Agnieszka Kamińska', '693-456-789', NULL, 'ul. Głogowska 120, 60-243 Poznań', 52.3929000, 16.8873000, '2026-04-17 07:06:15', 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 06:22:15', NULL, 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('e1e41f3b-8dde-487e-a7b7-0f16b26d4efc', 1, 'ORD/20260416/0025', 'Delivery', 'delivery', NULL, NULL, NULL, NULL, NULL, 'POS', NULL, NULL, 4400, 0, 0, 4400, 'cancelled', 'to_pay', NULL, 0, 'Jan Kowalski', '+48600333444', NULL, 'ul. Puławska 87, Warszawa', NULL, NULL, '2026-04-16 05:54:06', 1, NULL, NULL, NULL, 0, 0, NULL, '2026-04-16 05:19:06', '2026-04-16 12:40:21', 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('f0fd6d73-a538-404f-9f32-9f4db26d14bf', 1, 'D38', 'pos', 'delivery', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 3200, 0, 500, 3700, 'completed', 'unpaid', 'cash', 0, 'Maria Testowa', '600-333-444', NULL, 'ul. Półwiejska 8, Poznań', 52.4040000, 16.9200000, '2026-04-17 05:22:15', 6, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 06:22:15', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL),
('f4656ac5-3ab5-4168-88d5-9c956bad83a1', 1, 'T18', 'online', 'takeaway', NULL, NULL, NULL, NULL, NULL, 'web', NULL, NULL, 3500, 0, 0, 3500, 'pending', 'unpaid', 'online', 0, 'Klient Online', '500-100-200', NULL, NULL, NULL, NULL, '2026-04-17 03:42:04', NULL, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 03:12:04', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL),
('f90a3ec9-d055-413f-9bf7-5acc53dab1d0', 1, 'ORD/20260416/0014', 'Delivery', 'delivery', NULL, NULL, NULL, NULL, NULL, 'POS', NULL, NULL, 11200, 0, 0, 11200, 'cancelled', 'unpaid', 'card', 0, 'Anna Nowak', '+48 512 345 678', NULL, 'ul. Św. Marcin 80/82, Poznań', 52.4082000, 16.9210000, '2026-04-16 06:02:06', 9, NULL, NULL, NULL, 0, 0, NULL, '2026-04-16 05:19:06', '2026-04-16 12:40:33', 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('fa9c3a38-d46f-4480-9965-4d3bfd387d9c', 1, 'S27', 'pos', 'dine_in', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 3800, 0, 0, 3800, 'preparing', 'unpaid', 'cash', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 06:22:15', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL),
('fc7acd2f-32e7-4603-bac8-927eb868d63d', 1, 'ORD/20260417/0002', 'Takeaway', 'takeaway', NULL, NULL, NULL, NULL, NULL, 'POS', NULL, NULL, 5198, 0, 0, 5198, 'ready', 'cash', 'cash', 0, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-17 00:20:25', 9, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 00:00:25', '2026-04-17 03:10:25', 0, 0, NULL, '[{\"cart_id\":\"test1\",\"ascii_key\":\"MARGHERITA\",\"name\":\"Margherita\",\"price\":\"25.99\",\"qty\":2,\"added\":[{\"ascii_key\":\"OPT_JALAPENO\",\"name\":\"Jalapeno\",\"price\":\"4.00\"}],\"removed\":[{\"sku\":\"SER_MOZZARELLA\",\"name\":\"Mozzarella\"}],\"comment\":\"Dobrze wypieczona\"}]', NULL, NULL, NULL),
('fd0b1c4c-5c2a-4bd2-8a8d-8eaf01d1b2c1', 1, 'ORD/20260416/0035', 'Delivery', 'delivery', NULL, NULL, NULL, NULL, NULL, 'local', NULL, NULL, 5200, 0, 0, 5200, 'completed', 'cash', 'cash', 0, 'fgvrrf', '555555', NULL, 'dąbrowskiego 58, 64-980 trzcianka', NULL, NULL, '2026-04-16 14:15:00', 2, '10', 'K2', 'L2', 0, 0, NULL, '2026-04-16 13:16:05', '2026-04-16 13:22:35', 0, 1, NULL, '[{\"cart_id\":\"L1776338136588_j2xn8\",\"line_id\":\"L1776338136588_j2xn8\",\"id\":\"PIZZA_MARGHERITA\",\"ascii_key\":\"PIZZA_MARGHERITA\",\"name\":\"Margherita\",\"price\":\"24.00\",\"qty\":1,\"quantity\":1,\"vat_rate\":5,\"removed\":[],\"added\":[],\"comment\":\"\",\"is_half\":false,\"half_a\":null,\"half_b\":null},{\"cart_id\":\"L1776338147555_rg4rq\",\"line_id\":\"L1776338147555_rg4rq\",\"id\":\"PIZZA_HAWAJSKA\",\"ascii_key\":\"PIZZA_HAWAJSKA\",\"name\":\"Hawajska\",\"price\":\"28.00\",\"qty\":1,\"quantity\":1,\"vat_rate\":5,\"removed\":[],\"added\":[],\"comment\":\"\",\"is_half\":false,\"half_a\":null,\"half_b\":null}]', '', 'delivered', NULL),
('fd9b25fc-c203-491e-8d4d-2cacf00914d0', 1, 'ORD/20260416/0020', 'Delivery', 'delivery', NULL, NULL, NULL, NULL, NULL, 'POS', NULL, NULL, 13800, 0, 0, 13800, 'cancelled', 'paid', 'online', 0, 'Monika Wójcik', '+48 795 123 456', NULL, 'os. Tysiąclecia 11/30, Poznań', 52.3880000, 16.9550000, '2026-04-16 05:37:06', 9, NULL, NULL, NULL, 0, 0, NULL, '2026-04-16 05:19:06', '2026-04-16 12:40:05', 0, 0, NULL, NULL, NULL, 'unassigned', NULL),
('ff3bbc98-ab25-4db8-8731-12a351bb5bd0', 1, 'S4', 'pos', 'dine_in', NULL, NULL, NULL, NULL, NULL, 'pos', NULL, NULL, 5700, 0, 0, 5700, 'ready', 'unpaid', 'card', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 3, NULL, NULL, NULL, 0, 0, NULL, '2026-04-17 03:11:28', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_order_audit`
--

CREATE TABLE `sh_order_audit` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `order_id` char(36) NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `old_status` varchar(32) DEFAULT NULL,
  `new_status` varchar(32) NOT NULL,
  `timestamp` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sh_order_audit`
--

INSERT INTO `sh_order_audit` (`id`, `order_id`, `user_id`, `old_status`, `new_status`, `timestamp`) VALUES
(231, '6806b54c-8f40-4df5-a49f-fbffbbdc7cf4', 3, 'new', 'preparing', '2026-04-16 05:19:04'),
(232, 'cd7bbc6e-772f-4be2-83a2-2b6bf523d700', 3, 'new', 'ready', '2026-04-16 05:19:04'),
(233, 'b75fa82b-0116-4250-bd8c-25fcb717f3b1', 3, 'new', 'completed', '2026-04-16 05:19:04'),
(234, 'e028f5e8-c7fd-42eb-97e8-05ec94294d75', NULL, 'new', 'pending', '2026-04-16 05:19:04'),
(235, 'c365bf79-2443-4800-9037-f31b30ae5dd4', NULL, 'new', 'ready', '2026-04-16 05:19:04'),
(236, '6f84d5a6-44e8-4283-8953-93ec2f7503ce', NULL, 'preparing', 'ready', '2026-04-16 05:19:04'),
(237, 'd76ff6f1-32cb-4604-a402-638533e1aa09', NULL, 'preparing', 'ready', '2026-04-16 05:19:04'),
(238, '2606e5e7-0a8c-4e34-8139-56ff984899d6', NULL, 'preparing', 'ready', '2026-04-16 05:19:04'),
(239, 'a53b4c9b-5845-45b1-9f7c-69bcbc0ad695', NULL, 'preparing', 'ready', '2026-04-16 05:19:04'),
(240, '5d81a72e-9517-46c5-bf27-88ae7be1f3ff', NULL, 'preparing', 'ready', '2026-04-16 05:19:04'),
(241, 'ba039aa7-26df-47ff-baa3-a6031362e05a', 6, 'in_delivery', 'completed', '2026-04-16 05:19:04'),
(242, 'ba49298f-1763-419c-b495-d7afe0992db9', 6, 'in_delivery', 'completed', '2026-04-16 05:19:04'),
(243, '0adc30b4-d89b-4d19-b7a1-fced39a5deaf', 3, 'ready', 'cancelled', '2026-04-16 12:39:58'),
(244, '655dfde8-e921-4ce6-a94e-82901d788661', 3, 'ready', 'cancelled', '2026-04-16 12:40:00'),
(245, '38c45a98-2b44-40a2-953b-cc8d2509a3f9', 3, 'ready', 'cancelled', '2026-04-16 12:40:03'),
(246, 'fd9b25fc-c203-491e-8d4d-2cacf00914d0', 3, 'ready', 'cancelled', '2026-04-16 12:40:05'),
(247, '6f84d5a6-44e8-4283-8953-93ec2f7503ce', 3, 'ready', 'cancelled', '2026-04-16 12:40:07'),
(248, '2aa9aaa2-571e-4286-befe-bc5664dc694c', 3, 'ready', 'cancelled', '2026-04-16 12:40:10'),
(249, 'd76ff6f1-32cb-4604-a402-638533e1aa09', 3, 'ready', 'cancelled', '2026-04-16 12:40:15'),
(250, '32eeb1cd-ed58-4784-b1bd-9a5dc2aa235e', 3, 'ready', 'cancelled', '2026-04-16 12:40:17'),
(251, '6e8bebdf-14de-496c-9a8f-c3fe0ee1a9eb', 3, 'ready', 'cancelled', '2026-04-16 12:40:19'),
(252, 'e1e41f3b-8dde-487e-a7b7-0f16b26d4efc', 3, 'ready', 'cancelled', '2026-04-16 12:40:21'),
(253, '2606e5e7-0a8c-4e34-8139-56ff984899d6', 3, 'ready', 'cancelled', '2026-04-16 12:40:28'),
(254, 'ae3030b8-ef40-4ce2-9952-4567e26d1270', 3, 'ready', 'cancelled', '2026-04-16 12:40:31'),
(255, 'f90a3ec9-d055-413f-9bf7-5acc53dab1d0', 3, 'ready', 'cancelled', '2026-04-16 12:40:33'),
(256, 'a53b4c9b-5845-45b1-9f7c-69bcbc0ad695', 3, 'ready', 'cancelled', '2026-04-16 12:40:36'),
(257, 'a7f6931c-27fb-49e3-b6cd-a2a8006df4bf', 2, 'ready', 'cancelled', '2026-04-16 12:57:35'),
(258, '92f626d9-538e-416d-a707-1f89d59fbdd8', 2, 'ready', 'cancelled', '2026-04-16 12:57:37'),
(259, 'bfed4919-4c0a-4e47-bc36-058d226e1e33', 2, 'ready', 'cancelled', '2026-04-16 12:57:40'),
(260, '5d81a72e-9517-46c5-bf27-88ae7be1f3ff', 2, 'ready', 'cancelled', '2026-04-16 12:57:42'),
(261, '9ff90ee9-89d9-49c9-b756-6d09ad154f0a', 2, 'ready', 'cancelled', '2026-04-16 12:57:44'),
(262, 'aba34c27-dc11-401c-bb4a-1065e2778641', 2, 'ready', 'cancelled', '2026-04-16 12:57:46'),
(263, 'c365bf79-2443-4800-9037-f31b30ae5dd4', 2, 'ready', 'cancelled', '2026-04-16 12:57:50'),
(264, 'e028f5e8-c7fd-42eb-97e8-05ec94294d75', 2, 'ready', 'cancelled', '2026-04-16 12:57:53'),
(265, '6806b54c-8f40-4df5-a49f-fbffbbdc7cf4', 2, 'ready', 'cancelled', '2026-04-16 12:57:55'),
(266, 'cd7bbc6e-772f-4be2-83a2-2b6bf523d700', 2, 'ready', 'cancelled', '2026-04-16 12:57:58'),
(267, '1aeb78f0-2f74-4ed4-8553-9f335d15650a', 3, 'pending', 'preparing', '2026-04-16 13:03:59'),
(268, 'cc17bad0-2714-47ed-9386-b96b58c450c2', 3, 'pending', 'preparing', '2026-04-16 13:04:07'),
(269, '231d4921-b571-45a5-986f-5e0c59ae7d62', 3, 'unassigned', 'in_delivery', '2026-04-16 13:04:33'),
(270, 'cc17bad0-2714-47ed-9386-b96b58c450c2', 3, 'unassigned', 'in_delivery', '2026-04-16 13:04:33'),
(271, '1aeb78f0-2f74-4ed4-8553-9f335d15650a', 3, 'unassigned', 'in_delivery', '2026-04-16 13:04:33'),
(272, '26128f4b-ff53-41be-b2f4-e0c531babf92', 2, 'pending', 'preparing', '2026-04-16 13:06:30'),
(273, '26128f4b-ff53-41be-b2f4-e0c531babf92', 2, 'preparing', 'ready', '2026-04-16 13:06:31'),
(274, '4845480b-3214-4287-9e87-8b220357ef78', 2, 'pending', 'preparing', '2026-04-16 13:13:16'),
(275, '26128f4b-ff53-41be-b2f4-e0c531babf92', 2, 'ready', 'completed', '2026-04-16 13:14:07'),
(276, '6a0a457e-0226-4a28-95f2-df12da98361e', 2, 'pending', 'preparing', '2026-04-16 13:16:38'),
(277, '6a0a457e-0226-4a28-95f2-df12da98361e', 2, 'preparing', 'ready', '2026-04-16 13:16:39'),
(278, 'fd0b1c4c-5c2a-4bd2-8a8d-8eaf01d1b2c1', 2, 'pending', 'preparing', '2026-04-16 13:16:40'),
(279, 'fd0b1c4c-5c2a-4bd2-8a8d-8eaf01d1b2c1', 2, 'preparing', 'ready', '2026-04-16 13:16:41'),
(280, '3928ce3c-3c92-4884-914f-69148d4ff6d9', 2, 'pending', 'preparing', '2026-04-16 13:16:42'),
(281, '3928ce3c-3c92-4884-914f-69148d4ff6d9', 2, 'preparing', 'ready', '2026-04-16 13:16:42'),
(282, '3928ce3c-3c92-4884-914f-69148d4ff6d9', 2, 'unassigned', 'in_delivery', '2026-04-16 13:16:52'),
(283, 'fd0b1c4c-5c2a-4bd2-8a8d-8eaf01d1b2c1', 2, 'unassigned', 'in_delivery', '2026-04-16 13:16:52'),
(284, '6a0a457e-0226-4a28-95f2-df12da98361e', 2, 'unassigned', 'in_delivery', '2026-04-16 13:16:52'),
(285, '3928ce3c-3c92-4884-914f-69148d4ff6d9', 10, 'ready', 'completed', '2026-04-16 13:22:00'),
(286, 'fd0b1c4c-5c2a-4bd2-8a8d-8eaf01d1b2c1', 10, 'to_pay', 'payment_cash', '2026-04-16 13:22:35'),
(287, 'fd0b1c4c-5c2a-4bd2-8a8d-8eaf01d1b2c1', 10, 'ready', 'completed', '2026-04-16 13:22:35'),
(288, 'fc7acd2f-32e7-4603-bac8-927eb868d63d', 2, 'pending', 'preparing', '2026-04-17 03:10:24'),
(289, 'fc7acd2f-32e7-4603-bac8-927eb868d63d', 2, 'preparing', 'ready', '2026-04-17 03:10:25'),
(290, '4845480b-3214-4287-9e87-8b220357ef78', 2, 'preparing', 'cancelled', '2026-04-17 03:10:33'),
(291, 'c5574d32-b7c3-468d-b6ba-06c1dff48033', 2, 'pending', 'cancelled', '2026-04-17 03:10:36'),
(292, '1cd1050b-98b4-4d8c-a143-325b5a0dcbc4', 3, 'new', 'preparing', '2026-04-17 03:11:28'),
(293, 'ff3bbc98-ab25-4db8-8731-12a351bb5bd0', 3, 'new', 'ready', '2026-04-17 03:11:28'),
(294, '39b70fae-7667-47c6-84eb-6d0cd8499fcd', 3, 'new', 'completed', '2026-04-17 03:11:28'),
(295, '5299aba2-dbad-464f-9a97-f661e48177d9', NULL, 'new', 'pending', '2026-04-17 03:11:28'),
(296, 'b74488fe-a664-40d1-992c-d62c3eeb7058', NULL, 'new', 'ready', '2026-04-17 03:11:28'),
(297, 'b33c4b1e-828c-4514-bd83-683316a7cfde', NULL, 'preparing', 'ready', '2026-04-17 03:11:28'),
(298, '24b89d25-db01-456c-a77b-dbdf308f95b5', NULL, 'preparing', 'ready', '2026-04-17 03:11:28'),
(299, '9fb40f85-91f8-45a6-8774-1c05c94ddf4c', NULL, 'preparing', 'ready', '2026-04-17 03:11:28'),
(300, '2316316b-a1e7-493d-885f-a0c8a3372195', NULL, 'preparing', 'ready', '2026-04-17 03:11:28'),
(301, '49d438fe-c22c-44ad-90c1-14aac07da9d5', NULL, 'preparing', 'ready', '2026-04-17 03:11:28'),
(302, '3463368c-d99c-42e9-99a9-d112c92c1f33', 6, 'in_delivery', 'completed', '2026-04-17 03:11:28'),
(303, '74d47591-275e-4727-a23e-de09d3c63563', 6, 'in_delivery', 'completed', '2026-04-17 03:11:28'),
(304, 'd6e82a8f-9fcb-4f27-a5e3-70b70242babb', 3, 'new', 'preparing', '2026-04-17 03:12:04'),
(305, '0a5dfced-8920-44ea-afc4-aa5f890cc95e', 3, 'new', 'ready', '2026-04-17 03:12:04'),
(306, '7bd2e3f4-d689-4c49-8415-75ed5e879bb5', 3, 'new', 'completed', '2026-04-17 03:12:04'),
(307, 'f4656ac5-3ab5-4168-88d5-9c956bad83a1', NULL, 'new', 'pending', '2026-04-17 03:12:04'),
(308, 'ae252b3e-5e97-458d-8f9e-a57dac0b2304', NULL, 'new', 'ready', '2026-04-17 03:12:04'),
(309, '80fd22d8-ee3a-44b0-973c-6f65abbd54f2', NULL, 'preparing', 'ready', '2026-04-17 03:12:04'),
(310, '797998a1-f625-4e71-a774-38602b91f4a9', NULL, 'preparing', 'ready', '2026-04-17 03:12:04'),
(311, '425d27b7-7454-492e-8b74-e247c005dfac', NULL, 'preparing', 'ready', '2026-04-17 03:12:04'),
(312, '645db9b1-072c-4a60-86a8-a585536878dd', NULL, 'preparing', 'ready', '2026-04-17 03:12:04'),
(313, '7c043a11-b5e3-41b6-8ca6-acf60b6cbd1a', NULL, 'preparing', 'ready', '2026-04-17 03:12:04'),
(314, '3c27c4fb-567d-4e40-9a5f-19d55a82069a', 6, 'in_delivery', 'completed', '2026-04-17 03:12:04'),
(315, '7e9f030d-5df5-4bce-b096-25c6cb407ab3', 6, 'in_delivery', 'completed', '2026-04-17 03:12:04'),
(316, 'fa9c3a38-d46f-4480-9965-4d3bfd387d9c', 3, 'new', 'preparing', '2026-04-17 06:22:15'),
(317, '581e533e-7e45-4072-a94e-0415513a4f1b', 3, 'new', 'ready', '2026-04-17 06:22:15'),
(318, '91253b46-5b47-4a55-afa6-a3ccf3247491', 3, 'new', 'completed', '2026-04-17 06:22:15'),
(319, '8653afa5-d893-47b2-b235-87c271b28d09', NULL, 'new', 'pending', '2026-04-17 06:22:15'),
(320, '7d535f2f-ad05-4aef-a667-877a8f1c673d', NULL, 'new', 'ready', '2026-04-17 06:22:15'),
(321, 'd73e8154-501b-443b-9721-9482d6a436a3', NULL, 'preparing', 'ready', '2026-04-17 06:22:15'),
(322, '3cf9427f-b9c2-46d9-b0b8-3a607f69c3b6', NULL, 'preparing', 'ready', '2026-04-17 06:22:15'),
(323, '8506f171-dd58-4d6e-a268-64a9dface5f2', NULL, 'preparing', 'ready', '2026-04-17 06:22:15'),
(324, 'e1054790-9dd8-476b-afa0-701e69ffdec0', NULL, 'preparing', 'ready', '2026-04-17 06:22:15'),
(325, '0b0728b6-f94a-4901-8815-99c61db77bbf', NULL, 'preparing', 'ready', '2026-04-17 06:22:15'),
(326, '7bbf21fc-750c-4107-8c53-b81dc0be3080', 6, 'in_delivery', 'completed', '2026-04-17 06:22:15'),
(327, 'f0fd6d73-a538-404f-9f32-9f4db26d14bf', 6, 'in_delivery', 'completed', '2026-04-17 06:22:15'),
(328, '857d9ade-143e-4b03-9839-1da9b52d07e6', 3, 'new', 'preparing', '2026-04-17 18:34:47'),
(329, 'cb3c200c-5769-4cdd-ab5f-4d310fb08f90', 3, 'new', 'ready', '2026-04-17 18:34:47'),
(330, '4e7edd28-1b94-40aa-8da8-39c8229f53ca', 3, 'new', 'completed', '2026-04-17 18:34:47'),
(331, '41aa3420-2a7b-4cd8-b29f-5c355bbbf581', NULL, 'new', 'pending', '2026-04-17 18:34:47'),
(332, '9a0cf0da-22cb-4643-aa17-0b8f53347cdf', NULL, 'new', 'ready', '2026-04-17 18:34:47'),
(333, '5e6a8437-8c22-4d99-bb12-d85e4d423116', NULL, 'preparing', 'ready', '2026-04-17 18:34:47'),
(334, '8ad39786-7dbd-4183-8219-34b796f5eefc', NULL, 'preparing', 'ready', '2026-04-17 18:34:47'),
(335, '11f93faa-4fe8-45a4-9507-ca17a91e9fcb', NULL, 'preparing', 'ready', '2026-04-17 18:34:47'),
(336, '81deb272-c484-4fed-8699-ad4cd3b82054', NULL, 'preparing', 'ready', '2026-04-17 18:34:47'),
(337, '33b87d71-cd7c-4265-9b7f-389a2bfb6cf1', NULL, 'preparing', 'ready', '2026-04-17 18:34:48'),
(338, 'd75352a4-6984-4452-9440-a5e39ad13ea7', 6, 'in_delivery', 'completed', '2026-04-17 18:34:48'),
(339, '98f2f88e-c53c-4d08-9c04-cbeeccaf3be6', 6, 'in_delivery', 'completed', '2026-04-17 18:34:48');

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_order_item_modifiers`
--

CREATE TABLE `sh_order_item_modifiers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `order_item_id` char(36) NOT NULL,
  `modifier_type` varchar(16) NOT NULL,
  `modifier_sku` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sh_order_item_modifiers`
--

INSERT INTO `sh_order_item_modifiers` (`id`, `order_item_id`, `modifier_type`, `modifier_sku`) VALUES
(1, 'a95f259a-0d68-4cd1-8591-ce840d2b5d35', 'ADDED', 'OPT_JALAPENO'),
(2, 'a95f259a-0d68-4cd1-8591-ce840d2b5d35', 'REMOVED', 'SER_MOZZARELLA'),
(3, '03178252-e950-4b65-bd99-190ffad4ea40', 'ADDED', 'OPT_JALAPENO'),
(4, '03178252-e950-4b65-bd99-190ffad4ea40', 'REMOVED', 'SER_MOZZARELLA'),
(5, '169acf7f-121d-4160-9284-a5e6b35b08b7', 'ADDED', 'EXTRA_JALAP'),
(6, '169acf7f-121d-4160-9284-a5e6b35b08b7', 'ADDED', 'EXTRA_CHEESE'),
(7, '169acf7f-121d-4160-9284-a5e6b35b08b7', 'ADDED', 'EXTRA_HAM'),
(8, 'd2b14be3-5893-45bc-be8e-163de8cd3c8e', 'ADDED', 'OPT_JALAPENO'),
(9, 'd2b14be3-5893-45bc-be8e-163de8cd3c8e', 'REMOVED', 'SER_MOZZARELLA'),
(10, 'dbe756e0-6ae0-4e3a-a324-bb87d671d066', 'ADDED', 'SIZE_L'),
(11, '9a0dc775-9d13-4133-8f59-3bbca4be4437', 'ADDED', 'OPT_JALAPENO'),
(12, '9a0dc775-9d13-4133-8f59-3bbca4be4437', 'REMOVED', 'SER_MOZZARELLA'),
(13, '47af754a-0957-47cd-a2d6-719542fd9dca', 'ADDED', 'OPT_JALAPENO'),
(14, '47af754a-0957-47cd-a2d6-719542fd9dca', 'REMOVED', 'SER_MOZZARELLA'),
(15, 'e2835a41-1359-4324-b715-d6d98bb9da85', 'ADDED', 'SIZE_S'),
(16, 'e2835a41-1359-4324-b715-d6d98bb9da85', 'ADDED', 'EXTRA_OLIVES'),
(17, 'e2835a41-1359-4324-b715-d6d98bb9da85', 'REMOVED', 'SER_MOZZ'),
(18, 'e8a74a9f-02c6-484e-ae34-49468536948d', 'ADDED', 'OPT_JALAPENO'),
(19, 'e8a74a9f-02c6-484e-ae34-49468536948d', 'REMOVED', 'SER_MOZZARELLA'),
(23, '71e6790d-e5c7-4763-ae7b-b2bd8ea2266a', 'ADDED', 'EXTRA_JALAP'),
(24, '71e6790d-e5c7-4763-ae7b-b2bd8ea2266a', 'ADDED', 'EXTRA_CHEESE'),
(25, '05d608f2-ef05-475d-8b7c-87ad8140bc5b', 'ADDED', 'EXTRA_JALAP'),
(26, '05d608f2-ef05-475d-8b7c-87ad8140bc5b', 'ADDED', 'SIZE_S'),
(27, '05d608f2-ef05-475d-8b7c-87ad8140bc5b', 'REMOVED', 'SER_MOZZ'),
(28, '87812328-8f9f-46de-b411-e961e2842f09', 'ADDED', 'EXTRA_JALAP'),
(29, '87812328-8f9f-46de-b411-e961e2842f09', 'ADDED', 'SIZE_XL'),
(30, '3c0e646f-3583-4a59-825f-50c2941c5de2', 'ADDED', 'EXTRA_OLIVES'),
(31, '3c0e646f-3583-4a59-825f-50c2941c5de2', 'REMOVED', 'ANANAS'),
(32, '00c0745a-0149-43df-8fec-8585dd772eb1', 'ADDED', 'EXTRA_JALAP'),
(33, '00c0745a-0149-43df-8fec-8585dd772eb1', 'ADDED', 'EXTRA_CHEESE'),
(34, '00c0745a-0149-43df-8fec-8585dd772eb1', 'ADDED', 'SIZE_XL'),
(35, '00c0745a-0149-43df-8fec-8585dd772eb1', 'REMOVED', 'OPAK_PIZZA'),
(36, '00c0745a-0149-43df-8fec-8585dd772eb1', 'REMOVED', 'SER_MOZZ'),
(37, '3f7b5c90-08d4-4d51-9869-355cb9ee0597', 'ADDED', 'SIZE_L'),
(38, '3f7b5c90-08d4-4d51-9869-355cb9ee0597', 'ADDED', 'SIZE_XL'),
(39, '3f7b5c90-08d4-4d51-9869-355cb9ee0597', 'ADDED', 'EXTRA_OLIVES'),
(40, '4ba7ec93-abf5-42a2-84ae-7725d86766ff', 'ADDED', 'SIZE_M'),
(41, 'ecdd55f3-b3df-4518-a53f-cd52252fa40d', 'ADDED', 'OPT_JALAPENO'),
(42, 'ecdd55f3-b3df-4518-a53f-cd52252fa40d', 'REMOVED', 'SER_MOZZARELLA'),
(47, 'dda01445-bbb8-48b6-adc1-d19c3e27b3fb', 'ADDED', 'EXTRA_OLIVES'),
(48, '97b4060a-634d-4885-a81f-b5b8e50f7dbf', 'ADDED', 'EXTRA_OLIVES'),
(49, '97b4060a-634d-4885-a81f-b5b8e50f7dbf', 'ADDED', 'EXTRA_CHEESE'),
(50, '97b4060a-634d-4885-a81f-b5b8e50f7dbf', 'REMOVED', 'MKA_TIPO00'),
(51, '97b4060a-634d-4885-a81f-b5b8e50f7dbf', 'REMOVED', 'OPAK_PIZZA'),
(52, '97b4060a-634d-4885-a81f-b5b8e50f7dbf', 'REMOVED', 'SER_MOZZ'),
(53, 'd835ab8c-e2fb-49f3-bce8-a35d4970973e', 'ADDED', 'OPT_JALAPENO'),
(54, 'd835ab8c-e2fb-49f3-bce8-a35d4970973e', 'REMOVED', 'SER_MOZZARELLA');

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_order_lines`
--

CREATE TABLE `sh_order_lines` (
  `id` char(36) NOT NULL,
  `order_id` char(36) NOT NULL,
  `item_sku` varchar(255) NOT NULL,
  `snapshot_name` varchar(255) NOT NULL,
  `unit_price` int(11) NOT NULL DEFAULT 0 COMMENT 'Grosze',
  `quantity` int(11) NOT NULL DEFAULT 1,
  `line_total` int(11) NOT NULL DEFAULT 0,
  `vat_rate` decimal(6,2) NOT NULL DEFAULT 0.00,
  `vat_amount` int(11) NOT NULL DEFAULT 0,
  `modifiers_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`modifiers_json`)),
  `removed_ingredients_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`removed_ingredients_json`)),
  `comment` varchar(512) DEFAULT NULL,
  `driver_action_type` enum('none','pack_cold','pack_separate','check_id') NOT NULL DEFAULT 'none',
  `kds_ticket_id` char(36) DEFAULT NULL,
  `course_number` int(11) NOT NULL DEFAULT 1 COMMENT 'Course sequence for pacing',
  `fired_at` datetime DEFAULT NULL COMMENT 'Timestamp when course was fired to KDS',
  `line_status` varchar(16) NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sh_order_lines`
--

INSERT INTO `sh_order_lines` (`id`, `order_id`, `item_sku`, `snapshot_name`, `unit_price`, `quantity`, `line_total`, `vat_rate`, `vat_amount`, `modifiers_json`, `removed_ingredients_json`, `comment`, `driver_action_type`, `kds_ticket_id`, `course_number`, `fired_at`, `line_status`) VALUES
('0044ae2f-9f6c-42dc-889b-7239d947cb0c', '74d47591-275e-4727-a23e-de09d3c63563', 'DRINK_COLA_05', 'Cola 0.5L', 800, 1, 800, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('02ed8192-c696-4104-a0dd-34ff92a3c551', 'f90a3ec9-d055-413f-9bf7-5acc53dab1d0', 'seed_item', 'Sałatka Cezar', 1800, 2, 3600, 5.00, 171, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('065c9802-c930-4622-bc4e-033a98bb42f9', '797998a1-f625-4e71-a774-38602b91f4a9', 'SIDE_GARLIC_SAUCE', 'Sos czosnkowy', 300, 2, 600, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('06a00e8f-8d7d-4e0d-999a-317c9db9f0f9', '7d535f2f-ad05-4aef-a667-877a8f1c673d', 'SET_BURGER_COMBO', 'Zestaw Burger+Frytki+Napój', 3200, 1, 3200, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('06a8f6c9-fbc5-4740-81e0-c1467fb62125', '3cf9427f-b9c2-46d9-b0b8-3a607f69c3b6', 'PIZZA_4FORMAGGI', 'Quattro Formaggi', 3400, 1, 3400, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('0798485a-4683-4ff7-938f-a2b7cc6678d7', 'ba039aa7-26df-47ff-baa3-a6031362e05a', 'PIZZA_HAWAJSKA', 'Hawajska', 3000, 1, 3000, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('0812de81-ecec-40d6-b710-0b0346d3dcb3', '2aa9aaa2-571e-4286-befe-bc5664dc694c', 'seed_item', 'Sałatka Cezar', 1800, 1, 1800, 5.00, 86, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('0af25ddb-5556-4f2c-81e3-d6e1c5b0db03', 'ba49298f-1763-419c-b495-d7afe0992db9', 'BURGER_CLASSIC', 'Classic Burger', 2400, 1, 2400, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('0b873c27-7daa-4915-a177-0575962dc3aa', 'd6e82a8f-9fcb-4f27-a5e3-70b70242babb', 'PIZZA_MARGHERITA', 'Margherita', 2400, 1, 2400, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('0c7c9718-2f30-4e78-a0d1-3ffd356edd75', '7c043a11-b5e3-41b6-8ca6-acf60b6cbd1a', 'DRINK_BEER_TYSKIE', 'Piwo Tyskie', 1000, 1, 1000, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('0d296ddb-9e9a-4a5b-abaa-682c053aaee1', '74d47591-275e-4727-a23e-de09d3c63563', 'BURGER_CLASSIC', 'Classic Burger', 2400, 1, 2400, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('0d92fde0-61d8-43fe-abf3-bc6fe6839b4a', '6f84d5a6-44e8-4283-8953-93ec2f7503ce', 'DRINK_COLA_05', 'Coca-Cola 0.5L', 800, 1, 800, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('1082d256-fd48-4759-97cf-7c067befe29d', '7c043a11-b5e3-41b6-8ca6-acf60b6cbd1a', 'DESSERT_TIRAMISU', 'Tiramisu', 1800, 1, 1800, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('13c0e962-524d-40d7-a6a6-3d23f7f7bedf', '92f626d9-538e-416d-a707-1f89d59fbdd8', 'seed_item', 'Sałatka Cezar', 1800, 1, 1800, 5.00, 86, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('14a39891-4b3b-4f97-86f2-af82736ab4e2', '5e6a8437-8c22-4d99-bb12-d85e4d423116', 'PIZZA_CAPRICCIOSA', 'Capricciosa', 3000, 1, 3000, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('1735301f-cb66-4b49-990d-51d05b90f6fb', 'cc17bad0-2714-47ed-9386-b96b58c450c2', 'PIZZA_PEPPERONI', 'Pepperoni', 2800, 1, 2800, 5.00, 133, NULL, NULL, '', 'none', NULL, 1, NULL, 'active'),
('180b0fd3-be28-40f8-92c8-30c9b6c11f57', 'b33c4b1e-828c-4514-bd83-683316a7cfde', 'DRINK_COLA_05', 'Coca-Cola 0.5L', 800, 1, 800, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('1a085310-ae80-4b50-bdf0-f437dd1fbdcc', '645db9b1-072c-4a60-86a8-a585536878dd', 'PIZZA_DIAVOLA', 'Diavola', 3200, 1, 3200, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('1cd443d9-43c3-42d7-9b85-e5398bd0520f', '91253b46-5b47-4a55-afa6-a3ccf3247491', 'PASTA_CARBONARA', 'Penne Carbonara', 2800, 1, 2800, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('1f60f6ac-34cf-4201-9092-b59ca658e359', '8ad39786-7dbd-4183-8219-34b796f5eefc', 'PIZZA_4FORMAGGI', 'Quattro Formaggi', 3400, 1, 3400, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('20fc7844-c2f7-4a62-b3fd-0f2b8025c446', 'e1e41f3b-8dde-487e-a7b7-0f16b26d4efc', 'FRIES', 'Frytki duże', 1200, 1, 1200, 8.00, 89, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('22939d3d-0280-4ab7-b801-e37c0f521b26', '8ad39786-7dbd-4183-8219-34b796f5eefc', 'SIDE_GARLIC_SAUCE', 'Sos czosnkowy', 300, 2, 600, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('23d3c0de-9674-4565-bc99-5d3eb8a6f408', 'cd7bbc6e-772f-4be2-83a2-2b6bf523d700', 'SIDE_FRIES', 'Frytki', 900, 1, 900, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('2910d994-852c-4a8d-9d46-9ac900d5ca0d', '8653afa5-d893-47b2-b235-87c271b28d09', 'PIZZA_PEPPERONI', 'Pepperoni', 2800, 1, 2800, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('29f61832-789e-43fc-9016-68007c1bf00b', 'fd9b25fc-c203-491e-8d4d-2cacf00914d0', 'seed_item', 'Pepperoni 32cm', 3200, 2, 6400, 5.00, 305, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('2d7901bf-444f-4038-9e31-962a8b2c14fe', '38c45a98-2b44-40a2-953b-cc8d2509a3f9', 'seed_item', 'Pepperoni 32cm', 3200, 1, 3200, 5.00, 152, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('2e2777bd-2195-4464-9f19-afcc97078123', '1cd1050b-98b4-4d8c-a143-325b5a0dcbc4', 'DRINK_COLA_05', 'Coca-Cola 0.5L', 700, 2, 1400, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('2e65e413-a7c5-49bd-9fba-e7cd797d357b', '425d27b7-7454-492e-8b74-e247c005dfac', 'BURGER_BBQ', 'BBQ Burger', 2800, 2, 5600, 0.00, 0, NULL, NULL, 'Bez cebuli, extra sos', 'none', NULL, 1, NULL, 'active'),
('33c64528-4435-4567-97a4-71f509b3eac4', 'fd0b1c4c-5c2a-4bd2-8a8d-8eaf01d1b2c1', 'PIZZA_HAWAJSKA', 'Hawajska', 2800, 1, 2800, 5.00, 133, NULL, NULL, '', 'none', NULL, 1, NULL, 'active'),
('35e81177-744c-4d7d-a228-e346ae019caa', '5299aba2-dbad-464f-9a97-f661e48177d9', 'DRINK_SPRITE_05', 'Sprite 0.5L', 700, 1, 700, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('374e5854-7b13-4852-ae87-2ef700901912', '24b89d25-db01-456c-a77b-dbdf308f95b5', 'PIZZA_4FORMAGGI', 'Quattro Formaggi', 3400, 1, 3400, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('3cf14bd3-d15d-4fee-9d67-c679197647bb', 'b75fa82b-0116-4250-bd8c-25fcb717f3b1', 'DRINK_WATER_05', 'Woda 0.5L', 500, 1, 500, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('3e06bed3-101c-435c-b2cd-c4c9c24d9068', 'ff3bbc98-ab25-4db8-8731-12a351bb5bd0', 'BURGER_CHEESE', 'Cheese Burger', 2400, 2, 4800, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('3f23aff4-91f2-49bb-a799-688ec9d7e21d', '7e9f030d-5df5-4bce-b096-25c6cb407ab3', 'BURGER_CLASSIC', 'Classic Burger', 2400, 1, 2400, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('3f75215a-29db-4359-b610-97bd9bee3d8e', '39b70fae-7667-47c6-84eb-6d0cd8499fcd', 'PASTA_CARBONARA', 'Penne Carbonara', 2800, 1, 2800, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('3fa83575-205f-4f4d-a130-1492d5fad523', '6806b54c-8f40-4df5-a49f-fbffbbdc7cf4', 'DRINK_COLA_05', 'Coca-Cola 0.5L', 700, 2, 1400, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('41521962-bb89-4b42-ac51-fa11e1479c8c', 'f90a3ec9-d055-413f-9bf7-5acc53dab1d0', 'seed_item', 'Frytki duże', 1200, 2, 2400, 5.00, 114, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('424f4ef0-32f1-4ed0-8be6-5bb344a2e904', 'f4656ac5-3ab5-4168-88d5-9c956bad83a1', 'PIZZA_PEPPERONI', 'Pepperoni', 2800, 1, 2800, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('4423665d-548d-4b62-bd67-5e358420391a', 'e028f5e8-c7fd-42eb-97e8-05ec94294d75', 'DRINK_SPRITE_05', 'Sprite 0.5L', 700, 1, 700, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('44a31ae1-139c-4849-b3a0-0dbbfeddbc42', 'd75352a4-6984-4452-9440-a5e39ad13ea7', 'PIZZA_HAWAJSKA', 'Hawajska', 3000, 1, 3000, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('46beb6d7-e4d8-402e-9c60-32f08812acae', 'ae3030b8-ef40-4ce2-9952-4567e26d1270', 'seed_item', 'Margherita 32cm', 2800, 1, 2800, 5.00, 133, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('47b73d83-ae36-42b6-a870-e68849067da4', '80fd22d8-ee3a-44b0-973c-6f65abbd54f2', 'DRINK_COLA_05', 'Coca-Cola 0.5L', 800, 1, 800, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('4ca9d3f5-d8cd-40eb-8710-f37de6d2c954', '3c27c4fb-567d-4e40-9a5f-19d55a82069a', 'PIZZA_HAWAJSKA', 'Hawajska', 3000, 1, 3000, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('4cd5c3b5-27d1-4b5a-b3bc-2bec7132855a', '857d9ade-143e-4b03-9839-1da9b52d07e6', 'PIZZA_MARGHERITA', 'Margherita', 2400, 1, 2400, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('4e48bcb8-a8c9-4ef6-9cd9-5756a77ecdcb', 'e1054790-9dd8-476b-afa0-701e69ffdec0', 'PIZZA_DIAVOLA', 'Diavola', 3200, 1, 3200, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('500b693d-7dc0-4ad3-9808-6f105990451b', '3463368c-d99c-42e9-99a9-d112c92c1f33', 'PIZZA_HAWAJSKA', 'Hawajska', 3000, 1, 3000, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('5557fb2a-d986-47d4-ab43-065cafd5e3d7', 'aba34c27-dc11-401c-bb4a-1065e2778641', 'seed_item', 'Kebab XL', 2600, 1, 2600, 5.00, 124, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('57c85cee-ae0d-40e6-b479-ff8b2743cf9e', '7c043a11-b5e3-41b6-8ca6-acf60b6cbd1a', 'PASTA_LASAGNE', 'Lasagne', 3200, 1, 3200, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('593da151-b6cd-4f5e-be1e-45dc98942601', '6806b54c-8f40-4df5-a49f-fbffbbdc7cf4', 'PIZZA_MARGHERITA', 'Margherita', 2400, 1, 2400, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('5a32e5a3-fcfe-4264-8a4c-651899ad3391', '98f2f88e-c53c-4d08-9c04-cbeeccaf3be6', 'BURGER_CLASSIC', 'Classic Burger', 2400, 1, 2400, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('5a5ba85d-3cee-4b25-8375-d7b05806baa1', 'b33c4b1e-828c-4514-bd83-683316a7cfde', 'PIZZA_CAPRICCIOSA', 'Capricciosa', 3000, 1, 3000, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('5d00fc94-0556-4b61-be2c-206653fd0c13', '24b89d25-db01-456c-a77b-dbdf308f95b5', 'SIDE_GARLIC_SAUCE', 'Sos czosnkowy', 300, 2, 600, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('5d4e8d21-08a8-42b9-a650-a81e73cedf7e', '38c45a98-2b44-40a2-953b-cc8d2509a3f9', 'seed_item', 'Capricciosa 32cm', 3400, 2, 6800, 5.00, 324, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('5df29f2c-9548-4b89-887e-fa1e91a2bb5c', 'f4656ac5-3ab5-4168-88d5-9c956bad83a1', 'DRINK_SPRITE_05', 'Sprite 0.5L', 700, 1, 700, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('5e058bf8-42de-4fb0-ba65-66de9b5ce9c4', 'fd9b25fc-c203-491e-8d4d-2cacf00914d0', 'seed_item', 'Burger Classic', 2200, 1, 2200, 5.00, 105, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('5e4ca536-e724-4013-813e-200167746340', 'd6e82a8f-9fcb-4f27-a5e3-70b70242babb', 'DRINK_COLA_05', 'Coca-Cola 0.5L', 700, 2, 1400, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('5e5fb374-ad76-4f01-a9f1-53414ad7cdcd', '6e8bebdf-14de-496c-9a8f-c3fe0ee1a9eb', 'MARG32', 'Margherita 32cm', 2800, 1, 2800, 8.00, 207, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('5ef9ec05-c7a6-4c51-b9a2-2a09cbc63449', '26128f4b-ff53-41be-b2f4-e0c531babf92', 'BURGER_BBQ', 'BBQ Burger', 2600, 1, 2600, 5.00, 124, NULL, NULL, '', 'none', NULL, 1, NULL, 'active'),
('622137ab-a493-49a6-8cae-909379c67e41', '07ffa7b6-4341-43f7-8679-e31c9f9872cc', 'BURGER_CHEESE', 'Cheese Burger', 2400, 1, 2400, 8.00, 178, NULL, NULL, '', 'none', NULL, 1, NULL, 'active'),
('623df8f9-4fb3-4b69-858f-f729ec11a3e0', '4845480b-3214-4287-9e87-8b220357ef78', 'SALAD_GREEK', 'Sałatka Grecka', 2000, 1, 2000, 5.00, 95, NULL, NULL, '', 'none', NULL, 1, NULL, 'active'),
('63774f00-f760-403a-8b78-4b2ccf33568c', '8506f171-dd58-4d6e-a268-64a9dface5f2', 'BURGER_BBQ', 'BBQ Burger', 2800, 2, 5600, 0.00, 0, NULL, NULL, 'Bez cebuli, extra sos', 'none', NULL, 1, NULL, 'active'),
('64865a3a-3bf8-45da-93d9-4aadc8e834dd', '9fb40f85-91f8-45a6-8774-1c05c94ddf4c', 'SIDE_FRIES', 'Frytki', 1000, 1, 1000, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('64ff3f84-08a3-4024-babf-29cae2fccf40', '33b87d71-cd7c-4265-9b7f-389a2bfb6cf1', 'PASTA_LASAGNE', 'Lasagne', 3200, 1, 3200, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('65982802-325c-410d-9f00-ab3ff7c2f70a', '7bd2e3f4-d689-4c49-8415-75ed5e879bb5', 'PASTA_CARBONARA', 'Penne Carbonara', 2800, 1, 2800, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('6a2173ed-6444-4b2b-ab40-206d8f53c321', '9ff90ee9-89d9-49c9-b756-6d09ad154f0a', 'seed_item', 'Pepperoni 32cm', 3200, 1, 3200, 5.00, 152, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('6ab7923c-bffa-4ee3-84ea-d6b3efd3833c', '581e533e-7e45-4072-a94e-0415513a4f1b', 'BURGER_CHEESE', 'Cheese Burger', 2400, 2, 4800, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('6d3eb954-d12b-4e5f-a8eb-f3e831eb364b', 'e1054790-9dd8-476b-afa0-701e69ffdec0', 'PIZZA_MARGHERITA', 'Margherita', 2600, 1, 2600, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('6f28efee-eb49-4ebf-9c26-922e07815f19', 'a7f6931c-27fb-49e3-b6cd-a2a8006df4bf', 'seed_item', 'Kebab XL', 2600, 2, 5200, 5.00, 248, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('7171c5b2-c667-4160-85d8-dac4ecb1b3f2', 'b74488fe-a664-40d1-992c-d62c3eeb7058', 'SET_BURGER_COMBO', 'Zestaw Burger+Frytki+Napój', 3200, 1, 3200, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('72587add-6d4e-4df3-8fea-b40b878ab64e', '2aa9aaa2-571e-4286-befe-bc5664dc694c', 'seed_item', 'Pepperoni 32cm', 3200, 1, 3200, 5.00, 152, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('753fd69d-e2cd-4cc1-9e67-3ff8a77f3f7c', '41aa3420-2a7b-4cd8-b29f-5c355bbbf581', 'PIZZA_PEPPERONI', 'Pepperoni', 2800, 1, 2800, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('76daa153-cb2f-4890-ac55-5578420d1aa0', '9fb40f85-91f8-45a6-8774-1c05c94ddf4c', 'BURGER_BBQ', 'BBQ Burger', 2800, 2, 5600, 0.00, 0, NULL, NULL, 'Bez cebuli, extra sos', 'none', NULL, 1, NULL, 'active'),
('77caebef-66cf-44c0-995c-5799692ad6f7', '4e7edd28-1b94-40aa-8da8-39c8229f53ca', 'PASTA_CARBONARA', 'Penne Carbonara', 2800, 1, 2800, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('78112df2-6e81-456c-80da-449da0d7a98c', '11f93faa-4fe8-45a4-9507-ca17a91e9fcb', 'SIDE_FRIES', 'Frytki', 1000, 1, 1000, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('784dacf2-eb91-4d0a-96ea-b47f0e3c27ec', '7bd2e3f4-d689-4c49-8415-75ed5e879bb5', 'DRINK_WATER_05', 'Woda 0.5L', 500, 1, 500, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('7a57c18b-dc24-43ff-aa99-bb2688ece915', 'f90a3ec9-d055-413f-9bf7-5acc53dab1d0', 'seed_item', 'Burger Classic', 2200, 2, 4400, 5.00, 210, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('7c07ce00-bfcf-4131-b8e6-5e5bea8b57ed', 'f0fd6d73-a538-404f-9f32-9f4db26d14bf', 'DRINK_COLA_05', 'Cola 0.5L', 800, 1, 800, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('7d2dc7c8-a3a9-4ad6-b694-6b73caeaee2d', '0b0728b6-f94a-4901-8815-99c61db77bbf', 'DESSERT_TIRAMISU', 'Tiramisu', 1800, 1, 1800, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('7d9204bd-70fe-417e-a41b-058a28405d94', 'a53b4c9b-5845-45b1-9f7c-69bcbc0ad695', 'PIZZA_MARGHERITA', 'Margherita', 2600, 1, 2600, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('8067e438-d5e3-46cf-b944-f7ac350b64b9', 'b75fa82b-0116-4250-bd8c-25fcb717f3b1', 'PASTA_CARBONARA', 'Penne Carbonara', 2800, 1, 2800, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('8089ba09-660c-4430-bd40-401664d5fb66', '231d4921-b571-45a5-986f-5e0c59ae7d62', 'PIZZA_PEPPERONI', 'Pepperoni', 2800, 1, 2800, 5.00, 133, NULL, NULL, '', 'none', NULL, 1, NULL, 'active'),
('81057df7-15ef-4ccf-8dea-bf7e0f76855b', '3928ce3c-3c92-4884-914f-69148d4ff6d9', 'BURGER_BBQ', 'BBQ Burger', 2600, 1, 2600, 5.00, 124, NULL, NULL, '', 'none', NULL, 1, NULL, 'active'),
('865c3b7a-a3c9-42c9-bf40-d1c4150004e2', 'bfed4919-4c0a-4e47-bc36-058d226e1e33', 'seed_item', 'Burger Classic', 2200, 1, 2200, 5.00, 105, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('88e63be8-1b2e-41ff-9f71-f7f71f17b888', 'cb3c200c-5769-4cdd-ab5f-4d310fb08f90', 'BURGER_CHEESE', 'Cheese Burger', 2400, 2, 4800, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('8b6ad83c-1e01-4b71-a045-f93637149003', 'fa9c3a38-d46f-4480-9965-4d3bfd387d9c', 'DRINK_COLA_05', 'Coca-Cola 0.5L', 700, 2, 1400, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('8c626f30-8311-4421-b430-5aa595b8709f', '645db9b1-072c-4a60-86a8-a585536878dd', 'PIZZA_MARGHERITA', 'Margherita', 2600, 1, 2600, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('8edb7691-a160-470e-ba34-fd958e35e6ea', 'cd7bbc6e-772f-4be2-83a2-2b6bf523d700', 'BURGER_CHEESE', 'Cheese Burger', 2400, 2, 4800, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('8f42090f-fd8c-416d-a956-620ab9d11f3e', 'ae3030b8-ef40-4ce2-9952-4567e26d1270', 'seed_item', 'Pepperoni 32cm', 3200, 2, 6400, 5.00, 305, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('94aad065-b063-4b4a-92cc-f8694b84acda', 'aba34c27-dc11-401c-bb4a-1065e2778641', 'seed_item', 'Burger Classic', 2200, 1, 2200, 5.00, 105, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('97b4060a-634d-4885-a81f-b5b8e50f7dbf', '1aeb78f0-2f74-4ed4-8553-9f335d15650a', 'PIZZA_CAPRICCIOSA', 'Capricciosa', 3700, 1, 3700, 8.00, 274, '[{\"ascii_key\":\"EXTRA_OLIVES\",\"name\":\"Oliwki\",\"price\":\"3.00\"},{\"ascii_key\":\"EXTRA_CHEESE\",\"name\":\"Podwójny ser\",\"price\":\"4.00\"}]', '[{\"sku\":\"MKA_TIPO00\",\"name\":\"Mąka Caputo Tipo 00\"},{\"sku\":\"OPAK_PIZZA\",\"name\":\"Opakowanie karton pizza 32cm\"},{\"sku\":\"SER_MOZZ\",\"name\":\"Ser Mozzarella Fior di Latte\"}]', '', 'none', NULL, 1, NULL, 'active'),
('97bc3e57-24b8-4e2c-9f83-53a5499b1b38', '231d4921-b571-45a5-986f-5e0c59ae7d62', 'PIZZA_HAWAJSKA', 'Hawajska', 2800, 1, 2800, 5.00, 133, NULL, NULL, '', 'none', NULL, 1, NULL, 'active'),
('97d54aba-3f9b-44f8-8565-5507559cd431', '33b87d71-cd7c-4265-9b7f-389a2bfb6cf1', 'DRINK_BEER_TYSKIE', 'Piwo Tyskie', 1000, 1, 1000, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('98070a8c-495c-421d-8519-4b5e7679dba0', '4e7edd28-1b94-40aa-8da8-39c8229f53ca', 'DRINK_WATER_05', 'Woda 0.5L', 500, 1, 500, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('9a091117-e2c6-42b7-9dac-c4fed5d479c5', '6e8bebdf-14de-496c-9a8f-c3fe0ee1a9eb', 'COLA05', 'Cola 0.5L', 600, 2, 1200, 8.00, 89, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('9c8f31b6-17eb-4ed4-ab10-a411b63356d9', 'cc17bad0-2714-47ed-9386-b96b58c450c2', 'PIZZA_HAWAJSKA', 'Hawajska', 2800, 1, 2800, 5.00, 133, NULL, NULL, '', 'none', NULL, 1, NULL, 'active'),
('a068b58f-42ee-4b17-ac28-49c0daf4b396', '0b0728b6-f94a-4901-8815-99c61db77bbf', 'PASTA_LASAGNE', 'Lasagne', 3200, 1, 3200, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('a08ef9c2-9855-4589-b232-bf2bef453489', '8653afa5-d893-47b2-b235-87c271b28d09', 'DRINK_SPRITE_05', 'Sprite 0.5L', 700, 1, 700, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('a13491ca-2655-4ea3-8e6e-d38b96dd1593', 'fa9c3a38-d46f-4480-9965-4d3bfd387d9c', 'PIZZA_MARGHERITA', 'Margherita', 2400, 1, 2400, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('a2c50a30-855f-49fd-9255-66ba53daa317', 'ae252b3e-5e97-458d-8f9e-a57dac0b2304', 'SET_BURGER_COMBO', 'Zestaw Burger+Frytki+Napój', 3200, 1, 3200, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('a3fc1e3a-4ca7-4089-ab4b-9c40ce2b993f', 'a7f6931c-27fb-49e3-b6cd-a2a8006df4bf', 'seed_item', 'Frytki duże', 1200, 1, 1200, 5.00, 57, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('a40a8266-1215-42b1-8633-119a9ccf9b25', '581e533e-7e45-4072-a94e-0415513a4f1b', 'SIDE_FRIES', 'Frytki', 900, 1, 900, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('a53e36cd-810b-4892-93dd-d8252330a169', '11f93faa-4fe8-45a4-9507-ca17a91e9fcb', 'BURGER_BBQ', 'BBQ Burger', 2800, 2, 5600, 0.00, 0, NULL, NULL, 'Bez cebuli, extra sos', 'none', NULL, 1, NULL, 'active'),
('a7f1842e-6b26-4342-ae8b-280817704a98', '2316316b-a1e7-493d-885f-a0c8a3372195', 'PIZZA_DIAVOLA', 'Diavola', 3200, 1, 3200, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('a9a0016e-6745-4072-ae35-ecdcb840a29c', '1cd1050b-98b4-4d8c-a143-325b5a0dcbc4', 'PIZZA_MARGHERITA', 'Margherita', 2400, 1, 2400, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('a9e7df35-85fc-43ad-80a9-552f3ee076b2', '38c45a98-2b44-40a2-953b-cc8d2509a3f9', 'seed_item', 'Burger Classic', 2200, 2, 4400, 5.00, 210, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('ac4b2690-29f8-4c42-9c23-c88202d213d6', '81deb272-c484-4fed-8699-ad4cd3b82054', 'PIZZA_MARGHERITA', 'Margherita', 2600, 1, 2600, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('aee7d27a-794b-4f90-9461-d55cb64e8fd6', '49d438fe-c22c-44ad-90c1-14aac07da9d5', 'PASTA_LASAGNE', 'Lasagne', 3200, 1, 3200, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('af1ea7c0-f1bf-4896-835a-f93c79a85e71', '39b70fae-7667-47c6-84eb-6d0cd8499fcd', 'DRINK_WATER_05', 'Woda 0.5L', 500, 1, 500, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('b00a87cb-388a-4d2b-aee7-b29458dfa494', '3cf9427f-b9c2-46d9-b0b8-3a607f69c3b6', 'SIDE_GARLIC_SAUCE', 'Sos czosnkowy', 300, 2, 600, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('b0d14f03-0f37-43b9-aa4d-2dbc867ee073', '5299aba2-dbad-464f-9a97-f661e48177d9', 'PIZZA_PEPPERONI', 'Pepperoni', 2800, 1, 2800, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('b20c012d-822b-4a3b-8be6-cee5c97569f6', 'cb3c200c-5769-4cdd-ab5f-4d310fb08f90', 'SIDE_FRIES', 'Frytki', 900, 1, 900, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('b3c9571c-0844-42dc-9910-80178c3541c2', '9a0cf0da-22cb-4643-aa17-0b8f53347cdf', 'SET_BURGER_COMBO', 'Zestaw Burger+Frytki+Napój', 3200, 1, 3200, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('b66a427b-4dbe-45ca-8651-e5ecf2bbd2df', 'aba34c27-dc11-401c-bb4a-1065e2778641', 'seed_item', 'Frytki duże', 1200, 1, 1200, 5.00, 57, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('b6e5d3b5-1903-4a6c-8e50-5865373739c8', '91253b46-5b47-4a55-afa6-a3ccf3247491', 'DRINK_WATER_05', 'Woda 0.5L', 500, 1, 500, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('bb684d6c-92c9-4254-b229-55bd4c9641be', '5d81a72e-9517-46c5-bf27-88ae7be1f3ff', 'DESSERT_TIRAMISU', 'Tiramisu', 1800, 1, 1800, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('bc795977-5c8f-4646-a25f-7f2a03df1849', 'd73e8154-501b-443b-9721-9482d6a436a3', 'PIZZA_CAPRICCIOSA', 'Capricciosa', 3000, 1, 3000, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('bc8e6257-5fdd-4622-b74e-80ccdf974988', '425d27b7-7454-492e-8b74-e247c005dfac', 'SIDE_FRIES', 'Frytki', 1000, 1, 1000, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('bef2ceab-4447-4f5e-b944-6513612eeba2', '231d4921-b571-45a5-986f-5e0c59ae7d62', 'PIZZA_CAPRICCIOSA', 'Capricciosa', 3000, 1, 3000, 5.00, 143, NULL, NULL, '', 'none', NULL, 1, NULL, 'active'),
('bf1361a8-81e5-4019-99e5-6801686ecaae', 'd76ff6f1-32cb-4604-a402-638533e1aa09', 'SIDE_GARLIC_SAUCE', 'Sos czosnkowy', 300, 2, 600, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('c01022b6-4c62-47e1-a796-c6b0988f8362', 'f0fd6d73-a538-404f-9f32-9f4db26d14bf', 'BURGER_CLASSIC', 'Classic Burger', 2400, 1, 2400, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('c384b70d-a9e6-47b5-b5c6-db79773a76ff', 'e028f5e8-c7fd-42eb-97e8-05ec94294d75', 'PIZZA_PEPPERONI', 'Pepperoni', 2800, 1, 2800, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('c5b5f064-2746-46fb-960e-0446a9b5eddb', '98f2f88e-c53c-4d08-9c04-cbeeccaf3be6', 'DRINK_COLA_05', 'Cola 0.5L', 800, 1, 800, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('c6083113-97ca-4312-becb-cdb4486c8ca2', '0b0728b6-f94a-4901-8815-99c61db77bbf', 'DRINK_BEER_TYSKIE', 'Piwo Tyskie', 1000, 1, 1000, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('c95126d3-ad84-437f-b531-e80cb9788800', '80fd22d8-ee3a-44b0-973c-6f65abbd54f2', 'PIZZA_CAPRICCIOSA', 'Capricciosa', 3000, 1, 3000, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('ca8ff536-3c15-4a01-a246-9137360a9fa3', '2316316b-a1e7-493d-885f-a0c8a3372195', 'PIZZA_MARGHERITA', 'Margherita', 2600, 1, 2600, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('cb870e23-a498-4651-869d-d9bfbdb28c67', '5d81a72e-9517-46c5-bf27-88ae7be1f3ff', 'DRINK_BEER_TYSKIE', 'Piwo Tyskie', 1000, 1, 1000, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('cd20b3f8-7919-4d34-86f9-ada24361d848', 'ba49298f-1763-419c-b495-d7afe0992db9', 'DRINK_COLA_05', 'Cola 0.5L', 800, 1, 800, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('cdaf48c6-402d-4f99-bf1e-b67f887ba217', 'a53b4c9b-5845-45b1-9f7c-69bcbc0ad695', 'PIZZA_DIAVOLA', 'Diavola', 3200, 1, 3200, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('cdbc7683-c277-4c95-aa17-72da826731ad', 'bfed4919-4c0a-4e47-bc36-058d226e1e33', 'seed_item', 'Frytki duże', 1200, 2, 2400, 5.00, 114, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('cf55b2d0-e4af-408f-9d6a-3455011b8233', '33b87d71-cd7c-4265-9b7f-389a2bfb6cf1', 'DESSERT_TIRAMISU', 'Tiramisu', 1800, 1, 1800, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('cfab5b40-8c01-42e6-8f16-b60d2f9d1c2d', 'fd9b25fc-c203-491e-8d4d-2cacf00914d0', 'seed_item', 'Burger Classic', 2200, 2, 4400, 5.00, 210, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('d009dd04-8c92-49a2-9a9b-22db9850ab3e', 'ff3bbc98-ab25-4db8-8731-12a351bb5bd0', 'SIDE_FRIES', 'Frytki', 900, 1, 900, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('d3299202-1beb-47b6-82e7-18d8c7d866aa', 'd76ff6f1-32cb-4604-a402-638533e1aa09', 'PIZZA_4FORMAGGI', 'Quattro Formaggi', 3400, 1, 3400, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('d826bd0c-689d-439b-8552-e1172a701659', '49d438fe-c22c-44ad-90c1-14aac07da9d5', 'DESSERT_TIRAMISU', 'Tiramisu', 1800, 1, 1800, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('d835ab8c-e2fb-49f3-bce8-a35d4970973e', 'fc7acd2f-32e7-4603-bac8-927eb868d63d', 'MARGHERITA', 'Margherita', 2599, 2, 5198, 5.00, 248, '[{\"ascii_key\":\"OPT_JALAPENO\",\"name\":\"Jalapeno\",\"price\":\"4.00\"}]', '[{\"sku\":\"SER_MOZZARELLA\",\"name\":\"Mozzarella\"}]', 'Dobrze wypieczona', 'none', NULL, 1, NULL, 'active'),
('da34e51c-d06c-40dc-b998-3980a83b2b33', '49d438fe-c22c-44ad-90c1-14aac07da9d5', 'DRINK_BEER_TYSKIE', 'Piwo Tyskie', 1000, 1, 1000, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('da626c4f-df0b-4465-8a1a-2a47363ad232', '5d81a72e-9517-46c5-bf27-88ae7be1f3ff', 'PASTA_LASAGNE', 'Lasagne', 3200, 1, 3200, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('dac34683-24d6-43b5-9849-0b36ac2be505', 'd73e8154-501b-443b-9721-9482d6a436a3', 'DRINK_COLA_05', 'Coca-Cola 0.5L', 800, 1, 800, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('db6e767c-fd58-4373-9304-3927f0f3e8c0', '8506f171-dd58-4d6e-a268-64a9dface5f2', 'SIDE_FRIES', 'Frytki', 1000, 1, 1000, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('dbcceaae-1eaf-4bca-969a-5f550de25090', '5e6a8437-8c22-4d99-bb12-d85e4d423116', 'DRINK_COLA_05', 'Coca-Cola 0.5L', 800, 1, 800, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('dda01445-bbb8-48b6-adc1-d19c3e27b3fb', '231d4921-b571-45a5-986f-5e0c59ae7d62', 'PIZZA_MARGHERITA', 'Margherita', 2700, 1, 2700, 5.00, 129, '[{\"ascii_key\":\"EXTRA_OLIVES\",\"name\":\"Oliwki\",\"price\":\"3.00\"}]', NULL, '', 'none', NULL, 1, NULL, 'active'),
('e0862177-3607-4708-b6da-33c66de6edf5', '797998a1-f625-4e71-a774-38602b91f4a9', 'PIZZA_4FORMAGGI', 'Quattro Formaggi', 3400, 1, 3400, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('e0d7a993-cff4-4ede-a82a-a1b1df921588', '6f84d5a6-44e8-4283-8953-93ec2f7503ce', 'PIZZA_CAPRICCIOSA', 'Capricciosa', 3000, 1, 3000, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('e11719bb-2cb0-4fc9-a341-d2ba69108806', '0a5dfced-8920-44ea-afc4-aa5f890cc95e', 'SIDE_FRIES', 'Frytki', 900, 1, 900, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('e189afc0-7091-4c77-abe3-ce8a78f36f01', '7bbf21fc-750c-4107-8c53-b81dc0be3080', 'PIZZA_HAWAJSKA', 'Hawajska', 3000, 1, 3000, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('e42efce7-4be8-42ff-9c0e-27ec9e6559d0', '6a0a457e-0226-4a28-95f2-df12da98361e', 'PASTA_LASAGNE', 'Lasagne', 3000, 1, 3000, 5.00, 143, NULL, NULL, '', 'none', NULL, 1, NULL, 'active'),
('e4dfd4da-afec-483d-9ae9-6d89707cddf2', '857d9ade-143e-4b03-9839-1da9b52d07e6', 'DRINK_COLA_05', 'Coca-Cola 0.5L', 700, 2, 1400, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('e657c40b-5e1e-4423-a744-4cc9971f67b7', '2aa9aaa2-571e-4286-befe-bc5664dc694c', 'seed_item', 'Cola 0.5L', 600, 1, 600, 5.00, 29, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('e6ed32f5-470f-4a5b-9182-cf7e0d5d6633', '41aa3420-2a7b-4cd8-b29f-5c355bbbf581', 'DRINK_SPRITE_05', 'Sprite 0.5L', 700, 1, 700, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('e9222dda-2254-4ea3-a934-b892d7a0cdfc', '7e9f030d-5df5-4bce-b096-25c6cb407ab3', 'DRINK_COLA_05', 'Cola 0.5L', 800, 1, 800, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('ed01d995-7d7c-4dbb-8a01-8b636b98e70d', 'fd0b1c4c-5c2a-4bd2-8a8d-8eaf01d1b2c1', 'PIZZA_MARGHERITA', 'Margherita', 2400, 1, 2400, 5.00, 114, NULL, NULL, '', 'none', NULL, 1, NULL, 'active'),
('ed66f0f1-5e0f-47aa-8b48-bb0cd1e21876', '2606e5e7-0a8c-4e34-8139-56ff984899d6', 'SIDE_FRIES', 'Frytki', 1000, 1, 1000, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('f08e3d62-76e7-47c2-b083-eb6d576522ab', '9ff90ee9-89d9-49c9-b756-6d09ad154f0a', 'seed_item', 'Pepperoni 32cm', 3200, 2, 6400, 5.00, 305, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('f0fdec37-0013-4343-aa03-8b176f4c1b70', 'e1e41f3b-8dde-487e-a7b7-0f16b26d4efc', 'PEPP32', 'Pepperoni 32cm', 3200, 1, 3200, 8.00, 237, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('f37172a0-1f7b-4162-b0e9-477968ccebd5', '81deb272-c484-4fed-8699-ad4cd3b82054', 'PIZZA_DIAVOLA', 'Diavola', 3200, 1, 3200, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('f535ce96-55c2-40cd-87cb-53d2813fe7ac', '32eeb1cd-ed58-4784-b1bd-9a5dc2aa235e', 'HAWAI32', 'Hawajska 32cm', 3000, 2, 6000, 8.00, 444, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('f570845d-80dd-48d4-947b-ada4cb3464bc', '2606e5e7-0a8c-4e34-8139-56ff984899d6', 'BURGER_BBQ', 'BBQ Burger', 2800, 2, 5600, 0.00, 0, NULL, NULL, 'Bez cebuli, extra sos', 'none', NULL, 1, NULL, 'active'),
('f686a2d3-9f37-4999-a5f7-0b8ee6e4bbc1', 'c365bf79-2443-4800-9037-f31b30ae5dd4', 'SET_BURGER_COMBO', 'Zestaw Burger+Frytki+Napój', 3200, 1, 3200, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('f7f15373-b01e-4ad6-9c8f-33e69e596f7f', '655dfde8-e921-4ce6-a94e-82901d788661', 'seed_item', 'Cola 0.5L', 600, 2, 1200, 5.00, 57, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('f848d29b-4e39-451a-9a15-fff77276dfac', '0adc30b4-d89b-4d19-b7a1-fced39a5deaf', 'seed_item', 'Burger Classic', 2200, 2, 4400, 5.00, 210, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active'),
('fe768161-a727-49e3-8ef7-5e3c71f36786', '0a5dfced-8920-44ea-afc4-aa5f890cc95e', 'BURGER_CHEESE', 'Cheese Burger', 2400, 2, 4800, 0.00, 0, NULL, NULL, NULL, 'none', NULL, 1, NULL, 'active');

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_order_logs`
--

CREATE TABLE `sh_order_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `order_id` char(36) DEFAULT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `action` varchar(64) NOT NULL COMMENT 'state_change|payment|merge|split|fire_course|etc',
  `detail_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Structured payload of the action' CHECK (json_valid(`detail_json`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sh_order_logs`
--

INSERT INTO `sh_order_logs` (`id`, `order_id`, `tenant_id`, `user_id`, `action`, `detail_json`, `created_at`) VALUES
(1, '46ddaeee-2b6e-43e4-9ee5-f93ec42fa717', 1, 3, 'state_change', '{\"old_status\":\"pending\",\"new_status\":\"preparing\",\"order_type\":\"takeaway\",\"extra_cols\":[]}', '2026-04-14 04:25:05'),
(2, 'c0ab86e5-4383-4a4b-af44-c268a97e7c72', 1, 5, 'state_change', '{\"old_status\":\"ready\",\"new_status\":\"cancelled\",\"order_type\":\"delivery\",\"extra_cols\":[\"delivery_status\",\"driver_id\",\"course_id\",\"stop_number\",\"cancellation_reason\"]}', '2026-04-14 04:43:04'),
(3, 'd12391f7-af3d-48b4-b1fb-62b5dac16248', 1, 5, 'state_change', '{\"old_status\":\"ready\",\"new_status\":\"cancelled\",\"order_type\":\"delivery\",\"extra_cols\":[\"delivery_status\",\"driver_id\",\"course_id\",\"stop_number\",\"cancellation_reason\"]}', '2026-04-14 05:25:31'),
(4, 'ae433c2a-9c9c-4785-bb8d-0cb0225348b1', 1, 5, 'state_change', '{\"old_status\":\"ready\",\"new_status\":\"completed\",\"order_type\":\"delivery\",\"extra_cols\":[\"delivery_status\"]}', '2026-04-14 05:26:25'),
(5, 'e87c063e-d905-450c-9f20-552573a16f34', 1, 5, 'state_change', '{\"old_status\":\"pending\",\"new_status\":\"preparing\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-14 05:27:49'),
(6, 'e87c063e-d905-450c-9f20-552573a16f34', 1, 5, 'state_change', '{\"old_status\":\"preparing\",\"new_status\":\"ready\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-14 05:27:58'),
(7, '132c1b72-1480-4691-8bf6-e338ee76884a', 1, 5, 'state_change', '{\"old_status\":\"pending\",\"new_status\":\"preparing\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-14 05:29:37'),
(8, '132c1b72-1480-4691-8bf6-e338ee76884a', 1, 5, 'state_change', '{\"old_status\":\"preparing\",\"new_status\":\"ready\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-14 05:29:46'),
(9, '132c1b72-1480-4691-8bf6-e338ee76884a', 1, 5, 'state_change', '{\"old_status\":\"ready\",\"new_status\":\"completed\",\"order_type\":\"delivery\",\"extra_cols\":[\"delivery_status\"]}', '2026-04-14 05:32:08'),
(10, 'e87c063e-d905-450c-9f20-552573a16f34', 1, 5, 'state_change', '{\"old_status\":\"ready\",\"new_status\":\"completed\",\"order_type\":\"delivery\",\"extra_cols\":[\"delivery_status\"]}', '2026-04-14 05:32:36'),
(11, '39e1849f-7d54-422a-97a2-b52cc38b9ad8', 1, 5, 'state_change', '{\"old_status\":\"pending\",\"new_status\":\"preparing\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-14 05:36:51'),
(12, '39e1849f-7d54-422a-97a2-b52cc38b9ad8', 1, 5, 'state_change', '{\"old_status\":\"preparing\",\"new_status\":\"ready\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-14 05:36:52'),
(13, '39e1849f-7d54-422a-97a2-b52cc38b9ad8', 1, 5, 'state_change', '{\"old_status\":\"ready\",\"new_status\":\"completed\",\"order_type\":\"delivery\",\"extra_cols\":[\"delivery_status\"]}', '2026-04-14 05:45:59'),
(14, '96e075d6-a279-4409-8137-64e268f78559', 1, 1, 'open_table', '{\"table_id\":1,\"table_number\":\"1\",\"guest_count\":2}', '2026-04-14 13:20:56'),
(15, '96e075d6-a279-4409-8137-64e268f78559', 1, 1, 'state_change', '{\"old_status\":\"pending\",\"new_status\":\"cancelled\",\"order_type\":\"dine_in\",\"extra_cols\":[]}', '2026-04-14 13:52:21'),
(16, '992b9255-eeb8-4cfe-9a77-7ee7f6dcf5f7', 1, 1, 'state_change', '{\"old_status\":\"ready\",\"new_status\":\"cancelled\",\"order_type\":\"dine_in\",\"extra_cols\":[]}', '2026-04-14 13:52:25'),
(17, 'df0e179d-ea6e-4001-ac85-75a913c719df', 1, 1, 'state_change', '{\"old_status\":\"ready\",\"new_status\":\"cancelled\",\"order_type\":\"dine_in\",\"extra_cols\":[]}', '2026-04-14 13:52:27'),
(18, '43ebcc2f-2e91-48b5-a179-e691b32267f4', 1, 1, 'state_change', '{\"old_status\":\"ready\",\"new_status\":\"cancelled\",\"order_type\":\"takeaway\",\"extra_cols\":[]}', '2026-04-14 13:52:29'),
(19, '4369afa5-ae38-413f-ae22-159ce0854d3e', 1, 1, 'open_table', '{\"table_id\":2,\"table_number\":\"1\",\"guest_count\":2}', '2026-04-14 13:57:49'),
(20, '4369afa5-ae38-413f-ae22-159ce0854d3e', 1, 1, 'state_change', '{\"old_status\":\"pending\",\"new_status\":\"cancelled\",\"order_type\":\"dine_in\",\"extra_cols\":[]}', '2026-04-14 14:07:29'),
(21, 'e3316ee7-b85a-4237-84cd-8abbf5b31e7d', 1, 1, 'state_change', '{\"old_status\":\"pending\",\"new_status\":\"preparing\",\"order_type\":\"dine_in\",\"extra_cols\":[]}', '2026-04-14 14:14:18'),
(22, 'e3316ee7-b85a-4237-84cd-8abbf5b31e7d', 1, 1, 'fire_course', '{\"course_number\":1,\"fired_count\":1,\"fired_at\":\"2026-04-14 14:14:18\"}', '2026-04-14 14:14:18'),
(23, 'e3316ee7-b85a-4237-84cd-8abbf5b31e7d', 1, 1, 'state_change', '{\"old_status\":\"preparing\",\"new_status\":\"cancelled\",\"order_type\":\"dine_in\",\"extra_cols\":[]}', '2026-04-14 14:46:51'),
(24, 'e1a69f95-1a90-404c-80fe-7d2204c8583a', 1, 1, 'state_change', '{\"old_status\":\"pending\",\"new_status\":\"cancelled\",\"order_type\":\"dine_in\",\"extra_cols\":[]}', '2026-04-14 15:16:35'),
(25, '44d36275-b0a9-485f-ab07-620fd1081525', 1, 1, 'state_change', '{\"old_status\":\"pending\",\"new_status\":\"cancelled\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-14 15:39:48'),
(26, '644e2f33-711c-42a7-822e-d8c48d451218', 1, 1, 'state_change', '{\"old_status\":\"pending\",\"new_status\":\"preparing\",\"order_type\":\"dine_in\",\"extra_cols\":[]}', '2026-04-14 16:25:21'),
(27, '644e2f33-711c-42a7-822e-d8c48d451218', 1, 1, 'state_change', '{\"old_status\":\"preparing\",\"new_status\":\"ready\",\"order_type\":\"dine_in\",\"extra_cols\":[]}', '2026-04-14 16:25:22'),
(28, '0d0f861d-b043-4218-b89d-42d002c8c917', 1, 1, 'state_change', '{\"old_status\":\"pending\",\"new_status\":\"preparing\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-14 16:25:58'),
(29, '0d0f861d-b043-4218-b89d-42d002c8c917', 1, 1, 'state_change', '{\"old_status\":\"preparing\",\"new_status\":\"ready\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-14 16:25:59'),
(30, 'ec7ca04b-adee-4154-8b16-07d3dcca266d', 1, 5, 'state_change', '{\"old_status\":\"ready\",\"new_status\":\"cancelled\",\"order_type\":\"delivery\",\"extra_cols\":[\"delivery_status\",\"driver_id\",\"course_id\",\"stop_number\",\"cancellation_reason\"]}', '2026-04-14 17:59:11'),
(31, '0907a8ee-f927-44e0-99eb-593a66fbf46b', 1, 1, 'state_change', '{\"old_status\":\"pending\",\"new_status\":\"preparing\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-14 18:05:36'),
(32, '0907a8ee-f927-44e0-99eb-593a66fbf46b', 1, 1, 'state_change', '{\"old_status\":\"preparing\",\"new_status\":\"ready\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-14 18:05:37'),
(33, 'ab5b8b48-3af5-47f3-b476-8b919d8122fb', 1, 1, 'state_change', '{\"old_status\":\"pending\",\"new_status\":\"preparing\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-14 18:05:38'),
(34, 'ab5b8b48-3af5-47f3-b476-8b919d8122fb', 1, 1, 'state_change', '{\"old_status\":\"preparing\",\"new_status\":\"ready\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-14 18:05:39'),
(35, 'da11d0b9-32c3-4828-b30b-8e8bc17f98f3', 1, 1, 'state_change', '{\"old_status\":\"pending\",\"new_status\":\"preparing\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-14 18:05:40'),
(36, 'da11d0b9-32c3-4828-b30b-8e8bc17f98f3', 1, 1, 'state_change', '{\"old_status\":\"preparing\",\"new_status\":\"ready\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-14 18:05:40'),
(37, '0907a8ee-f927-44e0-99eb-593a66fbf46b', 1, 5, 'state_change', '{\"old_status\":\"ready\",\"new_status\":\"completed\",\"order_type\":\"delivery\",\"extra_cols\":[\"delivery_status\"]}', '2026-04-15 00:17:46'),
(38, 'b8113545-6826-44b3-b1bd-481484ffbcf8', 1, 3, 'state_change', '{\"old_status\":\"pending\",\"new_status\":\"preparing\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-16 05:18:52'),
(39, 'b8113545-6826-44b3-b1bd-481484ffbcf8', 1, 3, 'state_change', '{\"old_status\":\"preparing\",\"new_status\":\"ready\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-16 05:18:52'),
(40, '0adc30b4-d89b-4d19-b7a1-fced39a5deaf', 1, 3, 'state_change', '{\"old_status\":\"ready\",\"new_status\":\"cancelled\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-16 12:39:58'),
(41, '655dfde8-e921-4ce6-a94e-82901d788661', 1, 3, 'state_change', '{\"old_status\":\"ready\",\"new_status\":\"cancelled\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-16 12:40:00'),
(42, '38c45a98-2b44-40a2-953b-cc8d2509a3f9', 1, 3, 'state_change', '{\"old_status\":\"ready\",\"new_status\":\"cancelled\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-16 12:40:03'),
(43, 'fd9b25fc-c203-491e-8d4d-2cacf00914d0', 1, 3, 'state_change', '{\"old_status\":\"ready\",\"new_status\":\"cancelled\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-16 12:40:05'),
(44, '6f84d5a6-44e8-4283-8953-93ec2f7503ce', 1, 3, 'state_change', '{\"old_status\":\"ready\",\"new_status\":\"cancelled\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-16 12:40:07'),
(45, '2aa9aaa2-571e-4286-befe-bc5664dc694c', 1, 3, 'state_change', '{\"old_status\":\"ready\",\"new_status\":\"cancelled\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-16 12:40:10'),
(46, 'd76ff6f1-32cb-4604-a402-638533e1aa09', 1, 3, 'state_change', '{\"old_status\":\"ready\",\"new_status\":\"cancelled\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-16 12:40:15'),
(47, '32eeb1cd-ed58-4784-b1bd-9a5dc2aa235e', 1, 3, 'state_change', '{\"old_status\":\"ready\",\"new_status\":\"cancelled\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-16 12:40:17'),
(48, '6e8bebdf-14de-496c-9a8f-c3fe0ee1a9eb', 1, 3, 'state_change', '{\"old_status\":\"ready\",\"new_status\":\"cancelled\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-16 12:40:19'),
(49, 'e1e41f3b-8dde-487e-a7b7-0f16b26d4efc', 1, 3, 'state_change', '{\"old_status\":\"ready\",\"new_status\":\"cancelled\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-16 12:40:21'),
(50, '2606e5e7-0a8c-4e34-8139-56ff984899d6', 1, 3, 'state_change', '{\"old_status\":\"ready\",\"new_status\":\"cancelled\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-16 12:40:28'),
(51, 'ae3030b8-ef40-4ce2-9952-4567e26d1270', 1, 3, 'state_change', '{\"old_status\":\"ready\",\"new_status\":\"cancelled\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-16 12:40:31'),
(52, 'f90a3ec9-d055-413f-9bf7-5acc53dab1d0', 1, 3, 'state_change', '{\"old_status\":\"ready\",\"new_status\":\"cancelled\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-16 12:40:33'),
(53, 'a53b4c9b-5845-45b1-9f7c-69bcbc0ad695', 1, 3, 'state_change', '{\"old_status\":\"ready\",\"new_status\":\"cancelled\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-16 12:40:36'),
(54, 'a7f6931c-27fb-49e3-b6cd-a2a8006df4bf', 1, 2, 'state_change', '{\"old_status\":\"ready\",\"new_status\":\"cancelled\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-16 12:57:35'),
(55, '92f626d9-538e-416d-a707-1f89d59fbdd8', 1, 2, 'state_change', '{\"old_status\":\"ready\",\"new_status\":\"cancelled\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-16 12:57:37'),
(56, 'bfed4919-4c0a-4e47-bc36-058d226e1e33', 1, 2, 'state_change', '{\"old_status\":\"ready\",\"new_status\":\"cancelled\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-16 12:57:40'),
(57, '5d81a72e-9517-46c5-bf27-88ae7be1f3ff', 1, 2, 'state_change', '{\"old_status\":\"ready\",\"new_status\":\"cancelled\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-16 12:57:42'),
(58, '9ff90ee9-89d9-49c9-b756-6d09ad154f0a', 1, 2, 'state_change', '{\"old_status\":\"ready\",\"new_status\":\"cancelled\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-16 12:57:44'),
(59, 'aba34c27-dc11-401c-bb4a-1065e2778641', 1, 2, 'state_change', '{\"old_status\":\"ready\",\"new_status\":\"cancelled\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-16 12:57:46'),
(60, 'c365bf79-2443-4800-9037-f31b30ae5dd4', 1, 2, 'state_change', '{\"old_status\":\"ready\",\"new_status\":\"cancelled\",\"order_type\":\"takeaway\",\"extra_cols\":[]}', '2026-04-16 12:57:50'),
(61, 'e028f5e8-c7fd-42eb-97e8-05ec94294d75', 1, 2, 'state_change', '{\"old_status\":\"ready\",\"new_status\":\"cancelled\",\"order_type\":\"takeaway\",\"extra_cols\":[]}', '2026-04-16 12:57:53'),
(62, '6806b54c-8f40-4df5-a49f-fbffbbdc7cf4', 1, 2, 'state_change', '{\"old_status\":\"ready\",\"new_status\":\"cancelled\",\"order_type\":\"dine_in\",\"extra_cols\":[]}', '2026-04-16 12:57:55'),
(63, 'cd7bbc6e-772f-4be2-83a2-2b6bf523d700', 1, 2, 'state_change', '{\"old_status\":\"ready\",\"new_status\":\"cancelled\",\"order_type\":\"dine_in\",\"extra_cols\":[]}', '2026-04-16 12:57:58'),
(64, '1aeb78f0-2f74-4ed4-8553-9f335d15650a', 1, 3, 'state_change', '{\"old_status\":\"pending\",\"new_status\":\"preparing\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-16 13:03:59'),
(65, 'cc17bad0-2714-47ed-9386-b96b58c450c2', 1, 3, 'state_change', '{\"old_status\":\"pending\",\"new_status\":\"preparing\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-16 13:04:07'),
(66, '26128f4b-ff53-41be-b2f4-e0c531babf92', 1, 2, 'state_change', '{\"old_status\":\"pending\",\"new_status\":\"preparing\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-16 13:06:30'),
(67, '26128f4b-ff53-41be-b2f4-e0c531babf92', 1, 2, 'state_change', '{\"old_status\":\"preparing\",\"new_status\":\"ready\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-16 13:06:31'),
(68, '4845480b-3214-4287-9e87-8b220357ef78', 1, 2, 'state_change', '{\"old_status\":\"pending\",\"new_status\":\"preparing\",\"order_type\":\"takeaway\",\"extra_cols\":[]}', '2026-04-16 13:13:16'),
(69, '6a0a457e-0226-4a28-95f2-df12da98361e', 1, 2, 'state_change', '{\"old_status\":\"pending\",\"new_status\":\"preparing\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-16 13:16:38'),
(70, '6a0a457e-0226-4a28-95f2-df12da98361e', 1, 2, 'state_change', '{\"old_status\":\"preparing\",\"new_status\":\"ready\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-16 13:16:39'),
(71, 'fd0b1c4c-5c2a-4bd2-8a8d-8eaf01d1b2c1', 1, 2, 'state_change', '{\"old_status\":\"pending\",\"new_status\":\"preparing\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-16 13:16:40'),
(72, 'fd0b1c4c-5c2a-4bd2-8a8d-8eaf01d1b2c1', 1, 2, 'state_change', '{\"old_status\":\"preparing\",\"new_status\":\"ready\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-16 13:16:41'),
(73, '3928ce3c-3c92-4884-914f-69148d4ff6d9', 1, 2, 'state_change', '{\"old_status\":\"pending\",\"new_status\":\"preparing\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-16 13:16:42'),
(74, '3928ce3c-3c92-4884-914f-69148d4ff6d9', 1, 2, 'state_change', '{\"old_status\":\"preparing\",\"new_status\":\"ready\",\"order_type\":\"delivery\",\"extra_cols\":[]}', '2026-04-16 13:16:42'),
(75, '3928ce3c-3c92-4884-914f-69148d4ff6d9', 1, 10, 'state_change', '{\"old_status\":\"ready\",\"new_status\":\"completed\",\"order_type\":\"delivery\",\"extra_cols\":[\"delivery_status\"]}', '2026-04-16 13:22:00'),
(76, 'fd0b1c4c-5c2a-4bd2-8a8d-8eaf01d1b2c1', 1, 10, 'state_change', '{\"old_status\":\"ready\",\"new_status\":\"completed\",\"order_type\":\"delivery\",\"extra_cols\":[\"delivery_status\"]}', '2026-04-16 13:22:35'),
(77, 'fc7acd2f-32e7-4603-bac8-927eb868d63d', 1, 2, 'state_change', '{\"old_status\":\"pending\",\"new_status\":\"preparing\",\"order_type\":\"takeaway\",\"extra_cols\":[]}', '2026-04-17 03:10:24'),
(78, 'fc7acd2f-32e7-4603-bac8-927eb868d63d', 1, 2, 'state_change', '{\"old_status\":\"preparing\",\"new_status\":\"ready\",\"order_type\":\"takeaway\",\"extra_cols\":[]}', '2026-04-17 03:10:25'),
(79, '4845480b-3214-4287-9e87-8b220357ef78', 1, 2, 'state_change', '{\"old_status\":\"preparing\",\"new_status\":\"cancelled\",\"order_type\":\"takeaway\",\"extra_cols\":[]}', '2026-04-17 03:10:33'),
(80, 'c5574d32-b7c3-468d-b6ba-06c1dff48033', 1, 2, 'state_change', '{\"old_status\":\"pending\",\"new_status\":\"cancelled\",\"order_type\":\"dine_in\",\"extra_cols\":[]}', '2026-04-17 03:10:36');

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_order_payments`
--

CREATE TABLE `sh_order_payments` (
  `id` char(36) NOT NULL,
  `order_id` char(36) NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `method` varchar(32) NOT NULL,
  `payment_method` varchar(32) DEFAULT NULL,
  `amount_grosze` int(11) NOT NULL,
  `tendered_grosze` int(11) NOT NULL,
  `transaction_id` varchar(128) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sh_order_payments`
--

INSERT INTO `sh_order_payments` (`id`, `order_id`, `tenant_id`, `user_id`, `method`, `payment_method`, `amount_grosze`, `tendered_grosze`, `transaction_id`, `created_at`) VALUES
('057d8a77-6d72-4e6e-8079-3eaf0cb07022', '0907a8ee-f927-44e0-99eb-593a66fbf46b', 1, 5, 'cash', NULL, 14500, 14500, NULL, '2026-04-14 18:06:49'),
('5d82b754-71ef-4d64-a1be-f24134bc9f64', 'ec7ca04b-adee-4154-8b16-07d3dcca266d', 1, 5, 'cash', NULL, 3100, 3100, NULL, '2026-04-14 17:58:27'),
('88fb62ea-5d2e-4337-bda6-0c02a8526533', 'fd0b1c4c-5c2a-4bd2-8a8d-8eaf01d1b2c1', 1, 10, 'cash', NULL, 5200, 5200, NULL, '2026-04-16 13:22:35'),
('c5bc031c-664e-4eff-92d1-820085d18d8a', '39e1849f-7d54-422a-97a2-b52cc38b9ad8', 1, 5, 'cash', NULL, 8100, 8100, NULL, '2026-04-14 05:45:36');

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_order_sequences`
--

CREATE TABLE `sh_order_sequences` (
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `date` date NOT NULL,
  `seq` bigint(20) UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sh_order_sequences`
--

INSERT INTO `sh_order_sequences` (`tenant_id`, `date`, `seq`) VALUES
(1, '0000-00-00', 0),
(1, '2026-04-13', 0),
(1, '2026-04-14', 0),
(1, '2026-04-15', 0),
(1, '2026-04-16', 36),
(1, '2026-04-17', 50);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_panic_log`
--

CREATE TABLE `sh_panic_log` (
  `id` char(36) NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `triggered_by` bigint(20) UNSIGNED DEFAULT NULL,
  `delay_minutes` int(11) NOT NULL,
  `affected_count` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sh_panic_log`
--

INSERT INTO `sh_panic_log` (`id`, `tenant_id`, `triggered_by`, `delay_minutes`, `affected_count`, `created_at`) VALUES
('0a4352a8-3e61-4375-9a43-e7eb7d799224', 1, 3, 20, 17, '2026-04-13 19:00:36'),
('30287971-d974-43dd-9c37-f0682469491f', 1, 3, 20, 5, '2026-04-14 05:01:59'),
('374cf318-9cf0-4b7e-a2e7-cf8c1c041df8', 1, 3, 20, 10, '2026-04-13 17:57:17'),
('48f424e3-5d27-4a85-a17e-ae4dad06cef6', 1, 3, 20, 17, '2026-04-13 19:00:43'),
('54a0c5ad-adf7-40c3-a0cb-78fd4bce1ca4', 1, 3, 20, 12, '2026-04-13 17:57:30'),
('5e6dba2d-3858-454e-8b9c-85070162e873', 1, 9, 20, 7, '2026-04-17 00:00:25'),
('8f19b0f5-3c61-4227-ab65-97bf121fe50b', 1, 2, 20, 10, '2026-04-15 00:24:24'),
('ad73bc35-6014-46d1-aac2-301f2016cb7e', 1, 3, 20, 17, '2026-04-13 19:00:38'),
('c28ee68d-1310-4de0-8730-a11975ba8e75', 1, 3, 20, 104, '2026-04-14 01:31:20'),
('f1fe0569-276d-410f-95eb-1f8d99b64dfe', 1, 3, 20, 2, '2026-04-14 01:59:24'),
('ff3b3853-607b-463d-85aa-b3914003bfde', 1, 3, 20, 27, '2026-04-13 19:53:27');

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_price_tiers`
--

CREATE TABLE `sh_price_tiers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = global / HQ default',
  `target_type` varchar(16) NOT NULL,
  `target_sku` varchar(255) NOT NULL,
  `channel` varchar(32) NOT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sh_price_tiers`
--

INSERT INTO `sh_price_tiers` (`id`, `tenant_id`, `target_type`, `target_sku`, `channel`, `price`) VALUES
(1, 1, 'ITEM', 'PIZZA_MARGHERITA', 'POS', 24.00),
(2, 1, 'ITEM', 'PIZZA_MARGHERITA', 'Takeaway', 24.00),
(3, 1, 'ITEM', 'PIZZA_MARGHERITA', 'Delivery', 25.92),
(4, 1, 'ITEM', 'PIZZA_PEPPERONI', 'POS', 28.00),
(5, 1, 'ITEM', 'PIZZA_PEPPERONI', 'Takeaway', 28.00),
(6, 1, 'ITEM', 'PIZZA_PEPPERONI', 'Delivery', 30.24),
(7, 1, 'ITEM', 'PIZZA_CAPRICCIOSA', 'POS', 30.00),
(8, 1, 'ITEM', 'PIZZA_CAPRICCIOSA', 'Takeaway', 30.00),
(9, 1, 'ITEM', 'PIZZA_CAPRICCIOSA', 'Delivery', 32.40),
(10, 1, 'ITEM', 'PIZZA_HAWAJSKA', 'POS', 28.00),
(11, 1, 'ITEM', 'PIZZA_HAWAJSKA', 'Takeaway', 28.00),
(12, 1, 'ITEM', 'PIZZA_HAWAJSKA', 'Delivery', 30.24),
(13, 1, 'ITEM', 'PIZZA_4FORMAGGI', 'POS', 32.00),
(14, 1, 'ITEM', 'PIZZA_4FORMAGGI', 'Takeaway', 32.00),
(15, 1, 'ITEM', 'PIZZA_4FORMAGGI', 'Delivery', 34.56),
(16, 1, 'ITEM', 'PIZZA_DIAVOLA', 'POS', 30.00),
(17, 1, 'ITEM', 'PIZZA_DIAVOLA', 'Takeaway', 30.00),
(18, 1, 'ITEM', 'PIZZA_DIAVOLA', 'Delivery', 32.40),
(19, 1, 'ITEM', 'PIZZA_VEGETARIANA', 'POS', 26.00),
(20, 1, 'ITEM', 'PIZZA_VEGETARIANA', 'Takeaway', 26.00),
(21, 1, 'ITEM', 'PIZZA_VEGETARIANA', 'Delivery', 28.08),
(22, 1, 'ITEM', 'PIZZA_BBQ_CHICKEN', 'POS', 32.00),
(23, 1, 'ITEM', 'PIZZA_BBQ_CHICKEN', 'Takeaway', 32.00),
(24, 1, 'ITEM', 'PIZZA_BBQ_CHICKEN', 'Delivery', 34.56),
(25, 1, 'ITEM', 'PIZZA_PROSC_FUNGHI', 'POS', 30.00),
(26, 1, 'ITEM', 'PIZZA_PROSC_FUNGHI', 'Takeaway', 30.00),
(27, 1, 'ITEM', 'PIZZA_PROSC_FUNGHI', 'Delivery', 32.40),
(28, 1, 'ITEM', 'PIZZA_CALZONE', 'POS', 28.00),
(29, 1, 'ITEM', 'PIZZA_CALZONE', 'Takeaway', 28.00),
(30, 1, 'ITEM', 'PIZZA_CALZONE', 'Delivery', 30.24),
(31, 1, 'ITEM', 'BURGER_CLASSIC', 'POS', 22.00),
(32, 1, 'ITEM', 'BURGER_CLASSIC', 'Takeaway', 22.00),
(33, 1, 'ITEM', 'BURGER_CLASSIC', 'Delivery', 23.76),
(34, 1, 'ITEM', 'BURGER_CHEESE', 'POS', 24.00),
(35, 1, 'ITEM', 'BURGER_CHEESE', 'Takeaway', 24.00),
(36, 1, 'ITEM', 'BURGER_CHEESE', 'Delivery', 25.92),
(37, 1, 'ITEM', 'BURGER_BBQ', 'POS', 26.00),
(38, 1, 'ITEM', 'BURGER_BBQ', 'Takeaway', 26.00),
(39, 1, 'ITEM', 'BURGER_BBQ', 'Delivery', 28.08),
(40, 1, 'ITEM', 'BURGER_CHICKEN', 'POS', 24.00),
(41, 1, 'ITEM', 'BURGER_CHICKEN', 'Takeaway', 24.00),
(42, 1, 'ITEM', 'BURGER_CHICKEN', 'Delivery', 25.92),
(43, 1, 'ITEM', 'BURGER_VEGGIE', 'POS', 22.00),
(44, 1, 'ITEM', 'BURGER_VEGGIE', 'Takeaway', 22.00),
(45, 1, 'ITEM', 'BURGER_VEGGIE', 'Delivery', 23.76),
(46, 1, 'ITEM', 'PASTA_BOLOGNESE', 'POS', 26.00),
(47, 1, 'ITEM', 'PASTA_BOLOGNESE', 'Takeaway', 26.00),
(48, 1, 'ITEM', 'PASTA_BOLOGNESE', 'Delivery', 28.08),
(49, 1, 'ITEM', 'PASTA_CARBONARA', 'POS', 28.00),
(50, 1, 'ITEM', 'PASTA_CARBONARA', 'Takeaway', 28.00),
(51, 1, 'ITEM', 'PASTA_CARBONARA', 'Delivery', 30.24),
(52, 1, 'ITEM', 'PASTA_LASAGNE', 'POS', 30.00),
(53, 1, 'ITEM', 'PASTA_LASAGNE', 'Takeaway', 30.00),
(54, 1, 'ITEM', 'PASTA_LASAGNE', 'Delivery', 32.40),
(55, 1, 'ITEM', 'SALAD_CAESAR', 'POS', 22.00),
(56, 1, 'ITEM', 'SALAD_CAESAR', 'Takeaway', 22.00),
(57, 1, 'ITEM', 'SALAD_CAESAR', 'Delivery', 23.76),
(58, 1, 'ITEM', 'SALAD_GREEK', 'POS', 20.00),
(59, 1, 'ITEM', 'SALAD_GREEK', 'Takeaway', 20.00),
(60, 1, 'ITEM', 'SALAD_GREEK', 'Delivery', 21.60),
(61, 1, 'ITEM', 'DRINK_COLA_05', 'POS', 7.00),
(62, 1, 'ITEM', 'DRINK_COLA_05', 'Takeaway', 7.00),
(63, 1, 'ITEM', 'DRINK_COLA_05', 'Delivery', 7.56),
(64, 1, 'ITEM', 'DRINK_SPRITE_05', 'POS', 7.00),
(65, 1, 'ITEM', 'DRINK_SPRITE_05', 'Takeaway', 7.00),
(66, 1, 'ITEM', 'DRINK_SPRITE_05', 'Delivery', 7.56),
(67, 1, 'ITEM', 'DRINK_WATER_05', 'POS', 5.00),
(68, 1, 'ITEM', 'DRINK_WATER_05', 'Takeaway', 5.00),
(69, 1, 'ITEM', 'DRINK_WATER_05', 'Delivery', 5.40),
(70, 1, 'ITEM', 'DRINK_JUICE_ORANGE', 'POS', 8.00),
(71, 1, 'ITEM', 'DRINK_JUICE_ORANGE', 'Takeaway', 8.00),
(72, 1, 'ITEM', 'DRINK_JUICE_ORANGE', 'Delivery', 8.64),
(73, 1, 'ITEM', 'DRINK_BEER_TYSKIE', 'POS', 9.00),
(74, 1, 'ITEM', 'DRINK_BEER_TYSKIE', 'Takeaway', 9.00),
(75, 1, 'ITEM', 'DRINK_BEER_TYSKIE', 'Delivery', 9.72),
(76, 1, 'ITEM', 'SIDE_FRIES', 'POS', 9.00),
(77, 1, 'ITEM', 'SIDE_FRIES', 'Takeaway', 9.00),
(78, 1, 'ITEM', 'SIDE_FRIES', 'Delivery', 9.72),
(79, 1, 'ITEM', 'SIDE_GARLIC_SAUCE', 'POS', 3.00),
(80, 1, 'ITEM', 'SIDE_GARLIC_SAUCE', 'Takeaway', 3.00),
(81, 1, 'ITEM', 'SIDE_GARLIC_SAUCE', 'Delivery', 3.24),
(82, 1, 'ITEM', 'SIDE_ONION_RINGS', 'POS', 10.00),
(83, 1, 'ITEM', 'SIDE_ONION_RINGS', 'Takeaway', 10.00),
(84, 1, 'ITEM', 'SIDE_ONION_RINGS', 'Delivery', 10.80),
(85, 1, 'ITEM', 'SIDE_NUGGETS_6', 'POS', 14.00),
(86, 1, 'ITEM', 'SIDE_NUGGETS_6', 'Takeaway', 14.00),
(87, 1, 'ITEM', 'SIDE_NUGGETS_6', 'Delivery', 15.12),
(88, 1, 'ITEM', 'DESSERT_TIRAMISU', 'POS', 16.00),
(89, 1, 'ITEM', 'DESSERT_TIRAMISU', 'Takeaway', 16.00),
(90, 1, 'ITEM', 'DESSERT_TIRAMISU', 'Delivery', 17.28),
(91, 1, 'ITEM', 'DESSERT_PANNA_COTTA', 'POS', 14.00),
(92, 1, 'ITEM', 'DESSERT_PANNA_COTTA', 'Takeaway', 14.00),
(93, 1, 'ITEM', 'DESSERT_PANNA_COTTA', 'Delivery', 15.12),
(94, 1, 'ITEM', 'SET_LUNCH_PIZZA', 'POS', 29.00),
(95, 1, 'ITEM', 'SET_LUNCH_PIZZA', 'Takeaway', 29.00),
(96, 1, 'ITEM', 'SET_LUNCH_PIZZA', 'Delivery', 31.32),
(97, 1, 'ITEM', 'SET_BURGER_COMBO', 'POS', 32.00),
(98, 1, 'ITEM', 'SET_BURGER_COMBO', 'Takeaway', 32.00),
(99, 1, 'ITEM', 'SET_BURGER_COMBO', 'Delivery', 34.56),
(100, 1, 'MODIFIER', 'SIZE_S', 'POS', -4.00),
(101, 1, 'MODIFIER', 'SIZE_M', 'POS', 0.00),
(102, 1, 'MODIFIER', 'SIZE_L', 'POS', 6.00),
(103, 1, 'MODIFIER', 'SIZE_XL', 'POS', 14.00),
(104, 1, 'MODIFIER', 'EXTRA_CHEESE', 'POS', 4.00),
(105, 1, 'MODIFIER', 'EXTRA_JALAP', 'POS', 3.00),
(106, 1, 'MODIFIER', 'EXTRA_OLIVES', 'POS', 3.00),
(107, 1, 'MODIFIER', 'EXTRA_HAM', 'POS', 5.00),
(108, 1, 'MODIFIER', 'SAUCE_GARLIC', 'POS', 2.00),
(109, 1, 'MODIFIER', 'SAUCE_BBQ', 'POS', 2.00),
(110, 1, 'MODIFIER', 'SAUCE_HOT', 'POS', 2.00),
(111, 1, 'MODIFIER', 'BURG_STD', 'POS', 0.00),
(112, 1, 'MODIFIER', 'BURG_DBL', 'POS', 8.00),
(782, 1, 'ITEM', 'DRINK_PEPSI_500', 'Delivery', 6.00),
(783, 1, 'ITEM', 'SAUCE_GARLIC', 'Delivery', 3.50),
(786, 1, 'ITEM', 'DRINK_WATER_500', 'Delivery', 4.00),
(787, 1, 'ITEM', 'SAUCE_BBQ', 'Delivery', 3.50);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_product_mapping`
--

CREATE TABLE `sh_product_mapping` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `external_name` varchar(255) NOT NULL,
  `internal_sku` varchar(128) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sh_product_mapping`
--

INSERT INTO `sh_product_mapping` (`id`, `tenant_id`, `external_name`, `internal_sku`) VALUES
(1, 1, 'Mąka pszenna Caputo \"00\"', 'MKA_TIPO00'),
(2, 1, 'Mozzarella Fior di Latte 1kg', 'SER_MOZZ'),
(3, 1, 'Passata pomidorowa S.Marzano 2.5L', 'SOS_POM'),
(4, 1, 'Oliwa extra vergine Ferrini 5L', 'OLJ_OLIWA'),
(5, 1, 'Coca-Cola 0.5L x24 zgrzewka', 'COCA_COLA_05'),
(6, 1, 'Woda Żywiec 0.5L x12', 'WODA_05');

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_promotions`
--

CREATE TABLE `sh_promotions` (
  `id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `ascii_key` varchar(64) NOT NULL,
  `name` varchar(128) NOT NULL,
  `rule_kind` enum('discount_percent','discount_amount','combo_half_price','free_item_if_threshold','bundle') NOT NULL DEFAULT 'discount_percent',
  `rule_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Parametry regu??y: trigger_sku, target_sku, discount, threshold itd.' CHECK (json_valid(`rule_json`)),
  `badge_text` varchar(32) DEFAULT NULL COMMENT 'np. "-50%", "GRATIS", "KOMBO"',
  `badge_style` varchar(32) DEFAULT 'amber' COMMENT 'neon / gold / red_burst / amber / vintage',
  `time_window_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT '{ days:[1..7], start:"HH:MM", end:"HH:MM" }' CHECK (json_valid(`time_window_json`)),
  `valid_from` datetime DEFAULT NULL,
  `valid_to` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='M022: definicje promocji (logika w CartEngine ??? Faza 4)';

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_promo_codes`
--

CREATE TABLE `sh_promo_codes` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `code` varchar(64) NOT NULL,
  `type` varchar(32) NOT NULL,
  `value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `min_order_value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `max_uses` int(11) NOT NULL DEFAULT 0,
  `current_uses` int(11) NOT NULL DEFAULT 0,
  `valid_from` datetime NOT NULL,
  `valid_to` datetime NOT NULL,
  `allowed_channels` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_rate_limits`
--

CREATE TABLE `sh_rate_limits` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `api_key_id` bigint(20) UNSIGNED NOT NULL,
  `window_kind` enum('minute','day','hour') NOT NULL,
  `window_bucket` varchar(19) NOT NULL COMMENT 'ISO timestamp bucketu: "2026-04-18 19:45" (minute) / "2026-04-18" (day) / "2026-04-18 19" (hour)',
  `request_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `first_hit_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_hit_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Sliding-window rate limiter per API key (m027)';

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_recipes`
--

CREATE TABLE `sh_recipes` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `menu_item_sku` varchar(255) NOT NULL COMMENT 'Logical item_sku (ascii_key); FK below',
  `warehouse_sku` varchar(128) NOT NULL,
  `quantity_base` decimal(10,4) NOT NULL DEFAULT 0.0000,
  `waste_percent` decimal(10,4) NOT NULL DEFAULT 0.0000,
  `is_packaging` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sh_recipes`
--

INSERT INTO `sh_recipes` (`id`, `tenant_id`, `menu_item_sku`, `warehouse_sku`, `quantity_base`, `waste_percent`, `is_packaging`) VALUES
(7, 1, 'PIZZA_PEPPERONI', 'MKA_TIPO00', 0.2500, 2.0000, 0),
(8, 1, 'PIZZA_PEPPERONI', 'SER_MOZZ', 0.1800, 0.0000, 0),
(9, 1, 'PIZZA_PEPPERONI', 'SOS_POM', 0.1000, 0.0000, 0),
(10, 1, 'PIZZA_PEPPERONI', 'PEPP_SALAMI', 0.0800, 0.0000, 0),
(11, 1, 'PIZZA_PEPPERONI', 'OPAK_PIZZA', 1.0000, 0.0000, 1),
(12, 1, 'PIZZA_CAPRICCIOSA', 'MKA_TIPO00', 0.2500, 2.0000, 0),
(13, 1, 'PIZZA_CAPRICCIOSA', 'SER_MOZZ', 0.1800, 0.0000, 0),
(14, 1, 'PIZZA_CAPRICCIOSA', 'SOS_POM', 0.1000, 0.0000, 0),
(15, 1, 'PIZZA_CAPRICCIOSA', 'SZYNKA_PARM', 0.0600, 0.0000, 0),
(16, 1, 'PIZZA_CAPRICCIOSA', 'PIECZARKI', 0.0500, 0.0000, 0),
(17, 1, 'PIZZA_CAPRICCIOSA', 'OPAK_PIZZA', 1.0000, 0.0000, 1),
(18, 1, 'PIZZA_HAWAJSKA', 'MKA_TIPO00', 0.2500, 2.0000, 0),
(19, 1, 'PIZZA_HAWAJSKA', 'SER_MOZZ', 0.1800, 0.0000, 0),
(20, 1, 'PIZZA_HAWAJSKA', 'SOS_POM', 0.1000, 0.0000, 0),
(21, 1, 'PIZZA_HAWAJSKA', 'SZYNKA_PARM', 0.0600, 0.0000, 0),
(22, 1, 'PIZZA_HAWAJSKA', 'ANANAS', 0.0600, 0.0000, 0),
(23, 1, 'PIZZA_HAWAJSKA', 'OPAK_PIZZA', 1.0000, 0.0000, 1),
(24, 1, 'PIZZA_4FORMAGGI', 'MKA_TIPO00', 0.2500, 2.0000, 0),
(25, 1, 'PIZZA_4FORMAGGI', 'SER_MOZZ', 0.1200, 0.0000, 0),
(26, 1, 'PIZZA_4FORMAGGI', 'SER_GORG', 0.0500, 0.0000, 0),
(27, 1, 'PIZZA_4FORMAGGI', 'SER_PARM', 0.0400, 0.0000, 0),
(28, 1, 'PIZZA_4FORMAGGI', 'SER_CHEDDAR', 0.0400, 0.0000, 0),
(29, 1, 'PIZZA_4FORMAGGI', 'OPAK_PIZZA', 1.0000, 0.0000, 1),
(30, 1, 'BURGER_CLASSIC', 'WOLOWINA_M', 0.1800, 3.0000, 0),
(31, 1, 'BURGER_CLASSIC', 'BULKA_BURG', 1.0000, 0.0000, 0),
(32, 1, 'BURGER_CLASSIC', 'SALATA_RZY', 0.0300, 0.0000, 0),
(33, 1, 'BURGER_CLASSIC', 'POMIDOR', 0.0400, 0.0000, 0),
(34, 1, 'BURGER_CLASSIC', 'CEBULA', 0.0200, 0.0000, 0),
(35, 1, 'BURGER_CLASSIC', 'OPAK_BURGER', 1.0000, 0.0000, 1),
(36, 1, 'PASTA_BOLOGNESE', 'MAKARON_SPAG', 0.1500, 0.0000, 0),
(37, 1, 'PASTA_BOLOGNESE', 'WOLOWINA_M', 0.1200, 3.0000, 0),
(38, 1, 'PASTA_BOLOGNESE', 'SOS_POM', 0.1200, 0.0000, 0),
(39, 1, 'PASTA_BOLOGNESE', 'CEBULA', 0.0300, 0.0000, 0),
(40, 1, 'SALAD_CAESAR', 'SALATA_RZY', 0.1500, 5.0000, 0),
(41, 1, 'SALAD_CAESAR', 'KURCZAK', 0.1000, 0.0000, 0),
(42, 1, 'SALAD_CAESAR', 'SER_PARM', 0.0300, 0.0000, 0),
(43, 1, 'SALAD_CAESAR', 'OLJ_OLIWA', 0.0200, 0.0000, 0),
(44, 1, 'SIDE_FRIES', 'FRYTKI_MRZ', 0.2500, 5.0000, 0),
(617, 1, 'PIZZA_MARGHERITA', 'MKA_TIPO00', 0.2500, 2.0000, 0),
(618, 1, 'PIZZA_MARGHERITA', 'SER_MOZZ', 0.2000, 0.0000, 0),
(619, 1, 'PIZZA_MARGHERITA', 'SOS_POM', 0.1000, 0.0000, 0),
(620, 1, 'PIZZA_MARGHERITA', 'OLJ_OLIWA', 0.0150, 0.0000, 0),
(621, 1, 'PIZZA_MARGHERITA', 'DRZ_SUCHE', 0.0030, 0.0000, 0),
(622, 1, 'PIZZA_MARGHERITA', 'OPAK_PIZZA', 1.0000, 0.0000, 1);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_scene_promotion_slots`
--

CREATE TABLE `sh_scene_promotion_slots` (
  `id` int(10) UNSIGNED NOT NULL,
  `scene_id` int(11) NOT NULL COMMENT 'FK logiczne do sh_atelier_scenes.id (signed INT bo tak jest tam)',
  `promotion_id` int(10) UNSIGNED NOT NULL COMMENT 'FK logiczne do sh_promotions.id',
  `slot_x` decimal(6,2) NOT NULL DEFAULT 50.00 COMMENT 'Pozycja % na scenie (X)',
  `slot_y` decimal(6,2) NOT NULL DEFAULT 50.00 COMMENT 'Pozycja % na scenie (Y)',
  `slot_z_index` int(11) NOT NULL DEFAULT 100,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='M022: link-table promotion ??? scena z pozycj??';

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_scene_templates`
--

CREATE TABLE `sh_scene_templates` (
  `id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = system template (wbudowany), >0 = custom tenanta',
  `ascii_key` varchar(64) NOT NULL COMMENT 'Stabilny identyfikator: pizza_top_down, static_hero, ...',
  `name` varchar(128) NOT NULL,
  `kind` enum('item','category') NOT NULL DEFAULT 'item' COMMENT 'item = jedno danie; category = st???? ca??ej kategorii',
  `stage_preset_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'aspect, background_kind, lighting, vignette, grain, letterbox' CHECK (json_valid(`stage_preset_json`)),
  `composition_schema_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'layers_required, layers_optional, centerpiece, centerpiece_position' CHECK (json_valid(`composition_schema_json`)),
  `scene_kit_assets_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Lista asset_id z sh_assets dla t??a/rekwizyt??w/??wiate?? ??? Faza 2' CHECK (json_valid(`scene_kit_assets_json`)),
  `pipeline_preset_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Domy??lne ustawienia AI Photo Pipeline: preset, auto_apply, kroki' CHECK (json_valid(`pipeline_preset_json`)),
  `placeholder_asset_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'FK logiczne do sh_assets ??? co pokaza?? gdy brak zdj??cia dania',
  `photographer_brief_md` text DEFAULT NULL COMMENT 'Markdown brief dla fotografa per template',
  `available_cameras_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Tablica camera presets dost??pnych w tym template' CHECK (json_valid(`available_cameras_json`)),
  `available_luts_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Tablica LUT presets dost??pnych w tym template' CHECK (json_valid(`available_luts_json`)),
  `atmospheric_effects_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Tablica atmospheric effects dost??pnych w tym template' CHECK (json_valid(`atmospheric_effects_json`)),
  `default_style_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK logiczne do sh_style_presets ??? domy??lny styl wizualny',
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='M022: biblioteka szablon??w scenografii (Scene Studio)';

--
-- Dumping data for table `sh_scene_templates`
--

INSERT INTO `sh_scene_templates` (`id`, `tenant_id`, `ascii_key`, `name`, `kind`, `stage_preset_json`, `composition_schema_json`, `scene_kit_assets_json`, `pipeline_preset_json`, `placeholder_asset_id`, `photographer_brief_md`, `available_cameras_json`, `available_luts_json`, `atmospheric_effects_json`, `default_style_id`, `is_system`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 0, 'pizza_top_down', 'Pizza — kamera z góry', 'item', '{\"aspect\": \"1/1\", \"background_kind\": \"rustic_wood\", \"lighting\": {\"preset\": \"warm_top\", \"x\": 50, \"y\": 15}, \"vignette\": 25, \"grain\": 6, \"letterbox\": 0}', '{\"layers_required\": [\"base\"], \"layers_optional\": [\"sauce\", \"cheese\", \"meat\", \"veg\", \"herb\", \"garnish\"], \"centerpiece\": \"pizza\", \"centerpiece_position\": {\"x\": 50, \"y\": 50, \"scale\": 1.0}}', '{\"backgrounds\":[266,267,268,270,275],\"props\":[296,297,276,277,298,286,289,281,282,301,302],\"lights\":[306,311,309,307],\"badges\":[314,315,316,317,318]}', '{\"preset\": \"appetizing\", \"auto_apply\": true, \"background_remove\": true, \"tone_map_to_kit\": true, \"add_drop_shadow\": true}', NULL, '## Pizza Top-Down — Brief Fotograficzny\n\n**Kamera:** prostopadle z góry, odległość 35-45cm od deski.\n**Światło:** naturalne z okna PO LEWEJ stronie, najlepiej 10:00-12:00.\n**Tło:** BIAŁY talerz lub czarna deska drewniana (NIE kuchenny blat).\n**Kompozycja:** pizza idealnie wycentrowana, lekki cień z prawej.\n\n**Czego unikać:**\n- Neonu kuchennego (szaro-zielony podkład).\n- Flesza telefonu (twardy cień).\n- Zdjęć pod kątem (my prostujemy, ale jakość spada).', '[\"top_down\", \"macro_close\", \"wide_establishing\"]', '[\"warm_summer_evening\", \"golden_hour\", \"crisp_morning\", \"teal_orange_blockbuster\"]', '[\"steam_rising\", \"dust_particles_golden\"]', NULL, 1, 1, '2026-04-18 00:41:17', '2026-04-18 16:52:11'),
(2, 0, 'static_hero', 'Gotowe zdjęcie dania', 'item', '{\"aspect\": \"4/3\", \"background_kind\": \"neutral_wood\", \"lighting\": {\"preset\": \"soft_box\", \"x\": 50, \"y\": 30}, \"vignette\": 20, \"grain\": 4, \"letterbox\": 0}', '{\"layers_required\": [\"hero\"], \"layers_optional\": [], \"centerpiece\": \"hero\", \"centerpiece_position\": {\"x\": 50, \"y\": 55, \"scale\": 1.0}}', '{\"backgrounds\":[266,267,268,269,270,271,272,273,274,275],\"props\":[276,277,278,279,280,281,282,283,284,285,286,287,288,289,290,291,292,293,294,295,296,297,298,299,300,301,302,303,304,305],\"lights\":[306,307,308,309,310,311,312,313],\"badges\":[314,315,316,317,318]}', '{\"preset\": \"appetizing\", \"auto_apply\": true, \"background_remove\": true, \"tone_map_to_kit\": true, \"add_drop_shadow\": true}', NULL, '## Static Hero — Brief Fotograficzny\n\n**Kamera:** kąt 3/4 (najlepszy dla burgerów, kanapek) lub z góry (dla makaronu, sałatki).\n**Światło:** miękkie, soft-box lub okno z dyfuzorem.\n**Tło:** neutralne (białe, beżowe, jasne drewno) — żeby nie konkurowało z daniem.\n**Kompozycja:** danie zajmuje ~70% kadru, mały oddech wokół.\n\n**Czego unikać:**\n- Głębokich, zimnych cieni (twardy flesz).\n- Mocnych refleksów na talerzu.\n- Tła kolorowo-wzorzystego.', '[\"hero_three_quarter\", \"top_down\", \"macro_close\"]', '[\"warm_summer_evening\", \"crisp_morning\", \"golden_hour\", \"cold_nordic\"]', '[\"steam_rising\"]', NULL, 1, 1, '2026-04-18 00:41:17', '2026-04-18 16:52:11'),
(3, 0, 'pasta_bowl_placeholder', 'Makaron — miska 3/4 kąt', 'item', '{\"aspect\": \"4/3\", \"background_kind\": \"linen_beige\", \"lighting\": {\"preset\": \"warm_window_left\", \"x\": 30, \"y\": 20}, \"vignette\": 18, \"grain\": 3, \"letterbox\": 0}', '{\"layers_required\": [\"hero\"], \"layers_optional\": [\"garnish\"], \"centerpiece\": \"bowl\", \"centerpiece_position\": {\"x\": 50, \"y\": 60, \"scale\": 1.0}}', '{\"backgrounds\":[271,268,266,272],\"props\":[281,283,296,297,276,298,288,287,291,293],\"lights\":[306,311,309],\"badges\":[314,315,316,317,318]}', '{\"preset\": \"appetizing\", \"auto_apply\": true, \"background_remove\": true, \"tone_map_to_kit\": true, \"add_drop_shadow\": true, \"warm_boost\": 0.15}', NULL, '## Pasta Bowl — Brief Fotograficzny\n\n**Kamera:** kąt 3/4 (30-45°) od góry, odległość 40-50cm.\n**Światło:** miękkie, naturalne z okna PO LEWEJ stronie, dodatkowy rim-light z prawej dla głębi.\n**Tło:** beżowy lniany obrus lub jasne drewno, rozmyte tło (bokeh).\n**Kompozycja:** miska zajmuje ~60% kadru, widelec lub drewniane pałeczki obok (opcjonalnie zwinięty makaron na widelcu — „glamour shot\"). Odrobina świeżej bazylii/pietruszki na wierzchu.\n**Para:** najlepiej gdy danie jest gorące — para dodaje realizmu.\n\n**Czego unikać:**\n- Zimnego makaronu (brak pary, zastygnięty sos = martwe zdjęcie).\n- Płaskiego, top-down kadru (to dla pizzy, nie miski).\n- Tła kolorowo-wzorzystego (odwraca uwagę od dania).', '[\"hero_three_quarter\", \"macro_close\", \"top_down\"]', '[\"warm_summer_evening\", \"golden_hour\", \"crisp_morning\", \"bleach_bypass\"]', '[\"steam_rising\", \"dust_particles_golden\"]', NULL, 1, 1, '2026-04-18 00:41:17', '2026-04-18 16:52:11'),
(4, 0, 'beverage_bottle_placeholder', 'Napój — butelka / kubek (boczny)', 'item', '{\"aspect\": \"3/4\", \"background_kind\": \"condensation_glass\", \"lighting\": {\"preset\": \"studio_softbox_rim\", \"x\": 30, \"y\": 30}, \"vignette\": 12, \"grain\": 2, \"letterbox\": 0}', '{\"layers_required\": [\"hero\"], \"layers_optional\": [\"condensation\", \"label_foreground\"], \"centerpiece\": \"bottle\", \"centerpiece_position\": {\"x\": 50, \"y\": 55, \"scale\": 1.1}}', '{\"backgrounds\":[270,269,272,273],\"props\":[291,292,293,295,294,286],\"lights\":[308,309,313,312],\"badges\":[314,315,316,317,318]}', '{\"preset\": \"studio\", \"auto_apply\": true, \"background_remove\": true, \"tone_map_to_kit\": true, \"add_drop_shadow\": true, \"enhance_label\": true}', NULL, '## Beverage Bottle — Brief Fotograficzny\n\n**Kamera:** poziom oczu (eye-level) lub lekko z dołu (low angle 5-10°) dla heroicznego efektu.\n**Światło:** dwa softboxy — główne z boku (45°), kontra z tyłu (rim light) aby wyciągnąć krawędzie butelki i kondensację.\n**Tło:** ciemne (granatowe, czarne) lub neutralne szare z efektem motion blur — napój ma być bohaterem.\n**Butelka:** schłodzona z lodówki, krople kondensacji na szkle. Etykieta idealnie czytelna.\n**Kompozycja:** butelka lekko po lewej od centrum (zasada trzech), przestrzeń negatywna po prawej dla ceny.\n\n**Czego unikać:**\n- Zdjęć z góry (tracimy proporcje butelki, wyglądają jak lekarstwo).\n- Butelki w pełnym słońcu (odblaski na etykiecie = nieczytelne).\n- Wody w szklance bez lodu (amator-look).', '[\"hero_eye_level\", \"slight_low_angle\", \"macro_close\"]', '[\"cold_nordic\", \"crisp_morning\", \"teal_orange_blockbuster\"]', '[\"condensation_drops\", \"dust_particles_golden\"]', NULL, 1, 1, '2026-04-18 00:41:17', '2026-04-18 16:52:11'),
(5, 0, 'burger_three_quarter_placeholder', 'Burger — kąt 3/4 (signature shot)', 'item', '{\"aspect\": \"1/1\", \"background_kind\": \"rustic_wood_dark\", \"lighting\": {\"preset\": \"warm_rim_both\", \"x\": 50, \"y\": 25}, \"vignette\": 30, \"grain\": 7, \"letterbox\": 0}', '{\"layers_required\": [\"hero\"], \"layers_optional\": [\"side_dish\", \"sauce_drip\"], \"centerpiece\": \"burger\", \"centerpiece_position\": {\"x\": 50, \"y\": 55, \"scale\": 1.05}}', '{\"backgrounds\":[267,270,273,274],\"props\":[287,289,278,281,282,305,295],\"lights\":[307,312,311,306],\"badges\":[314,315,316,317,318]}', '{\"preset\": \"dramatic\", \"auto_apply\": true, \"background_remove\": false, \"tone_map_to_kit\": true, \"add_drop_shadow\": true, \"warm_boost\": 0.25, \"contrast_boost\": 0.15}', NULL, '## Burger 3/4 — Brief Fotograficzny\n\n**Kamera:** kąt 3/4 (około 30° od poziomu), odległość 25-35cm. Żywy burger = widoczne 3 warstwy.\n**Światło:** ciepły rim light z tyłu (żółto-pomarańczowy) + główne światło z boku (neutralne białe). Rim najważniejszy — oddziela burger od tła.\n**Tło:** ciemne drewno rustykalne, łupek, metalowa taca — męski klimat steakhouse.\n**Akcesoria:** frytki obok (w papierowym rożku lub małym wiaderku), kapsla od cola. OPCJONALNIE: kropla sosu spływająca z bułki.\n**Bułka:** lekko posypana sezamem, świeża, NIE zgnieciona — górna bułka w połowie zsunięta dla pokazu warstw.\n\n**Czego unikać:**\n- Zimnego burgera (ser musi się lekko topić).\n- Zdjęć top-down (tracimy warstwy — to nie pizza!).\n- Białego tła (burger zlewa się, brak głębi).', '[\"hero_three_quarter\", \"hero_eye_level\", \"macro_close\"]', '[\"warm_summer_evening\", \"golden_hour\", \"hot_mexican\", \"bleach_bypass\"]', '[\"steam_rising\", \"sauce_drip\", \"dust_particles_golden\"]', NULL, 1, 1, '2026-04-18 00:41:17', '2026-04-18 16:52:11'),
(6, 0, 'sushi_top_down_placeholder', 'Sushi — kamera z góry (board)', 'item', '{\"aspect\": \"16/10\", \"background_kind\": \"slate_dark\", \"lighting\": {\"preset\": \"studio_soft_top\", \"x\": 50, \"y\": 0}, \"vignette\": 20, \"grain\": 4, \"letterbox\": 0}', '{\"layers_required\": [\"hero\"], \"layers_optional\": [\"garnish\", \"chopsticks\", \"soy_sauce_bowl\"], \"centerpiece\": \"sushi_board\", \"centerpiece_position\": {\"x\": 50, \"y\": 50, \"scale\": 1.0}}', '{\"backgrounds\":[270,269,274,267],\"props\":[284,285,304,303,288,298],\"lights\":[308,309,306],\"badges\":[314,315,316,317,318]}', '{\"preset\": \"studio\", \"auto_apply\": true, \"background_remove\": true, \"tone_map_to_kit\": true, \"add_drop_shadow\": true, \"sharpness_boost\": 0.2}', NULL, '## Sushi Top-Down — Brief Fotograficzny\n\n**Kamera:** prostopadle z góry (90°), odległość 40-50cm. Cała deska w kadrze.\n**Światło:** soft-box nad stołem (central top), lekki rim z boku. Sushi ma subtelny połysk — nie twardy, nie matowy.\n**Tło:** czarny łupek, ciemne drewno bambusowe, czarny marmur — kontrastuje z ryżem.\n**Akcesoria:** pałeczki drewniane skośnie w rogu, małe naczynko z sosem sojowym, listek wasabi. OPCJONALNIE: imbir marinowany w rogu.\n**Układ:** sushi NIE w rzędzie — naturalnie rozrzucone, lekko pod różnymi kątami dla dynamiki.\n\n**Czego unikać:**\n- Zdjęć pod kątem (top-down to tradycja w food photography sushi).\n- Białego tła (ryż się zlewa, tracimy kontrast).\n- Rozwinietych rolek (wygląda jak pomyłka; chyba że to celowy art shot).', '[\"top_down\", \"macro_close\", \"dutch_angle\"]', '[\"cold_nordic\", \"film_noir_bw\", \"crisp_morning\", \"teal_orange_blockbuster\"]', '[\"dust_particles_golden\"]', NULL, 1, 1, '2026-04-18 00:41:17', '2026-04-18 16:52:11'),
(7, 0, 'category_flat_table', 'Kategoria — wspólny stół', 'category', '{\"aspect\": \"16/10\", \"background_kind\": \"rustic_wood\", \"lighting\": {\"preset\": \"warm_diffused\"}}', '{\"layout\": \"grid_2x3\", \"max_items\": 6, \"item_thumb_size\": \"medium\", \"spacing_px\": 32, \"allow_item_reorder\": true, \"show_prices\": true, \"show_cta\": true, \"cta_style\": \"glass_dark\"}', '{\"backgrounds\":[266,267,268,269,270,271,272,273,274,275],\"props\":[286,288,300,296],\"lights\":[306,309,310],\"badges\":[314,315,316,317,318]}', '{\"preset\": \"appetizing\", \"auto_apply\": true, \"unified_lighting\": true, \"unified_background_removal\": true}', NULL, '## Category Flat Table — Brief dla Managera\n\n**Kiedy używać:** kategoria ma 2-6 pozycji, wszystkie można zmieścić na jednym wspólnym stole (np. sosy, napoje, desery).\n\n**Manager:** w edytorze wybierz stół (Scene Kit background), rozłóż items drag&drop, każdy z własną ceną i CTA „Dodaj\". Klient widzi jedną dioramę z wszystkimi pozycjami naraz.\n\n**Nie używać gdy:** więcej niż 6 pozycji (user traci overview) lub pozycje wymagają indywidualnej scenografii (użyj `individual`).', '[\"wide_establishing\", \"top_down\"]', '[\"warm_summer_evening\", \"golden_hour\", \"crisp_morning\"]', '[\"dust_particles_golden\", \"candle_glow\"]', NULL, 1, 1, '2026-04-18 00:41:17', '2026-04-18 16:52:11'),
(8, 0, 'category_hero_wall', 'Kategoria — banner kategorii', 'category', '{\"aspect\": \"16/9\", \"background_kind\": \"dark_premium\", \"lighting\": {\"preset\": \"dramatic_rim\"}}', '{\"layout\": \"hero_text\", \"tagline_visible\": true, \"subtitle_visible\": true, \"cta_visible\": true, \"cta_text_default\": \"Zobacz wszystkie\", \"text_position\": \"left_center\", \"text_max_width_percent\": 50}', '{\"backgrounds\":[267,270,269,272,274],\"props\":[],\"lights\":[312,311,313,307],\"badges\":[314,315,316,317,318]}', '{\"preset\": \"dramatic\", \"auto_apply\": true, \"warm_boost\": 0.2, \"contrast_boost\": 0.3}', NULL, '## Category Hero Wall — Brief dla Managera\n\n**Kiedy używać:** otwarcie kategorii premium (np. „Pizze\", „Desery autorskie\") — pierwsza diorama w sekwencji indywidualnych scen (layout_mode=hybrid).\n\n**Struktura:** duże dramatyczne zdjęcie flagowego produktu + tagline (np. „NAJLEPSZE PIZZE W MIEŚCIE\") + subtitle + CTA „Zobacz wszystkie →\".\n\n**Manager:** wybierz hero_asset (jedno zdjęcie flagowca kategorii), ustaw tagline + subtitle. Kolejne dioramy to dania indywidualne.\n\n**Nie używać gdy:** kategoria ma < 3 pozycje (overkill) lub layout_mode = legacy_list / grouped.', '[\"wide_establishing\", \"hero_three_quarter\"]', '[\"film_noir_bw\", \"teal_orange_blockbuster\", \"golden_hour\", \"bleach_bypass\"]', '[\"dust_particles_golden\", \"sun_rays\", \"candle_glow\"]', NULL, 1, 1, '2026-04-18 00:41:17', '2026-04-18 16:52:11');

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_scene_triggers`
--

CREATE TABLE `sh_scene_triggers` (
  `id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `scene_id` int(11) NOT NULL COMMENT 'FK logiczne do sh_atelier_scenes.id',
  `trigger_rule_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT '{ date_range, time_range, weather, day_of_week }' CHECK (json_valid(`trigger_rule_json`)),
  `priority` int(11) NOT NULL DEFAULT 100 COMMENT 'Wy??szy priorytet wygrywa gdy wiele triggers aktywnych',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `valid_from` datetime DEFAULT NULL,
  `valid_to` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='M022: automatyczne triggery scen (Faza 4 cron runner)';

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_scene_variants`
--

CREATE TABLE `sh_scene_variants` (
  `id` int(10) UNSIGNED NOT NULL,
  `parent_scene_id` int(11) NOT NULL COMMENT 'FK logiczne do sh_atelier_scenes.id',
  `variant_spec_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Pe??ny DishSceneSpec wariantu' CHECK (json_valid(`variant_spec_json`)),
  `generated_by` enum('manual','ai_oneshot','ai_ab_test') NOT NULL DEFAULT 'manual',
  `variant_label` varchar(128) DEFAULT NULL COMMENT 'np. "wieczorne", "drugi k??t", "winter mood"',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='M022: warianty scen (Faza 4)';

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_sla_breaches`
--

CREATE TABLE `sh_sla_breaches` (
  `id` char(36) NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `order_id` char(36) NOT NULL,
  `breach_minutes` int(11) NOT NULL,
  `driver_id` varchar(64) DEFAULT NULL,
  `course_id` varchar(32) DEFAULT NULL,
  `logged_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_style_presets`
--

CREATE TABLE `sh_style_presets` (
  `id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = system, >0 = custom brand style tenanta (Faza 6)',
  `ascii_key` varchar(64) NOT NULL,
  `name` varchar(128) NOT NULL,
  `cinema_reference` varchar(255) DEFAULT NULL COMMENT 'np. "Studio Ghibli / Makoto Shinkai"',
  `thumbnail_asset_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'FK logiczne do sh_assets ??? miniatura w Style Gallery',
  `ai_prompt_template` text DEFAULT NULL COMMENT 'Template dla img2img ??? Faza 4',
  `ai_model_ref` varchar(128) DEFAULT NULL COMMENT 'np. replicate/flux-schnell',
  `lora_ref` varchar(255) DEFAULT NULL COMMENT 'Reference do LoRA stylu (Faza 4)',
  `color_palette_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT '{primary, secondary, accent, bg, text}' CHECK (json_valid(`color_palette_json`)),
  `font_family` varchar(64) DEFAULT NULL COMMENT 'Google Font name lub system stack',
  `motion_preset` varchar(32) DEFAULT 'spring' COMMENT 'spring / glass / vhs_glitch / slow_fade / instant',
  `ambient_audio_ascii_key` varchar(64) DEFAULT NULL COMMENT 'FK logiczne do sh_assets (audio bucket)',
  `default_lut` varchar(64) DEFAULT NULL COMMENT 'np. warm_summer_evening / golden_hour / film_noir_bw',
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='M022: biblioteka styl??w wizualnych (Style Engine)';

--
-- Dumping data for table `sh_style_presets`
--

INSERT INTO `sh_style_presets` (`id`, `tenant_id`, `ascii_key`, `name`, `cinema_reference`, `thumbnail_asset_id`, `ai_prompt_template`, `ai_model_ref`, `lora_ref`, `color_palette_json`, `font_family`, `motion_preset`, `ambient_audio_ascii_key`, `default_lut`, `is_system`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 0, 'realistic', 'Realistyczny', 'baseline food photography', NULL, NULL, NULL, NULL, '{\"primary\": \"#d97706\", \"secondary\": \"#92400e\", \"accent\": \"#fbbf24\", \"bg\": \"#0a0a0a\", \"text\": \"#fafafa\"}', 'Inter', 'spring', NULL, 'warm_summer_evening', 1, 1, '2026-04-18 00:41:17', '2026-04-18 16:52:11'),
(2, 0, 'pastel_watercolor', 'Pastelowy', 'Wes Anderson / akwarelowy', NULL, NULL, NULL, NULL, '{\"primary\": \"#fda4af\", \"secondary\": \"#fbcfe8\", \"accent\": \"#fde68a\", \"bg\": \"#fef3c7\", \"text\": \"#3f3f46\"}', 'Quicksand', 'glass', NULL, 'crisp_morning', 1, 1, '2026-04-18 00:41:17', '2026-04-18 16:52:11'),
(3, 0, 'hand_drawn_ink', 'Rysowany', 'ink illustration book', NULL, NULL, NULL, NULL, '{\"primary\": \"#1f2937\", \"secondary\": \"#374151\", \"accent\": \"#fbbf24\", \"bg\": \"#fafaf9\", \"text\": \"#0c0a09\"}', 'Caveat', 'spring', NULL, 'crisp_morning', 1, 1, '2026-04-18 00:41:17', '2026-04-18 16:52:11'),
(4, 0, 'anime_ghibli', 'Anime', 'Studio Ghibli / Makoto Shinkai', NULL, NULL, NULL, NULL, '{\"primary\": \"#0ea5e9\", \"secondary\": \"#22d3ee\", \"accent\": \"#fde047\", \"bg\": \"#dbeafe\", \"text\": \"#1e3a8a\"}', 'Mochiy Pop One', 'spring', NULL, 'crisp_morning', 1, 1, '2026-04-18 00:41:17', '2026-04-18 16:52:11'),
(5, 0, 'pixar_3d', 'Pixar 3D', 'Ratatouille cinematic', NULL, NULL, NULL, NULL, '{\"primary\": \"#ea580c\", \"secondary\": \"#facc15\", \"accent\": \"#22c55e\", \"bg\": \"#fef3c7\", \"text\": \"#1c1917\"}', 'Fredoka', 'spring', NULL, 'golden_hour', 1, 1, '2026-04-18 00:41:17', '2026-04-18 16:52:11'),
(6, 0, 'retro_80s_synthwave', 'Retro 80s', 'Stranger Things VHS', NULL, NULL, NULL, NULL, '{\"primary\": \"#ec4899\", \"secondary\": \"#a855f7\", \"accent\": \"#22d3ee\", \"bg\": \"#0c0a1f\", \"text\": \"#fce7f3\"}', 'Orbitron', 'vhs_glitch', NULL, 'teal_orange_blockbuster', 1, 1, '2026-04-18 00:41:17', '2026-04-18 16:52:11'),
(7, 0, 'film_noir_bw', 'Film Noir', 'czarno-biały high-contrast', NULL, NULL, NULL, NULL, '{\"primary\": \"#fafafa\", \"secondary\": \"#a3a3a3\", \"accent\": \"#ef4444\", \"bg\": \"#0a0a0a\", \"text\": \"#fafafa\"}', 'Playfair Display', 'slow_fade', NULL, 'film_noir_bw', 1, 1, '2026-04-18 00:41:17', '2026-04-18 16:52:11'),
(8, 0, 'cyberpunk_blade_runner', 'Cyberpunk', 'Blade Runner 2049', NULL, NULL, NULL, NULL, '{\"primary\": \"#06b6d4\", \"secondary\": \"#f97316\", \"accent\": \"#fde047\", \"bg\": \"#020617\", \"text\": \"#cffafe\"}', 'Rajdhani', 'glass', NULL, 'teal_orange_blockbuster', 1, 1, '2026-04-18 00:41:17', '2026-04-18 16:52:11'),
(9, 0, 'cottagecore_rustic', 'Cottagecore', 'warm rustic illustration', NULL, NULL, NULL, NULL, '{\"primary\": \"#84cc16\", \"secondary\": \"#a16207\", \"accent\": \"#fbbf24\", \"bg\": \"#fef3c7\", \"text\": \"#3f2e1c\"}', 'Merriweather', 'slow_fade', NULL, 'warm_summer_evening', 1, 1, '2026-04-18 00:41:17', '2026-04-18 16:52:11'),
(10, 0, 'minimalist_editorial', 'Minimalistyczny', 'white space clean', NULL, NULL, NULL, NULL, '{\"primary\": \"#0a0a0a\", \"secondary\": \"#52525b\", \"accent\": \"#0ea5e9\", \"bg\": \"#fafafa\", \"text\": \"#0a0a0a\"}', 'Inter', 'glass', NULL, 'crisp_morning', 1, 1, '2026-04-18 00:41:17', '2026-04-18 16:52:11'),
(11, 0, 'pop_art_lichtenstein', 'Pop Art', 'comic book halftone', NULL, NULL, NULL, NULL, '{\"primary\": \"#dc2626\", \"secondary\": \"#facc15\", \"accent\": \"#0ea5e9\", \"bg\": \"#fef3c7\", \"text\": \"#0a0a0a\"}', 'Bangers', 'spring', NULL, 'teal_orange_blockbuster', 1, 1, '2026-04-18 00:41:17', '2026-04-18 16:52:11'),
(12, 0, 'vintage_50s_diner', 'Vintage 50s', 'americana pastel', NULL, NULL, NULL, NULL, '{\"primary\": \"#06b6d4\", \"secondary\": \"#fbbf24\", \"accent\": \"#ef4444\", \"bg\": \"#fef3c7\", \"text\": \"#3f3f46\"}', 'Lobster', 'spring', NULL, 'warm_summer_evening', 1, 1, '2026-04-18 00:41:17', '2026-04-18 16:52:11');

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_tables`
--

CREATE TABLE `sh_tables` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `zone_id` bigint(20) UNSIGNED DEFAULT NULL,
  `table_number` varchar(16) NOT NULL,
  `seats` tinyint(3) UNSIGNED NOT NULL DEFAULT 4,
  `shape` varchar(16) NOT NULL DEFAULT 'square' COMMENT 'square|round|rectangle',
  `pos_x` smallint(6) NOT NULL DEFAULT 0 COMMENT 'Floor-plan X coordinate (px)',
  `pos_y` smallint(6) NOT NULL DEFAULT 0 COMMENT 'Floor-plan Y coordinate (px)',
  `qr_hash` varchar(128) DEFAULT NULL COMMENT 'Unique QR code hash for self-order',
  `parent_table_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Non-NULL = merged into parent',
  `physical_status` varchar(32) NOT NULL DEFAULT 'free' COMMENT 'free|occupied|reserved|dirty|merged',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sh_tables`
--

INSERT INTO `sh_tables` (`id`, `tenant_id`, `zone_id`, `table_number`, `seats`, `shape`, `pos_x`, `pos_y`, `qr_hash`, `parent_table_id`, `physical_status`, `is_active`, `created_at`, `updated_at`) VALUES
(2, 1, NULL, '1', 13, 'square', 29, 46, NULL, NULL, 'occupied', 1, '2026-04-14 13:53:25', '2026-04-14 17:42:29'),
(3, 1, NULL, '2', 6, 'square', 25, 17, NULL, 2, 'merged', 1, '2026-04-14 13:53:36', '2026-04-14 14:18:33'),
(4, 1, NULL, '3', 3, 'square', 29, 46, NULL, 2, 'merged', 1, '2026-04-14 13:53:49', '2026-04-14 14:18:38'),
(5, 1, NULL, '5', 4, 'round', 50, 50, NULL, NULL, 'occupied', 1, '2026-04-14 14:49:09', '2026-04-14 14:49:24');

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_tenant`
--

CREATE TABLE `sh_tenant` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sh_tenant`
--

INSERT INTO `sh_tenant` (`id`, `name`, `created_at`) VALUES
(1, 'SliceHub Pizzeria Poznań', '2026-04-13 15:07:50');

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_tenant_integrations`
--

CREATE TABLE `sh_tenant_integrations` (
  `id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `provider` varchar(32) NOT NULL COMMENT 'papu | dotykacka | gastrosoft | custom | webhook — determinuje który adapter ładowany',
  `display_name` varchar(128) NOT NULL,
  `api_base_url` varchar(512) DEFAULT NULL,
  `credentials` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'api_key, tokens, tenant_ext_id — zaszyfrowane (TODO: AES-256 at-rest w fazie 7.4)' CHECK (json_valid(`credentials`)),
  `direction` enum('push','pull','bidirectional') NOT NULL DEFAULT 'push' COMMENT 'push = SliceHub → 3rd-party | pull = scrape orders | bidirectional',
  `events_bridged` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Whitelist event_types przekazywanych do tego providera' CHECK (json_valid(`events_bridged`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_sync_at` datetime DEFAULT NULL,
  `consecutive_failures` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Auto-pause przy consecutive_failures >= max_retries (m028)',
  `last_failure_at` datetime DEFAULT NULL,
  `max_retries` tinyint(3) UNSIGNED NOT NULL DEFAULT 6,
  `timeout_seconds` tinyint(3) UNSIGNED NOT NULL DEFAULT 8 COMMENT 'HTTP timeout dla adaptera (wyższy niż webhooki — 3rd-party POS bywa wolne)',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='3rd-party POS / ERP adapters registry (m026)';

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_tenant_settings`
--

CREATE TABLE `sh_tenant_settings` (
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(64) NOT NULL DEFAULT '',
  `is_active` tinyint(1) DEFAULT 1,
  `min_order_value` int(11) DEFAULT 0 COMMENT 'Grosze',
  `opening_hours_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`opening_hours_json`)),
  `min_prep_time_minutes` int(11) DEFAULT 30,
  `sla_green_min` int(11) DEFAULT 10,
  `sla_yellow_min` int(11) DEFAULT 5,
  `base_prep_minutes` int(11) DEFAULT 25,
  `min_lead_time_minutes` int(11) DEFAULT 30,
  `setting_value` varchar(255) DEFAULT NULL COMMENT 'KV rows (e.g. half_half_surcharge)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sh_tenant_settings`
--

INSERT INTO `sh_tenant_settings` (`tenant_id`, `setting_key`, `is_active`, `min_order_value`, `opening_hours_json`, `min_prep_time_minutes`, `sla_green_min`, `sla_yellow_min`, `base_prep_minutes`, `min_lead_time_minutes`, `setting_value`) VALUES
(1, '', 1, 0, '{\"monday\":{\"open\":\"10:00\",\"close\":\"22:00\"},\"tuesday\":{\"open\":\"10:00\",\"close\":\"22:00\"},\"wednesday\":{\"open\":\"10:00\",\"close\":\"22:00\"},\"thursday\":{\"open\":\"10:00\",\"close\":\"22:00\"},\"friday\":{\"open\":\"10:00\",\"close\":\"22:00\"},\"saturday\":{\"closed\":true},\"sunday\":{\"open\":\"10:00\",\"close\":\"22:00\"}}', 30, 10, 5, 25, 30, NULL),
(1, 'ai_budget_reset_at', 1, 0, NULL, 30, 10, 5, 25, 30, ''),
(1, 'ai_current_month_spent_zl', 1, 0, NULL, 30, 10, 5, 25, 30, '0.00'),
(1, 'ai_monthly_budget_zl', 1, 0, NULL, 30, 10, 5, 25, 30, '50.00'),
(1, 'currency', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'PLN'),
(1, 'default_vat_dine_in', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '8'),
(1, 'default_vat_takeaway', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '5'),
(1, 'half_half_surcharge', 1, 0, NULL, 30, 10, 5, 25, 30, '200'),
(1, 'online_apple_pay_enabled', 1, 0, NULL, 30, 10, 5, 25, 30, '0'),
(1, 'online_default_eta_min', 1, 0, NULL, 30, 10, 5, 25, 30, '30'),
(1, 'online_guest_checkout', 1, 0, NULL, 30, 10, 5, 25, 30, '1'),
(1, 'online_min_order_value', 1, 0, NULL, 30, 10, 5, 25, 30, '0.00'),
(1, 'online_promotion_banner', 1, 0, NULL, 30, 10, 5, 25, 30, ''),
(1, 'storefront_address', 1, 0, NULL, 30, 10, 5, 25, 30, 'ul. Dąbrowskiego 58'),
(1, 'storefront_channels_json', 1, 0, NULL, 30, 10, 5, 25, 30, '[\"delivery\",\"takeaway\",\"dine_in\"]'),
(1, 'storefront_city', 1, 0, NULL, 30, 10, 5, 25, 30, 'Trzcianka'),
(1, 'storefront_email', 1, 0, NULL, 30, 10, 5, 25, 30, 'trzcianka@pizzaforno.pl'),
(1, 'storefront_lat', 1, 0, NULL, 30, 10, 5, 25, 30, '53.039682'),
(1, 'storefront_lng', 1, 0, NULL, 30, 10, 5, 25, 30, '16.460392'),
(1, 'storefront_phone', 1, 0, NULL, 30, 10, 5, 25, 30, '519405251'),
(1, 'storefront_preorder_enabled', 1, 0, NULL, 30, 10, 5, 25, 30, '1'),
(1, 'storefront_preorder_min_lead_minutes', 1, 0, NULL, 30, 10, 5, 25, 30, '30'),
(1, 'storefront_surface_bg', 1, 0, NULL, 30, 10, 5, 25, 30, 'board___0010_13_wynik_be6f85.webp'),
(1, 'storefront_tagline', 1, 0, NULL, 30, 10, 5, 25, 30, 'PIZZA FORNO');

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_users`
--

CREATE TABLE `sh_users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `username` varchar(128) NOT NULL,
  `password_hash` varchar(255) NOT NULL DEFAULT '',
  `pin_code` varchar(32) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `first_name` varchar(128) DEFAULT NULL,
  `last_name` varchar(128) DEFAULT NULL,
  `role` varchar(32) NOT NULL DEFAULT 'team',
  `status` varchar(32) NOT NULL DEFAULT 'active',
  `hourly_rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `last_seen` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sh_users`
--

INSERT INTO `sh_users` (`id`, `tenant_id`, `username`, `password_hash`, `pin_code`, `name`, `first_name`, `last_name`, `role`, `status`, `hourly_rate`, `last_seen`, `is_active`, `is_deleted`, `created_at`) VALUES
(1, 1, 'manager_szef', '', NULL, 'Administrator', 'Jan', 'Kowalski', 'owner', 'active', 0.00, NULL, 1, 0, '2026-04-16 05:19:02'),
(2, 1, 'kelnerka_ania', '', '0000', 'Kierownik Anna', 'Anna', 'Nowak', 'manager', 'active', 28.00, NULL, 1, 0, '2026-04-16 05:19:02'),
(3, 1, 'kelner_piotr', '', '1111', 'Kelner Marek', 'Marek', 'Zieliński', 'waiter', 'active', 22.00, NULL, 1, 0, '2026-04-16 05:19:02'),
(4, 1, 'kelnerka_ola', '', '2222', 'Kelnerka Ola', 'Ola', 'Wójcik', 'waiter', 'active', 22.00, NULL, 1, 0, '2026-04-16 05:19:02'),
(5, 1, 'kucharz_piotr', '', '3333', 'Kucharz Piotr', 'Piotr', 'Mazur', 'cook', 'active', 25.00, NULL, 1, 0, '2026-04-16 05:19:02'),
(6, 1, 'driver1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '4444', 'Kierowca Tomek', 'Tomek', 'Kaczmarek', 'driver', 'active', 20.00, NULL, 1, 0, '2026-04-17 03:11:28'),
(7, 1, 'driver2', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '5555', 'Kierowca Ania', 'Ania', 'Kowalczyk', 'driver', 'active', 20.00, NULL, 1, 0, '2026-04-17 03:11:28'),
(8, 1, 'team1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '6666', 'Pracownik Asia', 'Asia', 'Dąbrowska', 'team', 'active', 19.50, NULL, 1, 0, '2026-04-16 05:19:04'),
(9, 1, 'driver_marek', '$2y$10$3ly6ggVq5TNoquhOcQ0xGO0jpL5YE9FvUTTid41591Hc5CgR8mYQy', '1111', NULL, 'Marek', 'Kowalski', 'driver', 'active', 0.00, NULL, 1, 0, '2026-04-16 05:19:06'),
(10, 1, 'driver_kasia', '$2y$10$mpZDEWt7D/LJPd5OBjSzFO.02ocNMO/NNF8Sr7YSiRYhgoWgGMjTW', '2222', NULL, 'Kasia', 'Nowak', 'driver', 'active', 0.00, NULL, 1, 0, '2026-04-16 05:19:06'),
(11, 1, 'driver_tomek', '$2y$10$WrFXm4rflpnzsjTdYTlFW.tXbQuos.xdobWS63/x1VXTf1P5hFBtW', '3333', NULL, 'Tomek', 'Wiśniewski', 'driver', 'active', 0.00, NULL, 1, 0, '2026-04-16 05:19:06');

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_visual_layers`
--

CREATE TABLE `sh_visual_layers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `item_sku` varchar(255) NOT NULL COMMENT 'sh_menu_items.ascii_key — the product being configured',
  `layer_sku` varchar(255) NOT NULL COMMENT 'item_sku for base layer, sh_modifiers.ascii_key for toppings',
  `library_category` varchar(64) DEFAULT NULL COMMENT 'Library filter category',
  `library_sub_type` varchar(64) DEFAULT NULL COMMENT 'Library filter sub-type',
  `asset_filename` varchar(255) NOT NULL COMMENT 'DEPRECATED_M021: cień-zapis; canonical w sh_asset_links(visual_layer,layer_top_down).',
  `product_filename` varchar(255) DEFAULT NULL COMMENT 'DEPRECATED_M021: cień-zapis; canonical w sh_asset_links(visual_layer,product_shot).',
  `cal_scale` decimal(4,2) NOT NULL DEFAULT 1.00 COMMENT 'Visual calibration scale 0.50-2.00',
  `cal_rotate` smallint(6) NOT NULL DEFAULT 0 COMMENT 'Visual calibration rotation -180 to 180',
  `offset_x` decimal(4,3) NOT NULL DEFAULT 0.000 COMMENT 'Visual X offset (-0.5..+0.5 of half-pizza radius)',
  `offset_y` decimal(4,3) NOT NULL DEFAULT 0.000 COMMENT 'Visual Y offset (-0.5..+0.5 of half-pizza radius)',
  `z_index` int(11) NOT NULL DEFAULT 0 COMMENT 'Stacking order: higher = on top',
  `is_base` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = base product layer (dough), 0 = modifier layer',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `version` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Optimistic locking',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sh_visual_layers`
--

INSERT INTO `sh_visual_layers` (`id`, `tenant_id`, `item_sku`, `layer_sku`, `library_category`, `library_sub_type`, `asset_filename`, `product_filename`, `cal_scale`, `cal_rotate`, `offset_x`, `offset_y`, `z_index`, `is_base`, `is_active`, `version`, `created_at`, `updated_at`) VALUES
(18, 1, 'PIZZA_MARGHERITA', 'board___0010_13_wynik_be6f85', 'board', '__0010_13_wynik', 'board___0010_13_wynik_be6f85.webp', NULL, 1.00, 0, 0.000, 0.000, 10, 1, 1, 1, '2026-04-17 05:17:29', NULL),
(19, 1, 'PIZZA_MARGHERITA', 'base_dough_69ec5a', 'base', 'dough', 'base_dough_69ec5a.webp', NULL, 1.00, 0, 0.000, 0.000, 30, 1, 1, 1, '2026-04-17 05:17:29', NULL),
(20, 1, 'PIZZA_MARGHERITA', 'sauce_sauce_3ebc9a', 'sauce', 'sauce', 'sauce_sauce_3ebc9a.webp', NULL, 1.00, 0, 0.000, 0.000, 40, 0, 1, 1, '2026-04-17 05:17:29', NULL),
(21, 1, 'PIZZA_MARGHERITA', 'cheese_cheese_82f9f8', 'cheese', 'cheese', 'cheese_cheese_82f9f8.webp', NULL, 1.00, 0, 0.000, 0.000, 50, 0, 1, 1, '2026-04-17 05:17:29', NULL),
(22, 1, 'PIZZA_BBQ_CHICKEN', 'board_plate_0fa8df', 'board', 'plate', 'board_plate_0fa8df.webp', NULL, 1.00, 0, 0.000, 0.000, 0, 1, 1, 1, '2026-04-17 05:25:22', NULL),
(23, 1, 'PIZZA_CALZONE', 'board_plate_0fa8df', 'board', 'plate', 'board_plate_0fa8df.webp', NULL, 1.00, 0, 0.000, 0.000, 0, 1, 1, 1, '2026-04-17 05:25:22', NULL),
(24, 1, 'PIZZA_CAPRICCIOSA', 'board_plate_0fa8df', 'board', 'plate', 'board_plate_0fa8df.webp', NULL, 1.00, 0, 0.000, 0.000, 0, 1, 1, 1, '2026-04-17 05:25:22', NULL),
(25, 1, 'PIZZA_DIAVOLA', 'board_plate_0fa8df', 'board', 'plate', 'board_plate_0fa8df.webp', NULL, 1.00, 0, 0.000, 0.000, 0, 1, 1, 1, '2026-04-17 05:25:22', NULL),
(26, 1, 'PIZZA_HAWAJSKA', 'board_plate_0fa8df', 'board', 'plate', 'board_plate_0fa8df.webp', NULL, 1.00, 0, 0.000, 0.000, 0, 1, 1, 1, '2026-04-17 05:25:22', NULL),
(27, 1, 'PIZZA_PEPPERONI', 'board_plate_0fa8df', 'board', 'plate', 'board_plate_0fa8df.webp', NULL, 1.00, 0, 0.000, 0.000, 0, 1, 1, 1, '2026-04-17 05:25:22', NULL),
(28, 1, 'PIZZA_PROSC_FUNGHI', 'board_plate_0fa8df', 'board', 'plate', 'board_plate_0fa8df.webp', NULL, 1.00, 0, 0.000, 0.000, 0, 1, 1, 1, '2026-04-17 05:25:22', NULL),
(29, 1, 'PIZZA_4FORMAGGI', 'board_plate_0fa8df', 'board', 'plate', 'board_plate_0fa8df.webp', NULL, 1.00, 0, 0.000, 0.000, 0, 1, 1, 1, '2026-04-17 05:25:22', NULL),
(30, 1, 'PIZZA_VEGETARIANA', 'board_plate_0fa8df', 'board', 'plate', 'board_plate_0fa8df.webp', NULL, 1.00, 0, 0.000, 0.000, 0, 1, 1, 1, '2026-04-17 05:25:22', NULL),
(31, 1, 'SET_LUNCH_PIZZA', 'board_plate_0fa8df', 'board', 'plate', 'board_plate_0fa8df.webp', NULL, 1.00, 0, 0.000, 0.000, 0, 1, 1, 1, '2026-04-17 05:25:22', NULL);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_webhook_deliveries`
--

CREATE TABLE `sh_webhook_deliveries` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `event_id` bigint(20) UNSIGNED NOT NULL,
  `endpoint_id` int(10) UNSIGNED NOT NULL,
  `attempt_number` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `http_code` smallint(5) UNSIGNED DEFAULT NULL,
  `response_body` text DEFAULT NULL COMMENT 'Limited to first 2000 chars — debug tylko',
  `error_message` text DEFAULT NULL,
  `duration_ms` int(10) UNSIGNED DEFAULT NULL,
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Webhook delivery log (m026)';

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_webhook_endpoints`
--

CREATE TABLE `sh_webhook_endpoints` (
  `id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(128) NOT NULL COMMENT 'Human-readable label, np. "Papu sync" / "Analytics firehose"',
  `url` varchar(512) NOT NULL,
  `secret` varchar(128) NOT NULL COMMENT 'HMAC-SHA256 signing secret — header X-Slicehub-Signature',
  `events_subscribed` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Lista event_type np. ["order.created","order.ready"]. ["*"] = wszystkie.' CHECK (json_valid(`events_subscribed`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `max_retries` tinyint(3) UNSIGNED NOT NULL DEFAULT 5,
  `timeout_seconds` tinyint(3) UNSIGNED NOT NULL DEFAULT 5,
  `last_success_at` datetime DEFAULT NULL,
  `last_failure_at` datetime DEFAULT NULL,
  `consecutive_failures` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Gdy >= max_retries → endpoint auto-paused (is_active=0 z decyzją managera)',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Webhook subscribers (m026)';

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_work_sessions`
--

CREATE TABLE `sh_work_sessions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `session_uuid` char(36) NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `start_time` datetime NOT NULL,
  `end_time` datetime DEFAULT NULL,
  `total_hours` decimal(10,4) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sh_work_sessions`
--

INSERT INTO `sh_work_sessions` (`id`, `session_uuid`, `tenant_id`, `user_id`, `start_time`, `end_time`, `total_hours`) VALUES
(19, '5b70deec-f311-4a61-93b3-3212575bf5cc', 1, 2, '2026-04-16 05:19:04', NULL, NULL),
(20, '7d6a7517-a0b0-453e-adfa-d1545738dd71', 1, 3, '2026-04-16 05:19:04', NULL, NULL),
(21, '90304d0d-5903-44cd-acc7-5e15522fecf9', 1, 4, '2026-04-16 05:19:04', NULL, NULL),
(22, '6c233a39-e8a3-47fb-ae51-1d761f3f5056', 1, 5, '2026-04-16 05:19:04', NULL, NULL),
(25, 'bb92c066-6ddb-43c2-aaf1-26a6330ba7aa', 1, 6, '2026-04-17 03:11:28', NULL, NULL),
(26, '26c0510e-1f4d-4cea-8b81-e5347bdcdfb0', 1, 7, '2026-04-17 03:11:28', NULL, NULL);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_zones`
--

CREATE TABLE `sh_zones` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(128) NOT NULL,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sys_items`
--

CREATE TABLE `sys_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `sku` varchar(128) NOT NULL,
  `name` varchar(255) NOT NULL,
  `base_unit` varchar(32) NOT NULL DEFAULT 'pcs'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sys_items`
--

INSERT INTO `sys_items` (`id`, `tenant_id`, `sku`, `name`, `base_unit`) VALUES
(1, 1, 'MKA_TIPO00', 'Mąka Caputo Tipo 00', 'kg'),
(2, 1, 'SER_MOZZ', 'Ser Mozzarella Fior di Latte', 'kg'),
(3, 1, 'SOS_POM', 'Sos pomidorowy San Marzano', 'l'),
(4, 1, 'OLJ_OLIWA', 'Oliwa z oliwek Extra Virgin', 'l'),
(5, 1, 'DRZ_SUCHE', 'Drożdże suche instant', 'kg'),
(6, 1, 'SOL_MORSKA', 'Sól morska drobna', 'kg'),
(7, 1, 'PEPP_SALAMI', 'Pepperoni / Salami pikantne', 'kg'),
(8, 1, 'SZYNKA_PARM', 'Szynka parmeńska (Prosciutto)', 'kg'),
(9, 1, 'PIECZARKI', 'Pieczarki krojone', 'kg'),
(10, 1, 'CEBULA', 'Cebula biała', 'kg'),
(11, 1, 'ANANAS', 'Ananas plastry (puszka)', 'kg'),
(12, 1, 'SER_GORG', 'Ser Gorgonzola DOP', 'kg'),
(13, 1, 'SER_PARM', 'Parmezan (Grana Padano)', 'kg'),
(14, 1, 'SER_CHEDDAR', 'Ser Cheddar', 'kg'),
(15, 1, 'JALAPENO', 'Jalapeno krojone (słoik)', 'kg'),
(16, 1, 'OLIWKI_CZ', 'Oliwki czarne bez pestek', 'kg'),
(17, 1, 'KURCZAK', 'Filet z kurczaka', 'kg'),
(18, 1, 'SOS_BBQ', 'Sos BBQ', 'l'),
(19, 1, 'BULKA_BURG', 'Bułka burgerowa brioche', 'szt'),
(20, 1, 'WOLOWINA_M', 'Mięso wołowe mielone (burger)', 'kg'),
(21, 1, 'SALATA_RZY', 'Sałata rzymska', 'kg'),
(22, 1, 'POMIDOR', 'Pomidory świeże', 'kg'),
(23, 1, 'OGOREK_KIS', 'Ogórek kiszony', 'kg'),
(24, 1, 'SOS_CZOSN', 'Sos czosnkowy', 'l'),
(25, 1, 'SOS_OSTRY', 'Sos ostry (chili)', 'l'),
(26, 1, 'MAKARON_SPAG', 'Makaron Spaghetti', 'kg'),
(27, 1, 'MAKARON_PENN', 'Makaron Penne Rigate', 'kg'),
(28, 1, 'MAKARON_LAS', 'Płaty lasagne', 'kg'),
(29, 1, 'FETA', 'Ser Feta', 'kg'),
(30, 1, 'FRYTKI_MRZ', 'Frytki mrożone', 'kg'),
(31, 1, 'NUGGETS_MRZ', 'Nuggetsy mrożone', 'szt'),
(32, 1, 'COCA_COLA_05', 'Coca-Cola 0.5L', 'szt'),
(33, 1, 'SPRITE_05', 'Sprite 0.5L', 'szt'),
(34, 1, 'WODA_05', 'Woda mineralna 0.5L', 'szt'),
(35, 1, 'SOK_POM_1L', 'Sok pomarańczowy 1L', 'l'),
(36, 1, 'PIWO_TYSKIE', 'Piwo Tyskie 0.5L', 'szt'),
(37, 1, 'KRAZKI_CEB', 'Krążki cebulowe mrożone', 'kg'),
(38, 1, 'MASCARPONE', 'Mascarpone', 'kg'),
(39, 1, 'SMIETANKA_30', 'Śmietanka 30%', 'l'),
(40, 1, 'CUKIER', 'Cukier biały', 'kg'),
(41, 1, 'BAZYLIA_SW', 'Bazylia świeża (doniczka)', 'szt'),
(42, 1, 'OPAK_PIZZA', 'Opakowanie karton pizza 32cm', 'szt'),
(43, 1, 'OPAK_BURGER', 'Opakowanie styro burger', 'szt');

-- --------------------------------------------------------

--
-- Zastąpiona struktura widoku `v_menu_item_hero`
-- (See below for the actual view)
--
CREATE TABLE `v_menu_item_hero` (
`tenant_id` int(10) unsigned
,`menu_item_id` bigint(20) unsigned
,`item_sku` varchar(255)
,`item_name` varchar(255)
,`asset_id` bigint(20) unsigned
,`hero_url` varchar(1024)
,`width_px` int(10) unsigned
,`height_px` int(10) unsigned
,`mime_type` varchar(64)
,`params_json` longtext
);

-- --------------------------------------------------------

--
-- Zastąpiona struktura widoku `v_modifier_icon`
-- (See below for the actual view)
--
CREATE TABLE `v_modifier_icon` (
`tenant_id` int(10) unsigned
,`modifier_id` bigint(20) unsigned
,`modifier_group_id` bigint(20) unsigned
,`modifier_sku` varchar(255)
,`modifier_name` varchar(255)
,`asset_id` bigint(20) unsigned
,`icon_url` varchar(1024)
,`asset_category` varchar(32)
,`asset_sub_type` varchar(64)
,`z_order_hint` int(11)
,`width_px` int(10) unsigned
,`height_px` int(10) unsigned
);

-- --------------------------------------------------------

--
-- Zastąpiona struktura widoku `v_visual_layer_asset`
-- (See below for the actual view)
--
CREATE TABLE `v_visual_layer_asset` (
`tenant_id` int(10) unsigned
,`layer_id` bigint(20) unsigned
,`item_sku` varchar(255)
,`layer_sku` varchar(255)
,`z_index` int(11)
,`is_base` tinyint(1)
,`asset_id` bigint(20) unsigned
,`layer_url` varchar(1024)
,`asset_category` varchar(32)
,`asset_sub_type` varchar(64)
,`display_params_json` longtext
);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `wh_documents`
--

CREATE TABLE `wh_documents` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `doc_number` varchar(64) NOT NULL,
  `type` varchar(16) NOT NULL,
  `warehouse_id` varchar(64) DEFAULT NULL,
  `target_warehouse_id` varchar(64) DEFAULT NULL,
  `order_id` char(36) DEFAULT NULL,
  `references_wz` varchar(64) DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'completed',
  `required_approval_level` varchar(32) DEFAULT NULL,
  `supplier_name` varchar(255) DEFAULT NULL,
  `supplier_invoice` varchar(128) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` bigint(20) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `wh_documents`
--

INSERT INTO `wh_documents` (`id`, `tenant_id`, `doc_number`, `type`, `warehouse_id`, `target_warehouse_id`, `order_id`, `references_wz`, `status`, `required_approval_level`, `supplier_name`, `supplier_invoice`, `notes`, `created_at`, `created_by`) VALUES
(1, 1, 'PZ/2026/04/0001', 'PZ', 'MAIN', NULL, NULL, NULL, 'completed', NULL, 'Makro Cash & Carry', 'FV/2026/3345', 'Dostawa tygodniowa', '2026-04-13 15:08:41', 2),
(2, 1, 'PZ/2026/04/0002', 'PZ', 'MAIN', NULL, NULL, NULL, 'completed', NULL, 'Hurtownia Gastro-Pol', 'FV/2026/1102', 'Nabiał + sery', '2026-04-13 15:08:41', 2),
(3, 1, 'PZ/2026/04/0003', 'PZ', 'MAIN', NULL, NULL, NULL, 'completed', NULL, 'Coca-Cola HBC Polska', 'FV/2026/8890', 'Napoje', '2026-04-13 15:08:41', 2),
(4, 1, 'RW/2026/04/0001', 'RW', 'MAIN', NULL, NULL, NULL, 'completed', NULL, NULL, NULL, 'Strata — przeterminowane pieczarki', '2026-04-13 15:08:41', 2),
(5, 1, 'PZ/2026/04/15/00005', 'PZ', 'MAIN', NULL, NULL, NULL, 'completed', NULL, 'PZ — Control Tower', NULL, NULL, '2026-04-15 00:19:51', 5);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `wh_document_lines`
--

CREATE TABLE `wh_document_lines` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `document_id` bigint(20) UNSIGNED NOT NULL,
  `sku` varchar(128) NOT NULL,
  `quantity` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `system_qty` decimal(12,4) DEFAULT NULL,
  `counted_qty` decimal(12,4) DEFAULT NULL,
  `variance` decimal(12,4) DEFAULT NULL,
  `unit_net_cost` decimal(10,4) NOT NULL DEFAULT 0.0000,
  `line_net_value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `vat_rate` decimal(6,2) NOT NULL DEFAULT 0.00,
  `old_avco` decimal(10,4) DEFAULT NULL,
  `new_avco` decimal(10,4) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `wh_document_lines`
--

INSERT INTO `wh_document_lines` (`id`, `document_id`, `sku`, `quantity`, `system_qty`, `counted_qty`, `variance`, `unit_net_cost`, `line_net_value`, `vat_rate`, `old_avco`, `new_avco`) VALUES
(1, 1, 'MKA_TIPO00', 25.0000, NULL, NULL, NULL, 3.8000, 95.00, 5.00, 0.0000, 3.8000),
(2, 1, 'DRZ_SUCHE', 1.0000, NULL, NULL, NULL, 18.0000, 18.00, 5.00, 0.0000, 18.0000),
(3, 2, 'SER_MOZZ', 10.0000, NULL, NULL, NULL, 28.0000, 280.00, 5.00, 0.0000, 28.0000),
(4, 2, 'SER_GORG', 2.0000, NULL, NULL, NULL, 55.0000, 110.00, 5.00, 0.0000, 55.0000),
(5, 3, 'COCA_COLA_05', 48.0000, NULL, NULL, NULL, 2.8000, 134.40, 23.00, 0.0000, 2.8000),
(6, 3, 'WODA_05', 60.0000, NULL, NULL, NULL, 1.2000, 72.00, 23.00, 0.0000, 1.2000),
(7, 4, 'PIECZARKI', 2.0000, NULL, NULL, NULL, 12.0000, 24.00, 5.00, 12.0000, 12.0000),
(8, 1, 'MKA_TIPO00', 25.0000, NULL, NULL, NULL, 3.8000, 95.00, 5.00, 0.0000, 3.8000),
(9, 1, 'DRZ_SUCHE', 1.0000, NULL, NULL, NULL, 18.0000, 18.00, 5.00, 0.0000, 18.0000),
(10, 2, 'SER_MOZZ', 10.0000, NULL, NULL, NULL, 28.0000, 280.00, 5.00, 0.0000, 28.0000),
(11, 2, 'SER_GORG', 2.0000, NULL, NULL, NULL, 55.0000, 110.00, 5.00, 0.0000, 55.0000),
(12, 3, 'COCA_COLA_05', 48.0000, NULL, NULL, NULL, 2.8000, 134.40, 23.00, 0.0000, 2.8000),
(13, 3, 'WODA_05', 60.0000, NULL, NULL, NULL, 1.2000, 72.00, 23.00, 0.0000, 1.2000),
(14, 4, 'PIECZARKI', 2.0000, NULL, NULL, NULL, 12.0000, 24.00, 5.00, 12.0000, 12.0000),
(15, 1, 'MKA_TIPO00', 25.0000, NULL, NULL, NULL, 3.8000, 95.00, 5.00, 0.0000, 3.8000),
(16, 1, 'DRZ_SUCHE', 1.0000, NULL, NULL, NULL, 18.0000, 18.00, 5.00, 0.0000, 18.0000),
(17, 2, 'SER_MOZZ', 10.0000, NULL, NULL, NULL, 28.0000, 280.00, 5.00, 0.0000, 28.0000),
(18, 2, 'SER_GORG', 2.0000, NULL, NULL, NULL, 55.0000, 110.00, 5.00, 0.0000, 55.0000),
(19, 3, 'COCA_COLA_05', 48.0000, NULL, NULL, NULL, 2.8000, 134.40, 23.00, 0.0000, 2.8000),
(20, 3, 'WODA_05', 60.0000, NULL, NULL, NULL, 1.2000, 72.00, 23.00, 0.0000, 1.2000),
(21, 4, 'PIECZARKI', 2.0000, NULL, NULL, NULL, 12.0000, 24.00, 5.00, 12.0000, 12.0000),
(22, 1, 'MKA_TIPO00', 25.0000, NULL, NULL, NULL, 3.8000, 95.00, 5.00, 0.0000, 3.8000),
(23, 1, 'DRZ_SUCHE', 1.0000, NULL, NULL, NULL, 18.0000, 18.00, 5.00, 0.0000, 18.0000),
(24, 2, 'SER_MOZZ', 10.0000, NULL, NULL, NULL, 28.0000, 280.00, 5.00, 0.0000, 28.0000),
(25, 2, 'SER_GORG', 2.0000, NULL, NULL, NULL, 55.0000, 110.00, 5.00, 0.0000, 55.0000),
(26, 3, 'COCA_COLA_05', 48.0000, NULL, NULL, NULL, 2.8000, 134.40, 23.00, 0.0000, 2.8000),
(27, 3, 'WODA_05', 60.0000, NULL, NULL, NULL, 1.2000, 72.00, 23.00, 0.0000, 1.2000),
(28, 4, 'PIECZARKI', 2.0000, NULL, NULL, NULL, 12.0000, 24.00, 5.00, 12.0000, 12.0000),
(29, 1, 'MKA_TIPO00', 25.0000, NULL, NULL, NULL, 3.8000, 95.00, 5.00, 0.0000, 3.8000),
(30, 1, 'DRZ_SUCHE', 1.0000, NULL, NULL, NULL, 18.0000, 18.00, 5.00, 0.0000, 18.0000),
(31, 2, 'SER_MOZZ', 10.0000, NULL, NULL, NULL, 28.0000, 280.00, 5.00, 0.0000, 28.0000),
(32, 2, 'SER_GORG', 2.0000, NULL, NULL, NULL, 55.0000, 110.00, 5.00, 0.0000, 55.0000),
(33, 3, 'COCA_COLA_05', 48.0000, NULL, NULL, NULL, 2.8000, 134.40, 23.00, 0.0000, 2.8000),
(34, 3, 'WODA_05', 60.0000, NULL, NULL, NULL, 1.2000, 72.00, 23.00, 0.0000, 1.2000),
(35, 4, 'PIECZARKI', 2.0000, NULL, NULL, NULL, 12.0000, 24.00, 5.00, 12.0000, 12.0000),
(36, 1, 'MKA_TIPO00', 25.0000, NULL, NULL, NULL, 3.8000, 95.00, 5.00, 0.0000, 3.8000),
(37, 1, 'DRZ_SUCHE', 1.0000, NULL, NULL, NULL, 18.0000, 18.00, 5.00, 0.0000, 18.0000),
(38, 2, 'SER_MOZZ', 10.0000, NULL, NULL, NULL, 28.0000, 280.00, 5.00, 0.0000, 28.0000),
(39, 2, 'SER_GORG', 2.0000, NULL, NULL, NULL, 55.0000, 110.00, 5.00, 0.0000, 55.0000),
(40, 3, 'COCA_COLA_05', 48.0000, NULL, NULL, NULL, 2.8000, 134.40, 23.00, 0.0000, 2.8000),
(41, 3, 'WODA_05', 60.0000, NULL, NULL, NULL, 1.2000, 72.00, 23.00, 0.0000, 1.2000),
(42, 4, 'PIECZARKI', 2.0000, NULL, NULL, NULL, 12.0000, 24.00, 5.00, 12.0000, 12.0000),
(43, 1, 'MKA_TIPO00', 25.0000, NULL, NULL, NULL, 3.8000, 95.00, 5.00, 0.0000, 3.8000),
(44, 1, 'DRZ_SUCHE', 1.0000, NULL, NULL, NULL, 18.0000, 18.00, 5.00, 0.0000, 18.0000),
(45, 2, 'SER_MOZZ', 10.0000, NULL, NULL, NULL, 28.0000, 280.00, 5.00, 0.0000, 28.0000),
(46, 2, 'SER_GORG', 2.0000, NULL, NULL, NULL, 55.0000, 110.00, 5.00, 0.0000, 55.0000),
(47, 3, 'COCA_COLA_05', 48.0000, NULL, NULL, NULL, 2.8000, 134.40, 23.00, 0.0000, 2.8000),
(48, 3, 'WODA_05', 60.0000, NULL, NULL, NULL, 1.2000, 72.00, 23.00, 0.0000, 1.2000),
(49, 4, 'PIECZARKI', 2.0000, NULL, NULL, NULL, 12.0000, 24.00, 5.00, 12.0000, 12.0000),
(50, 5, 'ANANAS', 25.0000, NULL, NULL, NULL, 15.0000, 375.00, 0.00, 14.0000, 14.8772),
(51, 1, 'MKA_TIPO00', 25.0000, NULL, NULL, NULL, 3.8000, 95.00, 5.00, 0.0000, 3.8000),
(52, 1, 'DRZ_SUCHE', 1.0000, NULL, NULL, NULL, 18.0000, 18.00, 5.00, 0.0000, 18.0000),
(53, 2, 'SER_MOZZ', 10.0000, NULL, NULL, NULL, 28.0000, 280.00, 5.00, 0.0000, 28.0000),
(54, 2, 'SER_GORG', 2.0000, NULL, NULL, NULL, 55.0000, 110.00, 5.00, 0.0000, 55.0000),
(55, 3, 'COCA_COLA_05', 48.0000, NULL, NULL, NULL, 2.8000, 134.40, 23.00, 0.0000, 2.8000),
(56, 3, 'WODA_05', 60.0000, NULL, NULL, NULL, 1.2000, 72.00, 23.00, 0.0000, 1.2000),
(57, 4, 'PIECZARKI', 2.0000, NULL, NULL, NULL, 12.0000, 24.00, 5.00, 12.0000, 12.0000),
(58, 1, 'MKA_TIPO00', 25.0000, NULL, NULL, NULL, 3.8000, 95.00, 5.00, 0.0000, 3.8000),
(59, 1, 'DRZ_SUCHE', 1.0000, NULL, NULL, NULL, 18.0000, 18.00, 5.00, 0.0000, 18.0000),
(60, 2, 'SER_MOZZ', 10.0000, NULL, NULL, NULL, 28.0000, 280.00, 5.00, 0.0000, 28.0000),
(61, 2, 'SER_GORG', 2.0000, NULL, NULL, NULL, 55.0000, 110.00, 5.00, 0.0000, 55.0000),
(62, 3, 'COCA_COLA_05', 48.0000, NULL, NULL, NULL, 2.8000, 134.40, 23.00, 0.0000, 2.8000),
(63, 3, 'WODA_05', 60.0000, NULL, NULL, NULL, 1.2000, 72.00, 23.00, 0.0000, 1.2000),
(64, 4, 'PIECZARKI', 2.0000, NULL, NULL, NULL, 12.0000, 24.00, 5.00, 12.0000, 12.0000),
(65, 1, 'MKA_TIPO00', 25.0000, NULL, NULL, NULL, 3.8000, 95.00, 5.00, 0.0000, 3.8000),
(66, 1, 'DRZ_SUCHE', 1.0000, NULL, NULL, NULL, 18.0000, 18.00, 5.00, 0.0000, 18.0000),
(67, 2, 'SER_MOZZ', 10.0000, NULL, NULL, NULL, 28.0000, 280.00, 5.00, 0.0000, 28.0000),
(68, 2, 'SER_GORG', 2.0000, NULL, NULL, NULL, 55.0000, 110.00, 5.00, 0.0000, 55.0000),
(69, 3, 'COCA_COLA_05', 48.0000, NULL, NULL, NULL, 2.8000, 134.40, 23.00, 0.0000, 2.8000),
(70, 3, 'WODA_05', 60.0000, NULL, NULL, NULL, 1.2000, 72.00, 23.00, 0.0000, 1.2000),
(71, 4, 'PIECZARKI', 2.0000, NULL, NULL, NULL, 12.0000, 24.00, 5.00, 12.0000, 12.0000),
(72, 1, 'MKA_TIPO00', 25.0000, NULL, NULL, NULL, 3.8000, 95.00, 5.00, 0.0000, 3.8000),
(73, 1, 'DRZ_SUCHE', 1.0000, NULL, NULL, NULL, 18.0000, 18.00, 5.00, 0.0000, 18.0000),
(74, 2, 'SER_MOZZ', 10.0000, NULL, NULL, NULL, 28.0000, 280.00, 5.00, 0.0000, 28.0000),
(75, 2, 'SER_GORG', 2.0000, NULL, NULL, NULL, 55.0000, 110.00, 5.00, 0.0000, 55.0000),
(76, 3, 'COCA_COLA_05', 48.0000, NULL, NULL, NULL, 2.8000, 134.40, 23.00, 0.0000, 2.8000),
(77, 3, 'WODA_05', 60.0000, NULL, NULL, NULL, 1.2000, 72.00, 23.00, 0.0000, 1.2000),
(78, 4, 'PIECZARKI', 2.0000, NULL, NULL, NULL, 12.0000, 24.00, 5.00, 12.0000, 12.0000),
(79, 1, 'MKA_TIPO00', 25.0000, NULL, NULL, NULL, 3.8000, 95.00, 5.00, 0.0000, 3.8000),
(80, 1, 'DRZ_SUCHE', 1.0000, NULL, NULL, NULL, 18.0000, 18.00, 5.00, 0.0000, 18.0000),
(81, 2, 'SER_MOZZ', 10.0000, NULL, NULL, NULL, 28.0000, 280.00, 5.00, 0.0000, 28.0000),
(82, 2, 'SER_GORG', 2.0000, NULL, NULL, NULL, 55.0000, 110.00, 5.00, 0.0000, 55.0000),
(83, 3, 'COCA_COLA_05', 48.0000, NULL, NULL, NULL, 2.8000, 134.40, 23.00, 0.0000, 2.8000),
(84, 3, 'WODA_05', 60.0000, NULL, NULL, NULL, 1.2000, 72.00, 23.00, 0.0000, 1.2000),
(85, 4, 'PIECZARKI', 2.0000, NULL, NULL, NULL, 12.0000, 24.00, 5.00, 12.0000, 12.0000),
(86, 1, 'MKA_TIPO00', 25.0000, NULL, NULL, NULL, 3.8000, 95.00, 5.00, 0.0000, 3.8000),
(87, 1, 'DRZ_SUCHE', 1.0000, NULL, NULL, NULL, 18.0000, 18.00, 5.00, 0.0000, 18.0000),
(88, 2, 'SER_MOZZ', 10.0000, NULL, NULL, NULL, 28.0000, 280.00, 5.00, 0.0000, 28.0000),
(89, 2, 'SER_GORG', 2.0000, NULL, NULL, NULL, 55.0000, 110.00, 5.00, 0.0000, 55.0000),
(90, 3, 'COCA_COLA_05', 48.0000, NULL, NULL, NULL, 2.8000, 134.40, 23.00, 0.0000, 2.8000),
(91, 3, 'WODA_05', 60.0000, NULL, NULL, NULL, 1.2000, 72.00, 23.00, 0.0000, 1.2000),
(92, 4, 'PIECZARKI', 2.0000, NULL, NULL, NULL, 12.0000, 24.00, 5.00, 12.0000, 12.0000),
(93, 1, 'MKA_TIPO00', 25.0000, NULL, NULL, NULL, 3.8000, 95.00, 5.00, 0.0000, 3.8000),
(94, 1, 'DRZ_SUCHE', 1.0000, NULL, NULL, NULL, 18.0000, 18.00, 5.00, 0.0000, 18.0000),
(95, 2, 'SER_MOZZ', 10.0000, NULL, NULL, NULL, 28.0000, 280.00, 5.00, 0.0000, 28.0000),
(96, 2, 'SER_GORG', 2.0000, NULL, NULL, NULL, 55.0000, 110.00, 5.00, 0.0000, 55.0000),
(97, 3, 'COCA_COLA_05', 48.0000, NULL, NULL, NULL, 2.8000, 134.40, 23.00, 0.0000, 2.8000),
(98, 3, 'WODA_05', 60.0000, NULL, NULL, NULL, 1.2000, 72.00, 23.00, 0.0000, 1.2000),
(99, 4, 'PIECZARKI', 2.0000, NULL, NULL, NULL, 12.0000, 24.00, 5.00, 12.0000, 12.0000);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `wh_inventory_docs`
--

CREATE TABLE `wh_inventory_docs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `doc_number` varchar(64) NOT NULL,
  `doc_type` varchar(16) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'COMPLETED',
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `wh_inventory_doc_items`
--

CREATE TABLE `wh_inventory_doc_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `doc_id` bigint(20) UNSIGNED NOT NULL,
  `sku` varchar(128) NOT NULL,
  `qty` decimal(12,4) NOT NULL,
  `unit_price` decimal(10,4) NOT NULL DEFAULT 0.0000,
  `total_price` decimal(10,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `wh_stock`
--

CREATE TABLE `wh_stock` (
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `warehouse_id` varchar(64) NOT NULL,
  `sku` varchar(128) NOT NULL,
  `quantity` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `current_avco_price` decimal(10,4) NOT NULL DEFAULT 0.0000,
  `unit_net_cost` decimal(10,4) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `wh_stock`
--

INSERT INTO `wh_stock` (`tenant_id`, `warehouse_id`, `sku`, `quantity`, `current_avco_price`, `unit_net_cost`, `updated_at`) VALUES
(1, 'MAIN', 'ANANAS', 3.5000, 14.0000, 14.0000, '2026-04-16 04:57:52'),
(1, 'MAIN', 'BAZYLIA_SW', 10.0000, 4.5000, 4.5000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'BULKA_BURG', 80.0000, 1.8000, 1.8000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'CEBULA', 8.0000, 3.5000, 3.5000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'COCA_COLA_05', 48.0000, 2.8000, 2.8000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'CUKIER', 5.0000, 4.0000, 4.0000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'DRZ_SUCHE', 2.0000, 18.0000, 18.0000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'FETA', 2.5000, 35.0000, 35.0000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'FRYTKI_MRZ', 15.0000, 7.5000, 7.5000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'JALAPENO', 1.5000, 28.0000, 28.0000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'KRAZKI_CEB', 5.0000, 14.0000, 14.0000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'KURCZAK', 10.0000, 22.0000, 22.0000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'MAKARON_LAS', 4.0000, 9.0000, 9.0000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'MAKARON_PENN', 8.0000, 6.5000, 6.5000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'MAKARON_SPAG', 10.0000, 6.5000, 6.5000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'MASCARPONE', 3.0000, 22.0000, 22.0000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'MKA_TIPO00', 50.0000, 3.8500, 3.8500, '2026-04-13 15:08:40'),
(1, 'MAIN', 'NUGGETS_MRZ', 120.0000, 0.9500, 0.9500, '2026-04-13 15:08:40'),
(1, 'MAIN', 'OGOREK_KIS', 4.0000, 9.0000, 9.0000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'OLIWKI_CZ', 2.0000, 24.0000, 24.0000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'OLJ_OLIWA', 6.0000, 32.0000, 32.0000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'OPAK_BURGER', 150.0000, 0.8000, 0.8000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'OPAK_PIZZA', 200.0000, 1.2000, 1.2000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'PEPP_SALAMI', 4.2000, 42.0000, 42.0000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'PIECZARKI', 6.0000, 12.0000, 12.0000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'PIWO_TYSKIE', 24.0000, 3.2000, 3.2000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'POMIDOR', 5.0000, 8.5000, 8.5000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'SALATA_RZY', 3.0000, 12.0000, 12.0000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'SER_CHEDDAR', 4.0000, 32.0000, 32.0000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'SER_GORG', 2.0000, 55.0000, 55.0000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'SER_MOZZ', 18.5000, 28.5000, 28.5000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'SER_PARM', 1.8000, 72.0000, 72.0000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'SMIETANKA_30', 6.0000, 8.0000, 8.0000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'SOK_POM_1L', 12.0000, 4.5000, 4.5000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'SOL_MORSKA', 5.0000, 2.5000, 2.5000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'SOS_BBQ', 3.0000, 15.0000, 15.0000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'SOS_CZOSN', 5.0000, 18.0000, 18.0000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'SOS_OSTRY', 2.0000, 22.0000, 22.0000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'SOS_POM', 24.0000, 8.9000, 8.9000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'SPRITE_05', 36.0000, 2.8000, 2.8000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'SZYNKA_PARM', 3.0000, 65.0000, 65.0000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'WODA_05', 60.0000, 1.2000, 1.2000, '2026-04-13 15:08:40'),
(1, 'MAIN', 'WOLOWINA_M', 8.0000, 38.0000, 38.0000, '2026-04-13 15:08:40');

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `wh_stock_logs`
--

CREATE TABLE `wh_stock_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `warehouse_id` varchar(64) NOT NULL,
  `sku` varchar(128) NOT NULL,
  `change_qty` decimal(12,4) NOT NULL,
  `after_qty` decimal(12,4) NOT NULL,
  `document_type` varchar(16) NOT NULL,
  `document_id` bigint(20) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` bigint(20) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `wh_stock_logs`
--

INSERT INTO `wh_stock_logs` (`id`, `tenant_id`, `warehouse_id`, `sku`, `change_qty`, `after_qty`, `document_type`, `document_id`, `created_at`, `created_by`) VALUES
(1, 1, 'MAIN', 'MKA_TIPO00', 25.0000, 25.0000, 'PZ', 1, '2026-04-13 15:08:41', 2),
(2, 1, 'MAIN', 'SER_MOZZ', 10.0000, 10.0000, 'PZ', 2, '2026-04-13 15:08:41', 2),
(3, 1, 'MAIN', 'COCA_COLA_05', 48.0000, 48.0000, 'PZ', 3, '2026-04-13 15:08:41', 2),
(4, 1, 'MAIN', 'PIECZARKI', -2.0000, 4.0000, 'RW', 4, '2026-04-13 15:08:41', 2),
(5, 1, 'MAIN', 'MKA_TIPO00', 25.0000, 25.0000, 'PZ', 1, '2026-04-13 17:57:42', 2),
(6, 1, 'MAIN', 'SER_MOZZ', 10.0000, 10.0000, 'PZ', 2, '2026-04-13 17:57:42', 2),
(7, 1, 'MAIN', 'COCA_COLA_05', 48.0000, 48.0000, 'PZ', 3, '2026-04-13 17:57:42', 2),
(8, 1, 'MAIN', 'PIECZARKI', -2.0000, 4.0000, 'RW', 4, '2026-04-13 17:57:42', 2),
(9, 1, 'MAIN', 'MKA_TIPO00', 25.0000, 25.0000, 'PZ', 1, '2026-04-13 19:51:56', 2),
(10, 1, 'MAIN', 'SER_MOZZ', 10.0000, 10.0000, 'PZ', 2, '2026-04-13 19:51:56', 2),
(11, 1, 'MAIN', 'COCA_COLA_05', 48.0000, 48.0000, 'PZ', 3, '2026-04-13 19:51:56', 2),
(12, 1, 'MAIN', 'PIECZARKI', -2.0000, 4.0000, 'RW', 4, '2026-04-13 19:51:56', 2),
(13, 1, 'MAIN', 'MKA_TIPO00', 25.0000, 25.0000, 'PZ', 1, '2026-04-13 19:54:38', 2),
(14, 1, 'MAIN', 'SER_MOZZ', 10.0000, 10.0000, 'PZ', 2, '2026-04-13 19:54:38', 2),
(15, 1, 'MAIN', 'COCA_COLA_05', 48.0000, 48.0000, 'PZ', 3, '2026-04-13 19:54:38', 2),
(16, 1, 'MAIN', 'PIECZARKI', -2.0000, 4.0000, 'RW', 4, '2026-04-13 19:54:38', 2),
(17, 1, 'MAIN', 'MKA_TIPO00', 25.0000, 25.0000, 'PZ', 1, '2026-04-13 20:27:30', 2),
(18, 1, 'MAIN', 'SER_MOZZ', 10.0000, 10.0000, 'PZ', 2, '2026-04-13 20:27:30', 2),
(19, 1, 'MAIN', 'COCA_COLA_05', 48.0000, 48.0000, 'PZ', 3, '2026-04-13 20:27:30', 2),
(20, 1, 'MAIN', 'PIECZARKI', -2.0000, 4.0000, 'RW', 4, '2026-04-13 20:27:30', 2),
(21, 1, 'MAIN', 'MKA_TIPO00', 25.0000, 25.0000, 'PZ', 1, '2026-04-13 23:51:24', 2),
(22, 1, 'MAIN', 'SER_MOZZ', 10.0000, 10.0000, 'PZ', 2, '2026-04-13 23:51:24', 2),
(23, 1, 'MAIN', 'COCA_COLA_05', 48.0000, 48.0000, 'PZ', 3, '2026-04-13 23:51:24', 2),
(24, 1, 'MAIN', 'PIECZARKI', -2.0000, 4.0000, 'RW', 4, '2026-04-13 23:51:24', 2),
(25, 1, 'MAIN', 'MKA_TIPO00', 25.0000, 25.0000, 'PZ', 1, '2026-04-13 23:51:26', 2),
(26, 1, 'MAIN', 'SER_MOZZ', 10.0000, 10.0000, 'PZ', 2, '2026-04-13 23:51:26', 2),
(27, 1, 'MAIN', 'COCA_COLA_05', 48.0000, 48.0000, 'PZ', 3, '2026-04-13 23:51:26', 2),
(28, 1, 'MAIN', 'PIECZARKI', -2.0000, 4.0000, 'RW', 4, '2026-04-13 23:51:26', 2),
(29, 1, 'MAIN', 'ANANAS', 25.0000, 28.5000, 'PZ', 5, '2026-04-15 00:19:51', 5),
(30, 1, 'MAIN', 'MKA_TIPO00', 25.0000, 25.0000, 'PZ', 1, '2026-04-16 04:57:52', 2),
(31, 1, 'MAIN', 'SER_MOZZ', 10.0000, 10.0000, 'PZ', 2, '2026-04-16 04:57:52', 2),
(32, 1, 'MAIN', 'COCA_COLA_05', 48.0000, 48.0000, 'PZ', 3, '2026-04-16 04:57:52', 2),
(33, 1, 'MAIN', 'PIECZARKI', -2.0000, 4.0000, 'RW', 4, '2026-04-16 04:57:52', 2),
(34, 1, 'MAIN', 'MKA_TIPO00', 25.0000, 25.0000, 'PZ', 1, '2026-04-16 04:59:13', 2),
(35, 1, 'MAIN', 'SER_MOZZ', 10.0000, 10.0000, 'PZ', 2, '2026-04-16 04:59:13', 2),
(36, 1, 'MAIN', 'COCA_COLA_05', 48.0000, 48.0000, 'PZ', 3, '2026-04-16 04:59:13', 2),
(37, 1, 'MAIN', 'PIECZARKI', -2.0000, 4.0000, 'RW', 4, '2026-04-16 04:59:13', 2),
(38, 1, 'MAIN', 'MKA_TIPO00', 25.0000, 25.0000, 'PZ', 1, '2026-04-16 05:19:04', 2),
(39, 1, 'MAIN', 'SER_MOZZ', 10.0000, 10.0000, 'PZ', 2, '2026-04-16 05:19:04', 2),
(40, 1, 'MAIN', 'COCA_COLA_05', 48.0000, 48.0000, 'PZ', 3, '2026-04-16 05:19:04', 2),
(41, 1, 'MAIN', 'PIECZARKI', -2.0000, 4.0000, 'RW', 4, '2026-04-16 05:19:04', 2),
(42, 1, 'MAIN', 'MKA_TIPO00', 25.0000, 25.0000, 'PZ', 1, '2026-04-17 03:11:28', 2),
(43, 1, 'MAIN', 'SER_MOZZ', 10.0000, 10.0000, 'PZ', 2, '2026-04-17 03:11:28', 2),
(44, 1, 'MAIN', 'COCA_COLA_05', 48.0000, 48.0000, 'PZ', 3, '2026-04-17 03:11:28', 2),
(45, 1, 'MAIN', 'PIECZARKI', -2.0000, 4.0000, 'RW', 4, '2026-04-17 03:11:28', 2),
(46, 1, 'MAIN', 'MKA_TIPO00', 25.0000, 25.0000, 'PZ', 1, '2026-04-17 03:12:04', 2),
(47, 1, 'MAIN', 'SER_MOZZ', 10.0000, 10.0000, 'PZ', 2, '2026-04-17 03:12:04', 2),
(48, 1, 'MAIN', 'COCA_COLA_05', 48.0000, 48.0000, 'PZ', 3, '2026-04-17 03:12:04', 2),
(49, 1, 'MAIN', 'PIECZARKI', -2.0000, 4.0000, 'RW', 4, '2026-04-17 03:12:04', 2),
(50, 1, 'MAIN', 'MKA_TIPO00', 25.0000, 25.0000, 'PZ', 1, '2026-04-17 06:22:15', 2),
(51, 1, 'MAIN', 'SER_MOZZ', 10.0000, 10.0000, 'PZ', 2, '2026-04-17 06:22:15', 2),
(52, 1, 'MAIN', 'COCA_COLA_05', 48.0000, 48.0000, 'PZ', 3, '2026-04-17 06:22:15', 2),
(53, 1, 'MAIN', 'PIECZARKI', -2.0000, 4.0000, 'RW', 4, '2026-04-17 06:22:15', 2),
(54, 1, 'MAIN', 'MKA_TIPO00', 25.0000, 25.0000, 'PZ', 1, '2026-04-17 18:34:47', 2),
(55, 1, 'MAIN', 'SER_MOZZ', 10.0000, 10.0000, 'PZ', 2, '2026-04-17 18:34:47', 2),
(56, 1, 'MAIN', 'COCA_COLA_05', 48.0000, 48.0000, 'PZ', 3, '2026-04-17 18:34:47', 2),
(57, 1, 'MAIN', 'PIECZARKI', -2.0000, 4.0000, 'RW', 4, '2026-04-17 18:34:47', 2);

-- --------------------------------------------------------

--
-- Struktura widoku `sh_item_prices`
--
DROP TABLE IF EXISTS `sh_item_prices`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `sh_item_prices`  AS SELECT `sh_price_tiers`.`tenant_id` AS `tenant_id`, `sh_price_tiers`.`target_sku` AS `item_sku`, `sh_price_tiers`.`channel` AS `channel`, `sh_price_tiers`.`price` AS `price` FROM `sh_price_tiers` WHERE `sh_price_tiers`.`target_type` = 'ITEM' ;

-- --------------------------------------------------------

--
-- Struktura widoku `v_menu_item_hero`
--
DROP TABLE IF EXISTS `v_menu_item_hero`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_menu_item_hero`  AS SELECT `mi`.`tenant_id` AS `tenant_id`, `mi`.`id` AS `menu_item_id`, `mi`.`ascii_key` AS `item_sku`, `mi`.`name` AS `item_name`, `a`.`id` AS `asset_id`, `a`.`storage_url` AS `hero_url`, `a`.`width_px` AS `width_px`, `a`.`height_px` AS `height_px`, `a`.`mime_type` AS `mime_type`, `al`.`display_params_json` AS `params_json` FROM ((`sh_menu_items` `mi` left join `sh_asset_links` `al` on(`al`.`tenant_id` = `mi`.`tenant_id` and `al`.`entity_type` = 'menu_item' and `al`.`entity_ref` = `mi`.`ascii_key` and `al`.`role` = 'hero' and `al`.`is_active` = 1 and `al`.`deleted_at` is null)) left join `sh_assets` `a` on(`a`.`id` = `al`.`asset_id` and `a`.`is_active` = 1 and `a`.`deleted_at` is null)) ;

-- --------------------------------------------------------

--
-- Struktura widoku `v_modifier_icon`
--
DROP TABLE IF EXISTS `v_modifier_icon`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_modifier_icon`  AS SELECT `mg`.`tenant_id` AS `tenant_id`, `m`.`id` AS `modifier_id`, `m`.`group_id` AS `modifier_group_id`, `m`.`ascii_key` AS `modifier_sku`, `m`.`name` AS `modifier_name`, `a`.`id` AS `asset_id`, `a`.`storage_url` AS `icon_url`, `a`.`category` AS `asset_category`, `a`.`sub_type` AS `asset_sub_type`, `a`.`z_order_hint` AS `z_order_hint`, `a`.`width_px` AS `width_px`, `a`.`height_px` AS `height_px` FROM (((`sh_modifiers` `m` join `sh_modifier_groups` `mg` on(`mg`.`id` = `m`.`group_id`)) left join `sh_asset_links` `al` on(`al`.`tenant_id` = `mg`.`tenant_id` and `al`.`entity_type` = 'modifier' and `al`.`entity_ref` = `m`.`ascii_key` and `al`.`role` = 'modifier_icon' and `al`.`is_active` = 1 and `al`.`deleted_at` is null)) left join `sh_assets` `a` on(`a`.`id` = `al`.`asset_id` and `a`.`is_active` = 1 and `a`.`deleted_at` is null)) ;

-- --------------------------------------------------------

--
-- Struktura widoku `v_visual_layer_asset`
--
DROP TABLE IF EXISTS `v_visual_layer_asset`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_visual_layer_asset`  AS SELECT `vl`.`tenant_id` AS `tenant_id`, `vl`.`id` AS `layer_id`, `vl`.`item_sku` AS `item_sku`, `vl`.`layer_sku` AS `layer_sku`, `vl`.`z_index` AS `z_index`, `vl`.`is_base` AS `is_base`, `a`.`id` AS `asset_id`, `a`.`storage_url` AS `layer_url`, `a`.`category` AS `asset_category`, `a`.`sub_type` AS `asset_sub_type`, `al`.`display_params_json` AS `display_params_json` FROM ((`sh_visual_layers` `vl` left join `sh_asset_links` `al` on(`al`.`tenant_id` = `vl`.`tenant_id` and `al`.`entity_type` = 'visual_layer' and `al`.`entity_ref` = concat(`vl`.`item_sku`,'::',`vl`.`layer_sku`) and `al`.`role` = 'layer_top_down' and `al`.`is_active` = 1 and `al`.`deleted_at` is null)) left join `sh_assets` `a` on(`a`.`id` = `al`.`asset_id` and `a`.`is_active` = 1 and `a`.`deleted_at` is null)) ;

--
-- Indeksy dla zrzutów tabel
--

--
-- Indeksy dla tabeli `sh_ai_jobs`
--
ALTER TABLE `sh_ai_jobs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_aj_status_queued` (`status`,`created_at`),
  ADD KEY `idx_aj_tenant_status` (`tenant_id`,`status`,`created_at`);

--
-- Indeksy dla tabeli `sh_assets`
--
ALTER TABLE `sh_assets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_assets_tenant_key` (`tenant_id`,`ascii_key`),
  ADD KEY `idx_assets_tenant_active` (`tenant_id`,`is_active`,`deleted_at`),
  ADD KEY `idx_assets_role` (`tenant_id`,`role_hint`),
  ADD KEY `idx_assets_category` (`tenant_id`,`category`,`sub_type`),
  ADD KEY `idx_assets_variant` (`variant_of`),
  ADD KEY `idx_assets_checksum` (`tenant_id`,`checksum_sha256`),
  ADD KEY `idx_assets_bucket` (`tenant_id`,`storage_bucket`),
  ADD KEY `idx_assets_cook_state` (`tenant_id`,`category`,`cook_state`,`is_active`),
  ADD KEY `idx_assets_display_name` (`display_name`),
  ADD KEY `idx_assets_cat_active` (`tenant_id`,`category`,`is_active`),
  ADD KEY `idx_assets_checksum_tenant` (`tenant_id`,`checksum_sha256`);

--
-- Indeksy dla tabeli `sh_asset_links`
--
ALTER TABLE `sh_asset_links`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_al_unique` (`tenant_id`,`entity_type`,`entity_ref`,`role`,`asset_id`),
  ADD KEY `idx_al_entity` (`tenant_id`,`entity_type`,`entity_ref`,`role`),
  ADD KEY `idx_al_asset` (`asset_id`),
  ADD KEY `idx_al_role` (`tenant_id`,`role`);

--
-- Indeksy dla tabeli `sh_atelier_scenes`
--
ALTER TABLE `sh_atelier_scenes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_tenant_item` (`tenant_id`,`item_sku`),
  ADD KEY `idx_tenant` (`tenant_id`);

--
-- Indeksy dla tabeli `sh_atelier_scene_history`
--
ALTER TABLE `sh_atelier_scene_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_scene` (`scene_id`);

--
-- Indeksy dla tabeli `sh_board_companions`
--
ALTER TABLE `sh_board_companions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_bc_tenant_item_comp` (`tenant_id`,`item_sku`,`companion_sku`),
  ADD KEY `idx_bc_tenant_item` (`tenant_id`,`item_sku`);

--
-- Indeksy dla tabeli `sh_categories`
--
ALTER TABLE `sh_categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cat_tenant` (`tenant_id`);

--
-- Indeksy dla tabeli `sh_category_styles`
--
ALTER TABLE `sh_category_styles`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cs_category_active` (`tenant_id`,`category_id`,`is_active`),
  ADD KEY `idx_cs_style` (`style_preset_id`);

--
-- Indeksy dla tabeli `sh_checkout_locks`
--
ALTER TABLE `sh_checkout_locks`
  ADD PRIMARY KEY (`lock_token`),
  ADD KEY `idx_locks_expires` (`expires_at`),
  ADD KEY `idx_locks_phone` (`tenant_id`,`customer_phone`),
  ADD KEY `idx_locks_hash` (`tenant_id`,`cart_hash`);

--
-- Indeksy dla tabeli `sh_course_sequences`
--
ALTER TABLE `sh_course_sequences`
  ADD PRIMARY KEY (`tenant_id`,`date`);

--
-- Indeksy dla tabeli `sh_deductions`
--
ALTER TABLE `sh_deductions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ded_user` (`tenant_id`,`user_id`,`created_at`),
  ADD KEY `fk_ded_user` (`user_id`);

--
-- Indeksy dla tabeli `sh_delivery_zones`
--
ALTER TABLE `sh_delivery_zones`
  ADD PRIMARY KEY (`id`),
  ADD SPATIAL KEY `idx_zone_poly` (`zone_polygon`),
  ADD KEY `idx_zone_tenant` (`tenant_id`);

--
-- Indeksy dla tabeli `sh_dispatch_log`
--
ALTER TABLE `sh_dispatch_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_dispatch_tenant` (`tenant_id`);

--
-- Indeksy dla tabeli `sh_doc_sequences`
--
ALTER TABLE `sh_doc_sequences`
  ADD PRIMARY KEY (`tenant_id`,`doc_type`,`doc_date`);

--
-- Indeksy dla tabeli `sh_drivers`
--
ALTER TABLE `sh_drivers`
  ADD PRIMARY KEY (`tenant_id`,`user_id`),
  ADD KEY `fk_drivers_user` (`user_id`);

--
-- Indeksy dla tabeli `sh_driver_locations`
--
ALTER TABLE `sh_driver_locations`
  ADD PRIMARY KEY (`tenant_id`,`driver_id`);

--
-- Indeksy dla tabeli `sh_driver_shifts`
--
ALTER TABLE `sh_driver_shifts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_shift_driver` (`tenant_id`,`driver_id`,`status`);

--
-- Indeksy dla tabeli `sh_event_outbox`
--
ALTER TABLE `sh_event_outbox`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_event_idempotency` (`tenant_id`,`idempotency_key`),
  ADD KEY `idx_event_pending` (`status`,`next_attempt_at`,`id`) COMMENT 'Worker query: WHERE status=pending AND (next_attempt_at IS NULL OR next_attempt_at<=NOW()) ORDER BY id',
  ADD KEY `idx_event_aggregate` (`tenant_id`,`aggregate_type`,`aggregate_id`,`created_at`),
  ADD KEY `idx_event_type` (`tenant_id`,`event_type`,`created_at`);

--
-- Indeksy dla tabeli `sh_external_order_refs`
--
ALTER TABLE `sh_external_order_refs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_ext_ref` (`tenant_id`,`source`,`external_id`),
  ADD KEY `idx_ext_order` (`order_id`),
  ADD KEY `idx_ext_key` (`api_key_id`,`created_at`);

--
-- Indeksy dla tabeli `sh_gateway_api_keys`
--
ALTER TABLE `sh_gateway_api_keys`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_gw_key_prefix` (`key_prefix`),
  ADD KEY `idx_gw_tenant_source` (`tenant_id`,`source`,`is_active`),
  ADD KEY `idx_gw_active` (`is_active`,`expires_at`);

--
-- Indeksy dla tabeli `sh_global_assets`
--
ALTER TABLE `sh_global_assets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_ga_tenant_key` (`tenant_id`,`ascii_key`),
  ADD KEY `idx_ga_category` (`category`),
  ADD KEY `idx_ga_tenant` (`tenant_id`);

--
-- Indeksy dla tabeli `sh_integration_attempts`
--
ALTER TABLE `sh_integration_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_intattempt_delivery` (`delivery_id`,`attempted_at`);

--
-- Indeksy dla tabeli `sh_integration_deliveries`
--
ALTER TABLE `sh_integration_deliveries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_intdev_event_integration` (`event_id`,`integration_id`) COMMENT 'Jeden rekord per event × integration (idempotency)',
  ADD KEY `idx_intdev_pending` (`status`,`next_attempt_at`,`id`) COMMENT 'Worker query: WHERE status=pending AND (next_attempt_at IS NULL OR <= NOW())',
  ADD KEY `idx_intdev_tenant_provider` (`tenant_id`,`provider`,`created_at`),
  ADD KEY `idx_intdev_aggregate` (`aggregate_id`,`event_type`,`status`);

--
-- Indeksy dla tabeli `sh_integration_logs`
--
ALTER TABLE `sh_integration_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_intlog_tenant_order` (`tenant_id`,`order_id`);

--
-- Indeksy dla tabeli `sh_item_modifiers`
--
ALTER TABLE `sh_item_modifiers`
  ADD PRIMARY KEY (`item_id`,`group_id`),
  ADD KEY `fk_im_group` (`group_id`);

--
-- Indeksy dla tabeli `sh_kds_tickets`
--
ALTER TABLE `sh_kds_tickets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_kds_order` (`order_id`),
  ADD KEY `fk_kds_tenant` (`tenant_id`);

--
-- Indeksy dla tabeli `sh_meals`
--
ALTER TABLE `sh_meals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_meals_user` (`tenant_id`,`user_id`,`created_at`),
  ADD KEY `fk_meals_user` (`user_id`);

--
-- Indeksy dla tabeli `sh_menu_items`
--
ALTER TABLE `sh_menu_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_menu_tenant_ascii` (`tenant_id`,`ascii_key`),
  ADD KEY `idx_menu_category` (`category_id`);

--
-- Indeksy dla tabeli `sh_modifiers`
--
ALTER TABLE `sh_modifiers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_mod_group` (`group_id`),
  ADD KEY `idx_mod_ascii` (`ascii_key`);

--
-- Indeksy dla tabeli `sh_modifier_groups`
--
ALTER TABLE `sh_modifier_groups`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_modgrp_tenant` (`tenant_id`);

--
-- Indeksy dla tabeli `sh_orders`
--
ALTER TABLE `sh_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_one_active_order_per_table` (`tenant_id`,`_active_table_guard`),
  ADD KEY `idx_orders_tenant_status` (`tenant_id`,`status`),
  ADD KEY `idx_orders_delivery_status` (`tenant_id`,`delivery_status`),
  ADD KEY `fk_orders_table` (`table_id`),
  ADD KEY `fk_orders_waiter` (`waiter_id`),
  ADD KEY `idx_orders_table` (`tenant_id`,`table_id`),
  ADD KEY `idx_orders_tracking` (`tracking_token`),
  ADD KEY `idx_orders_gw_ext` (`tenant_id`,`gateway_source`,`gateway_external_id`);

--
-- Indeksy dla tabeli `sh_order_audit`
--
ALTER TABLE `sh_order_audit`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_order` (`order_id`);

--
-- Indeksy dla tabeli `sh_order_item_modifiers`
--
ALTER TABLE `sh_order_item_modifiers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_oim_line` (`order_item_id`);

--
-- Indeksy dla tabeli `sh_order_lines`
--
ALTER TABLE `sh_order_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_lines_order` (`order_id`),
  ADD KEY `idx_lines_ticket` (`kds_ticket_id`),
  ADD KEY `idx_lines_course` (`order_id`,`course_number`);

--
-- Indeksy dla tabeli `sh_order_logs`
--
ALTER TABLE `sh_order_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_order_logs_order` (`order_id`),
  ADD KEY `idx_order_logs_tenant_time` (`tenant_id`,`created_at`);

--
-- Indeksy dla tabeli `sh_order_payments`
--
ALTER TABLE `sh_order_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pay_order` (`order_id`),
  ADD KEY `idx_pay_user` (`tenant_id`,`user_id`,`method`);

--
-- Indeksy dla tabeli `sh_order_sequences`
--
ALTER TABLE `sh_order_sequences`
  ADD PRIMARY KEY (`tenant_id`,`date`);

--
-- Indeksy dla tabeli `sh_panic_log`
--
ALTER TABLE `sh_panic_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_panic_tenant_time` (`tenant_id`,`created_at`);

--
-- Indeksy dla tabeli `sh_price_tiers`
--
ALTER TABLE `sh_price_tiers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_price_tier` (`target_type`,`target_sku`,`channel`,`tenant_id`),
  ADD KEY `idx_price_tenant` (`tenant_id`);

--
-- Indeksy dla tabeli `sh_product_mapping`
--
ALTER TABLE `sh_product_mapping`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_mapping` (`tenant_id`,`external_name`(191));

--
-- Indeksy dla tabeli `sh_promotions`
--
ALTER TABLE `sh_promotions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_promo_tenant_key` (`tenant_id`,`ascii_key`),
  ADD KEY `idx_promo_tenant_active` (`tenant_id`,`is_active`),
  ADD KEY `idx_promo_validity` (`valid_from`,`valid_to`);

--
-- Indeksy dla tabeli `sh_promo_codes`
--
ALTER TABLE `sh_promo_codes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_promo` (`tenant_id`,`code`);

--
-- Indeksy dla tabeli `sh_rate_limits`
--
ALTER TABLE `sh_rate_limits`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_rl_bucket` (`api_key_id`,`window_kind`,`window_bucket`),
  ADD KEY `idx_rl_cleanup` (`last_hit_at`);

--
-- Indeksy dla tabeli `sh_recipes`
--
ALTER TABLE `sh_recipes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_recipe_line` (`tenant_id`,`menu_item_sku`,`warehouse_sku`),
  ADD KEY `idx_recipe_tenant_item` (`tenant_id`,`menu_item_sku`);

--
-- Indeksy dla tabeli `sh_scene_promotion_slots`
--
ALTER TABLE `sh_scene_promotion_slots`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sps_scene` (`scene_id`,`is_active`),
  ADD KEY `idx_sps_promo` (`promotion_id`);

--
-- Indeksy dla tabeli `sh_scene_templates`
--
ALTER TABLE `sh_scene_templates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_st_tenant_key` (`tenant_id`,`ascii_key`),
  ADD KEY `idx_st_kind_active` (`kind`,`is_active`),
  ADD KEY `idx_st_tenant` (`tenant_id`,`is_active`);

--
-- Indeksy dla tabeli `sh_scene_triggers`
--
ALTER TABLE `sh_scene_triggers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_st_scene_active` (`scene_id`,`is_active`),
  ADD KEY `idx_st_tenant_active` (`tenant_id`,`is_active`),
  ADD KEY `idx_st_validity` (`valid_from`,`valid_to`);

--
-- Indeksy dla tabeli `sh_scene_variants`
--
ALTER TABLE `sh_scene_variants`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sv_parent_active` (`parent_scene_id`,`is_active`);

--
-- Indeksy dla tabeli `sh_sla_breaches`
--
ALTER TABLE `sh_sla_breaches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sla_order` (`tenant_id`,`order_id`),
  ADD KEY `fk_sla_order` (`order_id`);

--
-- Indeksy dla tabeli `sh_style_presets`
--
ALTER TABLE `sh_style_presets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sp_tenant_key` (`tenant_id`,`ascii_key`),
  ADD KEY `idx_sp_active` (`tenant_id`,`is_active`);

--
-- Indeksy dla tabeli `sh_tables`
--
ALTER TABLE `sh_tables`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_table_number` (`tenant_id`,`table_number`),
  ADD UNIQUE KEY `uq_table_qr` (`qr_hash`),
  ADD KEY `idx_tables_tenant` (`tenant_id`),
  ADD KEY `idx_tables_zone` (`zone_id`),
  ADD KEY `idx_tables_parent` (`parent_table_id`);

--
-- Indeksy dla tabeli `sh_tenant`
--
ALTER TABLE `sh_tenant`
  ADD PRIMARY KEY (`id`);

--
-- Indeksy dla tabeli `sh_tenant_integrations`
--
ALTER TABLE `sh_tenant_integrations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_integration_tenant_provider` (`tenant_id`,`provider`),
  ADD KEY `idx_integration_active` (`is_active`,`provider`);

--
-- Indeksy dla tabeli `sh_tenant_settings`
--
ALTER TABLE `sh_tenant_settings`
  ADD PRIMARY KEY (`tenant_id`,`setting_key`);

--
-- Indeksy dla tabeli `sh_users`
--
ALTER TABLE `sh_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_users_username` (`username`),
  ADD KEY `idx_users_tenant` (`tenant_id`);

--
-- Indeksy dla tabeli `sh_visual_layers`
--
ALTER TABLE `sh_visual_layers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_vl_tenant_item_layer` (`tenant_id`,`item_sku`,`layer_sku`),
  ADD KEY `idx_vl_tenant_item` (`tenant_id`,`item_sku`),
  ADD KEY `idx_vl_library` (`tenant_id`,`library_category`,`library_sub_type`);

--
-- Indeksy dla tabeli `sh_webhook_deliveries`
--
ALTER TABLE `sh_webhook_deliveries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_delivery_event` (`event_id`),
  ADD KEY `idx_delivery_endpoint` (`endpoint_id`,`attempted_at`);

--
-- Indeksy dla tabeli `sh_webhook_endpoints`
--
ALTER TABLE `sh_webhook_endpoints`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_endpoint_tenant` (`tenant_id`,`is_active`);

--
-- Indeksy dla tabeli `sh_work_sessions`
--
ALTER TABLE `sh_work_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_session_uuid` (`session_uuid`),
  ADD KEY `idx_ws_user_open` (`tenant_id`,`user_id`,`end_time`),
  ADD KEY `fk_ws_user` (`user_id`);

--
-- Indeksy dla tabeli `sh_zones`
--
ALTER TABLE `sh_zones`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_zone_name` (`tenant_id`,`name`),
  ADD KEY `idx_zones_tenant` (`tenant_id`);

--
-- Indeksy dla tabeli `sys_items`
--
ALTER TABLE `sys_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sys_items_tenant_sku` (`tenant_id`,`sku`);

--
-- Indeksy dla tabeli `wh_documents`
--
ALTER TABLE `wh_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_whdoc_tenant_type` (`tenant_id`,`type`);

--
-- Indeksy dla tabeli `wh_document_lines`
--
ALTER TABLE `wh_document_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_whline_doc` (`document_id`);

--
-- Indeksy dla tabeli `wh_inventory_docs`
--
ALTER TABLE `wh_inventory_docs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_whinv_tenant` (`tenant_id`);

--
-- Indeksy dla tabeli `wh_inventory_doc_items`
--
ALTER TABLE `wh_inventory_doc_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_whinvitem_doc` (`doc_id`);

--
-- Indeksy dla tabeli `wh_stock`
--
ALTER TABLE `wh_stock`
  ADD PRIMARY KEY (`tenant_id`,`warehouse_id`,`sku`);

--
-- Indeksy dla tabeli `wh_stock_logs`
--
ALTER TABLE `wh_stock_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_whlog_doc` (`document_type`,`document_id`),
  ADD KEY `fk_whlog_tenant` (`tenant_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `sh_ai_jobs`
--
ALTER TABLE `sh_ai_jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_assets`
--
ALTER TABLE `sh_assets`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=326;

--
-- AUTO_INCREMENT for table `sh_asset_links`
--
ALTER TABLE `sh_asset_links`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `sh_atelier_scenes`
--
ALTER TABLE `sh_atelier_scenes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `sh_atelier_scene_history`
--
ALTER TABLE `sh_atelier_scene_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=135;

--
-- AUTO_INCREMENT for table `sh_board_companions`
--
ALTER TABLE `sh_board_companions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=362;

--
-- AUTO_INCREMENT for table `sh_categories`
--
ALTER TABLE `sh_categories`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `sh_category_styles`
--
ALTER TABLE `sh_category_styles`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_deductions`
--
ALTER TABLE `sh_deductions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_delivery_zones`
--
ALTER TABLE `sh_delivery_zones`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_driver_shifts`
--
ALTER TABLE `sh_driver_shifts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

--
-- AUTO_INCREMENT for table `sh_event_outbox`
--
ALTER TABLE `sh_event_outbox`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_external_order_refs`
--
ALTER TABLE `sh_external_order_refs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_gateway_api_keys`
--
ALTER TABLE `sh_gateway_api_keys`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_global_assets`
--
ALTER TABLE `sh_global_assets`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=172;

--
-- AUTO_INCREMENT for table `sh_integration_attempts`
--
ALTER TABLE `sh_integration_attempts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_integration_deliveries`
--
ALTER TABLE `sh_integration_deliveries`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_integration_logs`
--
ALTER TABLE `sh_integration_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_meals`
--
ALTER TABLE `sh_meals`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_menu_items`
--
ALTER TABLE `sh_menu_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=70;

--
-- AUTO_INCREMENT for table `sh_modifiers`
--
ALTER TABLE `sh_modifiers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `sh_modifier_groups`
--
ALTER TABLE `sh_modifier_groups`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `sh_order_audit`
--
ALTER TABLE `sh_order_audit`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=340;

--
-- AUTO_INCREMENT for table `sh_order_item_modifiers`
--
ALTER TABLE `sh_order_item_modifiers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=55;

--
-- AUTO_INCREMENT for table `sh_order_logs`
--
ALTER TABLE `sh_order_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=81;

--
-- AUTO_INCREMENT for table `sh_price_tiers`
--
ALTER TABLE `sh_price_tiers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1608;

--
-- AUTO_INCREMENT for table `sh_product_mapping`
--
ALTER TABLE `sh_product_mapping`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=70;

--
-- AUTO_INCREMENT for table `sh_promotions`
--
ALTER TABLE `sh_promotions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_promo_codes`
--
ALTER TABLE `sh_promo_codes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_rate_limits`
--
ALTER TABLE `sh_rate_limits`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_recipes`
--
ALTER TABLE `sh_recipes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=623;

--
-- AUTO_INCREMENT for table `sh_scene_promotion_slots`
--
ALTER TABLE `sh_scene_promotion_slots`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_scene_templates`
--
ALTER TABLE `sh_scene_templates`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=121;

--
-- AUTO_INCREMENT for table `sh_scene_triggers`
--
ALTER TABLE `sh_scene_triggers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_scene_variants`
--
ALTER TABLE `sh_scene_variants`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_style_presets`
--
ALTER TABLE `sh_style_presets`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=181;

--
-- AUTO_INCREMENT for table `sh_tables`
--
ALTER TABLE `sh_tables`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `sh_tenant`
--
ALTER TABLE `sh_tenant`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `sh_tenant_integrations`
--
ALTER TABLE `sh_tenant_integrations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_users`
--
ALTER TABLE `sh_users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `sh_visual_layers`
--
ALTER TABLE `sh_visual_layers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `sh_webhook_deliveries`
--
ALTER TABLE `sh_webhook_deliveries`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_webhook_endpoints`
--
ALTER TABLE `sh_webhook_endpoints`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_work_sessions`
--
ALTER TABLE `sh_work_sessions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `sh_zones`
--
ALTER TABLE `sh_zones`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sys_items`
--
ALTER TABLE `sys_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=603;

--
-- AUTO_INCREMENT for table `wh_documents`
--
ALTER TABLE `wh_documents`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `wh_document_lines`
--
ALTER TABLE `wh_document_lines`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=100;

--
-- AUTO_INCREMENT for table `wh_inventory_docs`
--
ALTER TABLE `wh_inventory_docs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wh_inventory_doc_items`
--
ALTER TABLE `wh_inventory_doc_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wh_stock_logs`
--
ALTER TABLE `wh_stock_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=58;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `sh_assets`
--
ALTER TABLE `sh_assets`
  ADD CONSTRAINT `fk_assets_variant_of` FOREIGN KEY (`variant_of`) REFERENCES `sh_assets` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `sh_asset_links`
--
ALTER TABLE `sh_asset_links`
  ADD CONSTRAINT `fk_al_asset` FOREIGN KEY (`asset_id`) REFERENCES `sh_assets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_al_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenant` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sh_atelier_scene_history`
--
ALTER TABLE `sh_atelier_scene_history`
  ADD CONSTRAINT `sh_atelier_scene_history_ibfk_1` FOREIGN KEY (`scene_id`) REFERENCES `sh_atelier_scenes` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sh_board_companions`
--
ALTER TABLE `sh_board_companions`
  ADD CONSTRAINT `fk_bc_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenant` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sh_categories`
--
ALTER TABLE `sh_categories`
  ADD CONSTRAINT `fk_categories_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenant` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sh_checkout_locks`
--
ALTER TABLE `sh_checkout_locks`
  ADD CONSTRAINT `fk_locks_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenant` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sh_course_sequences`
--
ALTER TABLE `sh_course_sequences`
  ADD CONSTRAINT `fk_course_seq_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenant` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sh_deductions`
--
ALTER TABLE `sh_deductions`
  ADD CONSTRAINT `fk_ded_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenant` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_ded_user` FOREIGN KEY (`user_id`) REFERENCES `sh_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sh_delivery_zones`
--
ALTER TABLE `sh_delivery_zones`
  ADD CONSTRAINT `fk_zone_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenant` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sh_dispatch_log`
--
ALTER TABLE `sh_dispatch_log`
  ADD CONSTRAINT `fk_dispatch_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenant` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sh_doc_sequences`
--
ALTER TABLE `sh_doc_sequences`
  ADD CONSTRAINT `fk_docseq_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenant` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sh_drivers`
--
ALTER TABLE `sh_drivers`
  ADD CONSTRAINT `fk_drivers_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenant` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_drivers_user` FOREIGN KEY (`user_id`) REFERENCES `sh_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sh_driver_shifts`
--
ALTER TABLE `sh_driver_shifts`
  ADD CONSTRAINT `fk_shift_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenant` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sh_item_modifiers`
--
ALTER TABLE `sh_item_modifiers`
  ADD CONSTRAINT `fk_im_group` FOREIGN KEY (`group_id`) REFERENCES `sh_modifier_groups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_im_item` FOREIGN KEY (`item_id`) REFERENCES `sh_menu_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sh_kds_tickets`
--
ALTER TABLE `sh_kds_tickets`
  ADD CONSTRAINT `fk_kds_order` FOREIGN KEY (`order_id`) REFERENCES `sh_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_kds_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenant` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sh_meals`
--
ALTER TABLE `sh_meals`
  ADD CONSTRAINT `fk_meals_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenant` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_meals_user` FOREIGN KEY (`user_id`) REFERENCES `sh_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sh_menu_items`
--
ALTER TABLE `sh_menu_items`
  ADD CONSTRAINT `fk_menu_category` FOREIGN KEY (`category_id`) REFERENCES `sh_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_menu_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenant` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sh_modifiers`
--
ALTER TABLE `sh_modifiers`
  ADD CONSTRAINT `fk_mod_group` FOREIGN KEY (`group_id`) REFERENCES `sh_modifier_groups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sh_modifier_groups`
--
ALTER TABLE `sh_modifier_groups`
  ADD CONSTRAINT `fk_modgrp_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenant` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sh_orders`
--
ALTER TABLE `sh_orders`
  ADD CONSTRAINT `fk_orders_table` FOREIGN KEY (`table_id`) REFERENCES `sh_tables` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_orders_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenant` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_orders_waiter` FOREIGN KEY (`waiter_id`) REFERENCES `sh_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `sh_order_audit`
--
ALTER TABLE `sh_order_audit`
  ADD CONSTRAINT `fk_audit_order` FOREIGN KEY (`order_id`) REFERENCES `sh_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sh_order_item_modifiers`
--
ALTER TABLE `sh_order_item_modifiers`
  ADD CONSTRAINT `fk_oim_line` FOREIGN KEY (`order_item_id`) REFERENCES `sh_order_lines` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sh_order_lines`
--
ALTER TABLE `sh_order_lines`
  ADD CONSTRAINT `fk_lines_kds` FOREIGN KEY (`kds_ticket_id`) REFERENCES `sh_kds_tickets` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_lines_order` FOREIGN KEY (`order_id`) REFERENCES `sh_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sh_order_logs`
--
ALTER TABLE `sh_order_logs`
  ADD CONSTRAINT `fk_order_logs_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenant` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sh_order_payments`
--
ALTER TABLE `sh_order_payments`
  ADD CONSTRAINT `fk_pay_order` FOREIGN KEY (`order_id`) REFERENCES `sh_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_pay_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenant` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sh_order_sequences`
--
ALTER TABLE `sh_order_sequences`
  ADD CONSTRAINT `fk_order_seq_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenant` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sh_panic_log`
--
ALTER TABLE `sh_panic_log`
  ADD CONSTRAINT `fk_panic_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenant` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sh_product_mapping`
--
ALTER TABLE `sh_product_mapping`
  ADD CONSTRAINT `fk_mapping_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenant` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sh_promo_codes`
--
ALTER TABLE `sh_promo_codes`
  ADD CONSTRAINT `fk_promo_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenant` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sh_recipes`
--
ALTER TABLE `sh_recipes`
  ADD CONSTRAINT `fk_recipes_menu_ascii` FOREIGN KEY (`tenant_id`,`menu_item_sku`) REFERENCES `sh_menu_items` (`tenant_id`, `ascii_key`);

--
-- Constraints for table `sh_sla_breaches`
--
ALTER TABLE `sh_sla_breaches`
  ADD CONSTRAINT `fk_sla_order` FOREIGN KEY (`order_id`) REFERENCES `sh_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_sla_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenant` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sh_tables`
--
ALTER TABLE `sh_tables`
  ADD CONSTRAINT `fk_tables_parent` FOREIGN KEY (`parent_table_id`) REFERENCES `sh_tables` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_tables_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenant` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_tables_zone` FOREIGN KEY (`zone_id`) REFERENCES `sh_zones` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `sh_tenant_settings`
--
ALTER TABLE `sh_tenant_settings`
  ADD CONSTRAINT `fk_tenant_settings_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenant` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sh_users`
--
ALTER TABLE `sh_users`
  ADD CONSTRAINT `fk_users_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenant` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `sh_visual_layers`
--
ALTER TABLE `sh_visual_layers`
  ADD CONSTRAINT `fk_vl_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenant` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sh_work_sessions`
--
ALTER TABLE `sh_work_sessions`
  ADD CONSTRAINT `fk_ws_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenant` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_ws_user` FOREIGN KEY (`user_id`) REFERENCES `sh_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sh_zones`
--
ALTER TABLE `sh_zones`
  ADD CONSTRAINT `fk_zones_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenant` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sys_items`
--
ALTER TABLE `sys_items`
  ADD CONSTRAINT `fk_sys_items_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenant` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `wh_documents`
--
ALTER TABLE `wh_documents`
  ADD CONSTRAINT `fk_whdoc_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenant` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `wh_document_lines`
--
ALTER TABLE `wh_document_lines`
  ADD CONSTRAINT `fk_whline_doc` FOREIGN KEY (`document_id`) REFERENCES `wh_documents` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `wh_inventory_docs`
--
ALTER TABLE `wh_inventory_docs`
  ADD CONSTRAINT `fk_whinv_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenant` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `wh_inventory_doc_items`
--
ALTER TABLE `wh_inventory_doc_items`
  ADD CONSTRAINT `fk_whinvitem_doc` FOREIGN KEY (`doc_id`) REFERENCES `wh_inventory_docs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `wh_stock`
--
ALTER TABLE `wh_stock`
  ADD CONSTRAINT `fk_wh_stock_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenant` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `wh_stock_logs`
--
ALTER TABLE `wh_stock_logs`
  ADD CONSTRAINT `fk_whlog_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenant` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
