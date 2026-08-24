<?php

declare(strict_types=1);

/**
 * MenuVisibilityFilter — SSOT warunków WHERE widoczności menu w SliceHub.
 *
 * KANON (Konstytucja v5 §3 — Prawo Czwartego Wymiaru / Temporal Tables):
 *   - Statusy publikacji: `Draft` / `Live` / `Archived` (kapitalizowane).
 *   - `valid_from` / `valid_to` sterują widocznością w czasie.
 *   - Soft delete przez `is_deleted = 1` — ZAWSZE.
 *   - `is_active` jest polem technicznym/reliktowym — NIE jest kanonicznym
 *     mechanizmem widoczności dań (Konstytucja go nie wymienia). POS dla dań
 *     już go nie filtruje; ten helper kontynuuje tę linię.
 *
 * DWA KANAŁE:
 *   - PUBLIC (Online storefront, ChoiceQR export): widzi tylko pozycje
 *     nie-secret + Live/published + w oknie temporal.
 *   - INTERNAL (POS kasa/kiosk): widzi pozycje secret (POS-only dania),
 *     ale nadal respektuje publication_status + temporal.
 *
 * ZASTĘPUJE 5 rozjechanych kopii fragmentów WHERE w:
 *   - api/pos/engine.php (dania + meals)
 *   - api/online/engine.php (get_menu, get_dish, board_companions, bestsellers)
 *   - api/integrations/choiceqr/menu.php
 *   - api/cart/CartEngine.php
 *   - api/marketing/deck_engine.php
 *
 * Klasa jest STATELESS poza cache probe kolumn (probe per-PDO, tenant-agnostic).
 * Multi-tenant rygor: ten helper NIE dodaje `tenant_id` do WHERE — to rola
 * wywołującego (każdy silnik już ma `WHERE tenant_id = ?`). Helper operuje
 * wyłącznie na kolumnach statusowych/czasowych tabeli menu.
 */
final class MenuVisibilityFilter
{
    /** Kanoniczne statusy publikacji akceptowane na PUBLIC i INTERNAL. */
    public const PUBLISHED_STATUSES = ['Live', 'published'];

    /** Statusy oznaczające brak widoczności (Draft, Archived + warianty wielkości liter). */
    public const HIDDEN_STATUSES = ['Draft', 'Archived', 'draft', 'archived'];

    /**
     * Cache probe: czy tabela ma kolumnę `publication_status` (graceful fallback
     * dla starych baz bez migracji 051). Klucz = spl_object_id($pdo).
     *
     * @var array<int, bool>
     */
    private static array $hasPubStatus = [];

    /** @var array<int, bool> */
    private static array $hasValidFromTo = [];

    /** @var array<int, bool> */
    private static array $hasIsSecret = [];

    /**
     * Zwraca fragment SQL WHERE dla dań w kanale PUBLIC (Online, ChoiceQR).
     *
     * Zakłada alias tabeli `sh_menu_items` (domyślnie `mi`). Wywołujący MUSI
     * dodać `tenant_id` i `is_variant_parent` według własnego kontekstu.
     *
     * Fragment zawiera:
     *   - is_deleted = 0
     *   - (is_secret = 0 OR is_secret IS NULL)  [jeśli kolumna istnieje]
     *   - (publication_status IN ('Live','published') OR publication_status IS NULL)  [jeśli kolumna]
     *   - (valid_from IS NULL OR valid_from <= NOW())  [jeśli kolumny DATETIME]
     *   - (valid_to   IS NULL OR valid_to   >= NOW())
     *
     * @param PDO    $pdo   połączenie do bazy (do probe kolumn)
     * @param string $alias alias tabeli sh_menu_items w zapytaniu wywołującego
     * @return string  fragment SQL (bez leading AND; pusty jeśli nic do dodania)
     */
    public static function publicItemsWhere(PDO $pdo, string $alias = 'mi'): string
    {
        $conditions = self::baseItemConditions($pdo, $alias, includeSecretFilter: true);
        return $conditions;
    }

