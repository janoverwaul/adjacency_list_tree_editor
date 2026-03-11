<?php
// +---------------------------------------------------------------------+
// | api.php – AJAX-Backend für ADJACENCY_LIST + KATEGORIE               |
// +---------------------------------------------------------------------+

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(0);
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// ── Konfiguration ── HIER ANPASSEN ────────────────────────────────────
$config = __DIR__ . '/config.php';
if (!file_exists($config)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'data' => null, 'error' => 'config.php fehlt. Bitte config.example.php kopieren und befüllen.']);
    exit;
}
require_once $config;
// ──────────────────────────────────────────────────────────────────────

require_once 'ADJACENCY_LIST.php';
require_once 'KATEGORIE.php';

function respond(bool $success, mixed $data = null, string $error = ''): never {
    ob_end_clean();
    echo json_encode(['success' => $success, 'data' => $data, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    $tree = new KATEGORIE(DB_HOST, DB_NAME, DB_USER, DB_PASSWORD);

    switch ($action) {

        // -----------------------------------------------------------------
        // Baum + Meta laden
        // -----------------------------------------------------------------
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

        // -----------------------------------------------------------------
        // Verfügbare Meta-Spalten abfragen
        // -----------------------------------------------------------------
        case 'meta_cols':
            $cols = $tree->get_meta_col_names(DB_TABLE);
            respond(true, $cols);

        // -----------------------------------------------------------------
        // Meta-Werte eines Knotens speichern (+ Spalte auto-anlegen)
        // POST action=update_meta &node_id=... &col=... &val=...
        // ODER &data={"col1":"val1","col2":"val2"}
        // -----------------------------------------------------------------
        case 'update_meta':
            $node_id = (int)($_POST['node_id'] ?? 0);
            if (!$node_id) respond(false, null, 'node_id fehlt.');

            // JSON-Batch oder einzelnes col/val
            if (!empty($_POST['data'])) {
                $data = json_decode($_POST['data'], true);
                if (!is_array($data)) respond(false, null, 'Ungültiges JSON in data.');
            } else {
                $col = trim($_POST['col'] ?? '');
                $val = $_POST['val'] ?? '';
                if ($col === '') respond(false, null, 'col fehlt.');
                $data = [$col => $val];
            }

            $tree->update_meta($node_id, DB_TABLE, $data);
            respond(true);

        // -----------------------------------------------------------------
        // Meta-Spalte löschen
        // POST action=delete_col &col=...
        // -----------------------------------------------------------------
        case 'delete_col':
            $col = trim($_POST['col'] ?? '');
            if ($col === '') respond(false, null, 'col fehlt.');
            $tree->delete_col(DB_TABLE, $col);
            respond(true);

        // -----------------------------------------------------------------
        // Baum-Aktionen (unverändert)
        // -----------------------------------------------------------------
		case 'rename':
			$id   = (int)($_POST['id']   ?? 0);
			$name = trim($_POST['name']  ?? '');
			if (!$id || $name === '') respond(false, null, 'Ungültige Daten.');
			$tree->rename_knoten($id, $name, DB_TABLE);
			respond(true);

        case 'insert':
            $name      = trim($_POST['name'] ?? '');
            $parent_id = isset($_POST['parent_id']) && $_POST['parent_id'] !== ''
                         ? (int)$_POST['parent_id'] : null;
            if ($name === '') respond(false, null, 'Name darf nicht leer sein.');
            $ok = $tree->insert_knoten($name, DB_TABLE, $parent_id);
            respond($ok, null, $ok ? '' : 'Insert fehlgeschlagen.');

        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            $tree->del_knoten($id, DB_TABLE);
            respond(true);

        case 'reorder':
            $id        = (int)($_POST['id'] ?? 0);
            $direction = $_POST['direction'] ?? '';
            if (!in_array($direction, ['links', 'rechts'], true)) {
                respond(false, null, "Ungültige Richtung: '{$direction}'.");
            }
            $tree->reorder_knoten($id, $direction, DB_TABLE);
            respond(true);

        case 'move':
            $id            = (int)($_POST['id'] ?? 0);
            $new_parent_id = (int)($_POST['new_parent_id'] ?? 0);
            $tree->move_knoten($id, $new_parent_id, DB_TABLE);
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
?>
