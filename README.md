# KATEGORIE

PHP-Klasse zur Verwaltung hierarchischer Baumstrukturen mit dynamischen Meta-Daten.  
Erweitert [`ADJACENCY_LIST`](./README.md) um eine automatisch verwaltete 1:1-Meta-Tabelle.

**Version:** 1.0 · **Autor:** Jan Overwaul + claude.ai · **Stand:** 2026

---

## Voraussetzungen

- PHP 8.0+
- MySQL 8.0+ oder MariaDB 10.2+
- [`ADJACENCY_LIST`](./README.md) (wird per `require_once 'ADJACENCY_LIST.php'` eingebunden)

---

## Installation

```php
require_once 'ADJACENCY_LIST.php'; // ADJACENCY_LIST
require_once 'KATEGORIE.php';

$kat = new KATEGORIE(
    host:     'localhost',
    dbname:   'meine_datenbank',
    user:     'db_user',
    password: 'geheim'
);
```

---

## Abhängigkeit

Benötigt die Basisklasse [ADJACENCY_LIST](https://github.com/janoverwaul/adjacency_list).
```

---

## Konzept: Haupt- und Meta-Tabelle

Für jede Haupt-Tabelle (z.B. `kategorien`) wird automatisch eine zweite Tabelle angelegt:

| Tabelle           | Inhalt                                              |
|-------------------|-----------------------------------------------------|
| `kategorien`      | Baumstruktur – id, name, parent_id, sort_order, online |
| `kategorien_meta` | Beliebige Meta-Felder, verknüpft per `node_id`      |

Die Verknüpfung erfolgt über einen **Foreign Key mit `ON DELETE CASCADE`** – wird ein Knoten gelöscht, verschwinden seine Meta-Daten automatisch mit.

Meta-Spalten haben immer den Typ `TEXT` und werden **on demand** per `ALTER TABLE` angelegt. Es müssen keine Spalten vorab definiert werden.

### Geschützte Systemspalten

Die Spalten `id` und `node_id` der Meta-Tabelle sind unveränderlich und können weder angelegt noch gelöscht werden.

---

## Methoden

`KATEGORIE` erbt alle Methoden von `ADJACENCY_LIST` unverändert. Zusätzlich stehen zur Verfügung:

### `get_menge_with_meta()`

Wie `get_menge()`, aber jeder Knoten enthält zusätzlich einen `_meta`-Schlüssel mit allen Meta-Werten.

```php
$result = $kat->get_menge_with_meta(
    meng_num:  1,
    sql_table: 'kategorien',
    secure:    'secure'   // optional: nur online=1
);

foreach ($result as $knoten) {
    echo $knoten['name'];
    echo $knoten['_meta']['beschreibung'] ?? '–';
}
```

Knoten ohne Meta-Eintrag erhalten ein `_meta`-Array mit `null`-Werten für alle bekannten Spalten.

---

### `update_meta()`

Speichert Meta-Werte für einen Knoten. Nicht vorhandene Spalten werden automatisch angelegt.  
Existiert bereits ein Meta-Eintrag für den Knoten, wird er aktualisiert (UPSERT).

```php
$kat->update_meta(
    node_id:   4,
    sql_table: 'kategorien',
    data: [
        'beschreibung' => 'Alle Smartphones im Sortiment',
        'bild'         => 'smartphones.jpg',
        'seo_title'    => 'Smartphones kaufen',
    ]
);
```

> Spaltennahmen dürfen nur Buchstaben, Zahlen und `_` enthalten und müssen mit einem Buchstaben oder `_` beginnen (max. 64 Zeichen).

---

### `delete_col()`

Löscht eine Meta-Spalte dauerhaft aus der Meta-Tabelle – **inklusive aller darin gespeicherten Werte**.

```php
$kat->delete_col('kategorien', 'seo_title');
```

> ⚠️ Irreversibel. Systemspalten (`id`, `node_id`) sind geschützt und werfen eine `InvalidArgumentException`.

---

### `get_meta_col_names()`

Gibt alle aktuell vorhandenen Meta-Spalten zurück (ohne Systemspalten).

```php
$spalten = $kat->get_meta_col_names('kategorien');
// ['beschreibung', 'bild', 'seo_title']
```

Nützlich um dynamisch Formulare oder Tabellenköpfe zu generieren.

---

## Vollständiges Beispiel

```php
require_once 'ADJACENCY_LIST.php';
require_once 'KATEGORIE.php';

$kat = new KATEGORIE('localhost', 'shop', 'root', 'geheim');

// Baumstruktur aufbauen (geerbt von ADJACENCY_LIST)
$kat->insert_knoten('Elektronik',  'kategorien');       // ID 1
$kat->insert_knoten('Smartphones', 'kategorien', 1);    // ID 2
$kat->insert_knoten('Laptops',     'kategorien', 1);    // ID 3

// Meta-Daten speichern – Spalten werden automatisch angelegt
$kat->update_meta(1, 'kategorien', [
    'beschreibung' => 'Unsere Elektronik-Kategorie',
    'bild'         => 'elektronik.jpg',
]);
$kat->update_meta(2, 'kategorien', [
    'beschreibung' => 'Alle Smartphones',
    'bild'         => 'smartphones.jpg',
    'seo_title'    => 'Smartphones günstig kaufen',
]);

// Baum mit Meta-Daten abrufen
$baum = $kat->get_menge_with_meta(1, 'kategorien');
foreach ($baum as $knoten) {
    $indent = str_repeat('  ', $knoten['LEVEL']);
    echo $indent . $knoten['name'] . "\n";
    echo $indent . '  Bild: ' . ($knoten['_meta']['bild'] ?? '–') . "\n";
}

// Welche Meta-Spalten gibt es?
print_r($kat->get_meta_col_names('kategorien'));
// ['beschreibung', 'bild', 'seo_title']

// Spalte entfernen
$kat->delete_col('kategorien', 'seo_title');

// Knoten löschen – Meta-Daten werden per CASCADE mitgelöscht
$kat->del_knoten(2, 'kategorien');
```

---

## Fehlerbehandlung

Zusätzlich zu den Exceptions aus `ADJACENCY_LIST`:

| Exception                  | Ursache                                                          |
|----------------------------|------------------------------------------------------------------|
| `InvalidArgumentException` | Geschützte Spalte (`id`, `node_id`) oder ungültiger Spaltenname  |
| `RuntimeException`         | Spalte zum Löschen existiert nicht                               |

```php
try {
    $kat->delete_col('kategorien', 'node_id'); // geschützt!
} catch (InvalidArgumentException $e) {
    echo $e->getMessage();
}

try {
    $kat->update_meta(99, 'kategorien', ['foo bar' => 'wert']); // ungültiger Name
} catch (InvalidArgumentException $e) {
    echo $e->getMessage();
}
```

---

## Vererbungshierarchie

```
ADJACENCY_LIST          Baumstruktur (parent_id, sort_order, rekursive CTEs)
    └── KATEGORIE       + dynamische Meta-Tabelle (_meta-Spalten per ALTER TABLE)
```

Alle Methoden von `ADJACENCY_LIST` (`get_menge`, `insert_knoten`, `del_knoten`, `reorder_knoten`, `move_knoten`) stehen in `KATEGORIE` unverändert zur Verfügung. Siehe [README ADJACENCY_LIST](./README.md).