    /**
     * Zwraca fragment SQL WHERE dla dań w kanale INTERNAL (POS).
     *
     * POS widzi pozycje `is_secret = 1` (POS-only dania, np. menu pracownicze
     * na kasie), ale nadal respektuje `publication_status` + temporal.
     *
     * @param PDO    $pdo
     * @param string $alias
     * @return string
     */
    public static function internalItemsWhere(PDO $pdo, string $alias = 'mi'): string
    {
        return self::baseItemConditions($pdo, $alias, includeSecretFilter: false);
    }

    /**
     * Zwraca fragment SQL WHERE dla meal packages (`sh_meal_packages`).
     *
     * Semantyka identyczna jak dla dań, ale inna nazwa tabeli i alias.
     * POS i Online używają tego samego fragmentu (meals nie mają is_secret).
     *
     * @param PDO    $pdo
     * @param string $alias
     * @return string
     */
    public static function mealsWhere(PDO $pdo, string $alias = 'mp'): string
    {
        // is_active zachowane dla meals — meals nie mają pełnego cyklu publikacji
        // w Studio (Konstytucja §3 dotyczy dań, nie meal packages). is_active jest
        // ich jedynym toggle widoczności poza is_deleted.
        $conditions = ["{$alias}.is_deleted = 0", "{$alias}.is_active = 1"];

        if (self::mealsHasPubStatus($pdo)) {
            $conditions[] = "({$alias}.publication_status IS NULL OR {$alias}.publication_status IN ('" . implode("','", self::PUBLISHED_STATUSES) . "'))";
        }

        if (self::mealsHasValidFromTo($pdo)) {
            $conditions[] = "({$alias}.valid_from IS NULL OR {$alias}.valid_from <= NOW())";
            $conditions[] = "({$alias}.valid_to   IS NULL OR {$alias}.valid_to   >= NOW())";
        }

        return implode(' AND ', $conditions);
    }

    /**
     * Zwraca fragment SQL WHERE dla grup modyfikatorów (`sh_modifier_groups`).
     *
     * Respektuje `publication_status` + temporal (jeśli kolumny istnieją) oraz
     * `is_active` (legacy toggle, zachowany dla modyfikatorów — tu nie jest
     * reliktowy, bo modyfikatory nie mają pełnego cyklu publikacji w Studio).
     *
     * @param PDO    $pdo
     * @param string $alias
     * @return string
     */
    public static function modifierGroupsWhere(PDO $pdo, string $alias = 'mg'): string
    {
        $conditions = ["{$alias}.is_deleted = 0", "{$alias}.is_active = 1"];

        if (self::modGroupsHasPubStatus($pdo)) {
            $conditions[] = "({$alias}.publication_status IS NULL OR {$alias}.publication_status IN ('" . implode("','", self::PUBLISHED_STATUSES) . "'))";
            $conditions[] = "({$alias}.valid_from IS NULL OR {$alias}.valid_from <= NOW())";
            $conditions[] = "({$alias}.valid_to   IS NULL OR {$alias}.valid_to   >= NOW())";
        }

        return implode(' AND ', $conditions);
    }

    /**
     * Zwraca fragment SQL WHERE dla pojedynczych modyfikatorów (`sh_modifiers`).
     *
     * Modyfikatory nie mają własnego `publication_status` — tylko `is_active`
     * i `is_deleted`.
     *
     * @param string $alias
     * @return string
     */
    public static function modifiersWhere(string $alias = 'm'): string
    {
        return "{$alias}.is_deleted = 0 AND {$alias}.is_active = 1";
    }

    /**
     * Zwraca fragment SQL WHERE dla kategorii (`sh_categories`) w kanale publicznym.
     *
     * Kategorie nie mają `publication_status` (gap — sekcja 3.2 audytu), ale
     * mają `is_menu` (czy kategoria jest częścią menu) i `is_deleted`.
     *
     * @param string $alias
     * @return string
     */
    public static function publicCategoriesWhere(string $alias = 'c'): string
    {
        return "{$alias}.is_deleted = 0 AND {$alias}.is_menu = 1";
    }

