<?php

declare(strict_types=1);

/**
 * Kanoniczna kolejność migracji SQL (database/migrations/*.sql).
 *
 * Jedno źródło prawdy używane przez:
 *   - scripts/apply_migrations_chain.php
 *   - scripts/install_panel.php
 *
 * UWAGA: dodając nową migrację, dopisz ją TUTAJ. Audyt w
 * collectChainIntegrityIssues() zgłosi rozbieżność, jeśli zapomnisz.
 *
 * 015_normalize_three_drivers.sql jest ŚWIADOMIE pominięte w chain
 * (DELETE/UPDATE na tenant 1, tylko --include-015 w CLI).
 */

return [
    '004_expand_search_aliases.sql',
    '006_studio_mission_control.sql',
    '007_pos_engine_columns.sql',
    '008_delivery_ecosystem.sql',
    '009_delivery_state_machine.sql',
    '010_driver_action_type.sql',
    '011_integration_logs.sql',
    '012_visual_layers.sql',
    '013_board_companions.sql',
    '014_global_assets.sql',
    '016_visual_compositor_upgrade.sql',
    '017_online_module_extensions.sql',
    '019_layer_positioning.sql',
    '020_director_scenes.sql',
    '021_unified_asset_library.sql',
    '022_scene_kit.sql',
    '023_scene_templates_content.sql',
    '024_modifier_visual_impact.sql',
    '025_drop_legacy_magic_dict.sql',
    '026_event_system.sql',
    '027_gateway_v2.sql',
    '028_integration_deliveries.sql',
    '029_infrastructure_completion.sql',
    '030_scene_harmony_cache.sql',
    '031_baked_variants.sql',
    '032_asset_library_organizer.sql',
    '033_notification_director.sql',
    '034_faza7_gdpr_security.sql',
    '035_atelier_performance.sql',
    '036_asset_display_name.sql',
    '037_pos_foundation.sql',
    '038_drop_legacy_inventory_docs.sql',
    '039_resilient_pos.sql',
    '040_pos_server_events.sql',
    '041_hr_employees_foundation.sql',
    '042_hr_work_sessions_extend.sql',
    '043_hr_payroll_ledger.sql',
    '044_hr_advances.sql',
    '045_tenant_legal_profile.sql',
    '046_ksef_inbox.sql',
    '047_order_geocoding.sql',
    '048_variant_scales.sql',
    '049_modifier_size_pricing.sql',
    '050_meal_packages.sql',
];
