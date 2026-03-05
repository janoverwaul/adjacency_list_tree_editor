<?php
// +---------------------------------------------------------------------+
// | Klasse KATEGORIE                                                    |
// | Erweitert ADJACENCY_LIST um eine 1:1-Meta-Tabelle                   |
// +---------------------------------------------------------------------+
// | Meta-Spalten werden automatisch angelegt (ALTER TABLE).             |
// | Nur definierte Meta-Spalten können gelöscht werden –                |
// | id und node_id sind geschützt.                                      |
// +---------------------------------------------------------------------+

declare(strict_types=1);

require_once 'ADJACENCY_LIST.php';

class KATEGORIE extends ADJACENCY_LIST {

    public const VERSION = '1.0';
    public const AUTOR   = 'Jan Overwaul + claude.ai';
    public const KLASSE  = 'KATEGORIE';

    /**
     * Geschützte Systemspalten – dürfen nie gelöscht oder überschrieben werden.
     */
    private const PROTECTED_COLUMNS = ['id', 'node_id'];

    /**
     * Suffix für die automatisch abgeleitete Meta-Tabellenname.
     * kategorien → kategorien_meta
     */
    private const META_SUFFIX = '_meta';

    // -------------------------------------------------------------------------
    // Private Hilfsmethoden
    // -------------------------------------------------------------------------

    /**
     * Gibt den Meta-Tabellennamen zur Haupt-Tabelle zurück.
     */
    private function meta_table(string $sql_table): string {
        return $sql_table . self::META_SUFFIX;
    }

    /**
     * Stellt sicher dass die Meta-Tabelle existiert.
     * Legt sie an falls nicht vorhanden.
     */
    protected function ensure_meta_table(string $sql_table): void {
        $meta = $this->meta_table($sql_table);
        $escaped = $this->pdo->quote($meta);

        $stmt = $this->pdo->query("SHOW TABLES LIKE {$escaped}");
        if ($stmt->rowCount() > 0) {
            return;
        }

        $this->pdo->exec("
            CREATE TABLE `{$meta}` (
                `id`      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `node_id` INT UNSIGNED NOT NULL UNIQUE,
                FOREIGN KEY (`node_id`) REFERENCES `{$sql_table}`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    /**
     * Gibt alle aktuellen Spalten der Meta-Tabelle zurück.
     */
    private function get_meta_columns(string $sql_table): array {
        $meta = $this->meta_table($sql_table);
        $stmt = $this->pdo->query("SHOW COLUMNS FROM `{$meta}`");
        return array_column($stmt->fetchAll(), 'Field');
    }

    /**
     * Fügt eine neue Spalte zur Meta-Tabelle hinzu falls noch nicht vorhanden.
     * Typ immer TEXT – flexibel genug für alle Meta-Werte.
     */
    private function ensure_meta_column(string $sql_table, string $column): void {
        if (in_array($column, self::PROTECTED_COLUMNS, true)) {
            throw new InvalidArgumentException(
                "Spalte '{$column}' ist geschützt und kann nicht angelegt werden."
            );
        }

        $existing = $this->get_meta_columns($sql_table);
        if (in_array($column, $existing, true)) {
            return; // Spalte existiert bereits
        }

        $meta = $this->meta_table($sql_table);
        // Spaltenname wird validiert – nur Buchstaben, Zahlen, Unterstriche erlaubt
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/', $column)) {
            throw new InvalidArgumentException(
                "Ungültiger Spaltenname: '{$column}'. Nur Buchstaben, Zahlen und _ erlaubt."
            );
        }

        $this->pdo->exec("ALTER TABLE `{$meta}` ADD COLUMN `{$column}` TEXT NULL");
    }

    // -------------------------------------------------------------------------
    // Öffentliche Methoden
    // -------------------------------------------------------------------------

    /**
     * Holt alle Knoten des Baums inkl. Meta-Daten via LEFT JOIN.
     *
     * @param int         $meng_num  ID des Wurzelknotens
     * @param string      $sql_table Tabellenname
     * @param string|null $secure    'secure' = nur online=1
     * @return array|false
     */
    public function get_menge_with_meta(int $meng_num, string $sql_table, ?string $secure = null): array|false {
        $this->ensure_meta_table($sql_table);

        // Baum holen
        $nodes = parent::get_menge($meng_num, $sql_table, $secure);
        if (!$nodes) {
            return false;
        }

        $meta = $this->meta_table($sql_table);

        // Meta-Spalten ermitteln (ohne id und node_id)
        $all_cols = $this->get_meta_columns($sql_table);
        $meta_cols = array_values(array_diff($all_cols, self::PROTECTED_COLUMNS));

        if (empty($meta_cols)) {
            // Noch keine Meta-Spalten vorhanden → Nodes direkt zurück mit leerem meta-Array
            return array_map(fn($n) => array_merge($n, ['_meta' => []]), $nodes);
        }

        // Alle Meta-Zeilen auf einmal holen
        $ids       = implode(',', array_column($nodes, 'id'));
        $col_list  = implode(', ', array_map(fn($c) => "`{$c}`", $meta_cols));
        $stmt      = $this->pdo->query(
            "SELECT node_id, {$col_list} FROM `{$meta}` WHERE node_id IN ({$ids})"
        );
        $meta_rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $node_id = $row['node_id'];
            unset($row['node_id']);
            $meta_rows[$node_id] = $row;
        }

        // Meta in Nodes einbauen
        return array_map(function ($n) use ($meta_rows, $meta_cols) {
            $n['_meta'] = $meta_rows[$n['id']] ?? array_fill_keys($meta_cols, null);
            return $n;
        }, $nodes);
    }

    /**
     * Speichert Meta-Werte für einen Knoten.
     * Neue Spalten werden automatisch per ALTER TABLE angelegt.
     *
     * @param int    $node_id    ID des Knotens
     * @param string $sql_table  Tabellenname
     * @param array  $data       Assoziatives Array ['spalte' => 'wert', ...]
     * @return bool
     * @throws InvalidArgumentException bei geschützten oder ungültigen Spaltennahmen
     */
    public function update_meta(int $node_id, string $sql_table, array $data): bool {
        if (empty($data)) {
            return true;
        }

        $this->ensure_meta_table($sql_table);

        // Alle Spalten ggf. anlegen
        foreach (array_keys($data) as $col) {
            $this->ensure_meta_column($sql_table, (string)$col);
        }

        $meta = $this->meta_table($sql_table);

        // UPSERT: Meta-Zeile anlegen oder aktualisieren
        $cols    = array_keys($data);
        $colList = implode(', ', array_map(fn($c) => "`{$c}`", $cols));
        $updates = implode(', ', array_map(fn($c) => "`{$c}` = VALUES(`{$c}`)", $cols));
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));

