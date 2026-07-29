<?php
require_once __DIR__ . '/../../../core/db_config.php';
$stmt = $pdo->query("SELECT id, tenant_id, provider, is_active, credentials FROM sh_tenant_integrations WHERE provider = 'choiceqr'");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
if (empty($rows)) {
    echo "No choiceqr integration found. Inserting test row...\n";
    $pdo->exec("INSERT INTO sh_tenant_integrations (tenant_id, provider, display_name, api_base_url, credentials, direction, is_active) VALUES (1, 'choiceqr', 'ChoiceQR', 'https://open-api.choiceqr.com', '{\"token\":\"test_jwt_token\",\"webhook_token\":\"test_secret_token\",\"var_symbol\":\"10102\"}', 'bidirectional', 1) ON DUPLICATE KEY UPDATE is_active=1");
    echo "Test row inserted.\n";
}
