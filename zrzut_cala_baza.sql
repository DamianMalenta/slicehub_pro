-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 21, 2026 at 12:41 AM
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
-- Database: `baza_slicehub`
--

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_categories`
--

CREATE TABLE `sh_categories` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ascii_key` varchar(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `is_menu` tinyint(1) NOT NULL DEFAULT 1,
  `display_order` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sh_categories`
--

INSERT INTO `sh_categories` (`id`, `tenant_id`, `name`, `ascii_key`, `is_menu`, `display_order`) VALUES
(1, 1, 'Pizze', 'CAT_PIZZA', 1, 0),
(2, 1, 'Napoje', 'CAT_DRINKS', 1, 0),
(3, 1, '🍕 Pizze TEST', 'CAT_PIZZA_TEST', 1, 90),
(4, 1, '🍔 Burgery TEST', 'CAT_BURGER_TEST', 1, 91),
(5, 1, '🥤 Napoje TEST', 'CAT_DRINK_TEST', 1, 92),
(6, 1, 'MAKARONY', 'CAT_MAKARONY', 1, 93);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_chat_messages`
--

CREATE TABLE `sh_chat_messages` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `room_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `target_user_id` int(11) DEFAULT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `image_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_chat_rooms`
--

CREATE TABLE `sh_chat_rooms` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `display_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `icon` varchar(50) CHARACTER SET ascii COLLATE ascii_bin DEFAULT 'fa-hashtag'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sh_chat_rooms`
--

INSERT INTO `sh_chat_rooms` (`id`, `tenant_id`, `display_name`, `icon`) VALUES
(1, 1, 'Główny', 'fa-fire'),
(2, 1, 'Kuchnia', 'fa-utensils'),
(3, 1, 'Kierowcy', 'fa-car');

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_customers`
--

CREATE TABLE `sh_customers` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `nip` varchar(20) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_customer_addresses`
--

CREATE TABLE `sh_customer_addresses` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `address` varchar(255) NOT NULL,
  `usage_count` int(11) DEFAULT 1,
  `last_used` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_customer_contacts`
--

CREATE TABLE `sh_customer_contacts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `phone` varchar(32) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `sms_consent` tinyint(1) NOT NULL DEFAULT 0,
  `marketing_consent` tinyint(1) NOT NULL DEFAULT 0,
  `sms_optout_at` datetime DEFAULT NULL COMMENT 'Kiedy klient wysłał STOP',
  `first_seen_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_order_at` datetime DEFAULT NULL,
  `order_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_customer_inbox`
--

CREATE TABLE `sh_customer_inbox` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `contact_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'FK → sh_customer_contacts.id (NULL jeśli nieznany)',
  `from_phone` varchar(32) NOT NULL,
  `body` text NOT NULL,
  `intent` varchar(32) DEFAULT NULL COMMENT 'Wynik SmartReplyEngine: eta_query | cancel_request | info_query | stop | other',
  `order_id` char(36) DEFAULT NULL COMMENT 'Powiązane zamówienie (jeśli znaleziono)',
  `auto_replied` tinyint(1) NOT NULL DEFAULT 0,
  `auto_reply_body` text DEFAULT NULL,
  `read_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `received_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_daily_rewards`
--

CREATE TABLE `sh_daily_rewards` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `claimed_date` date NOT NULL,
  `coins_won` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_deductions`
--

CREATE TABLE `sh_deductions` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `type` enum('advance','bonus','meal') CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pending','approved','rejected') CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_drivers`
--

CREATE TABLE `sh_drivers` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `status` enum('available','busy','offline') CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'offline',
  `initial_cash` decimal(10,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sh_drivers`
--

INSERT INTO `sh_drivers` (`id`, `user_id`, `status`, `initial_cash`) VALUES
(2, 6, 'available', 0.00),
(3, 5, 'available', 0.00);

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
-- Struktura tabeli dla tabeli `sh_finance_requests`
--

CREATE TABLE `sh_finance_requests` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `target_user_id` int(11) NOT NULL,
  `created_by_id` int(11) NOT NULL,
  `type` varchar(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_paid_cash` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
-- Struktura tabeli dla tabeli `sh_gdpr_consent_log`
--

CREATE TABLE `sh_gdpr_consent_log` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `contact_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'FK → sh_customer_contacts.id',
  `phone_hash` char(64) NOT NULL COMMENT 'SHA-256(phone) — bez raw PII',
  `consent_type` enum('sms','marketing') NOT NULL,
  `granted` tinyint(1) NOT NULL COMMENT '1=zgoda, 0=cofnięcie',
  `source` varchar(32) NOT NULL DEFAULT 'checkout' COMMENT 'checkout | sms_stop | api | admin | import',
  `ip_hash` char(64) DEFAULT NULL,
  `user_agent_hash` char(64) DEFAULT NULL,
  `order_id` char(36) DEFAULT NULL,
  `occurred_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_inbound_callbacks`
--

CREATE TABLE `sh_inbound_callbacks` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'Resolved po weryfikacji integration_id; może być NULL gdy request trafił z bad credentials',
  `integration_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK do sh_tenant_integrations — NULL gdy nie udało się rozpoznać',
  `provider` varchar(32) NOT NULL COMMENT 'papu | dotykacka | gastrosoft | uber | glovo | pyszne | wolt | custom',
  `external_event_id` varchar(128) DEFAULT NULL COMMENT 'ID eventu po stronie 3rd-party (dla idempotency)',
  `external_ref` varchar(128) DEFAULT NULL COMMENT 'ID zamówienia 3rd-party (np. Papu order_id)',
  `event_type` varchar(64) DEFAULT NULL COMMENT 'Rozpoznany typ: order.status_update | order.cancelled | driver.assigned | ...',
  `mapped_order_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'sh_orders.id po matchingu external_ref → gateway_external_id',
  `raw_headers` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Wybrane headery (Content-Type, X-Papu-Signature, User-Agent, X-Forwarded-For)' CHECK (json_valid(`raw_headers`)),
  `raw_body` mediumtext DEFAULT NULL COMMENT 'Pierwsze 64KB body — debug; powyżej TRUNCATED',
  `signature_verified` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = adapter potwierdził HMAC/OAuth signature',
  `status` enum('pending','processed','rejected','ignored','error') NOT NULL DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  `remote_ip` varchar(45) DEFAULT NULL,
  `received_at` datetime NOT NULL DEFAULT current_timestamp(),
  `processed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Inbound 3rd-party webhook callbacks (m029)';

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
-- Struktura tabeli dla tabeli `sh_inventory_docs`
--

CREATE TABLE `sh_inventory_docs` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `warehouse_id` int(11) NOT NULL,
  `doc_number` varchar(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `doc_type` enum('PZ','WZ','RW','IN') CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `supplier_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `supplier_invoice_number` varchar(50) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sh_inventory_docs`
--

INSERT INTO `sh_inventory_docs` (`id`, `tenant_id`, `warehouse_id`, `doc_number`, `doc_type`, `supplier_name`, `supplier_invoice_number`, `created_by`, `created_at`) VALUES
(1, 1, 1, 'fv/12/12/435', 'PZ', '', NULL, NULL, '2026-03-26 21:50:10'),
(5, 1, 1, 'fv/4434/6656/6', 'PZ', 'sde', NULL, NULL, '2026-03-27 00:22:08');

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_inventory_doc_items`
--

CREATE TABLE `sh_inventory_doc_items` (
  `id` int(11) NOT NULL,
  `doc_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` decimal(10,3) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Cena zakupu'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sh_inventory_doc_items`
--

INSERT INTO `sh_inventory_doc_items` (`id`, `doc_id`, `product_id`, `quantity`, `unit_price`) VALUES
(1, 1, 2, 100.000, 0.00),
(2, 1, 1, 200.000, 0.00),
(6, 5, 4, 5.000, 0.00);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_inventory_logs`
--

CREATE TABLE `sh_inventory_logs` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `quantity_changed` decimal(10,3) NOT NULL,
  `action_type` varchar(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sh_inventory_logs`
--

INSERT INTO `sh_inventory_logs` (`id`, `product_id`, `user_id`, `quantity_changed`, `action_type`, `created_at`) VALUES
(1127, 1, 3, 0.273, 'POS_CANCEL_RETURN', '2026-03-30 22:27:39'),
(1128, 2, 3, 0.158, 'POS_CANCEL_RETURN', '2026-03-30 22:27:39'),
(1129, 4, 3, 0.158, 'POS_CANCEL_RETURN', '2026-03-30 22:27:39'),
(1130, 3, 3, 0.210, 'POS_CANCEL_RETURN', '2026-03-30 22:27:39'),
(1131, 1, 3, -0.273, 'POS_SALE', '2026-03-30 22:28:34'),
(1132, 2, 3, -0.158, 'POS_SALE', '2026-03-30 22:28:34'),
(1133, 3, 3, -0.158, 'POS_SALE', '2026-03-30 22:28:34'),
(1134, 1, 3, -0.273, 'POS_SALE', '2026-03-30 22:58:10'),
(1135, 2, 3, -0.208, 'POS_SALE', '2026-03-30 22:58:10'),
(1136, 3, 3, -0.158, 'POS_SALE', '2026-03-30 22:58:10'),
(1137, 1, 3, -0.273, 'POS_SALE', '2026-03-30 23:06:07'),
(1138, 2, 3, -0.158, 'POS_SALE', '2026-03-30 23:06:07'),
(1139, 3, 3, -0.158, 'POS_SALE', '2026-03-30 23:06:07'),
(1140, 1, 3, -0.273, 'POS_SALE', '2026-03-30 23:06:07'),
(1141, 2, 3, -0.158, 'POS_SALE', '2026-03-30 23:06:07'),
(1142, 4, 3, -0.158, 'POS_SALE', '2026-03-30 23:06:07'),
(1143, 3, 3, -0.210, 'POS_SALE', '2026-03-30 23:06:07'),
(1144, 1, 3, -0.273, 'POS_SALE', '2026-03-30 23:06:07'),
(1145, 2, 3, -0.208, 'POS_SALE', '2026-03-30 23:06:07'),
(1146, 3, 3, -0.208, 'POS_SALE', '2026-03-30 23:06:07'),
(1147, 4, 3, -0.158, 'POS_SALE', '2026-03-30 23:06:07'),
(1148, 3, 3, -0.210, 'POS_SALE', '2026-03-30 23:06:07'),
(1149, 1, 3, -0.323, 'POS_SALE', '2026-03-30 23:17:34'),
(1150, 2, 3, -0.208, 'POS_SALE', '2026-03-30 23:17:34'),
(1151, 3, 3, -0.158, 'POS_SALE', '2026-03-30 23:17:34'),
(1152, 1, 3, -0.273, 'POS_SALE', '2026-03-30 23:17:34'),
(1153, 2, 3, -0.158, 'POS_SALE', '2026-03-30 23:17:34'),
(1154, 3, 3, -0.158, 'POS_SALE', '2026-03-30 23:17:34'),
(1155, 1, 3, -0.273, 'POS_SALE', '2026-03-30 23:17:34'),
(1156, 2, 3, -0.158, 'POS_SALE', '2026-03-30 23:17:34'),
(1157, 4, 3, -0.158, 'POS_SALE', '2026-03-30 23:17:34'),
(1158, 3, 3, -0.210, 'POS_SALE', '2026-03-30 23:17:34'),
(1159, 1, 3, -0.273, 'POS_SALE', '2026-03-30 23:30:36'),
(1160, 2, 3, -0.158, 'POS_SALE', '2026-03-30 23:30:36'),
(1161, 3, 3, -0.158, 'POS_SALE', '2026-03-30 23:30:36'),
(1162, 1, 3, -0.273, 'POS_SALE', '2026-03-30 23:30:36'),
(1163, 2, 3, -0.158, 'POS_SALE', '2026-03-30 23:30:36'),
(1164, 4, 3, -0.158, 'POS_SALE', '2026-03-30 23:30:36'),
(1165, 3, 3, -0.210, 'POS_SALE', '2026-03-30 23:30:36'),
(1166, 1, 3, -0.323, 'POS_SALE', '2026-03-30 23:30:36'),
(1167, 2, 3, -0.208, 'POS_SALE', '2026-03-30 23:30:36'),
(1168, 3, 3, -0.208, 'POS_SALE', '2026-03-30 23:30:36'),
(1169, 1, 3, -0.273, 'POS_SALE', '2026-03-30 23:30:36'),
(1170, 1, 3, -0.323, 'POS_SALE', '2026-03-30 23:31:03'),
(1171, 2, 3, -0.208, 'POS_SALE', '2026-03-30 23:31:03'),
(1172, 3, 3, -0.158, 'POS_SALE', '2026-03-30 23:31:03'),
(1173, 1, 3, -0.819, 'POS_SALE', '2026-03-30 23:31:03'),
(1174, 2, 3, -0.473, 'POS_SALE', '2026-03-30 23:31:03'),
(1175, 3, 3, -0.473, 'POS_SALE', '2026-03-30 23:31:03'),
(1176, 4, 3, -0.150, 'POS_SALE', '2026-03-30 23:31:03'),
(1177, 1, 3, -0.273, 'POS_SALE', '2026-03-30 23:31:03'),
(1178, 3, 3, -0.210, 'POS_SALE', '2026-03-30 23:31:03'),
(1179, 1, 3, -0.273, 'POS_SALE', '2026-03-30 23:31:03'),
(1180, 2, 3, -0.208, 'POS_SALE', '2026-03-30 23:31:03'),
(1181, 3, 3, -0.158, 'POS_SALE', '2026-03-30 23:31:03'),
(1182, 1, 3, -0.323, 'POS_SALE', '2026-03-30 23:31:44'),
(1183, 2, 3, -0.208, 'POS_SALE', '2026-03-30 23:31:44'),
(1184, 4, 3, -0.158, 'POS_SALE', '2026-03-30 23:31:44'),
(1185, 3, 3, -0.260, 'POS_SALE', '2026-03-30 23:31:44'),
(1186, 1, 3, -0.273, 'POS_SALE', '2026-03-30 23:31:44'),
(1187, 3, 3, -0.208, 'POS_SALE', '2026-03-30 23:31:44'),
(1188, 1, 3, -0.273, 'POS_SALE', '2026-03-31 06:48:19'),
(1189, 2, 3, -0.158, 'POS_SALE', '2026-03-31 06:48:19'),
(1190, 4, 3, -0.158, 'POS_SALE', '2026-03-31 06:48:19'),
(1191, 3, 3, -0.210, 'POS_SALE', '2026-03-31 06:48:19');

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_item_modifiers`
--

CREATE TABLE `sh_item_modifiers` (
  `item_id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_item_variants`
--

CREATE TABLE `sh_item_variants` (
  `id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `ascii_key` varchar(50) NOT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `display_order` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sh_item_variants`
--

INSERT INTO `sh_item_variants` (`id`, `item_id`, `name`, `ascii_key`, `price`, `is_active`, `display_order`) VALUES
(6, 1, '37 CM', 'EXISTING', 38.00, 1, 0),
(7, 1, '30 CM', 'EXISTING', 31.00, 1, 0),
(9, 14, 'MALA PORCJA', 'VAR_NEW', 18.00, 1, 0);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_marketing_campaigns`
--

CREATE TABLE `sh_marketing_campaigns` (
  `id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `channel_id` int(10) UNSIGNED NOT NULL,
  `template_id` int(10) UNSIGNED NOT NULL,
  `audience_filter_json` longtext DEFAULT NULL COMMENT '{"min_orders":2,"days_since_last":30,"requires_marketing_consent":true}' CHECK (json_valid(`audience_filter_json`) or `audience_filter_json` is null),
  `status` enum('draft','scheduled','running','completed','cancelled') NOT NULL DEFAULT 'draft',
  `scheduled_at` datetime DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `total_audience` int(10) UNSIGNED DEFAULT NULL,
  `sent_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `failed_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `rate_limit_per_hour` smallint(5) UNSIGNED DEFAULT NULL COMMENT 'Nadpisuje limit kanału dla tej kampanii',
  `created_by` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'sh_users.id',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_menu_items`
--

CREATE TABLE `sh_menu_items` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ascii_key` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `vat_rate` decimal(5,2) NOT NULL DEFAULT 8.00,
  `type` enum('standard','half_half','modifier') CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'standard',
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `vat_id` int(11) DEFAULT 1,
  `vat_takeaway_id` int(11) DEFAULT 1,
  `plu_code` varchar(20) DEFAULT NULL,
  `printer_group` varchar(50) DEFAULT 'KITCHEN_1',
  `prep_time` int(11) DEFAULT 15,
  `priority` int(11) DEFAULT 1,
  `unit` varchar(10) DEFAULT 'szt',
  `is_weighted` tinyint(1) DEFAULT 0,
  `tags` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `available_start` time DEFAULT NULL,
  `available_end` time DEFAULT NULL,
  `available_days` varchar(20) DEFAULT '1,2,3,4,5,6,7',
  `stock_count` int(11) DEFAULT -1,
  `badge_type` enum('none','new','promo','bestseller','hot') DEFAULT 'none',
  `is_secret` tinyint(1) DEFAULT 0,
  `video_url` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sh_menu_items`
--

INSERT INTO `sh_menu_items` (`id`, `tenant_id`, `category_id`, `name`, `ascii_key`, `is_active`, `price`, `vat_rate`, `type`, `is_deleted`, `vat_id`, `vat_takeaway_id`, `plu_code`, `printer_group`, `prep_time`, `priority`, `unit`, `is_weighted`, `tags`, `description`, `display_order`, `available_start`, `available_end`, `available_days`, `stock_count`, `badge_type`, `is_secret`, `video_url`) VALUES
(1, 1, 1, 'Margherita', 'ITM_MARGHERITA', 1, 29.00, 8.00, 'standard', 0, 1, 1, NULL, 'KITCHEN_1', 15, 1, 'szt', 0, NULL, NULL, 0, NULL, NULL, '1,2,3,4,5,6,7', -1, 'none', 0, NULL),
(2, 1, 1, 'Capricciosa 32cm', 'ITM_CAPRICCDFIOSA_32CM', 0, 34.00, 8.00, 'standard', 0, 1, 1, NULL, 'KITCHEN_1', 15, 1, 'szt', 0, NULL, NULL, 0, NULL, NULL, '1,2,3,4,5,6,7', -1, 'none', 0, NULL),
(3, 1, 1, 'PIZZA PÓŁ NA PÓŁ', NULL, 1, 0.00, 8.00, 'half_half', 0, 1, 1, NULL, 'KITCHEN_1', 15, 1, 'szt', 0, NULL, NULL, 0, NULL, NULL, '1,2,3,4,5,6,7', -1, 'none', 0, NULL),
(4, 1, 3, 'Margherita Baza TEST', NULL, 1, 25.00, 8.00, 'standard', 0, 1, 1, NULL, 'KITCHEN_1', 15, 1, 'szt', 0, NULL, NULL, 0, NULL, NULL, '1,2,3,4,5,6,7', -1, 'none', 0, NULL),
(5, 1, 3, 'Pepperoni Ostra (Wymaga 86) TEST', NULL, 1, 32.00, 8.00, 'standard', 0, 1, 1, NULL, 'KITCHEN_1', 15, 1, 'szt', 0, NULL, NULL, 0, NULL, NULL, '1,2,3,4,5,6,7', -1, 'none', 0, NULL),
(6, 1, 3, 'Wege Premium (Dużo składników) TEST', NULL, 1, 35.00, 8.00, 'standard', 0, 1, 1, NULL, 'KITCHEN_1', 15, 1, 'szt', 0, NULL, NULL, 0, NULL, NULL, '1,2,3,4,5,6,7', -1, 'none', 0, NULL),
(7, 1, 3, 'Pół na Pół (Kreator) TEST', NULL, 1, 0.00, 8.00, 'half_half', 0, 1, 1, NULL, 'KITCHEN_1', 15, 1, 'szt', 0, NULL, NULL, 0, NULL, NULL, '1,2,3,4,5,6,7', -1, 'none', 0, NULL),
(8, 1, 4, 'Classic Beef (Kuchnia) TEST', NULL, 1, 28.00, 8.00, 'standard', 0, 1, 1, NULL, 'KITCHEN_1', 15, 1, 'szt', 0, NULL, NULL, 0, NULL, NULL, '1,2,3,4,5,6,7', -1, 'none', 0, NULL),
(9, 1, 4, 'Drwal BBQ (Kuchnia) TEST', NULL, 1, 36.00, 8.00, 'standard', 0, 1, 1, NULL, 'KITCHEN_1', 15, 1, 'szt', 0, NULL, NULL, 0, NULL, NULL, '1,2,3,4,5,6,7', -1, 'none', 0, NULL),
(10, 1, 4, 'Vege Burger (Wege) TEST', NULL, 1, 30.00, 8.00, 'standard', 0, 1, 1, NULL, 'KITCHEN_1', 15, 1, 'szt', 0, NULL, NULL, 0, NULL, NULL, '1,2,3,4,5,6,7', -1, 'none', 0, NULL),
(11, 1, 5, 'Cola 0.5L (VAT 23%) TEST', NULL, 1, 8.00, 8.00, 'standard', 0, 1, 1, NULL, 'KITCHEN_1', 15, 1, 'szt', 0, NULL, NULL, 0, NULL, NULL, '1,2,3,4,5,6,7', -1, 'none', 0, NULL),
(12, 1, 5, 'Woda Gazowana (VAT 23%) TEST', NULL, 1, 6.00, 8.00, 'standard', 0, 1, 1, NULL, 'KITCHEN_1', 15, 1, 'szt', 0, NULL, NULL, 0, NULL, NULL, '1,2,3,4,5,6,7', -1, 'none', 0, NULL),
(13, 1, 5, 'Sok Tłoczony (VAT 8%) TEST', NULL, 1, 12.00, 8.00, 'standard', 0, 1, 1, NULL, 'KITCHEN_1', 15, 1, 'szt', 0, NULL, NULL, 0, NULL, NULL, '1,2,3,4,5,6,7', -1, 'none', 0, NULL),
(14, 1, 6, 'Spaghetti', 'ITM_SPAGHETTI', 0, 0.00, 8.00, 'standard', 0, 1, 1, NULL, 'KITCHEN_1', 15, 1, 'szt', 0, NULL, NULL, 0, NULL, NULL, '1,2,3,4,5,6,7', -1, 'none', 0, NULL);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_menu_tags`
--

CREATE TABLE `sh_menu_tags` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `icon` varchar(50) DEFAULT 'fa-tag',
  `color` varchar(20) DEFAULT '#ffffff'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_missions`
--

CREATE TABLE `sh_missions` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `title` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reward_amount` decimal(10,2) DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_mission_proofs`
--

CREATE TABLE `sh_mission_proofs` (
  `id` int(11) NOT NULL,
  `mission_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `status` enum('pending','approved','rejected') CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_modifiers`
--

CREATE TABLE `sh_modifiers` (
  `id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sh_modifiers`
--

INSERT INTO `sh_modifiers` (`id`, `group_id`, `name`, `price`, `is_active`) VALUES
(1, 1, 'sos czosnkowy', 3.50, 1);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_modifier_groups`
--

CREATE TABLE `sh_modifier_groups` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `min_selection` int(11) NOT NULL DEFAULT 0,
  `max_selection` int(11) NOT NULL DEFAULT 10,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sh_modifier_groups`
--

INSERT INTO `sh_modifier_groups` (`id`, `tenant_id`, `name`, `min_selection`, `max_selection`, `is_active`) VALUES
(1, 1, 'Sosy', 0, 10, 1);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_notification_channels`
--

CREATE TABLE `sh_notification_channels` (
  `id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(128) NOT NULL COMMENT 'Przyjazna nazwa np. "Mój telefon Android"',
  `channel_type` varchar(32) NOT NULL,
  `provider` varchar(32) NOT NULL,
  `credentials_json` longtext DEFAULT NULL COMMENT 'Zaszyfrowane dane: host, port, user, pass, token, etc.' CHECK (json_valid(`credentials_json`) or `credentials_json` is null),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `priority` tinyint(3) UNSIGNED NOT NULL DEFAULT 10 COMMENT 'Niższy = wyższy priorytet w fallback chain',
  `rate_limit_per_hour` smallint(5) UNSIGNED DEFAULT NULL COMMENT 'NULL = brak limitu',
  `rate_limit_per_day` smallint(5) UNSIGNED DEFAULT NULL,
  `consecutive_failures` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `paused_until` datetime DEFAULT NULL COMMENT 'Auto-pauza po X błędach (jak WebhookDispatcher)',
  `last_health_check_at` datetime DEFAULT NULL,
  `last_health_status` varchar(16) DEFAULT NULL COMMENT 'ok | error | timeout',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `webhook_secret` varchar(128) DEFAULT NULL COMMENT 'HMAC secret dla incoming webhooks od tego kanału',
  `hmac_algo` varchar(16) NOT NULL DEFAULT 'sha256' COMMENT 'sha256 | sha512',
  `tls_verify` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0 = wyłącz weryfikację TLS (tylko dev)',
  `pii_in_log` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = przechowuj surowy recipient w logach (opt-in, wymaga DPA)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_notification_deliveries`
--

CREATE TABLE `sh_notification_deliveries` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `event_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'FK → sh_event_outbox.id',
  `channel_id` int(10) UNSIGNED NOT NULL COMMENT 'FK → sh_notification_channels.id',
  `event_type` varchar(64) NOT NULL,
  `recipient` varchar(255) NOT NULL COMMENT 'email lub numer telefonu (hashed dla PII w logach)',
  `recipient_hash` char(64) DEFAULT NULL COMMENT 'SHA-256 recipient dla audytu bez ujawniania PII',
  `status` enum('queued','sent','failed','dead') NOT NULL DEFAULT 'queued',
  `attempt_number` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `provider_message_id` varchar(128) DEFAULT NULL COMMENT 'ID wiadomości od providera (dla statusu doręczenia)',
  `provider_status` varchar(32) DEFAULT NULL COMMENT 'SENT | DELIVERED | FAILED itd. z callbacku',
  `error_message` text DEFAULT NULL,
  `cost_grosze` int(11) DEFAULT NULL COMMENT 'Koszt SMS w groszach (dla rozliczenia bramki)',
  `next_attempt_at` datetime DEFAULT NULL,
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `delivered_at` datetime DEFAULT NULL,
  `http_status_code` smallint(5) UNSIGNED DEFAULT NULL COMMENT 'HTTP status code od providera (bez body)',
  `pii_redacted` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = recipient zamazany wg GDPR default'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_notification_routes`
--

CREATE TABLE `sh_notification_routes` (
  `id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `event_type` varchar(64) NOT NULL COMMENT 'order.created | order.accepted | order.ready | order.dispatched | order.delivered | order.cancelled | marketing.campaign',
  `channel_id` int(10) UNSIGNED NOT NULL COMMENT 'FK → sh_notification_channels.id',
  `fallback_order` tinyint(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT '0=pierwszy (primary), 1,2,3... = fallbacki',
  `requires_sms_consent` tinyint(1) NOT NULL DEFAULT 0,
  `requires_marketing_consent` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_notification_templates`
--

CREATE TABLE `sh_notification_templates` (
  `id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `event_type` varchar(64) NOT NULL,
  `channel_type` varchar(32) NOT NULL,
  `lang` char(2) NOT NULL DEFAULT 'pl',
  `subject` varchar(255) DEFAULT NULL COMMENT 'Dla email; dla SMS ignorowane',
  `body` text NOT NULL COMMENT 'Mustache-lite: {{customer_name}}, {{order_number}}, {{eta_minutes}}, {{tracking_url}}, {{store_name}}, {{store_phone}}, {{total_pln}}',
  `variables_json` longtext DEFAULT NULL COMMENT 'Metadata dla edytora: lista dostępnych zmiennych' CHECK (json_valid(`variables_json`) or `variables_json` is null),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sh_notification_templates`
--

INSERT INTO `sh_notification_templates` (`id`, `tenant_id`, `event_type`, `channel_type`, `lang`, `subject`, `body`, `variables_json`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 1, 'order.created', 'email', 'pl', 'Otrzymaliśmy Twoje zamówienie #{{order_number}}', 'Cześć {{customer_name}},\n\nOtrzymaliśmy Twoje zamówienie #{{order_number}} na kwotę {{total_pln}}.\nŚledzimy postęp tutaj: {{tracking_url}}\n\nDzięki, {{store_name}}', NULL, 1, '2026-04-21 00:31:02', NULL),
(2, 1, 'order.created', 'personal_phone', 'pl', NULL, 'Dzięki {{customer_name}}! Twoje zamówienie #{{order_number}} otrzymane. Śledzenie: {{tracking_url}}', NULL, 1, '2026-04-21 00:31:02', NULL),
(3, 1, 'order.created', 'sms_gateway', 'pl', NULL, 'Dzięki {{customer_name}}! Zamówienie #{{order_number}} otrzymane. Link: {{tracking_url}}', NULL, 1, '2026-04-21 00:31:02', NULL),
(4, 1, 'order.accepted', 'email', 'pl', 'Twoje zamówienie #{{order_number}} zaakceptowane!', 'Cześć {{customer_name}},\n\nRestauracja zaakceptowała zamówienie #{{order_number}}.\nSzacowany czas: {{eta_minutes}} min.\nŚledź: {{tracking_url}}\n\n{{store_name}}', NULL, 1, '2026-04-21 00:31:02', NULL),
(5, 1, 'order.accepted', 'personal_phone', 'pl', NULL, '{{store_name}}: zamówienie #{{order_number}} zaakceptowane! Gotowe za ok. {{eta_minutes}} min. Śledzenie: {{tracking_url}}', NULL, 1, '2026-04-21 00:31:02', NULL),
(6, 1, 'order.accepted', 'sms_gateway', 'pl', NULL, '{{store_name}}: zamówienie #{{order_number}} zaakceptowane! Gotowe za {{eta_minutes}} min. {{tracking_url}}', NULL, 1, '2026-04-21 00:31:02', NULL),
(7, 1, 'order.ready', 'personal_phone', 'pl', NULL, '{{store_name}}: zamówienie #{{order_number}} gotowe! Kierowca wyjeżdża za chwilę.', NULL, 1, '2026-04-21 00:31:02', NULL),
(8, 1, 'order.ready', 'sms_gateway', 'pl', NULL, '{{store_name}}: zamówienie #{{order_number}} gotowe do odbioru/wysyłki!', NULL, 1, '2026-04-21 00:31:02', NULL),
(9, 1, 'order.delivered', 'personal_phone', 'pl', NULL, '{{store_name}}: zamówienie #{{order_number}} dostarczono! Smacznego 🍕. Odp. STOP = rezygnacja z SMS.', NULL, 1, '2026-04-21 00:31:02', NULL),
(10, 1, 'order.delivered', 'sms_gateway', 'pl', NULL, '{{store_name}}: zamówienie #{{order_number}} dostarczone. Smacznego! Odp. STOP = rezygnacja z SMS.', NULL, 1, '2026-04-21 00:31:02', NULL),
(11, 1, 'order.cancelled', 'email', 'pl', 'Zamówienie #{{order_number}} anulowane', 'Cześć {{customer_name}},\n\nNiestety zamówienie #{{order_number}} zostało anulowane.\nMasz pytania? Zadzwoń: {{store_phone}}\n\n{{store_name}}', NULL, 1, '2026-04-21 00:31:02', NULL),
(12, 1, 'order.cancelled', 'personal_phone', 'pl', NULL, '{{store_name}}: zamówienie #{{order_number}} anulowane. Przepraszamy! Pytania: {{store_phone}}', NULL, 1, '2026-04-21 00:31:02', NULL),
(13, 1, 'reorder.nudge', 'personal_phone', 'pl', NULL, '{{store_name}}: Hej {{customer_name}}! Mamy dla Ciebie ulubioną pizzę 🍕 Zamów teraz: {{store_url}} Odp. STOP = rezygnacja.', NULL, 1, '2026-04-21 00:31:02', NULL),
(14, 1, 'reorder.nudge', 'sms_gateway', 'pl', NULL, '{{store_name}}: Hej {{customer_name}}! Twoja ulubiona pizza czeka 🍕 Zamów: {{store_url}} STOP - rezygnacja.', NULL, 1, '2026-04-21 00:31:02', NULL);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_orders`
--

CREATE TABLE `sh_orders` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `uuid` varchar(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `order_number` varchar(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source` enum('local','online','kiosk','uber','pyszne') CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'local',
  `gateway_source` varchar(32) DEFAULT NULL COMMENT 'Źródło gdy order przyszedł przez gateway (m027)',
  `gateway_external_id` varchar(128) DEFAULT NULL COMMENT 'external_id z 3rd-party systemu (m027)',
  `type` enum('dine_in','takeaway','delivery') CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'dine_in',
  `status` enum('new','pending','preparing','ready','in_delivery','completed','cancelled') CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'new',
  `document_type` enum('receipt','invoice') CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'receipt',
  `payment_method` enum('cash','card','online','mixed') CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'cash' COMMENT 'Niezbędne do Raportu Dobowego',
  `payment_status` enum('unpaid','paid','refunded') CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'unpaid',
  `nip` varchar(20) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `total_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `table_id` int(11) DEFAULT NULL,
  `driver_id` int(11) DEFAULT NULL,
  `route_id` int(11) DEFAULT NULL,
  `route_order_index` int(11) NOT NULL DEFAULT 0,
  `is_turned_back` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `customer_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_phone` varchar(20) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `promised_time` datetime DEFAULT NULL,
  `accepted_at` datetime DEFAULT NULL,
  `ready_at` datetime DEFAULT NULL,
  `out_for_delivery_at` datetime DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `customer_requested_cancel` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `receipt_printed` tinyint(1) NOT NULL DEFAULT 0,
  `cart_json` text DEFAULT NULL,
  `kitchen_ticket_printed` tinyint(1) NOT NULL DEFAULT 0,
  `edited_since_print` tinyint(1) NOT NULL DEFAULT 0,
  `customer_email` varchar(100) DEFAULT NULL,
  `sms_consent` tinyint(1) NOT NULL DEFAULT 0,
  `marketing_consent` tinyint(1) NOT NULL DEFAULT 0,
  `kitchen_changes` text DEFAULT NULL,
  `course_id` varchar(20) DEFAULT NULL,
  `stop_number` varchar(10) DEFAULT NULL,
  `is_half` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sh_orders`
--

INSERT INTO `sh_orders` (`id`, `tenant_id`, `uuid`, `order_number`, `source`, `gateway_source`, `gateway_external_id`, `type`, `status`, `document_type`, `payment_method`, `payment_status`, `nip`, `total_price`, `table_id`, `driver_id`, `route_id`, `route_order_index`, `is_turned_back`, `created_by`, `customer_name`, `customer_phone`, `address`, `promised_time`, `accepted_at`, `ready_at`, `out_for_delivery_at`, `delivered_at`, `cancelled_at`, `customer_requested_cancel`, `created_at`, `receipt_printed`, `cart_json`, `kitchen_ticket_printed`, `edited_since_print`, `customer_email`, `sms_consent`, `marketing_consent`, `kitchen_changes`, `course_id`, `stop_number`, `is_half`) VALUES
(2, 1, '4d7c1d09-5645-467b-a882-3aa7146abcc7', 'ORD/20260327/014142', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'card', 'paid', NULL, 29.00, NULL, NULL, NULL, 0, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 00:41:42', 0, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(3, 1, '4cecfe2d-5c27-4577-a144-3111942a1472', 'ORD/20260327/022935', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'card', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 01:29:35', 0, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(4, 1, '5e110eca-9b6b-40f9-8e7f-1a90ee52dcb7', 'ORD/20260327/023000', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'card', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 01:30:00', 0, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(5, 1, 'f259ddb2-7f0e-4897-b742-dba9ba68caf8', 'ORD/20260327/023025', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'cash', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 01:30:25', 0, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(6, 1, 'eb334f2e-6e36-468e-8bae-b41cc2a0a1a6', 'ORD/20260327/023049', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'card', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 01:30:49', 0, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(7, 1, 'c9b077e7-9586-4c6a-aadb-e3600af735aa', 'ORD/20260327/023124', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'card', 'paid', '', 63.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 01:31:24', 0, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(8, 1, 'a7cadaa9-1e74-45e2-8d30-8032c547535b', 'ORD/20260327/023148', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'card', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 01:31:48', 0, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(9, 1, '4c9b1853-15bc-41d9-bc5a-8d96b9d93dda', 'ORD/20260327/023205', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'card', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 01:32:05', 0, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(10, 1, '3b888010-4042-4080-81e8-3d5f3c630c15', 'ORD/20260327/023234', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'card', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 01:32:34', 0, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(11, 1, '462e4599-484b-4bc2-a073-c67317a2db79', 'ORD/20260327/023448', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'cash', 'paid', '', 36.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 01:34:48', 0, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(12, 1, '5d1af46f-ed62-4ec3-8143-ea6d97ddf677', 'ORD/20260327/023531', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'card', 'paid', '', 63.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 01:35:31', 0, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(13, 1, '5fa3f8be-95a0-4cff-b01c-8de817d29088', 'ORD/20260327/023650', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'cash', 'paid', '', 36.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 01:36:50', 0, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(14, 1, '2d929a63-cc03-4dcc-b947-13efa5a7afbb', 'ORD/20260327/023756', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'card', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 01:37:56', 0, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(15, 1, 'dceb1ff2-c14a-4be1-ac74-e37a30e33bda', 'ORD/20260327/025054', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'card', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 01:50:54', 0, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(16, 1, 'd153f694-ba76-4984-b973-e0685d6b13a4', 'ORD/20260327/025117', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'card', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 01:51:17', 0, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(17, 1, '30227663-dc22-4af5-af6a-343a853bf345', 'ORD/20260327/143436', 'local', NULL, NULL, 'delivery', 'cancelled', 'receipt', '', 'unpaid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '881439675', 'ul. gen. Henryka Dąbrowskiego 58', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 13:34:36', 0, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(18, 1, 'bd0145b4-9e85-4437-a337-c7b99fe44d8a', 'ORD/20260327/143454', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'card', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 13:34:54', 0, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(19, 1, 'e9dc2118-8edd-480f-b4ee-354f82b2fb8a', 'ORD/20260327/150337', 'local', NULL, NULL, 'delivery', 'cancelled', 'receipt', '', 'unpaid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '881439675', 'ul. gen. Henryka Dąbrowskiego 58', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 14:03:37', 0, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(20, 1, 'd177105a-8d6f-409d-bc24-8d0e4a99b3da', 'ORD/20260327/150358', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'card', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 14:03:58', 0, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(21, 1, 'a3c10d16-9d2f-4a15-9382-7cb9fe8367e3', 'ORD/20260327/150429', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'cash', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 14:04:29', 0, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(22, 1, '4e525d6d-3e05-4d4c-8bfe-da319a76fca7', 'ORD/20260327/150452', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'card', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 14:04:52', 0, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(23, 1, 'bc8f90ec-22e8-47bf-8723-b5e9975cf5e8', 'ORD/20260327/150500', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'cash', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 14:05:00', 0, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(24, 1, '1dc17f30-3e26-4a62-81b5-bcf7322d79e9', 'ORD/20260327/151658', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'card', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 14:16:58', 0, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(25, 1, 'dffff065-941d-4fbc-aacb-10a06b0b3e90', 'ORD/20260327/151720', 'local', NULL, NULL, 'delivery', 'cancelled', 'receipt', '', 'unpaid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '881439675', 'ul. gen. Henryka Dąbrowskiego 58', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 14:17:20', 0, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(26, 1, 'fb2efd53-9fb7-436c-9be8-5be9b6a05b90', 'ORD/20260327/151754', 'local', NULL, NULL, 'delivery', 'completed', 'receipt', 'cash', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '881439675', 'ul. gen. Henryka Dąbrowskiego 58, 7422244969', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 14:17:54', 0, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(27, 1, 'efdd0383-d2d2-400d-b952-071ccdb52e75', 'ORD/20260327/151817', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'card', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 14:18:17', 0, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(28, 1, 'dfe7e23e-54b6-4482-ac0f-0302495b522b', 'ORD/20260327/153557', 'local', NULL, NULL, 'delivery', 'cancelled', 'receipt', '', 'unpaid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '881439675', 'ul. gen. Henryka Dąbrowskiego 58', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 14:35:57', 0, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(29, 1, 'd2cce533-4bed-4cdd-b3e9-fdf4adfbaf76', 'ORD/20260327/153613', 'local', NULL, NULL, 'delivery', 'cancelled', 'receipt', 'cash', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '881439675', 'ul. gen. Henryka Dąbrowskiego 58', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 14:36:13', 0, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(30, 1, '4931a6a6-da4c-4af8-b8e9-2ca3ddf447bf', 'ORD/20260327/153649', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'card', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '881439675', 'ul. gen. Henryka Dąbrowskiego 58', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 14:36:49', 0, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(31, 1, 'e1d69f09-22aa-4ebb-85af-033699e638b2', 'ORD/20260327/155816', 'local', NULL, NULL, 'delivery', 'cancelled', 'receipt', 'card', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '881439675', 'ul. gen. Henryka Dąbrowskiego 58', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 14:58:16', 1, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(32, 1, 'f5586eff-2043-4ad0-8af6-1c047963609a', 'ORD/20260327/155856', 'local', NULL, NULL, 'delivery', 'cancelled', 'receipt', 'card', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '881439675', 'ul. gen. Henryka Dąbrowskiego 58', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 14:58:56', 0, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(33, 1, 'e36faeff-ce27-49ad-b9e2-5f10c8459e78', 'ORD/20260327/155923', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'cash', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '881439675', 'ul. gen. Henryka Dąbrowskiego 58', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 14:59:23', 1, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(34, 1, '66404ae8-1a87-4c8b-8fd8-d8039cdd1c58', 'ORD/20260327/160008', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'card', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '881439675', 'ul. gen. Henryka Dąbrowskiego 58', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 15:00:08', 1, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(35, 1, '2fdde3ba-9737-4077-96c7-127712a80922', 'ORD/20260327/160110', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'cash', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '881439675', 'ul. gen. Henryka Dąbrowskiego 58', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 15:01:10', 0, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(36, 1, '18a50627-0277-4954-bbca-07a3bc7ec7b6', 'ORD/20260327/160230', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'card', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '881439675', 'ul. gen. Henryka Dąbrowskiego 58', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 15:02:30', 0, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(37, 1, 'b36ff635-ebc7-4d50-8a0c-3ad1ae3504f1', 'ORD/20260327/161954', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'card', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 15:19:54', 0, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(38, 1, 'a1ef3978-abe6-4477-ad4c-c12e9cfbbb5a', 'ORD/20260327/162041', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'cash', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 15:20:41', 0, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(39, 1, 'bad52727-b4c4-47a7-8e2f-06c6350e48c8', 'ORD/20260327/162123', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'card', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 15:21:23', 1, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(40, 1, '9bf5d68e-e764-453f-8672-0cf1fdd64ad9', 'ORD/20260327/162223', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'cash', 'paid', '', 65.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 15:22:23', 0, NULL, 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(41, 1, 'bb34294b-2199-4c21-b529-39d167ceb0d4', 'ORD/20260327/165102', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'cash', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 15:51:02', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774626363160}]', 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(42, 1, '84548a0e-0de3-4249-a188-1d00037a2bf2', 'ORD/20260327/165215', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'cash', 'paid', '', 63.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 15:52:15', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774626720160},{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774627920848}]', 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(43, 1, 'a09027c8-2f44-4b0a-8b4b-e080a22c98ac', 'ORD/20260327/171222', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'cash', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 16:12:22', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774627936167}]', 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(44, 1, 'dada667a-2a00-4378-8028-3027c4261f03', 'ORD/20260327/171322', 'local', NULL, NULL, 'dine_in', 'cancelled', 'receipt', '', 'unpaid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 16:13:22', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774627949772}]', 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(45, 1, 'b3567a4d-321e-4bdc-85eb-56fe1406fe23', 'ORD/20260327/172843', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'card', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 16:28:43', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[1],\"added\":[],\"comment\":\"\",\"cart_id\":1774628317988}]', 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(46, 1, '65d51608-8fcf-40e5-9af2-54a495b2070b', 'ORD/20260327/173247', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'card', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', NULL, NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 16:32:47', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774629161127}]', 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(47, 1, '1e38fe96-e1b0-4eeb-8a2a-ff5d45ddbae4', 'ORD/20260327/180312', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'cash', 'paid', '', 63.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '881439675', 'ul. gen. Henryka Dąbrowskiego 58', '2026-03-27 18:52:51', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 17:03:12', 1, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774630928691},{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[2],\"added\":[],\"comment\":\"\",\"cart_id\":1774631015157}]', 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(48, 1, '98afcdbb-4d1b-4636-931a-d71c81151f9a', 'ORD/20260327/180854', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'cash', 'paid', '', 91.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '881439675', 'ul. gen. Henryka Dąbrowskiego 58', '2026-03-27 18:53:54', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 17:08:54', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774631296164},{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"33.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[2],\"comment\":\"\",\"cart_id\":1774631301164},{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[2],\"added\":[],\"comment\":\"\",\"cart_id\":1774631305806}]', 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(49, 1, 'f07b0faf-b13c-421f-94fa-1ae1830b87f4', 'ORD/20260327/180903', 'local', NULL, NULL, 'dine_in', 'cancelled', 'receipt', 'cash', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '881439675', 'ul. gen. Henryka Dąbrowskiego 58', '2026-03-27 18:54:03', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 17:09:03', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774631339379}]', 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(50, 1, 'a731131a-5818-4881-9dd2-bdcdc381f548', 'ORD/20260327/181552', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'cash', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '881439675', 'ul. gen. Henryka Dąbrowskiego 58', '2026-03-27 19:00:52', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 17:15:52', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774631748751}]', 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(51, 1, '0b13cb1b-c288-4af5-bdfd-c8c569ffedae', 'ORD/20260327/181610', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'cash', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '881439675', 'ul. gen. Henryka Dąbrowskiego 58', '2026-03-27 19:01:10', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 17:16:10', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774631764776}]', 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(52, 1, '0b143c61-59d4-4611-b825-e7598da85cf4', 'ORD/20260327/194211', 'local', NULL, NULL, 'takeaway', 'cancelled', 'receipt', '', 'unpaid', '', 63.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-27 19:57:46', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 18:42:11', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774636919688},{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774636943913}]', 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(53, 1, '8ac4518c-aaff-4be3-8c48-8eca848eacd6', 'ORD/20260327/194345', 'local', NULL, NULL, 'takeaway', 'cancelled', 'receipt', '', 'unpaid', '', 759.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-27 19:58:45', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 18:43:45', 1, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774636976469},{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":25,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774637004463}]', 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(54, 1, '85dfb8cd-db53-4cbb-b0e1-7d661ad67f31', 'ORD/20260327/194615', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'cash', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-27 20:01:15', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 18:46:15', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774637144138}]', 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(55, 1, '6ae82900-450c-4d4a-8732-f928d68594dc', 'ORD/20260327/194942', 'local', NULL, NULL, 'takeaway', 'cancelled', 'receipt', 'cash', 'paid', '', 442.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-27 20:04:42', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 18:49:42', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":13,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774637375657}]', 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(56, 1, '298c7f8b-6038-45e1-a783-6d82642530fe', 'ORD/20260327/201853', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'cash', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'Stolik: VIP', '2026-03-27 20:36:04', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 19:18:53', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774639251558}]', 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(57, 1, 'd123dc2d-3d4b-4b45-a9f1-fbc3c074bea9', 'ORD/20260327/202734', 'local', NULL, NULL, 'delivery', 'cancelled', 'receipt', 'card', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '881439675', 'ul. gen. Henryka Dąbrowskiego 58', '2026-03-27 21:27:34', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 19:27:34', 1, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774639641642}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(58, 1, 'effd0830-89ae-4ba8-aecb-850a44061df7', 'ORD/20260327/204420', 'local', NULL, NULL, 'delivery', 'cancelled', 'receipt', '', 'unpaid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '881439675', 'ul. gen. Henryka Dąbrowskiego 58, 7422244969', '2026-03-27 21:29:20', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 19:44:20', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774639702398}]', 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(59, 1, 'aacf1b84-0880-4c60-bad8-7a67af315844', 'ORD/20260327/211202', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'cash', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'Stolik: 2', '2026-03-27 21:12:02', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 20:12:02', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774642282100}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(60, 1, '7edbb791-856b-4633-8dc4-e1a2fd3acae2', 'ORD/20260327/211444', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'card', 'paid', '', 33.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-27 21:14:44', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 20:14:44', 1, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"33.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[4],\"comment\":\"\",\"cart_id\":1774642457630}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(61, 1, 'ee362ef1-678c-4467-aa60-72eb58f53783', 'ORD/20260327/211556', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'card', 'paid', '', 33.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-27 21:40:56', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 20:15:56', 1, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"33.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[4],\"comment\":\"\",\"cart_id\":1774642545570}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(62, 1, 'cbbdd44b-3f46-4070-bec5-2304df262f0e', 'ORD/20260327/211750', 'local', NULL, NULL, 'delivery', 'cancelled', 'receipt', 'cash', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'ul. gen. Henryka Dąbrowskiego 58', '2026-03-27 22:02:50', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 20:17:50', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774642665804}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(63, 1, '6a37f79e-52ce-4ea5-95a2-6052fe3eb76c', 'ORD/20260327/212223', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'card', 'paid', '', 116.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'Stolik: VIP', '2026-03-27 21:57:23', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 20:22:23', 1, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"33.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[2],\"added\":[4],\"comment\":\"\",\"cart_id\":1774642921545},{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"42.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[3,2],\"added\":[4,3],\"comment\":\"\",\"cart_id\":1774642928095},{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"41.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[1,3,2],\"added\":[1,3,4],\"comment\":\"\",\"cart_id\":1774642933145}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(64, 1, 'a47edcf4-0421-44fe-b635-1db76c7051cc', 'ORD/20260327/212409', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'cash', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-27 21:59:09', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 20:24:09', 1, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"khmns\",\"cart_id\":1774643033636}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(65, 1, '428150a8-88ce-49b3-89cc-d2c2f39d7aa2', 'ORD/20260327/212720', 'local', NULL, NULL, 'delivery', 'cancelled', 'receipt', 'cash', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'ul. gen. Henryka Dąbrowskiego 58', '2026-03-27 22:12:20', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 20:27:20', 1, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774643230533}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(66, 1, 'c48ea288-1ff3-49b2-a8a4-4ed5ba1f08a7', 'ORD/20260327/214947', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'cash', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-27 22:04:47', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 20:49:47', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774644574314}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(67, 1, '9360cfa0-4f40-4431-93e4-63a48b828dd9', 'ORD/20260327/215058', 'local', NULL, NULL, 'delivery', 'cancelled', 'receipt', 'cash', 'paid', '425345645', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'ul. gen. Henryka Dąbrowskiego 58, 7422244969', '2026-03-27 22:35:58', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 20:50:58', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774644650161}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(68, 1, '78297aeb-908f-4b68-bcb3-bec71819f234', 'ORD/20260327/215119', 'local', NULL, NULL, 'delivery', 'cancelled', 'receipt', 'cash', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'ul. gen. Henryka Dąbrowskiego 58, 7422244969', '2026-03-27 22:36:19', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 20:51:19', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774644676081}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(69, 1, '69cc5b0d-f1f2-4e92-98d5-3a82958d78f3', 'ORD/20260327/215132', 'local', NULL, NULL, 'delivery', 'cancelled', 'receipt', 'card', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'ul. gen. Henryka Dąbrowskiego 58', '2026-03-27 22:36:32', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 20:51:32', 1, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774644685396}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(70, 1, 'e105ac14-d835-4f7a-8d68-4504aeb850f7', 'ORD/20260327/215145', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'cash', 'paid', '', 42.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'Stolik: VIP', '2026-03-27 22:06:45', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 20:51:45', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"42.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[3,2],\"comment\":\"\",\"cart_id\":1774644699155}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(71, 1, '271aad36-f2c7-40cb-b84b-263f43297107', 'WWW/20260327/220634', 'online', NULL, NULL, 'delivery', 'cancelled', 'receipt', 'online', 'paid', NULL, 29.00, NULL, NULL, NULL, 0, 0, NULL, 'Damian', '881439675', 'ul. gen. Henryka Dąbrowskiego 58', '2026-03-27 22:06:52', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 21:06:34', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774645563349}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(72, 1, '5e30ac2f-0357-48ae-91c8-99b618acda0d', 'WWW/20260327/220822', 'online', NULL, NULL, 'takeaway', 'cancelled', 'receipt', 'cash', 'unpaid', NULL, 29.00, NULL, NULL, NULL, 0, 0, NULL, 'asd', '12312312312', '', '2026-03-27 22:10:12', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 21:08:22', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774645678308}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(73, 1, '30029069-82cc-48e3-be12-1ef81bbebadb', 'WWW/20260327/220906', 'online', NULL, NULL, 'delivery', 'cancelled', 'receipt', 'online', 'paid', NULL, 63.00, NULL, NULL, NULL, 0, 0, NULL, 'asd', '12312312312', 'gjhhgj', '2026-03-27 22:25:21', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 21:09:06', 1, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774645726160},{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774645727711}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(74, 1, 'b2e0123b-00ef-4208-bc8b-b90e8dce5d29', 'WWW/20260327/220953', 'online', NULL, NULL, 'takeaway', 'completed', 'receipt', 'online', 'paid', NULL, 29.00, NULL, NULL, NULL, 0, 0, NULL, 'asd', '12312312312', '', '2026-03-28 01:14:47', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 21:09:53', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774645782512}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(75, 1, '9aa67862-4ec6-4382-ba92-f4b740f41749', 'WWW/20260327/223202', 'online', NULL, NULL, 'takeaway', 'completed', 'receipt', 'online', 'paid', NULL, 29.00, NULL, NULL, NULL, 0, 0, NULL, 'asd', '325436', '', '2026-03-28 01:14:51', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 21:32:02', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774647094613}]', 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(76, 1, '80472c66-6f3b-4b7b-9510-02e3f673e773', 'WWW/20260327/223246', 'online', NULL, NULL, 'takeaway', 'cancelled', 'receipt', 'cash', 'unpaid', NULL, 34.00, NULL, NULL, NULL, 0, 0, NULL, '324', '12312312312', '', '2026-03-27 22:50:05', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 21:32:46', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774647155084}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(77, 1, '6df2873f-9bb9-4e13-9319-4af5c1d2206e', 'WWW/20260327/223523', 'online', NULL, NULL, 'takeaway', 'completed', 'receipt', 'online', 'paid', NULL, 34.00, NULL, NULL, NULL, 0, 0, NULL, 'dsf', '12312312312', '', '2026-03-28 01:14:36', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 21:35:23', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774647296321}]', 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(78, 1, '0534ea68-c4eb-4b63-8f26-bda4cd53a274', 'ORD/20260327/224558', 'local', NULL, NULL, 'delivery', 'cancelled', 'receipt', 'card', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'ul. gen. Henryka Dąbrowskiego 58, 7422244969', '2026-03-27 23:30:58', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 21:45:58', 1, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774647954964}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(79, 1, '1382a7b8-ec3e-4c24-914b-12e4926a8909', 'WWW/20260327/224618', 'online', NULL, NULL, 'delivery', 'cancelled', 'receipt', 'online', 'paid', NULL, 29.00, NULL, NULL, NULL, 0, 0, NULL, '324', '12312312312', 'gjhhgj', '2026-03-28 00:30:48', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 21:46:18', 1, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774647966159}]', 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(80, 1, 'ab47b9fc-abbb-4323-824f-1e93ff706b10', 'WWW/20260328/003529', 'online', NULL, NULL, 'takeaway', 'completed', 'receipt', 'online', 'paid', NULL, 29.00, NULL, NULL, NULL, 0, 0, NULL, 'rt', '88', '', '2026-03-28 03:52:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 23:35:29', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774654509051}]', 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(81, 1, '69bf020e-a06e-49bc-a222-32f5aa819758', 'WWW/20260328/003626', 'online', NULL, NULL, 'delivery', 'completed', 'receipt', 'online', 'paid', NULL, 34.00, NULL, NULL, NULL, 0, 0, NULL, '324', '12312312312', 'gjhhgj', '2026-03-28 04:23:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-27 23:36:26', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774654576443}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(82, 1, '58cce097-30e0-4884-b25d-10aed2d374f5', 'ORD/20260328/011250', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'cash', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-28 01:28:25', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 00:12:50', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774656756672}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(83, 1, 'f0ffb8dc-c9a7-49d5-93d9-2f042620bf36', 'WWW/20260328/012041', 'online', NULL, NULL, 'delivery', 'completed', 'receipt', 'online', 'paid', NULL, 29.00, NULL, NULL, NULL, 0, 0, NULL, 'KATARZYNA', '881439675', 'ul. gen. Henryka Dąbrowskiego 58', '2026-03-28 12:00:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 00:20:41', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774657211623}]', 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(84, 1, '491af623-d5f7-44b3-b0a8-973688b7407a', 'ORD/20260328/032129', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'cash', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-28 03:51:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 02:21:29', 1, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774664468073}]', 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(85, 1, '60c02b0e-ffd7-4819-8fd3-bdb7cf7af533', 'ORD/034456', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'card', 'paid', '', 37.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'Stolik: 1', '2026-03-30 04:34:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 02:44:56', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"37.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[1,2],\"added\":[1,2],\"comment\":\"\",\"cart_id\":1774665837586}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(86, 1, 'a54bd4e9-8fc0-4d86-99c4-8d92abc29efa', 'ORD/20260328/130505', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'cash', 'paid', '', 102.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-28 13:51:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 12:05:05', 1, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774699502643},{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774700488081},{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774700517037}]', 1, 1, NULL, 0, 0, NULL, NULL, NULL, 0),
(87, 1, 'c8d3671f-cce0-4015-b8e4-2513e3d51db0', 'ORD/20260328/130547', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'card', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'Stolik: VIP', '2026-03-28 13:55:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 12:05:47', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774699545534}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(88, 1, '79d6bf8b-3ada-47cb-bf86-d0389588db28', 'ORD/20260328/130622', 'local', NULL, NULL, 'delivery', 'completed', 'receipt', 'card', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'ul. gen. Henryka Dąbrowskiego 58', '2026-03-28 14:06:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 12:06:22', 1, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774699565663}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(89, 1, 'f7c4be7b-0900-4a98-b787-c9a98c4cdf69', 'WWW/20260328/130758', 'online', NULL, NULL, 'delivery', 'completed', 'receipt', 'online', 'paid', NULL, 29.00, NULL, NULL, NULL, 0, 0, NULL, 'dsf', '12312312312', 'gjhhgj', '2026-03-28 14:08:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 12:07:58', 1, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774699668880}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(90, 1, 'ee43328e-7022-4810-948e-2a423df3a434', 'WWW/20260328/131033', 'online', NULL, NULL, 'delivery', 'completed', 'receipt', 'online', 'paid', NULL, 87.00, NULL, NULL, NULL, 0, 0, NULL, '324', '12312312312', 'gjhhgj', '2026-03-28 15:10:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 12:10:33', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774699741742},{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774699817095},{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774699820218}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(91, 1, '96d022ff-bbfb-40c8-b02c-25931caded2b', 'ORD/20260328/132248', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'cash', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-28 13:52:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 12:22:48', 1, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774700560658}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(92, 1, 'f454d635-eb66-4f0c-8a25-ff53f3fe28e1', 'ORD/20260328/132305', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'cash', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-28 13:52:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 12:23:05', 1, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774700576458}]', 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(93, 1, '7fc32900-642e-43c5-aa52-a4fc4ac56572', 'ORD/140644', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'card', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-28 14:36:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 13:06:44', 1, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774703202072}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(94, 1, '787f0a44-e839-44e0-90ea-048892bd04ee', 'ORD/142441', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'cash', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-28 14:54:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 13:24:41', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774704276421}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(95, 1, 'd2bf2917-05bf-48f5-b7a1-65be92b5e7da', 'ORD/142708', 'local', NULL, NULL, 'delivery', 'completed', 'receipt', 'cash', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'ul. gen. Henryka Dąbrowskiego 58', '2026-03-28 15:30:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 13:27:08', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774704394052}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(96, 1, 'c7c4af03-f153-42d3-9ca9-4b05a18bc2e5', 'ORD/143121', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'cash', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-28 14:45:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 13:31:21', 1, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774704604603}]', 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(97, 1, '3a85ca75-27d1-4a3d-828d-3836a9bf6a4b', 'ORD/143208', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'cash', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-28 15:02:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 13:32:08', 1, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774704719139}]', 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(98, 1, '810e18d8-7deb-4dbb-b708-2a6d5c4748a8', 'ORD/143520', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'cash', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-28 15:05:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 13:35:20', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774704917437}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(99, 1, 'c6343c13-aeb2-4611-9cf9-779108bc8ab6', 'ORD/144254', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'cash', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-28 15:13:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 13:42:54', 1, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774705370786}]', 0, 1, NULL, 0, 0, NULL, NULL, NULL, 0),
(100, 1, '3faaef36-cb25-461e-a974-0fbded0f1c67', 'ORD/144545', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'card', 'paid', '', 33.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'Stolik: VIP', '2026-03-28 15:15:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 13:45:45', 1, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"33.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[2],\"added\":[3],\"comment\":\"\",\"cart_id\":1774705518389}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(101, 1, 'a9c0c295-db3c-4744-bc20-e47ba1edac99', 'ORD/144611', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'card', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-28 15:32:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 13:46:11', 1, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774705567648}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(102, 1, 'b6c4d112-7c96-41e0-afa5-6457e584e235', 'ORD/144843', 'local', NULL, NULL, 'delivery', 'completed', 'receipt', 'cash', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'ul. gen. Henryka Dąbrowskiego 58', '2026-03-28 16:02:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 13:48:43', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774705720096}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(103, 1, '97493ec5-f515-4b90-a010-689ecee3bbcb', 'ORD/145335', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'card', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-28 15:23:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 13:53:35', 1, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774706011247}]', 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(104, 1, 'fdc86f76-05b6-4503-bb64-a8d20c0178c0', 'ORD/145530', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'cash', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-28 15:40:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 13:55:30', 1, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774706054719}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(105, 1, 'bba84f80-eb75-4eb1-a642-616f83ca1a0c', 'ORD/145538', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'card', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-28 15:32:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 13:55:38', 1, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774706136104}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(106, 1, '77c58001-0520-4358-9bb4-d1fc7d693a68', 'ORD/151329', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'card', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-28 15:43:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 14:13:29', 1, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774707192868}]', 0, 0, NULL, 0, 0, NULL, NULL, NULL, 0);
INSERT INTO `sh_orders` (`id`, `tenant_id`, `uuid`, `order_number`, `source`, `gateway_source`, `gateway_external_id`, `type`, `status`, `document_type`, `payment_method`, `payment_status`, `nip`, `total_price`, `table_id`, `driver_id`, `route_id`, `route_order_index`, `is_turned_back`, `created_by`, `customer_name`, `customer_phone`, `address`, `promised_time`, `accepted_at`, `ready_at`, `out_for_delivery_at`, `delivered_at`, `cancelled_at`, `customer_requested_cancel`, `created_at`, `receipt_printed`, `cart_json`, `kitchen_ticket_printed`, `edited_since_print`, `customer_email`, `sms_consent`, `marketing_consent`, `kitchen_changes`, `course_id`, `stop_number`, `is_half`) VALUES
(107, 1, 'f6905435-2ed0-4eef-8526-c5f024127b19', 'ORD/151411', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'card', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-28 15:44:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 14:14:11', 1, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774707247871}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(108, 1, 'fe64f6de-d485-4dda-b298-301eebd56bb1', 'ORD/155502', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'cash', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-28 16:10:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 14:55:02', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774709655503}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(109, 1, '997a0562-8213-4cc3-a894-5b8599c6e90f', 'ORD/155527', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'cash', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'Stolik: 2', '2026-03-28 16:25:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 14:55:27', 1, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774709724646}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(110, 1, '5f80a7fc-eb8b-48f6-a610-acf6685f0317', 'ORD/155536', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'cash', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-28 16:27:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 14:55:36', 1, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774709732236}]', 1, 0, NULL, 0, 0, '', NULL, NULL, 0),
(111, 1, '4d0da357-2f46-402b-9f3d-1ff699916f97', 'ORD/155601', 'local', NULL, NULL, 'delivery', 'completed', 'receipt', 'card', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '6454575', 'ul. gen. Henryka Dąbrowskiego 58', '2026-03-28 17:10:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 14:56:01', 1, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774709748993}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(112, 1, '07b10652-3acd-432b-a245-3f46cecda3fe', 'ORD/164016', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'cash', 'paid', '', 45.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'Stolik: VIP', '2026-03-28 17:24:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 15:40:16', 1, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"45.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[1,3,2,4],\"comment\":\"\",\"cart_id\":1774712309340}]', 1, 0, NULL, 0, 0, '', NULL, NULL, 0),
(113, 1, 'e0f66571-c7f3-402c-8bec-197f5d3e9e14', 'ORD/164517', 'local', NULL, NULL, 'delivery', 'completed', 'receipt', 'card', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'dąbrowskiego 58, 64-980 trzcianka', '2026-03-28 17:45:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 15:45:17', 1, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774712707472}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(114, 1, '2f8c208b-6eda-4b14-8014-42bec90abb69', 'WWW/20260328/164644', 'online', NULL, NULL, 'delivery', 'completed', 'receipt', 'online', 'paid', '', 37.00, NULL, NULL, NULL, 0, 0, NULL, 'Damian', '881439675', 'ul. gen. Henryka Dąbrowskiego 58', '2026-03-28 17:49:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 15:46:44', 1, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"37.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[2,3],\"added\":[3,1],\"comment\":\"\",\"cart_id\":1774712786512}]', 1, 0, NULL, 0, 0, '', NULL, NULL, 0),
(115, 1, '3598b265-58ce-429e-8bad-3994e893dcb7', 'ORD/172625', 'local', NULL, NULL, 'delivery', 'completed', 'receipt', 'cash', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, 'fsassfea', 'asdf', '2026-03-28 18:26:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 16:26:25', 1, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774715180781}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(116, 1, 'c1b6aec9-6c2b-4534-afae-407e6b7398b0', 'ORD/172745', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'cash', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-28 17:57:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 16:27:45', 1, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774715260587}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(117, 1, '08d176bf-e760-41ac-b539-073254f21c09', 'WWW/20260328/172948', 'online', NULL, NULL, 'takeaway', 'completed', 'receipt', 'online', 'paid', NULL, 29.00, NULL, NULL, NULL, 0, 0, NULL, 'Damian', '881439675', '', '2026-03-28 20:21:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 16:29:48', 1, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774715382410}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(118, 1, 'd78f19d7-b9f4-47a0-b558-63b141bbea39', 'WWW/20260328/172959', 'online', NULL, NULL, 'delivery', 'completed', 'receipt', 'online', 'paid', NULL, 29.00, NULL, NULL, NULL, 0, 0, NULL, 'Damian', '881439675', 'ul. gen. Henryka Dąbrowskiego 58', '2026-03-28 18:30:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 16:29:59', 1, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774715391414}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(119, 1, '455ef4b3-b153-4d98-b666-02bf45161ab2', 'ORD/173051', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'cash', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-28 21:40:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 16:30:51', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774715448675}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(120, 1, '66db1bf9-d340-48e9-8998-d70eb9501783', 'ORD/225821', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'cash', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-28 23:28:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 21:58:21', 1, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774735097987}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(121, 1, '850fcb03-0278-4331-aacc-0c77005211ee', 'ORD/225842', 'local', NULL, NULL, 'delivery', 'cancelled', 'receipt', 'cash', 'unpaid', '', 46.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'dąbrowskiego 58, 64-980 trzcianka', '2026-03-29 00:06:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 21:58:42', 1, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"46.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[1,3,2],\"comment\":\"\",\"cart_id\":1774735118805}]', 1, 0, NULL, 0, 0, '', NULL, NULL, 0),
(122, 1, 'cecaff8d-6e6e-4c0a-8f91-c99f8a0a0b85', 'ORD/230039', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'cash', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-28 23:30:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 22:00:39', 1, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774735234533}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(123, 1, '265dc91b-55ce-46e1-b2bb-66dddf9dec74', 'ORD/230600', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'card', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'dąbrowskiego 58, 64-980 trzcianka', '2026-03-29 02:55:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-28 22:06:00', 1, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774735547534}]', 1, 0, NULL, 0, 0, '', NULL, NULL, 0),
(124, 1, '6acf7ad3-acd8-462e-a5cf-954768773288', 'ORD/032135', 'local', NULL, NULL, 'delivery', 'completed', 'receipt', 'card', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'ul. gen. Henryka Dąbrowskiego 58', '2026-03-29 04:24:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-29 01:21:35', 1, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[4],\"added\":[],\"comment\":\"\",\"cart_id\":1774747291422}]', 1, 0, NULL, 0, 0, '', NULL, NULL, 0),
(125, 1, '722d8284-d506-4939-87e6-8a53808b3a3d', 'ORD/032413', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'cash', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-29 07:14:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-29 01:24:13', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774747450803}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(126, 1, '6fe88391-60c7-4ce9-8bf5-98db1623d0dd', 'ORD/044521', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'cash', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-29 08:35:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-29 02:45:21', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774752318012}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(127, 1, 'e91ed342-7d33-47b0-89ee-711d64c32d86', 'ORD/044533', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'card', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'Stolik: 2', '2026-03-29 08:35:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-29 02:45:33', 1, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774752329354}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(128, 1, '4a3ea905-3d5d-42b2-ac4a-07863ce3a3dd', 'ORD/044556', 'local', NULL, NULL, 'delivery', 'completed', 'receipt', 'card', 'paid', '', 361.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '6454575', 'ul. gen. Henryka Dąbrowskiego 58', '2026-03-29 05:45:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-29 02:45:56', 1, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"41.00\",\"type\":\"standard\",\"qty\":4,\"removed\":[],\"added\":[4,3,2],\"comment\":\"\",\"cart_id\":1774752341726},{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"42.00\",\"type\":\"standard\",\"qty\":3,\"removed\":[],\"added\":[2,3],\"comment\":\"\",\"cart_id\":1774752345953},{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"33.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[1],\"comment\":\"\",\"cart_id\":1774752349649},{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"38.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[3],\"comment\":\"\",\"cart_id\":1774752351803}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(129, 1, '32ca5ec2-d3d5-4283-85bd-25eacfea2ae4', 'ORD/044606', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'cash', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-29 08:36:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-29 02:46:06', 1, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774752362129}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(130, 1, '1f0bb2b6-19ad-4bdc-b403-6147b14b905c', 'ORD/052153', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'cash', 'paid', '', 73.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '1', '2026-03-29 08:01:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-29 03:21:53', 1, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774754502285},{\"id\":null,\"name\":\"\\u00bd Margherita 32cm + \\u00bd Capricciosa 32cm\",\"price\":\"44.00\",\"qty\":1,\"removed\":[2],\"added\":[1,3],\"comment\":\"\",\"is_half\":true,\"half_a\":1,\"half_b\":2,\"cart_id\":1774754632088}]', 1, 0, NULL, 0, 0, '', NULL, NULL, 0),
(131, 1, '6abaf893-129d-427d-b485-ffaf9dfc8c3a', 'ORD/054119', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'cash', 'paid', '', 33.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '2', '2026-03-29 07:01:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-29 03:41:19', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"33.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[2],\"added\":[1],\"comment\":\"Dobra ma by\\u0107 \",\"cart_id\":1774755657612}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(132, 1, '303c83ba-eb79-40d0-aa4f-0d201a6fda54', 'ORD/060316', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'cash', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'Stolik: 4', '2026-03-29 06:33:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-29 04:03:16', 1, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774756992089}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(133, 1, '8d3d1bfc-084a-4561-806b-250156605962', 'ORD/061514', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'cash', 'paid', '', 38.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'Stolik: VIP', '2026-03-29 06:45:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-29 04:15:14', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"38.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[4],\"comment\":\"\",\"cart_id\":1774757708038}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(134, 1, 'b2b6afa9-160c-4ba8-a7c1-d102629b8cc0', 'ORD/061558', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'card', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-29 15:49:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-29 04:15:58', 1, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[2,4],\"added\":[],\"comment\":\"\",\"cart_id\":1774757754146}]', 1, 0, NULL, 0, 0, '', NULL, NULL, 0),
(135, 1, '169f76c0-efd2-4c76-b7e7-fc067a633589', 'ORD/061616', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'cash', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'Stolik: 2', '2026-03-29 06:46:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-29 04:16:16', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774757772277}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(136, 1, '01c8c27b-6404-4e13-a1b7-d2ff4d2e445d', 'ORD/061634', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'card', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'Stolik: VIP', '2026-03-29 07:06:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-29 04:16:34', 1, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774757789302}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(137, 1, 'cbd456ef-f37d-42f9-ba34-d2ea374fdff8', 'ORD/061917', '', NULL, NULL, 'dine_in', 'cancelled', 'receipt', 'cash', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '1', '2026-03-29 15:17:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-29 04:19:17', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774757948653}]', 1, 0, NULL, 0, 0, '', NULL, NULL, 0),
(138, 1, '0c75803c-79ba-4ebc-8950-6a9ea509f396', 'ORD/062104', '', NULL, NULL, 'dine_in', 'completed', 'receipt', 'cash', 'paid', '', 42.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '2', '2026-03-29 17:28:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-29 04:21:04', 1, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"42.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[3],\"added\":[2,1],\"comment\":\"\",\"cart_id\":1774758053597}]', 1, 0, NULL, 0, 0, '', NULL, NULL, 0),
(139, 1, '94e9496a-05db-40df-8598-670afd3b2dfd', 'ORD/20260329/016', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', '', 'paid', '', 553.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-29 17:43:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-29 15:13:29', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774797171694},{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774797173717},{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774797175245},{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774797177045},{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774797178493},{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774797179909},{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774797181394},{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":4,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774797182641},{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":6,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774797187685}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(140, 1, '34ac01cf-2f34-4ad0-8ef0-ee2371bd6062', 'ORD/20260329/017', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'cash', 'paid', '', 284.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'Stolik: 1', '2026-03-29 21:48:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-29 15:14:17', 1, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":4,\"removed\":[4,2],\"added\":[],\"comment\":\"\",\"cart_id\":1774797233084},{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774797238401},{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"38.00\",\"type\":\"standard\",\"qty\":3,\"removed\":[4,2],\"added\":[3],\"comment\":\"\",\"cart_id\":1774797240989}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(141, 1, '43b76bb1-2e60-4a2f-b67a-f57960c6be1c', 'ORD/20260329/018', 'local', NULL, NULL, 'delivery', 'completed', 'receipt', 'card', 'paid', '', 160.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '6454575', 'ul. gen. Henryka Dąbrowskiego 58', '2026-03-29 18:14:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-29 15:14:46', 1, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774797273380},{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774797275729},{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774797277061},{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774797278381},{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774797279546}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(142, 1, '96244da5-019d-4582-bb7c-0efcbb6ce131', 'ORD/20260329/019', 'local', NULL, NULL, 'delivery', 'completed', 'receipt', '', 'paid', '', 34.00, NULL, NULL, 3, 0, 0, NULL, NULL, '6454575', 'ul. gen. Henryka Dąbrowskiego 58', '2026-03-29 18:15:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-29 15:15:03', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774797301265}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(143, 1, 'd79d206e-817e-48ff-8333-139963ee27c2', 'ORD/20260329/020', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'cash', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-29 17:45:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-29 15:15:28', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774797322481}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(144, 1, '196a7c7b-d9db-4fc1-98a6-14233e92cb62', 'ORD/20260329/021', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', '', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-29 17:46:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-29 15:16:16', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774797372829}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(145, 1, '8cfc2d6b-6698-46a2-b136-9bb78bbfe664', 'ORD/20260329/022', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', '', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-29 17:46:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-29 15:16:23', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774797380677}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(146, 1, '96b19e71-0b98-41bd-ba26-ed89e153a363', 'ORD/20260329/023', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', '', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-29 17:46:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-29 15:16:34', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774797392127}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(147, 1, '6afd9c62-3890-4b44-a9c9-ec51151774f9', 'ORD/20260329/024', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', '', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-29 17:46:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-29 15:16:42', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774797398372}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(148, 1, '4fa78143-7540-4e1c-8dd8-bd7f8f3f985f', 'ORD/20260329/025', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', '', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-29 17:46:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-29 15:16:48', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774797405985}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(149, 1, '0a018516-6a3c-4cb0-9b57-e57c7c661ae2', 'ORD/20260329/026', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'cash', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-29 21:22:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-29 19:08:19', 1, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774811200143}]', 1, 0, NULL, 0, 0, '', NULL, NULL, 0),
(150, 1, 'c80933e0-ab77-4477-a2d9-1a534e81b293', 'ORD/20260329/027', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', '', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-29 22:09:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-29 19:09:44', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774811139620}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(151, 1, 'c450fd7a-a158-4f9e-b336-caa44ac7ec66', 'ORD/20260329/028', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'card', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'Stolik: 1', '2026-03-29 21:24:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-29 19:10:00', 1, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[4],\"added\":[],\"comment\":\"\",\"cart_id\":1774811392118}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(152, 1, 'e1bf9c7a-ef0f-4a04-9c8e-0198ac6a4629', 'ORD/20260329/029', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', '', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-31 21:13:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-29 19:14:05', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774811628412}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(153, 1, '41034378-934f-403c-a7cf-864d3f132db4', 'ORD/20260329/030', 'local', NULL, NULL, 'delivery', 'completed', 'receipt', '', 'paid', '', 29.00, NULL, NULL, 3, 1, 0, NULL, NULL, '', 'ul. gen. Henryka Dąbrowskiego 58', '2026-03-29 22:14:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-29 19:14:32', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774811669085}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(154, 1, '7306bd58-a063-43b0-a4f6-cc456dedbdc3', 'ORD/20260329/031', 'local', NULL, NULL, 'delivery', 'completed', 'receipt', '', 'paid', '', 34.00, NULL, NULL, 3, 2, 0, NULL, NULL, '', 'ul. gen. Henryka Dąbrowskiego 58', '2026-03-29 22:19:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-29 19:19:38', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774811974032}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(155, 1, 'a3c65ed6-815a-46b8-8740-4ea00a58ae74', 'ORD/20260329/032', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', '', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-29 22:41:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-29 20:11:39', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774815024107}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(156, 1, '02b51d24-7314-4187-8252-cab2f335b576', 'ORD/20260329/033', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', '', 'paid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-30 00:14:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-29 21:44:04', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[4],\"added\":[],\"comment\":\"\",\"cart_id\":1774820637531}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(157, 1, 'e6fee73a-a051-4562-ad10-090079481431', 'ORD/20260329/034', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', '', 'paid', '', 67.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'Stolik: 4', '2026-03-30 00:14:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-29 21:44:23', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774820651671},{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"33.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[2],\"comment\":\"\",\"cart_id\":1774820653964}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(158, 1, 'a445cd4e-b994-467c-a4c4-663f5f417935', 'ORD/20260330/001', 'local', NULL, NULL, 'delivery', 'completed', 'receipt', '', 'paid', '', 29.00, NULL, NULL, 3, 3, 0, NULL, NULL, '', 'Os  Słowackiego 29/5, 64-980 Trzcianka', '2026-03-30 01:11:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-29 22:10:59', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774822254421}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(159, 1, '607a5ffd-785d-4ab8-af36-4a1482adfc08', 'ORD/20260330/002', 'local', NULL, NULL, 'delivery', 'completed', 'receipt', '', 'paid', '', 223.00, NULL, NULL, 3, 4, 0, NULL, NULL, '', '', '2026-03-30 01:07:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-29 23:09:07', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"cart_id\":1774825706207},{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"cart_id\":1774825707085},{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"cart_id\":1774825707361},{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"cart_id\":1774825707645},{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"cart_id\":1774825707920},{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"cart_id\":1774825708170},{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"cart_id\":1774825708391}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(160, 1, 'fcde313f-e15f-47d6-b709-6fce5ba73361', 'ORD/20260330/003', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'card', 'paid', '', 37.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'Stolik: VIP', '2026-03-30 05:31:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 01:21:36', 1, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"37.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[3],\"added\":[4,2],\"comment\":\"\",\"cart_id\":1774833666981}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(161, 1, '64e9f0ee-5f82-4c78-bbce-076482544bd8', 'ORD/20260330/004', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'card', 'paid', '', 181.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-30 06:07:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 01:22:43', 1, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"42.00\",\"type\":\"standard\",\"qty\":2,\"removed\":[],\"added\":[1,2],\"comment\":\"\",\"cart_id\":1774833717296},{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774833722366},{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774833723590},{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774833726741}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(162, 1, '478a7ea4-6ffe-45ff-8cb2-7a39f9bf006e', 'ORD/20260330/005', 'local', NULL, NULL, 'delivery', 'completed', 'receipt', 'card', 'paid', '5925295', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '6454575', 'ul. gen. Henryka Dąbrowskiego 58', '2026-03-30 04:53:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 01:23:22', 1, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774833768842}]', 1, 0, NULL, 0, 0, NULL, 'K2', 'L4', 0),
(163, 1, '86a7f34b-079b-41fc-a001-0a4862e797a9', 'ORD/20260330/006', 'local', NULL, NULL, 'delivery', 'completed', 'receipt', 'cash', 'paid', '', 67.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '88554553', 'ul. gen. Henryka Dąbrowskiego 32', '2026-03-30 05:23:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 01:23:56', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"33.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[2],\"comment\":\"\",\"cart_id\":1774833815763},{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774833826159}]', 1, 0, NULL, 0, 0, NULL, 'K2', 'L3', 0),
(164, 1, '2364c92b-3e65-4f7b-99b8-1cb3d554a906', 'WWW/20260330/032444', 'online', NULL, NULL, 'takeaway', 'completed', 'receipt', 'online', 'paid', NULL, 29.00, NULL, NULL, NULL, 0, 0, NULL, '324', '12312312312', '', '2026-03-30 05:04:44', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 01:24:44', 1, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774833873153}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(165, 1, '801df4cb-40d0-4b8c-8cd2-658423715268', 'WWW/20260330/032522', 'online', NULL, NULL, 'delivery', 'cancelled', 'receipt', 'cash', 'unpaid', NULL, 29.00, NULL, NULL, NULL, 0, 0, NULL, 'dsf', '12312312312', 'gjhhgj', '2026-03-30 20:00:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 01:25:22', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774833910573}]', 1, 0, NULL, 0, 0, NULL, 'K2', 'L1', 0),
(166, 1, '95ca4b85-0fc0-494d-9223-265cabec4263', 'WWW/20260330/042016', 'online', NULL, NULL, 'delivery', 'completed', 'receipt', 'online', 'paid', NULL, 29.00, NULL, NULL, NULL, 0, 0, NULL, '324', '12312312312', 'ul. lelewawaela 24 / 4 64-980 trzcianka', '2026-03-30 12:46:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 02:20:16', 1, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774837175995}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(167, 1, 'c3da745c-3d84-4fcc-88ce-4ae35add31a0', 'WWW/20260330/042031', 'online', NULL, NULL, 'takeaway', 'completed', 'receipt', 'online', 'paid', NULL, 34.00, NULL, NULL, NULL, 0, 0, NULL, '324', '12312312312', '', '2026-03-30 12:46:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 02:20:31', 1, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774837220671}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(168, 1, '7e68d6b3-f7ed-457b-bc0e-414080991bbd', 'ORD/20260330/011', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'card', 'paid', '', 38.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', '', '2026-03-30 05:22:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 02:32:58', 1, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"38.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[2],\"added\":[3],\"comment\":\"\",\"cart_id\":1774837815490}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(169, 1, 'e3b71f37-7b85-42ff-aab2-8b496b4e7e96', 'ORD/20260330/012', 'local', NULL, NULL, 'delivery', 'completed', 'receipt', 'card', 'paid', '', 136.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '111', 'sgfhdgfjhfdASFDGF', '2026-03-30 05:41:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 02:41:49', 1, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":4,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774838486333}]', 1, 0, NULL, 0, 0, NULL, 'K2', 'L2', 0),
(170, 1, '6b2880d9-3849-49dd-8090-445e9c2d559f', 'ORD/20260330/013', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'cash', 'paid', '', 33.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'Stolik: VIP', '2026-03-30 13:17:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 10:47:54', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"33.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[2],\"added\":[1],\"comment\":\"\",\"cart_id\":1774867648821}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(171, 1, 'a989ae7f-77ba-4f32-9643-fa6ceda8a6fe', 'ORD/20260330/014', 'local', NULL, NULL, 'delivery', 'cancelled', 'receipt', '', 'unpaid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, 'asd', 'asd', '2026-03-30 13:51:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 10:51:11', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[2],\"added\":[],\"comment\":\"\",\"cart_id\":1774867863754}]', 1, 0, NULL, 0, 0, NULL, 'K2', 'L1', 0),
(172, 1, 'c2d54330-bdc8-4b3d-b716-486addf08978', 'ORD/20260330/015', 'local', NULL, NULL, 'delivery', 'cancelled', 'receipt', '', 'unpaid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '88554553', 'ul. gen. Henryka Dąbrowskiego 58, 7422244969', '2026-03-30 13:51:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 10:51:26', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774867882727}]', 1, 0, NULL, 0, 0, NULL, 'K2', 'L2', 0),
(173, 1, '44e0179b-8e4d-4905-8921-332de1e8714a', 'ORD/20260330/016', 'local', NULL, NULL, 'delivery', 'cancelled', 'receipt', 'cash', 'unpaid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'os. słowackiego 29/19', '2026-04-02 21:50:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 16:40:59', 1, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774888846089}]', 1, 0, NULL, 0, 0, '', 'K2', 'L1', 0),
(174, 1, 'a955d8d2-6ecf-47a3-aedf-96876fea18c7', 'ORD/20260330/017', 'local', NULL, NULL, 'delivery', 'cancelled', 'receipt', '', 'unpaid', '', 234.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '881439675', 'os. słowackiego 29/19', '2026-03-30 19:54:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 16:42:45', 1, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"37.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[2],\"added\":[2,1],\"comment\":\"\",\"cart_id\":1774888930839},{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":3,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774888935631},{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":2,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774888937903},{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"37.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[2,3],\"comment\":\"\",\"cart_id\":1774889471400}]', 1, 0, NULL, 0, 0, '', 'K2', 'L3', 0),
(175, 1, 'c2f33d74-5292-484e-9d9a-f6e86507d064', 'ORD/20260330/018', 'local', NULL, NULL, 'dine_in', 'completed', 'receipt', 'card', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'Stolik: VIP', '2026-03-30 19:12:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 16:43:02', 1, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774888974683}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(176, 1, 'bf47accf-5a58-4fb4-bbd0-bd6b6bbd99c2', 'ORD/20260330/019', 'local', NULL, NULL, 'dine_in', 'cancelled', 'receipt', '', 'unpaid', '7422244969', 247.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'Stolik: 1', '2026-03-30 20:00:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 16:43:48', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774888990916},{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":5,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774888992762},{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774888995047},{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774888997319}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(177, 1, '57a1a722-3a18-41ec-8f83-75697595cdd4', 'ORD/20260330/020', 'local', NULL, NULL, 'delivery', 'cancelled', 'receipt', '', 'unpaid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'os. słowackiego 29/19', '2026-03-30 19:54:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 16:44:58', 1, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774889053149}]', 0, 0, NULL, 0, 0, '', 'K2', 'L2', 0),
(178, 1, '6957bd4e-baf2-4c5c-86d8-d7bfbd836d4e', 'ORD/20260330/021', 'local', NULL, NULL, 'delivery', 'cancelled', 'receipt', '', 'unpaid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'os. poniatowskiego 3', '2026-03-30 19:47:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 16:48:03', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774889254689}]', 1, 0, NULL, 0, 0, NULL, 'K2', 'L4', 0),
(179, 1, '4736a9ec-080b-46fa-92d1-23b3dcd44807', 'ORD/20260330/022', 'local', NULL, NULL, 'delivery', 'cancelled', 'receipt', '', 'unpaid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'os. słowackiego 29/19', '2026-03-30 20:54:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 17:54:08', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774893246437}]', 1, 0, NULL, 0, 0, NULL, 'K2', 'L1', 0),
(180, 1, 'e60e0335-5897-4187-9504-ae9a23b44f85', 'ORD/20260330/023', 'local', NULL, NULL, 'delivery', 'cancelled', 'receipt', '', 'unpaid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '222222', 'trzciankafaf', '2026-03-30 21:31:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 18:31:25', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774895481541}]', 1, 0, NULL, 0, 0, NULL, 'K2', 'L2', 0),
(181, 1, 'a82d0f99-93a3-40d8-8005-e5858a34c72a', 'ORD/20260330/024', 'local', NULL, NULL, 'dine_in', 'cancelled', 'receipt', '', 'unpaid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'Stolik: VIP', '2026-03-30 21:03:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 18:33:59', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774895637430}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(182, 1, '33e6a7bb-dc87-4dd9-83e4-34a0409a8ef2', 'ORD/20260330/025', 'local', NULL, NULL, 'delivery', 'cancelled', 'receipt', '', 'unpaid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '22222242', 'Stolik: VIP', '2026-03-30 20:49:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 18:34:44', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774895664966}]', 1, 0, NULL, 0, 0, NULL, 'K2', 'L1', 0),
(183, 1, 'b8b4cae2-eefa-4541-bb3a-4856007e04fa', 'ORD/20260330/026', 'local', NULL, NULL, 'delivery', 'cancelled', 'receipt', '', 'unpaid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '22222242', 'ul. słoneczna 1', '2026-03-30 22:16:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 19:16:21', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774898156470}]', 1, 0, NULL, 0, 0, NULL, 'K2', 'L1', 0),
(184, 1, '7f7f3ef2-647d-4d5a-9542-7268bbfd87ed', 'ORD/20260330/027', 'local', NULL, NULL, 'delivery', 'cancelled', 'receipt', '', 'unpaid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '1111111111111', 'ul. słoneczna 2', '2026-03-30 22:16:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 19:16:49', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774898186331}]', 1, 0, NULL, 0, 0, NULL, 'K2', 'L2', 0),
(185, 1, 'd7ea1deb-6187-4b80-8087-832107c76bd4', 'ORD/20260330/028', 'local', NULL, NULL, 'delivery', 'completed', 'receipt', 'card', 'paid', '', 38.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '', 'ul. słoneczna 1', '2026-03-30 22:59:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 19:59:42', 1, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"38.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[3],\"comment\":\"\",\"cart_id\":1774900773527}]', 1, 0, NULL, 0, 0, NULL, 'K2', 'L1', 0),
(186, 1, 'd5f9f6cf-dd3d-448b-8e9d-5606f824066e', 'ORD/20260330/029', 'local', NULL, NULL, 'delivery', 'cancelled', 'receipt', '', 'unpaid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '222222113', 'ul asda', '2026-03-30 23:22:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 20:19:54', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774901974378}]', 1, 0, NULL, 0, 0, NULL, 'K2', 'L1', 0),
(187, 1, 'c8399dc1-95e0-40d2-baf3-4c76dea995c8', 'ORD/20260330/030', 'local', NULL, NULL, 'delivery', 'cancelled', 'receipt', '', 'unpaid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '325345', 'ul asda', '2026-03-30 23:21:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 20:21:22', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774902074939}]', 1, 0, NULL, 0, 0, NULL, 'K2', 'L1', 0),
(188, 1, 'ace93ea2-d94e-49b8-926f-7445dff8d970', 'ORD/20260330/031', 'local', NULL, NULL, 'delivery', 'cancelled', 'receipt', '', 'unpaid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '12t2', '12rt', '2026-03-30 23:25:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 20:25:08', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774902302472}]', 1, 0, NULL, 0, 0, NULL, 'K2', 'L2', 0),
(189, 1, 'e07c6d2b-7dbe-4154-b075-c3dcb1fc226b', 'ORD/20260330/032', 'local', NULL, NULL, 'delivery', 'completed', 'receipt', 'card', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '6454575', 'ul. gen. Henryka Dąbrowskiego 58', '2026-03-30 23:29:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 20:29:09', 1, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774902542860}]', 1, 0, NULL, 0, 0, NULL, 'K2', 'L1', 0);
INSERT INTO `sh_orders` (`id`, `tenant_id`, `uuid`, `order_number`, `source`, `gateway_source`, `gateway_external_id`, `type`, `status`, `document_type`, `payment_method`, `payment_status`, `nip`, `total_price`, `table_id`, `driver_id`, `route_id`, `route_order_index`, `is_turned_back`, `created_by`, `customer_name`, `customer_phone`, `address`, `promised_time`, `accepted_at`, `ready_at`, `out_for_delivery_at`, `delivered_at`, `cancelled_at`, `customer_requested_cancel`, `created_at`, `receipt_printed`, `cart_json`, `kitchen_ticket_printed`, `edited_since_print`, `customer_email`, `sms_consent`, `marketing_consent`, `kitchen_changes`, `course_id`, `stop_number`, `is_half`) VALUES
(190, 1, 'bc52a701-3a36-430d-a983-13329dc64c86', 'ORD/20260330/033', 'local', NULL, NULL, 'delivery', 'cancelled', 'receipt', '', 'unpaid', '', 34.00, NULL, NULL, NULL, 0, 0, NULL, NULL, '111', 'ul. gen. Henryka Dąbrowskiego 58, 7422244969', '2026-03-31 00:41:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 20:41:27', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774903279892}]', 1, 0, NULL, 0, 0, NULL, 'K2', 'L1', 0),
(191, 1, '2436dc92-aa61-4c34-99b3-c7e552b411f1', 'ORD/20260331/001', 'local', NULL, NULL, 'delivery', 'completed', 'receipt', 'cash', 'paid', '', 29.00, NULL, NULL, NULL, 0, 0, 3, NULL, '11', 'Slowak', '2026-03-31 01:28:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 22:28:34', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774909693689}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(192, 1, 'ffa50e11-fa20-4f50-8bdc-319a9a402515', 'ORD/20260331/002', 'local', NULL, NULL, 'takeaway', 'completed', 'receipt', 'cash', 'paid', '', 33.00, NULL, NULL, NULL, 0, 0, 3, NULL, '', '', '2026-03-31 01:28:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 22:58:10', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"33.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[2],\"comment\":\"\",\"cart_id\":1774911479677}]', 1, 0, NULL, 0, 0, '', NULL, NULL, 0),
(193, 1, 'ea56b72e-5d3b-4c7c-8caa-9f8aef4f7285', 'ORD/20260331/003', 'local', NULL, NULL, 'delivery', 'cancelled', 'receipt', '', 'unpaid', '', 134.00, NULL, NULL, NULL, 0, 0, 3, NULL, '123123123123', 'ul. gen. Henryka Dąbrowskiego 58', '2026-03-31 03:06:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 23:06:07', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774911941371},{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774911943315},{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"37.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[3,2],\"comment\":\"\",\"cart_id\":1774911944928},{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[1,2],\"added\":[],\"comment\":\"\",\"cart_id\":1774911947778}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0),
(194, 1, '2b010930-e02a-49d7-9a63-7ab97f47cff9', 'ORD/20260331/004', 'local', NULL, NULL, 'delivery', 'in_delivery', 'receipt', 'cash', 'paid', '', 100.00, NULL, 6, NULL, 0, 0, 3, NULL, '123123123', 'osiedle podrzwiowe 2', '2026-03-31 02:17:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 23:17:34', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"37.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[2,1],\"comment\":\"\",\"cart_id\":1774912599775},{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774912602600},{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774912604292}]', 1, 0, NULL, 0, 0, NULL, 'K2', 'L1', 0),
(195, 1, 'a0b662fc-55dd-439c-9bb6-83fad444005e', 'ORD/20260331/005', 'local', NULL, NULL, 'delivery', 'in_delivery', 'receipt', '', 'unpaid', '', 138.00, NULL, 6, NULL, 0, 0, 3, NULL, '122221122', 'os. słowackiego 29/19', '2026-03-31 02:30:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 23:30:36', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774913396477},{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774913421886},{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"41.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[1,2,3],\"comment\":\"\",\"cart_id\":1774913423397},{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[2,3,4],\"added\":[],\"comment\":\"\",\"cart_id\":1774913427214}]', 1, 0, NULL, 0, 0, NULL, 'K2', 'L1', 0),
(196, 1, '430bcb21-8410-48cc-9c89-46e6b3e6c1a2', 'ORD/20260331/006', 'local', NULL, NULL, 'delivery', 'in_delivery', 'receipt', 'card', 'paid', '', 203.00, NULL, 6, NULL, 0, 0, 3, NULL, '1212', 'kocham cie', '2026-03-31 02:30:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 23:31:03', 1, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"37.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[1,2],\"comment\":\"\",\"cart_id\":1774913441204},{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"33.00\",\"type\":\"standard\",\"qty\":3,\"removed\":[],\"added\":[4],\"comment\":\"\",\"cart_id\":1774913444291},{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[2,4],\"added\":[],\"comment\":\"\",\"cart_id\":1774913447505},{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"33.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[2],\"comment\":\"\",\"cart_id\":1774913450223}]', 1, 0, NULL, 0, 0, NULL, 'K2', 'L3', 0),
(197, 1, 'c0ad8c17-dac1-4376-ba5e-65bc8d5a0406', 'ORD/20260331/007', 'local', NULL, NULL, 'delivery', 'in_delivery', 'receipt', 'card', 'paid', '', 79.00, NULL, 6, NULL, 0, 0, 3, NULL, '', 'osiedle podrzwiowe 2', '2026-03-31 02:31:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 23:31:44', 1, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"46.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[1,2,3],\"comment\":\"\",\"cart_id\":1774913468140},{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"33.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[2],\"added\":[3],\"comment\":\"\",\"cart_id\":1774913473760}]', 1, 0, NULL, 0, 0, NULL, 'K2', 'L2', 0),
(198, 1, '16adec57-5c36-4bfa-be83-2484a4cbfa48', 'WWW/20260331/013910', 'online', NULL, NULL, 'delivery', 'in_delivery', 'receipt', 'online', 'paid', NULL, 29.00, NULL, 6, NULL, 0, 0, NULL, 'asdasdas', '2222222', 'aaaqa', '2026-03-31 01:39:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-30 23:39:10', 0, '[{\"id\":1,\"category_id\":1,\"name\":\"Margherita 32cm\",\"price\":\"29.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774913936396}]', 1, 0, NULL, 0, 0, NULL, 'K2', 'L1', 0),
(199, 1, 'd3a26e35-d11d-47ea-809d-03f8880660ea', 'ORD/20260331/009', 'local', NULL, NULL, 'takeaway', 'pending', 'receipt', '', 'unpaid', '', 34.00, NULL, NULL, NULL, 0, 0, 3, NULL, '', '', '2026-03-31 09:18:00', NULL, NULL, NULL, NULL, NULL, 0, '2026-03-31 06:48:19', 0, '[{\"id\":2,\"category_id\":1,\"name\":\"Capricciosa 32cm\",\"price\":\"34.00\",\"type\":\"standard\",\"qty\":1,\"removed\":[],\"added\":[],\"comment\":\"\",\"cart_id\":1774939696258}]', 1, 0, NULL, 0, 0, NULL, NULL, NULL, 0);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_order_audit`
--

CREATE TABLE `sh_order_audit` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `new_value` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_order_items`
--

CREATE TABLE `sh_order_items` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `menu_item_id` int(11) DEFAULT NULL,
  `snapshot_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT 1.00,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `vat_rate` decimal(5,2) NOT NULL DEFAULT 8.00,
  `is_half` tinyint(1) NOT NULL DEFAULT 0,
  `half_a_id` int(11) DEFAULT NULL,
  `half_b_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sh_order_items`
--

INSERT INTO `sh_order_items` (`id`, `order_id`, `menu_item_id`, `snapshot_name`, `quantity`, `unit_price`, `vat_rate`, `is_half`, `half_a_id`, `half_b_id`) VALUES
(1, 2, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(2, 3, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(3, 4, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(4, 5, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(5, 6, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(6, 7, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(7, 7, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(8, 8, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(9, 9, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(10, 10, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(11, 11, NULL, '½ Margherita 32cm + ½ Capricciosa 32cm', 1.00, 36.00, 8.00, 0, NULL, NULL),
(12, 12, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(13, 12, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(14, 13, NULL, '½ Margherita 32cm + ½ Capricciosa 32cm', 1.00, 36.00, 8.00, 0, NULL, NULL),
(15, 14, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(16, 15, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(17, 16, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(18, 17, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(19, 18, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(20, 19, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(21, 20, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(22, 21, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(23, 22, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(24, 23, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(25, 24, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(26, 25, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(27, 26, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(28, 27, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(29, 28, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(30, 29, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(31, 30, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(32, 31, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(33, 32, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(34, 33, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(35, 34, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(36, 35, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(37, 36, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(38, 37, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(39, 38, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(40, 39, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(41, 40, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(42, 40, NULL, '½ Margherita 32cm + ½ Capricciosa 32cm', 1.00, 36.00, 8.00, 0, NULL, NULL),
(43, 41, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(45, 42, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(46, 42, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(47, 43, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(48, 44, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(49, 45, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(50, 46, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(52, 47, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(53, 47, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(54, 48, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(55, 48, 1, 'Margherita 32cm', 1.00, 33.00, 8.00, 0, NULL, NULL),
(56, 48, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(57, 49, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(58, 50, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(59, 51, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(61, 52, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(62, 52, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(63, 53, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(64, 53, 1, 'Margherita 32cm', 25.00, 29.00, 8.00, 0, NULL, NULL),
(65, 54, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(66, 55, 2, 'Capricciosa 32cm', 13.00, 34.00, 8.00, 0, NULL, NULL),
(68, 56, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(69, 57, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(70, 58, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(71, 59, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(72, 60, 1, 'Margherita 32cm', 1.00, 33.00, 8.00, 0, NULL, NULL),
(73, 61, 1, 'Margherita 32cm', 1.00, 33.00, 8.00, 0, NULL, NULL),
(74, 62, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(75, 63, 1, 'Margherita 32cm', 1.00, 33.00, 8.00, 0, NULL, NULL),
(76, 63, 2, 'Capricciosa 32cm', 1.00, 42.00, 8.00, 0, NULL, NULL),
(77, 63, 1, 'Margherita 32cm', 1.00, 41.00, 8.00, 0, NULL, NULL),
(78, 64, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(79, 65, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(80, 66, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(81, 67, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(82, 68, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(83, 69, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(84, 70, 2, 'Capricciosa 32cm', 1.00, 42.00, 8.00, 0, NULL, NULL),
(85, 71, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(86, 72, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(87, 73, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(88, 73, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(89, 74, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(90, 75, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(91, 76, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(92, 77, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(93, 78, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(94, 79, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(95, 80, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(96, 81, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(98, 82, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(99, 83, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(100, 84, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(101, 85, 1, 'Margherita 32cm', 1.00, 37.00, 8.00, 0, NULL, NULL),
(103, 87, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(104, 88, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(105, 89, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(106, 90, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(107, 90, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(108, 90, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(111, 86, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(112, 86, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(113, 86, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(114, 91, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(115, 92, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(116, 93, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(117, 94, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(118, 95, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(119, 96, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(120, 97, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(121, 98, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(123, 99, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(124, 100, 1, 'Margherita 32cm', 1.00, 33.00, 8.00, 0, NULL, NULL),
(127, 103, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(128, 104, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(130, 101, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(131, 105, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(132, 102, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(133, 106, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(134, 107, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(135, 108, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(136, 109, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(138, 111, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(139, 110, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(140, 112, 1, 'Margherita 32cm', 1.00, 45.00, 8.00, 0, NULL, NULL),
(141, 113, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(143, 114, 1, 'Margherita 32cm', 1.00, 37.00, 8.00, 0, NULL, NULL),
(144, 115, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(145, 116, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(146, 117, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(147, 118, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(148, 119, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(149, 120, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(151, 122, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(152, 123, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(153, 121, 2, 'Capricciosa 32cm', 1.00, 46.00, 8.00, 0, NULL, NULL),
(155, 125, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(156, 124, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(157, 126, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(158, 127, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(159, 128, 1, 'Margherita 32cm', 4.00, 41.00, 8.00, 0, NULL, NULL),
(160, 128, 2, 'Capricciosa 32cm', 3.00, 42.00, 8.00, 0, NULL, NULL),
(161, 128, 1, 'Margherita 32cm', 1.00, 33.00, 8.00, 0, NULL, NULL),
(162, 128, 2, 'Capricciosa 32cm', 1.00, 38.00, 8.00, 0, NULL, NULL),
(163, 129, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(167, 131, 1, 'Margherita 32cm', 1.00, 33.00, 8.00, 0, NULL, NULL),
(170, 132, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(171, 130, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(172, 130, NULL, '½ Margherita 32cm + ½ Capricciosa 32cm', 1.00, 44.00, 8.00, 0, NULL, NULL),
(173, 133, 2, 'Capricciosa 32cm', 1.00, 38.00, 8.00, 0, NULL, NULL),
(175, 135, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(176, 136, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(178, 138, 2, 'Capricciosa 32cm', 1.00, 42.00, 8.00, 0, NULL, NULL),
(181, 134, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(182, 137, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(183, 139, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(184, 139, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(185, 139, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(186, 139, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(187, 139, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(188, 139, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(189, 139, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(190, 139, 2, 'Capricciosa 32cm', 4.00, 34.00, 8.00, 0, NULL, NULL),
(191, 139, 2, 'Capricciosa 32cm', 6.00, 34.00, 8.00, 0, NULL, NULL),
(192, 140, 2, 'Capricciosa 32cm', 4.00, 34.00, 8.00, 0, NULL, NULL),
(193, 140, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(194, 140, 2, 'Capricciosa 32cm', 3.00, 38.00, 8.00, 0, NULL, NULL),
(195, 141, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(196, 141, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(197, 141, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(198, 141, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(199, 141, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(200, 142, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(201, 143, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(202, 144, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(203, 145, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(204, 146, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(205, 147, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(206, 148, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(207, 149, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(208, 150, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(209, 151, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(210, 152, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(211, 153, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(212, 154, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(213, 155, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(214, 156, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(215, 157, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(216, 157, 1, 'Margherita 32cm', 1.00, 33.00, 8.00, 0, NULL, NULL),
(217, 158, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(218, 159, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(219, 159, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(220, 159, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(221, 159, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(222, 159, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(223, 159, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(224, 159, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(225, 160, 1, 'Margherita 32cm', 1.00, 37.00, 8.00, 0, NULL, NULL),
(226, 161, 2, 'Capricciosa 32cm', 2.00, 42.00, 8.00, 0, NULL, NULL),
(227, 161, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(228, 161, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(229, 161, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(230, 162, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(231, 163, 1, 'Margherita 32cm', 1.00, 33.00, 8.00, 0, NULL, NULL),
(232, 163, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(233, 164, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(234, 165, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(235, 166, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(236, 167, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(237, 168, 2, 'Capricciosa 32cm', 1.00, 38.00, 8.00, 0, NULL, NULL),
(238, 169, 2, 'Capricciosa 32cm', 4.00, 34.00, 8.00, 0, NULL, NULL),
(239, 170, 1, 'Margherita 32cm', 1.00, 33.00, 8.00, 0, NULL, NULL),
(240, 171, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(241, 172, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(246, 175, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(247, 176, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(248, 176, 1, 'Margherita 32cm', 5.00, 29.00, 8.00, 0, NULL, NULL),
(249, 176, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(250, 176, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(252, 178, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(261, 177, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(262, 174, 1, 'Margherita 32cm', 1.00, 37.00, 8.00, 0, NULL, NULL),
(263, 174, 2, 'Capricciosa 32cm', 3.00, 34.00, 8.00, 0, NULL, NULL),
(264, 174, 1, 'Margherita 32cm', 2.00, 29.00, 8.00, 0, NULL, NULL),
(265, 174, 1, 'Margherita 32cm', 1.00, 37.00, 8.00, 0, NULL, NULL),
(266, 173, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(267, 179, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(268, 180, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(269, 181, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(270, 182, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(271, 183, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(272, 184, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(273, 185, 2, 'Capricciosa 32cm', 1.00, 38.00, 8.00, 0, NULL, NULL),
(274, 186, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(275, 187, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(276, 188, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(277, 189, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(278, 190, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(279, 191, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(280, 192, 1, 'Margherita 32cm', 1.00, 33.00, 8.00, 0, NULL, NULL),
(281, 193, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(282, 193, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(283, 193, 1, 'Margherita 32cm', 1.00, 37.00, 8.00, 0, NULL, NULL),
(284, 193, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(285, 194, 1, 'Margherita 32cm', 1.00, 37.00, 8.00, 0, NULL, NULL),
(286, 194, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(287, 194, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(288, 195, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(289, 195, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(290, 195, 1, 'Margherita 32cm', 1.00, 41.00, 8.00, 0, NULL, NULL),
(291, 195, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(292, 196, 1, 'Margherita 32cm', 1.00, 37.00, 8.00, 0, NULL, NULL),
(293, 196, 1, 'Margherita 32cm', 3.00, 33.00, 8.00, 0, NULL, NULL),
(294, 196, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL),
(295, 196, 1, 'Margherita 32cm', 1.00, 33.00, 8.00, 0, NULL, NULL),
(296, 197, 2, 'Capricciosa 32cm', 1.00, 46.00, 8.00, 0, NULL, NULL),
(297, 197, 1, 'Margherita 32cm', 1.00, 33.00, 8.00, 0, NULL, NULL),
(298, 198, 1, 'Margherita 32cm', 1.00, 29.00, 8.00, 0, NULL, NULL),
(299, 199, 2, 'Capricciosa 32cm', 1.00, 34.00, 8.00, 0, NULL, NULL);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_products`
--

CREATE TABLE `sh_products` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `sku` varchar(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'szt',
  `vat_rate` decimal(5,2) NOT NULL DEFAULT 23.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sh_products`
--

INSERT INTO `sh_products` (`id`, `tenant_id`, `sku`, `name`, `unit`, `vat_rate`, `is_active`) VALUES
(1, 1, 'SKU-001', 'Mąka Typ 00', 'kg', 0.00, 1),
(2, 1, 'SKU-002', 'Ser Mozzarella', 'kg', 5.00, 1),
(3, 1, 'SKU-003', 'Sos Pomidorowy Mutti', 'litr', 8.00, 1),
(4, 1, 'PROD-004', 'Szynka Cotto', 'kg', 5.00, 1);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_product_mapping`
--

CREATE TABLE `sh_product_mapping` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `external_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Nazwa z faktury dostawcy'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sh_product_mapping`
--

INSERT INTO `sh_product_mapping` (`id`, `tenant_id`, `product_id`, `external_name`) VALUES
(1, 1, 3, 'PULPA');

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
-- Struktura tabeli dla tabeli `sh_rate_limit_buckets`
--

CREATE TABLE `sh_rate_limit_buckets` (
  `id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `channel_id` int(10) UNSIGNED NOT NULL,
  `window_type` enum('hour','day') NOT NULL DEFAULT 'hour',
  `window_start` datetime NOT NULL COMMENT 'Początek aktualnego okna',
  `tokens_used` int(10) UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_recipes`
--

CREATE TABLE `sh_recipes` (
  `id` int(11) NOT NULL,
  `menu_item_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` decimal(10,3) NOT NULL,
  `waste_percent` decimal(5,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sh_recipes`
--

INSERT INTO `sh_recipes` (`id`, `menu_item_id`, `product_id`, `quantity`, `waste_percent`) VALUES
(7, 2, 1, 0.260, 5.00),
(8, 2, 2, 0.150, 5.00),
(9, 2, 4, 0.150, 5.00),
(10, 2, 3, 0.200, 5.00),
(11, 1, 1, 0.260, 5.00),
(12, 1, 2, 0.150, 5.00),
(13, 1, 3, 0.150, 5.00);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_routes`
--

CREATE TABLE `sh_routes` (
  `id` int(11) NOT NULL,
  `route_label` varchar(10) DEFAULT NULL,
  `tenant_id` int(11) NOT NULL,
  `driver_id` int(11) NOT NULL,
  `status` enum('active','completed','cancelled') NOT NULL DEFAULT 'active',
  `start_time` datetime NOT NULL DEFAULT current_timestamp(),
  `end_time` datetime DEFAULT NULL,
  `route_notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sh_routes`
--

INSERT INTO `sh_routes` (`id`, `route_label`, `tenant_id`, `driver_id`, `status`, `start_time`, `end_time`, `route_notes`) VALUES
(1, NULL, 1, 1, 'completed', '2026-03-30 00:09:43', '2026-03-30 00:10:45', NULL),
(2, NULL, 1, 1, 'active', '2026-03-30 00:11:18', NULL, NULL),
(3, NULL, 1, 1, 'active', '2026-03-30 01:09:20', NULL, NULL);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_schedule`
--

CREATE TABLE `sh_schedule` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `shift_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_security_audit_log`
--

CREATE TABLE `sh_security_audit_log` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'NULL dla zdarzeń globalnych',
  `event_type` varchar(64) NOT NULL,
  `severity` enum('info','warn','critical') NOT NULL DEFAULT 'info',
  `actor_type` varchar(32) DEFAULT NULL COMMENT 'user | system | external_api | webhook',
  `actor_id` varchar(128) DEFAULT NULL COMMENT 'user_id lub IP (hashed)',
  `resource_type` varchar(32) DEFAULT NULL COMMENT 'order | channel | contact | ...',
  `resource_id` varchar(64) DEFAULT NULL,
  `details_json` longtext DEFAULT NULL CHECK (json_valid(`details_json`) or `details_json` is null),
  `remote_ip_hash` char(64) DEFAULT NULL COMMENT 'SHA-256(IP) dla audytu bez przechowywania raw IP',
  `occurred_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_settings_audit`
--

CREATE TABLE `sh_settings_audit` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'sh_users.id — NULL tylko dla system/cron mutations',
  `actor_ip` varchar(45) DEFAULT NULL COMMENT 'IPv4 / IPv6 remote addr',
  `action` varchar(48) NOT NULL COMMENT 'integrations_save, webhooks_delete, api_keys_generate, api_keys_revoke, ...',
  `entity_type` varchar(32) NOT NULL COMMENT 'integration | webhook | api_key | dlq | other',
  `entity_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'id rekordu (po insercie / przed delete)',
  `before_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Snapshot rekordu PRZED mutacją (NULL dla create). Credentials/secrets zawsze redacted (••••).' CHECK (json_valid(`before_json`)),
  `after_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Snapshot rekordu PO mutacji (NULL dla delete). Credentials/secrets zawsze redacted.' CHECK (json_valid(`after_json`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit trail for Settings Panel mutations (m029)';

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_sse_broadcast`
--

CREATE TABLE `sh_sse_broadcast` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `tracking_token` char(16) NOT NULL,
  `event_type` varchar(64) NOT NULL,
  `payload_json` longtext NOT NULL CHECK (json_valid(`payload_json`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_stock_levels`
--

CREATE TABLE `sh_stock_levels` (
  `id` int(11) NOT NULL,
  `warehouse_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` decimal(10,3) NOT NULL DEFAULT 0.000
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sh_stock_levels`
--

INSERT INTO `sh_stock_levels` (`id`, `warehouse_id`, `product_id`, `quantity`) VALUES
(1, 1, 2, 100.000),
(2, 1, 1, 200.000),
(3, 1, 4, 5.000),
(4, 2, 1, -57.584),
(5, 2, 2, -31.682),
(6, 2, 3, -40.638),
(7, 2, 4, -17.089);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_tables`
--

CREATE TABLE `sh_tables` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `table_number` varchar(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `qr_key` varchar(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` enum('free','occupied','dirty','reserved') CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'free'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sh_tables`
--

INSERT INTO `sh_tables` (`id`, `tenant_id`, `table_number`, `qr_key`, `status`) VALUES
(1, 1, '1', 'SH-QR-F4936A3A', 'free'),
(2, 1, '2', 'QR-START-2', 'free'),
(3, 1, '3', 'QR-START-3', 'occupied'),
(4, 1, 'VIP', 'QR-START-4', 'free'),
(5, 1, '4', '', 'free'),
(6, 1, 'kasi', '', 'free');

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_taxes`
--

CREATE TABLE `sh_taxes` (
  `id` int(11) NOT NULL,
  `name` varchar(20) NOT NULL,
  `rate` decimal(5,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sh_taxes`
--

INSERT INTO `sh_taxes` (`id`, `name`, `rate`) VALUES
(1, 'VAT 5%', 5.00),
(2, 'VAT 8%', 8.00),
(3, 'VAT 23%', 23.00),
(4, 'VAT 0%', 0.00);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_tenants`
--

CREATE TABLE `sh_tenants` (
  `id` int(11) NOT NULL,
  `nip` varchar(20) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `primary_color` varchar(7) CHARACTER SET ascii COLLATE ascii_bin DEFAULT '#e63946',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sh_tenants`
--

INSERT INTO `sh_tenants` (`id`, `nip`, `name`, `primary_color`, `created_at`) VALUES
(1, '1112223344', 'Główna Pizzeria', '#e63946', '2026-03-26 19:47:20');

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
-- Struktura tabeli dla tabeli `sh_users`
--

CREATE TABLE `sh_users` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `username` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `first_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pin_code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `password_hash` varchar(255) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `role` enum('admin','manager','waiter','kitchen','driver') CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'waiter',
  `position` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `slice_coins` int(11) NOT NULL DEFAULT 0,
  `avatar_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hourly_rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `phone` varchar(20) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `last_seen` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `pin` varchar(4) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sh_users`
--

INSERT INTO `sh_users` (`id`, `tenant_id`, `username`, `first_name`, `last_name`, `pin_code`, `password_hash`, `role`, `position`, `slice_coins`, `avatar_path`, `hourly_rate`, `phone`, `last_seen`, `is_active`, `pin`) VALUES
(3, 1, 'Boss', NULL, NULL, NULL, '$2y$10$flAG58GzP5z/w6rX1bvNj.VD/3BVlR79ml62QFIR.jICC5QhUCUSG', 'admin', NULL, 0, NULL, 0.00, NULL, '2026-03-31 14:25:11', 1, '1122'),
(4, 1, 'Manager1', NULL, NULL, NULL, '$2y$10$7R.x/k7W8YwKkFpD/XpBnuXyB.fV5B5K5K5K5K5K5K5K5K5K5K5K', 'manager', NULL, 0, NULL, 0.00, NULL, NULL, 1, '4444'),
(5, 1, 'Kelner1', NULL, NULL, NULL, '$2y$10$7R.x/k7W8YwKkFpD/XpBnuXyB.fV5B5K5K5K5K5K5K5K5K5K5K5K', 'waiter', NULL, 0, NULL, 0.00, NULL, NULL, 1, '2222'),
(6, 1, 'Kierowca1', NULL, NULL, NULL, '$2y$10$7R.x/k7W8YwKkFpD/XpBnuXyB.fV5B5K5K5K5K5K5K5K5K5K5K5K', 'driver', NULL, 0, NULL, 0.00, NULL, NULL, 1, '1111'),
(7, 1, 'Kucharz1', NULL, NULL, NULL, '$2y$10$7R.x/k7W8YwKkFpD/XpBnuXyB.fV5B5K5K5K5K5K5K5K5K5K5K5K', 'kitchen', NULL, 0, NULL, 0.00, NULL, NULL, 1, '3333');

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `sh_warehouses`
--

CREATE TABLE `sh_warehouses` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sh_warehouses`
--

INSERT INTO `sh_warehouses` (`id`, `tenant_id`, `name`) VALUES
(1, 1, 'Magazyn Główny'),
(2, 1, 'Kuchnia / Bar');

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
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `start_time` datetime NOT NULL,
  `end_time` datetime DEFAULT NULL,
  `total_time` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sh_work_sessions`
--

INSERT INTO `sh_work_sessions` (`id`, `tenant_id`, `user_id`, `start_time`, `end_time`, `total_time`) VALUES
(14, 1, 6, '2026-03-31 00:18:28', NULL, NULL),
(15, 1, 6, '2026-03-31 00:26:44', NULL, NULL),
(16, 1, 6, '2026-03-31 00:57:44', '2026-03-31 01:15:37', 0.28),
(17, 1, 6, '2026-03-31 01:15:56', NULL, NULL),
(18, 1, 5, '2026-03-31 01:47:03', NULL, NULL);

--
-- Indeksy dla zrzutów tabel
--

--
-- Indeksy dla tabeli `sh_categories`
--
ALTER TABLE `sh_categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`);

--
-- Indeksy dla tabeli `sh_chat_messages`
--
ALTER TABLE `sh_chat_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_chat_msg_tenant` (`tenant_id`),
  ADD KEY `fk_chat_msg_room` (`room_id`),
  ADD KEY `fk_chat_msg_user` (`user_id`);

--
-- Indeksy dla tabeli `sh_chat_rooms`
--
ALTER TABLE `sh_chat_rooms`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_chat_room_tenant` (`tenant_id`);

--
-- Indeksy dla tabeli `sh_customers`
--
ALTER TABLE `sh_customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_tenant_phone` (`tenant_id`,`phone`);

--
-- Indeksy dla tabeli `sh_customer_addresses`
--
ALTER TABLE `sh_customer_addresses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_cust_addr` (`customer_id`,`address`);

--
-- Indeksy dla tabeli `sh_customer_contacts`
--
ALTER TABLE `sh_customer_contacts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_contact_phone` (`tenant_id`,`phone`),
  ADD KEY `idx_contact_tenant` (`tenant_id`),
  ADD KEY `idx_contact_last_order` (`tenant_id`,`last_order_at`);

--
-- Indeksy dla tabeli `sh_customer_inbox`
--
ALTER TABLE `sh_customer_inbox`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_inbox_tenant_read` (`tenant_id`,`read_at`,`received_at`),
  ADD KEY `idx_inbox_contact` (`tenant_id`,`contact_id`);

--
-- Indeksy dla tabeli `sh_daily_rewards`
--
ALTER TABLE `sh_daily_rewards`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_daily_rewards_tenant` (`tenant_id`),
  ADD KEY `fk_daily_rewards_user` (`user_id`);

--
-- Indeksy dla tabeli `sh_deductions`
--
ALTER TABLE `sh_deductions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indeksy dla tabeli `sh_drivers`
--
ALTER TABLE `sh_drivers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_driver_user` (`user_id`);

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
-- Indeksy dla tabeli `sh_finance_requests`
--
ALTER TABLE `sh_finance_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `target_user_id` (`target_user_id`),
  ADD KEY `created_by_id` (`created_by_id`);

--
-- Indeksy dla tabeli `sh_gateway_api_keys`
--
ALTER TABLE `sh_gateway_api_keys`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_gw_key_prefix` (`key_prefix`),
  ADD KEY `idx_gw_tenant_source` (`tenant_id`,`source`,`is_active`),
  ADD KEY `idx_gw_active` (`is_active`,`expires_at`);

--
-- Indeksy dla tabeli `sh_gdpr_consent_log`
--
ALTER TABLE `sh_gdpr_consent_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_gdpr_phone` (`tenant_id`,`phone_hash`,`consent_type`,`occurred_at`),
  ADD KEY `idx_gdpr_contact` (`tenant_id`,`contact_id`);

--
-- Indeksy dla tabeli `sh_inbound_callbacks`
--
ALTER TABLE `sh_inbound_callbacks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_inbound_idempotency` (`provider`,`external_event_id`) COMMENT 'Zapobiega double-processing gdy provider robi retry',
  ADD KEY `idx_inbound_tenant` (`tenant_id`,`received_at`),
  ADD KEY `idx_inbound_provider` (`provider`,`received_at`),
  ADD KEY `idx_inbound_ref` (`external_ref`,`provider`),
  ADD KEY `idx_inbound_status` (`status`,`received_at`);

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
-- Indeksy dla tabeli `sh_inventory_docs`
--
ALTER TABLE `sh_inventory_docs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `warehouse_id` (`warehouse_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indeksy dla tabeli `sh_inventory_doc_items`
--
ALTER TABLE `sh_inventory_doc_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `doc_id` (`doc_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indeksy dla tabeli `sh_inventory_logs`
--
ALTER TABLE `sh_inventory_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indeksy dla tabeli `sh_item_modifiers`
--
ALTER TABLE `sh_item_modifiers`
  ADD PRIMARY KEY (`item_id`,`group_id`),
  ADD KEY `group_id` (`group_id`);

--
-- Indeksy dla tabeli `sh_item_variants`
--
ALTER TABLE `sh_item_variants`
  ADD PRIMARY KEY (`id`),
  ADD KEY `item_id` (`item_id`);

--
-- Indeksy dla tabeli `sh_marketing_campaigns`
--
ALTER TABLE `sh_marketing_campaigns`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_camp_tenant_status` (`tenant_id`,`status`,`scheduled_at`);

--
-- Indeksy dla tabeli `sh_menu_items`
--
ALTER TABLE `sh_menu_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `category_id` (`category_id`);

--
-- Indeksy dla tabeli `sh_menu_tags`
--
ALTER TABLE `sh_menu_tags`
  ADD PRIMARY KEY (`id`);

--
-- Indeksy dla tabeli `sh_missions`
--
ALTER TABLE `sh_missions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`);

--
-- Indeksy dla tabeli `sh_mission_proofs`
--
ALTER TABLE `sh_mission_proofs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `mission_id` (`mission_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indeksy dla tabeli `sh_modifiers`
--
ALTER TABLE `sh_modifiers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `group_id` (`group_id`);

--
-- Indeksy dla tabeli `sh_modifier_groups`
--
ALTER TABLE `sh_modifier_groups`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`);

--
-- Indeksy dla tabeli `sh_notification_channels`
--
ALTER TABLE `sh_notification_channels`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notif_ch_tenant` (`tenant_id`,`is_active`,`priority`);

--
-- Indeksy dla tabeli `sh_notification_deliveries`
--
ALTER TABLE `sh_notification_deliveries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_nd_tenant_event` (`tenant_id`,`event_id`),
  ADD KEY `idx_nd_channel_status` (`channel_id`,`status`,`next_attempt_at`),
  ADD KEY `idx_nd_tenant_type_date` (`tenant_id`,`event_type`,`attempted_at`);

--
-- Indeksy dla tabeli `sh_notification_routes`
--
ALTER TABLE `sh_notification_routes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_route` (`tenant_id`,`event_type`,`channel_id`),
  ADD KEY `idx_route_tenant_event` (`tenant_id`,`event_type`,`fallback_order`);

--
-- Indeksy dla tabeli `sh_notification_templates`
--
ALTER TABLE `sh_notification_templates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_template` (`tenant_id`,`event_type`,`channel_type`,`lang`),
  ADD KEY `idx_tpl_tenant` (`tenant_id`);

--
-- Indeksy dla tabeli `sh_orders`
--
ALTER TABLE `sh_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uuid_api_key` (`uuid`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `table_id` (`table_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `fk_order_driver` (`driver_id`),
  ADD KEY `fk_order_route` (`route_id`),
  ADD KEY `idx_orders_gw_ext` (`tenant_id`,`gateway_source`,`gateway_external_id`);

--
-- Indeksy dla tabeli `sh_order_audit`
--
ALTER TABLE `sh_order_audit`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indeksy dla tabeli `sh_order_items`
--
ALTER TABLE `sh_order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `menu_item_id` (`menu_item_id`);

--
-- Indeksy dla tabeli `sh_products`
--
ALTER TABLE `sh_products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`);

--
-- Indeksy dla tabeli `sh_product_mapping`
--
ALTER TABLE `sh_product_mapping`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indeksy dla tabeli `sh_rate_limits`
--
ALTER TABLE `sh_rate_limits`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_rl_bucket` (`api_key_id`,`window_kind`,`window_bucket`),
  ADD KEY `idx_rl_cleanup` (`last_hit_at`);

--
-- Indeksy dla tabeli `sh_rate_limit_buckets`
--
ALTER TABLE `sh_rate_limit_buckets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_bucket` (`tenant_id`,`channel_id`,`window_type`,`window_start`),
  ADD KEY `idx_rl_channel_window` (`channel_id`,`window_type`,`window_start`);

--
-- Indeksy dla tabeli `sh_recipes`
--
ALTER TABLE `sh_recipes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `menu_item_id` (`menu_item_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indeksy dla tabeli `sh_routes`
--
ALTER TABLE `sh_routes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `driver_id` (`driver_id`);

--
-- Indeksy dla tabeli `sh_schedule`
--
ALTER TABLE `sh_schedule`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_schedule_tenant` (`tenant_id`),
  ADD KEY `fk_schedule_user` (`user_id`);

--
-- Indeksy dla tabeli `sh_security_audit_log`
--
ALTER TABLE `sh_security_audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sal_tenant_event` (`tenant_id`,`event_type`,`occurred_at`),
  ADD KEY `idx_sal_severity` (`severity`,`occurred_at`);

--
-- Indeksy dla tabeli `sh_settings_audit`
--
ALTER TABLE `sh_settings_audit`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_tenant_time` (`tenant_id`,`created_at`),
  ADD KEY `idx_audit_entity` (`entity_type`,`entity_id`,`created_at`),
  ADD KEY `idx_audit_user` (`user_id`,`created_at`);

--
-- Indeksy dla tabeli `sh_sse_broadcast`
--
ALTER TABLE `sh_sse_broadcast`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sse_token_time` (`tracking_token`,`created_at`),
  ADD KEY `idx_sse_tenant_time` (`tenant_id`,`created_at`);

--
-- Indeksy dla tabeli `sh_stock_levels`
--
ALTER TABLE `sh_stock_levels`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_stock` (`warehouse_id`,`product_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indeksy dla tabeli `sh_tables`
--
ALTER TABLE `sh_tables`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`);

--
-- Indeksy dla tabeli `sh_taxes`
--
ALTER TABLE `sh_taxes`
  ADD PRIMARY KEY (`id`);

--
-- Indeksy dla tabeli `sh_tenants`
--
ALTER TABLE `sh_tenants`
  ADD PRIMARY KEY (`id`);

--
-- Indeksy dla tabeli `sh_tenant_integrations`
--
ALTER TABLE `sh_tenant_integrations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_integration_tenant_provider` (`tenant_id`,`provider`),
  ADD KEY `idx_integration_active` (`is_active`,`provider`);

--
-- Indeksy dla tabeli `sh_users`
--
ALTER TABLE `sh_users`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`);

--
-- Indeksy dla tabeli `sh_warehouses`
--
ALTER TABLE `sh_warehouses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tenant_id` (`tenant_id`);

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
  ADD KEY `fk_work_sess_tenant` (`tenant_id`),
  ADD KEY `fk_work_sess_user` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `sh_categories`
--
ALTER TABLE `sh_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `sh_chat_messages`
--
ALTER TABLE `sh_chat_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_chat_rooms`
--
ALTER TABLE `sh_chat_rooms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `sh_customers`
--
ALTER TABLE `sh_customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_customer_addresses`
--
ALTER TABLE `sh_customer_addresses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_customer_contacts`
--
ALTER TABLE `sh_customer_contacts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_customer_inbox`
--
ALTER TABLE `sh_customer_inbox`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_daily_rewards`
--
ALTER TABLE `sh_daily_rewards`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_deductions`
--
ALTER TABLE `sh_deductions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_drivers`
--
ALTER TABLE `sh_drivers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

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
-- AUTO_INCREMENT for table `sh_finance_requests`
--
ALTER TABLE `sh_finance_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_gateway_api_keys`
--
ALTER TABLE `sh_gateway_api_keys`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_gdpr_consent_log`
--
ALTER TABLE `sh_gdpr_consent_log`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_inbound_callbacks`
--
ALTER TABLE `sh_inbound_callbacks`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

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
-- AUTO_INCREMENT for table `sh_inventory_docs`
--
ALTER TABLE `sh_inventory_docs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `sh_inventory_doc_items`
--
ALTER TABLE `sh_inventory_doc_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `sh_inventory_logs`
--
ALTER TABLE `sh_inventory_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1192;

--
-- AUTO_INCREMENT for table `sh_item_variants`
--
ALTER TABLE `sh_item_variants`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `sh_marketing_campaigns`
--
ALTER TABLE `sh_marketing_campaigns`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_menu_items`
--
ALTER TABLE `sh_menu_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `sh_menu_tags`
--
ALTER TABLE `sh_menu_tags`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_missions`
--
ALTER TABLE `sh_missions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_mission_proofs`
--
ALTER TABLE `sh_mission_proofs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_modifiers`
--
ALTER TABLE `sh_modifiers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `sh_modifier_groups`
--
ALTER TABLE `sh_modifier_groups`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `sh_notification_channels`
--
ALTER TABLE `sh_notification_channels`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_notification_deliveries`
--
ALTER TABLE `sh_notification_deliveries`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_notification_routes`
--
ALTER TABLE `sh_notification_routes`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_notification_templates`
--
ALTER TABLE `sh_notification_templates`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=43;

--
-- AUTO_INCREMENT for table `sh_orders`
--
ALTER TABLE `sh_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=200;

--
-- AUTO_INCREMENT for table `sh_order_audit`
--
ALTER TABLE `sh_order_audit`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `sh_order_items`
--
ALTER TABLE `sh_order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=300;

--
-- AUTO_INCREMENT for table `sh_products`
--
ALTER TABLE `sh_products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `sh_product_mapping`
--
ALTER TABLE `sh_product_mapping`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `sh_rate_limits`
--
ALTER TABLE `sh_rate_limits`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_rate_limit_buckets`
--
ALTER TABLE `sh_rate_limit_buckets`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_recipes`
--
ALTER TABLE `sh_recipes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `sh_routes`
--
ALTER TABLE `sh_routes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `sh_schedule`
--
ALTER TABLE `sh_schedule`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_security_audit_log`
--
ALTER TABLE `sh_security_audit_log`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_settings_audit`
--
ALTER TABLE `sh_settings_audit`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_sse_broadcast`
--
ALTER TABLE `sh_sse_broadcast`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_stock_levels`
--
ALTER TABLE `sh_stock_levels`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `sh_tables`
--
ALTER TABLE `sh_tables`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `sh_taxes`
--
ALTER TABLE `sh_taxes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `sh_tenants`
--
ALTER TABLE `sh_tenants`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `sh_tenant_integrations`
--
ALTER TABLE `sh_tenant_integrations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sh_users`
--
ALTER TABLE `sh_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `sh_warehouses`
--
ALTER TABLE `sh_warehouses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `sh_categories`
--
ALTER TABLE `sh_categories`
  ADD CONSTRAINT `sh_categories_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sh_chat_messages`
--
ALTER TABLE `sh_chat_messages`
  ADD CONSTRAINT `fk_chat_msg_room` FOREIGN KEY (`room_id`) REFERENCES `sh_chat_rooms` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_chat_msg_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_chat_msg_user` FOREIGN KEY (`user_id`) REFERENCES `sh_users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sh_chat_rooms`
--
ALTER TABLE `sh_chat_rooms`
  ADD CONSTRAINT `fk_chat_room_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sh_daily_rewards`
--
ALTER TABLE `sh_daily_rewards`
  ADD CONSTRAINT `fk_daily_rewards_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_daily_rewards_user` FOREIGN KEY (`user_id`) REFERENCES `sh_users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sh_deductions`
--
ALTER TABLE `sh_deductions`
  ADD CONSTRAINT `sh_deductions_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sh_deductions_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `sh_users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sh_drivers`
--
ALTER TABLE `sh_drivers`
  ADD CONSTRAINT `sh_drivers_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `sh_users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sh_finance_requests`
--
ALTER TABLE `sh_finance_requests`
  ADD CONSTRAINT `sh_finance_requests_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sh_finance_requests_ibfk_2` FOREIGN KEY (`target_user_id`) REFERENCES `sh_users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sh_finance_requests_ibfk_3` FOREIGN KEY (`created_by_id`) REFERENCES `sh_users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sh_inventory_docs`
--
ALTER TABLE `sh_inventory_docs`
  ADD CONSTRAINT `sh_inventory_docs_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sh_inventory_docs_ibfk_2` FOREIGN KEY (`warehouse_id`) REFERENCES `sh_warehouses` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sh_inventory_docs_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `sh_users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `sh_inventory_doc_items`
--
ALTER TABLE `sh_inventory_doc_items`
  ADD CONSTRAINT `sh_inventory_doc_items_ibfk_1` FOREIGN KEY (`doc_id`) REFERENCES `sh_inventory_docs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sh_inventory_doc_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `sh_products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sh_inventory_logs`
--
ALTER TABLE `sh_inventory_logs`
  ADD CONSTRAINT `sh_inventory_logs_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `sh_products` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sh_inventory_logs_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `sh_users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sh_item_modifiers`
--
ALTER TABLE `sh_item_modifiers`
  ADD CONSTRAINT `sh_item_modifiers_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `sh_menu_items` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sh_item_modifiers_ibfk_2` FOREIGN KEY (`group_id`) REFERENCES `sh_modifier_groups` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sh_item_variants`
--
ALTER TABLE `sh_item_variants`
  ADD CONSTRAINT `sh_item_variants_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `sh_menu_items` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sh_menu_items`
--
ALTER TABLE `sh_menu_items`
  ADD CONSTRAINT `sh_menu_items_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sh_menu_items_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `sh_categories` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sh_missions`
--
ALTER TABLE `sh_missions`
  ADD CONSTRAINT `sh_missions_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sh_mission_proofs`
--
ALTER TABLE `sh_mission_proofs`
  ADD CONSTRAINT `sh_mission_proofs_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `sh_missions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sh_mission_proofs_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `sh_users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sh_modifiers`
--
ALTER TABLE `sh_modifiers`
  ADD CONSTRAINT `sh_modifiers_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `sh_modifier_groups` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sh_modifier_groups`
--
ALTER TABLE `sh_modifier_groups`
  ADD CONSTRAINT `sh_modifier_groups_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sh_orders`
--
ALTER TABLE `sh_orders`
  ADD CONSTRAINT `fk_order_driver` FOREIGN KEY (`driver_id`) REFERENCES `sh_users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_order_route` FOREIGN KEY (`route_id`) REFERENCES `sh_routes` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `sh_orders_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sh_orders_ibfk_2` FOREIGN KEY (`table_id`) REFERENCES `sh_tables` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `sh_orders_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `sh_users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `sh_order_audit`
--
ALTER TABLE `sh_order_audit`
  ADD CONSTRAINT `sh_order_audit_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `sh_orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sh_order_audit_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `sh_users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sh_order_items`
--
ALTER TABLE `sh_order_items`
  ADD CONSTRAINT `sh_order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `sh_orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sh_order_items_ibfk_2` FOREIGN KEY (`menu_item_id`) REFERENCES `sh_menu_items` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `sh_products`
--
ALTER TABLE `sh_products`
  ADD CONSTRAINT `sh_products_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sh_product_mapping`
--
ALTER TABLE `sh_product_mapping`
  ADD CONSTRAINT `sh_product_mapping_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sh_product_mapping_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `sh_products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sh_recipes`
--
ALTER TABLE `sh_recipes`
  ADD CONSTRAINT `sh_recipes_ibfk_1` FOREIGN KEY (`menu_item_id`) REFERENCES `sh_menu_items` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sh_recipes_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `sh_products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sh_schedule`
--
ALTER TABLE `sh_schedule`
  ADD CONSTRAINT `fk_schedule_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_schedule_user` FOREIGN KEY (`user_id`) REFERENCES `sh_users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sh_stock_levels`
--
ALTER TABLE `sh_stock_levels`
  ADD CONSTRAINT `sh_stock_levels_ibfk_1` FOREIGN KEY (`warehouse_id`) REFERENCES `sh_warehouses` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sh_stock_levels_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `sh_products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sh_tables`
--
ALTER TABLE `sh_tables`
  ADD CONSTRAINT `sh_tables_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sh_users`
--
ALTER TABLE `sh_users`
  ADD CONSTRAINT `sh_users_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sh_warehouses`
--
ALTER TABLE `sh_warehouses`
  ADD CONSTRAINT `sh_warehouses_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sh_work_sessions`
--
ALTER TABLE `sh_work_sessions`
  ADD CONSTRAINT `fk_work_sess_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `sh_tenants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_work_sess_user` FOREIGN KEY (`user_id`) REFERENCES `sh_users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