        $sql  = "INSERT INTO `{$meta}` (node_id, {$colList})
                 VALUES (?, {$placeholders})
                 ON DUPLICATE KEY UPDATE {$updates}";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$node_id, ...array_values($data)]);
    }

    /**
     * Löscht eine Meta-Spalte aus der Meta-Tabelle.
     * Systemspalten (id, node_id) sind geschützt.
     *
     * @param string $sql_table  Tabellenname
     * @param string $column     Spaltenname
     * @return bool
     * @throws InvalidArgumentException bei geschützten Spalten
     * @throws RuntimeException wenn Spalte nicht existiert
     */
    public function delete_col(string $sql_table, string $column): bool {
        if (in_array($column, self::PROTECTED_COLUMNS, true)) {
            throw new InvalidArgumentException(
                "Spalte '{$column}' ist geschützt und kann nicht gelöscht werden."
            );
        }

        $this->ensure_meta_table($sql_table);
        $existing = $this->get_meta_columns($sql_table);

        if (!in_array($column, $existing, true)) {
            throw new RuntimeException(
                "Spalte '{$column}' existiert nicht in der Meta-Tabelle."
            );
        }

        $meta = $this->meta_table($sql_table);
        $this->pdo->exec("ALTER TABLE `{$meta}` DROP COLUMN `{$column}`");
        return true;
    }

    /**
     * Gibt alle aktuellen Meta-Spalten zurück (ohne Systemspalten).
     *
     * @param string $sql_table Tabellenname
     * @return array
     */
    public function get_meta_col_names(string $sql_table): array {
        $this->ensure_meta_table($sql_table);
        $all = $this->get_meta_columns($sql_table);
        return array_values(array_diff($all, self::PROTECTED_COLUMNS));
    }

}
