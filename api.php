<?php
// +---------------------------------------------------------------------+
// | api.php – AJAX-Backend für DEMO_SESSION / ADJACENCY_LIST            |
// +---------------------------------------------------------------------+

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(0);
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// ── Config laden ──────────────────────────────────────────────────────
function respond(bool $success, mixed $data = null, string $error = ''): never {
    ob_end_clean();
    echo json_encode(['success' => $success, 'data' => $data, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

$config = __DIR__ . '/config.php';
if (!file_exists($config)) {
    respond(false, null, 'config.php fehlt. Bitte config.example.php kopieren und befüllen.');
}
require_once $config;

require_once __DIR__ . '/ADJACENCY_LIST.php';
require_once __DIR__ . '/KATEGORIE.php';
require_once __DIR__ . '/DEMO_SESSION.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    $demo = new DEMO_SESSION(DB_HOST, DB_NAME, DB_USER, DB_PASSWORD);

    // ── Cleanup bei jeder X-ten Anfrage (1 von 20) ───────────────────
    if (random_int(1, 20) === 1) {
        $demo->cleanup_expired();
    }

    // ── Session initialisieren ────────────────────────────────────────
    if ($action === 'init_session') {
        $session = $demo->init_session();
        respond(true, ['is_new' => $session['is_new']]);
    }

    // Für alle anderen Actions: Session validieren
    $session    = $demo->init_session();
    $demoTable  = $session['table'];

    switch ($action) {

		case 'tree':
			$root_id = (int)($_POST['root_id'] ?? $_GET['root_id'] ?? 1);
			try {
				$data = $tree->get_menge_with_meta($root_id, DB_TABLE);
			} catch (RuntimeException) {
				$data = [];
			}
			respond(true, $data ?: []);

		case 'root_id':
			respond(true, $tree->get_root_id(DB_TABLE));

        case 'meta_cols':
            respond(true, $demo->get_meta_col_names($demoTable));

        case 'update_meta':
            $node_id = (int)($_POST['node_id'] ?? 0);
            if (!$node_id) respond(false, null, 'node_id fehlt.');
            if (!empty($_POST['data'])) {
                $data = json_decode($_POST['data'], true);
                if (!is_array($data)) respond(false, null, 'Ungültiges JSON.');
            } else {
                $col = trim($_POST['col'] ?? '');
                $val = $_POST['val'] ?? '';
                if ($col === '') respond(false, null, 'col fehlt.');
                $data = [$col => $val];
            }
            $demo->update_meta($node_id, $demoTable, $data);
            respond(true);

        case 'delete_col':
            $col = trim($_POST['col'] ?? '');
            if ($col === '') respond(false, null, 'col fehlt.');
            $demo->delete_col($demoTable, $col);
            respond(true);

        case 'rename':
            $id   = (int)($_POST['id']   ?? 0);
            $name = trim($_POST['name']  ?? '');
            if (!$id || $name === '') respond(false, null, 'Ungültige Daten.');
            $demo->rename_knoten($id, $name, $demoTable);
            respond(true);

        case 'insert':
            $name      = trim($_POST['name'] ?? '');
            $parent_id = isset($_POST['parent_id']) && $_POST['parent_id'] !== ''
                         ? (int)$_POST['parent_id'] : null;
            if ($name === '') respond(false, null, 'Name darf nicht leer sein.');
            $demo->check_node_limit($demoTable);
            $ok = $demo->insert_knoten($name, $demoTable, $parent_id);
            respond($ok, null, $ok ? '' : 'Insert fehlgeschlagen.');

        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            $demo->del_knoten($id, $demoTable);
            respond(true);

        case 'reorder':
            $id        = (int)($_POST['id'] ?? 0);
            $direction = $_POST['direction'] ?? '';
            if (!in_array($direction, ['links', 'rechts'], true)) {
                respond(false, null, "Ungültige Richtung: '{$direction}'.");
            }
            $demo->reorder_knoten($id, $direction, $demoTable);
            respond(true);

        case 'move':
            $id            = (int)($_POST['id'] ?? 0);
            $new_parent_id = (int)($_POST['new_parent_id'] ?? 0);
            $demo->move_knoten($id, $new_parent_id, $demoTable);
            respond(true);

        default:
            respond(false, null, "Unbekannte Aktion: '{$action}'.");
    }

} catch (InvalidArgumentException $e) {
    respond(false, null, 'Ungültige Parameter: ' . $e->getMessage());
} catch (RuntimeException $e) {
    respond(false, null, 'Fehler: ' . $e->getMessage());
} catch (PDOException $e) {
    respond(false, null, 'Datenbankfehler: ' . $e->getMessage());
} catch (Throwable $e) {
    respond(false, null, 'Unbekannter Fehler: ' . $e->getMessage());
}