    // =====================================================================
    // IMPLEMENTACJA — probe kolumn + budowanie warunków
    // =====================================================================

    private static function baseItemConditions(PDO $pdo, string $alias, bool $includeSecretFilter): string
    {
        $conditions = ["{$alias}.is_deleted = 0"];

        if ($includeSecretFilter && self::itemsHasIsSecret($pdo)) {
            $conditions[] = "({$alias}.is_secret = 0 OR {$alias}.is_secret IS NULL)";
        }

        if (self::itemsHasPubStatus($pdo)) {
            $conditions[] = "({$alias}.publication_status IS NULL OR {$alias}.publication_status IN ('" . implode("','", self::PUBLISHED_STATUSES) . "'))";
        }

        if (self::itemsHasValidFromTo($pdo)) {
            $conditions[] = "({$alias}.valid_from IS NULL OR {$alias}.valid_from <= NOW())";
            $conditions[] = "({$alias}.valid_to   IS NULL OR {$alias}.valid_to   >= NOW())";
        }

        return implode(' AND ', $conditions);
    }

    // --- Probe kolumn (idempotentne, cache per-PDO) ---

    private static function itemsHasPubStatus(PDO $pdo): bool
    {
        $key = spl_object_id($pdo);
        if (!isset(self::$hasPubStatus[$key])) {
            try {
                $pdo->query("SELECT publication_status FROM sh_menu_items LIMIT 0");
                self::$hasPubStatus[$key] = true;
            } catch (\PDOException $e) {
                self::$hasPubStatus[$key] = false;
            }
        }
        return self::$hasPubStatus[$key];
    }

    private static function itemsHasValidFromTo(PDO $pdo): bool
    {
        $key = spl_object_id($pdo);
        if (!isset(self::$hasValidFromTo[$key])) {
            try {
                $pdo->query("SELECT valid_from, valid_to FROM sh_menu_items LIMIT 0");
                self::$hasValidFromTo[$key] = true;
            } catch (\PDOException $e) {
                self::$hasValidFromTo[$key] = false;
            }
        }
        return self::$hasValidFromTo[$key];
    }

    private static function itemsHasIsSecret(PDO $pdo): bool
    {
        $key = spl_object_id($pdo);
        if (!isset(self::$hasIsSecret[$key])) {
            try {
                $pdo->query("SELECT is_secret FROM sh_menu_items LIMIT 0");
                self::$hasIsSecret[$key] = true;
            } catch (\PDOException $e) {
                self::$hasIsSecret[$key] = false;
            }
        }
        return self::$hasIsSecret[$key];
    }

    private static function mealsHasPubStatus(PDO $pdo): bool
    {
        $key = spl_object_id($pdo) . '_meals';
        if (!isset(self::$hasPubStatus[$key])) {
            try {
                $pdo->query("SELECT publication_status FROM sh_meal_packages LIMIT 0");
                self::$hasPubStatus[$key] = true;
            } catch (\PDOException $e) {
                self::$hasPubStatus[$key] = false;
            }
        }
        return self::$hasPubStatus[$key];
    }

    private static function mealsHasValidFromTo(PDO $pdo): bool
    {
        $key = spl_object_id($pdo) . '_meals_vt';
        if (!isset(self::$hasValidFromTo[$key])) {
            try {
                $pdo->query("SELECT valid_from, valid_to FROM sh_meal_packages LIMIT 0");
                self::$hasValidFromTo[$key] = true;
            } catch (\PDOException $e) {
                self::$hasValidFromTo[$key] = false;
            }
        }
        return self::$hasValidFromTo[$key];
    }

    private static function modGroupsHasPubStatus(PDO $pdo): bool
    {
        $key = spl_object_id($pdo) . '_modg';
        if (!isset(self::$hasPubStatus[$key])) {
            try {
                $pdo->query("SELECT publication_status FROM sh_modifier_groups LIMIT 0");
                self::$hasPubStatus[$key] = true;
            } catch (\PDOException $e) {
                self::$hasPubStatus[$key] = false;
            }
        }
        return self::$hasPubStatus[$key];
    }
}
