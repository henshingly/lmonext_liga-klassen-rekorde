<?php
/**
 * Project: LMOnext
 * Filename: addon/liga-klassen-rekorde/handler_ligaklassen.php
 * Fileversion: 1.3.0
 *
 * PHP version 8.2
 *
 * @author    Dietmar Kersting <webmaster@liga-manager-online.org>
 * @author    Torsten Hofmann <entwickler@bastel-code.de>
 * @copyright 2026 Dietmar Kersting, Torsten Hofmann
 * @license   GPL-3.0-only
 *
 * Liga-Klassen (Beitrag: Konzeptdiskussion 17.08.2026) - bündelt mehrere
 * Saison-Instanzen derselben Wettbewerbsserie (z.B. "Volleyball 3.Liga Nord
 * Männer" über mehrere Jahre) als Grundlage für Meisterliste/Rekordspiele
 * (siehe addon/rekorde/) und künftig Auf-/Absteiger.
 *
 * Wird von AddonManager::bootAdmin() auf JEDER Admin-Seite geladen (solange
 * dieses Addon aktiv ist) - die eigentlichen POST-Aktionen unten prüfen
 * daher selbst $action/$_SERVER['REQUEST_METHOD'], analog zu den anderen
 * Addon-Handlern (siehe addon/tipp/handler_tipp.php).
 *
 * Schema-Erweiterung (einmalig, additiv, keine bestehenden Daten werden
 * verändert): legt bei der ersten Ausführung nach Aktivierung die Tabelle
 * liga_klassen an sowie zwei NULLABLE Spalten (saison, klasse_id) auf der
 * Core-Tabelle liga. Wird per addonManager()->getSetting()/setSetting()
 * genau einmal ausgeführt (Flag "liga_klassen_schema_v1"), nicht bei jedem
 * Seitenaufruf erneut geprüft.
 */
declare(strict_types=1);

// ── Einmalige Schema-Migration ────────────────────────────────────────────
// Prueft NICHT nur das "erledigt"-Flag (das bei einem DB-Restore aus einer
// anderen Installation mitkopiert werden kann, obwohl die eigentliche
// Tabellenstruktur auf DIESER Datenbank fehlt oder unvollstaendig ist),
// sondern verifiziert die tatsaechliche Tabellen-/Spaltenexistenz zusaetzlich
// mit einem guenstigen SHOW-Aufruf. Nur wenn wirklich alles vorhanden ist,
// wird die Migration uebersprungen.
if (function_exists('addonManager')) {
    $am = addonManager();
    $schemaOk = false;
    if ($am->getSetting('liga_klassen_schema_v1', '') === 'done') {
        try {
            $db0 = getDB();
            $hasTable = (bool)$db0->query("SHOW TABLES LIKE " . $db0->quote(trim(tbl('liga_klassen'), '`')))->fetchColumn();
            $cols0 = $hasTable ? $db0->query('SHOW COLUMNS FROM ' . tbl('liga'))->fetchAll(\PDO::FETCH_COLUMN, 0) : [];
            $schemaOk = $hasTable && in_array('saison', $cols0, true) && in_array('klasse_id', $cols0, true);
        } catch (\Throwable) {
            $schemaOk = false;
        }
    }
    if (!$schemaOk) {
        try {
            $db = getDB();
            $db->exec(
                'CREATE TABLE IF NOT EXISTS ' . tbl('liga_klassen') . ' (
                    id           INT AUTO_INCREMENT PRIMARY KEY,
                    name         VARCHAR(255) NOT NULL DEFAULT \'\',
                    sport_type   VARCHAR(20)  NOT NULL DEFAULT \'football\',
                    beschreibung TEXT         NOT NULL,
                    UNIQUE KEY uniq_klasse_name (sport_type, name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );

            // Spalten nur ergänzen, wenn sie noch nicht existieren (additiv,
            // NULLABLE - keine Auswirkung auf bestehende Ligen/Ergebnisse).
            $cols = $db->query('SHOW COLUMNS FROM ' . tbl('liga'))->fetchAll(\PDO::FETCH_COLUMN, 0);
            if (!in_array('saison', $cols, true)) {
                $db->exec('ALTER TABLE ' . tbl('liga') . ' ADD COLUMN saison VARCHAR(20) NULL DEFAULT NULL AFTER name');
            }
            if (!in_array('klasse_id', $cols, true)) {
                $db->exec('ALTER TABLE ' . tbl('liga') . ' ADD COLUMN klasse_id INT NULL DEFAULT NULL, ADD KEY klasse_id (klasse_id)');
            }

            $am->setSetting('liga_klassen_schema_v1', 'done');
        } catch (\Throwable $e) {
            // Migration fehlgeschlagen (z.B. fehlende DB-Rechte) - kein
            // harter Abbruch der Seite, aber auch kein "done"-Flag, damit
            // der nächste Seitenaufruf es erneut versucht.
            error_log('[liga-klassen] Schema-Migration fehlgeschlagen: ' . $e->getMessage());
        }
    }
}

/**
 * Schätzt aus einem Liganamen den "Stamm" ohne Saison-/Jahres-Bestandteile
 * (Jahreszahlen, "2013/14", "2013-2014" etc.) und normalisiert Leerraum,
 * damit z.B. "Volleyball 3.Liga Nord Männer 2013/14 -X" und "Volleyball
 * 3.Liga Nord Männer 2014/15 -X" auf denselben Stamm "Volleyball 3.Liga
 * Nord Männer -X" abgebildet werden. Rein heuristisch - dient nur als
 * Vorschlag, den der Admin bestätigen oder verwerfen kann, nie als
 * automatische Zuordnung ohne Bestätigung.
 */
function ligaklassenSuggestStem(string $name): string
{
    $s = preg_replace('/\b(19|20)\d{2}\s*[\/\-]\s*(\d{2}|\d{4})\b/u', '', $name);
    $s = preg_replace('/\b(19|20)\d{2}\b/u', '', (string)$s);
    $s = preg_replace('/\s{2,}/u', ' ', (string)$s);
    return trim((string)$s);
}

// ── Daten für die Ligaklassen-Übersicht (view_ligaklassen.php) ─────────────
// WICHTIG: handler_ligaklassen.php wird von AddonManager::bootAdmin() per
// require_once INNERHALB einer Klassenmethode geladen (nicht im globalen
// Scope von admin.php). Ohne "global" wuerde $ligaKlassenData nur lokal in
// bootAdmin() gesetzt und waere in view_ligaklassen.php (das admin.php
// direkt im globalen Scope requir't) unsichtbar - dort dann still null,
// keine Fehlermeldung, nur eine leer wirkende Liste trotz vorhandener
// Daten in der DB.
global $ligaKlassenData;
$ligaKlassenData = null;
if ($action === 'ligaklassen' && isLoggedIn()) {
    try {
        $db = getDB();
        $klassen = $db->query(
            'SELECT k.*, COUNT(l.id) AS liga_count
               FROM ' . tbl('liga_klassen') . ' k
               LEFT JOIN ' . tbl('liga') . ' l ON l.klasse_id = k.id
              GROUP BY k.id
              ORDER BY k.sport_type, k.name'
        )->fetchAll();

        $ligenByKlasse = [];
        $ligenRows = $db->query(
            'SELECT id, name, saison, klasse_id, sport_type, datum FROM ' . tbl('liga') . ' ORDER BY datum ASC'
        )->fetchAll();
        foreach ($ligenRows as $l) {
            if ($l['klasse_id'] !== null) {
                $ligenByKlasse[(int)$l['klasse_id']][] = $l;
            }
        }

        // Unzugeordnete Ligen (klasse_id IS NULL) fuer den "Zuordnen"-Bereich
        // und die Migrations-Vorschläge.
        $unassigned = array_values(array_filter($ligenRows, fn($l) => $l['klasse_id'] === null));

        // Migrations-Vorschläge: unzugeordnete Ligen nach geschätztem
        // Wettbewerbsstamm UND Sportart gruppieren, nur Gruppen mit mind. 2
        // Mitgliedern als Vorschlag anzeigen (eine einzelne Liga
        // "vorzuschlagen" wäre keine sinnvolle Wettbewerbsserie).
        $stemGroups = [];
        foreach ($unassigned as $l) {
            $stem = ligaklassenSuggestStem($l['name']);
            if ($stem === '') { continue; }
            $key = $l['sport_type'] . '|' . $stem;
            $stemGroups[$key]['stem'] = $stem;
            $stemGroups[$key]['sport_type'] = $l['sport_type'];
            $stemGroups[$key]['ligen'][] = $l;
        }
        $suggestions = array_values(array_filter($stemGroups, fn($g) => count($g['ligen']) >= 2));
        usort($suggestions, fn($a, $b) => count($b['ligen']) <=> count($a['ligen']));

        $ligaKlassenData = [
            'klassen'       => $klassen,
            'ligenByKlasse' => $ligenByKlasse,
            'unassigned'    => $unassigned,
            'suggestions'   => $suggestions,
        ];
    } catch (\Throwable $e) {
        error_log('[liga-klassen-rekorde] Fehler beim Laden der Klassen-Liste: ' . $e->getMessage());
        $ligaKlassenData = ['klassen' => [], 'ligenByKlasse' => [], 'unassigned' => [], 'suggestions' => []];
        // Sichtbarer Hinweis fuer den Admin, statt eines stillen leeren
        // Zustands (der aussieht wie "noch keine Klassen angelegt", obwohl
        // in Wahrheit ein DB-Fehler vorliegt - z.B. nach einem DB-Restore
        // aus einer anderen Installation mit abweichender SQL-Mode-
        // Konfiguration o.ae.).
        flash(t('lk_flash_list_error', ['msg' => $e->getMessage()]), 'error');
    }
}

// ── Liga-Klasse anlegen/bearbeiten ──────────────────────────────────────────
if ($action === 'save_liga_klasse' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireLogin();
    $id        = (int)($_POST['klasse_id']    ?? 0);
    $name      = trim($_POST['klasse_name']   ?? '');
    $sportType = trim($_POST['klasse_sport']  ?? 'football');
    $beschr    = trim($_POST['klasse_beschr'] ?? '');
    if (!in_array($sportType, ['football', 'volleyball', 'icehockey', 'basketball', 'handball', 'badminton'], true)) {
        $sportType = 'football';
    }
    if ($name === '') { flash(t('hl_flash_name_required'), 'error'); redirect('?action=ligaklassen'); }
    try {
        $db = getDB();
        if ($id > 0) {
            $db->prepare('UPDATE ' . tbl('liga_klassen') . ' SET name=?,sport_type=?,beschreibung=? WHERE id=?')
               ->execute([$name, $sportType, $beschr, $id]);
            flash(t('lk_flash_updated'));
        } else {
            $db->prepare('INSERT INTO ' . tbl('liga_klassen') . ' (name,sport_type,beschreibung) VALUES (?,?,?)')
               ->execute([$name, $sportType, $beschr]);
            flash(t('lk_flash_created'));
        }
    } catch (\PDOException $e) {
        // Vermutlich Verletzung von UNIQUE(sport_type, name).
        flash(t('lk_flash_duplicate'), 'error');
    } catch (\Throwable $e) {
        flash(t('flash_error_prefix', ['msg' => $e->getMessage()]), 'error');
    }
    redirect('?action=ligaklassen');
}

// ── Liga-Klasse löschen (Ligen werden nur entkoppelt, nicht gelöscht) ──────
if ($action === 'delete_liga_klasse' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireLogin();
    $id = (int)($_POST['klasse_id'] ?? 0);
    if ($id > 0) {
        try {
            $db = getDB();
            $db->prepare('UPDATE ' . tbl('liga') . ' SET klasse_id=NULL WHERE klasse_id=?')->execute([$id]);
            $db->prepare('DELETE FROM ' . tbl('liga_klassen') . ' WHERE id=?')->execute([$id]);
            flash(t('lk_flash_deleted'));
        } catch (\Throwable $e) {
            flash(t('flash_error_prefix', ['msg' => $e->getMessage()]), 'error');
        }
    }
    redirect('?action=ligaklassen');
}

// ── Einzelne Liga einer Klasse zuordnen (inkl. optional Saison-Text) ───────
if ($action === 'assign_liga_klasse' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireLogin();
    $ligaId   = (int)($_POST['liga_id']   ?? 0);
    $klasseId = (int)($_POST['klasse_id'] ?? 0) ?: null;
    $saison   = trim($_POST['saison'] ?? '');
    if ($ligaId > 0) {
        try {
            $db = getDB();
            // klasse_id gegen Sportart der Liga pruefen.
            if ($klasseId !== null) {
                $ligaSport = $db->prepare('SELECT sport_type FROM ' . tbl('liga') . ' WHERE id=?');
                $ligaSport->execute([$ligaId]);
                $sportOfLiga = $ligaSport->fetchColumn();
                $chk = $db->prepare('SELECT id FROM ' . tbl('liga_klassen') . ' WHERE id=? AND sport_type=?');
                $chk->execute([$klasseId, $sportOfLiga]);
                if (!$chk->fetch()) { $klasseId = null; }
            }
            $db->prepare('UPDATE ' . tbl('liga') . ' SET klasse_id=?, saison=? WHERE id=?')
               ->execute([$klasseId, $saison !== '' ? $saison : null, $ligaId]);
            flash(t('lk_flash_assigned'));
        } catch (\Throwable $e) {
            flash(t('flash_error_prefix', ['msg' => $e->getMessage()]), 'error');
        }
    }
    redirect('?action=ligaklassen');
}

// ── Alle Saisons einer Klasse auf einmal speichern (Sammelformular in
// view_ligaklassen.php - HTML5 form=""-Attribut bindet die Saison-Inputs
// aus jeder Zeile an dieses eine Formular). Deutlich schneller als jede
// Saison einzeln zu bestätigen, besonders bei Klassen mit vielen Saisons
// (z.B. 64 bei "1. Fussball Bundesliga"). ────────────────────────────────
if ($action === 'bulk_update_saison' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireLogin();
    $klasseId = (int)($_POST['klasse_id'] ?? 0);
    $saisons  = (array)($_POST['saison'] ?? []);
    $updated  = 0;
    if ($klasseId > 0 && !empty($saisons)) {
        try {
            $db = getDB();
            // Nur Ligen aktualisieren, die TATSAECHLICH dieser Klasse
            // angehoeren (Schutz gegen einen manipulierten POST-Request mit
            // fremden liga_id-Schluesseln).
            $stmt = $db->prepare(
                'UPDATE ' . tbl('liga') . ' SET saison=? WHERE id=? AND klasse_id=?'
            );
            foreach ($saisons as $ligaId => $saisonValue) {
                $ligaId = (int)$ligaId;
                if ($ligaId <= 0) { continue; }
                $saisonValue = trim((string)$saisonValue);
                $stmt->execute([$saisonValue !== '' ? $saisonValue : null, $ligaId, $klasseId]);
                if ($stmt->rowCount() > 0) { $updated++; }
            }
            flash(t('lk_flash_bulk_saison_saved', ['n' => $updated]));
        } catch (\Throwable $e) {
            flash(t('flash_error_prefix', ['msg' => $e->getMessage()]), 'error');
        }
    }
    redirect('?action=ligaklassen');
}

// ── Migrations-Vorschlag übernehmen: legt eine neue Klasse an und ordnet
// alle vorgeschlagenen Ligen dieser Gruppe ihr in einem Rutsch zu ─────────
if ($action === 'accept_klasse_suggestion' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireLogin();
    $name      = trim($_POST['klasse_name']  ?? '');
    $sportType = trim($_POST['klasse_sport'] ?? 'football');
    $ligaIds   = array_map('intval', (array)($_POST['liga_ids'] ?? []));
    $ligaIds   = array_filter($ligaIds, fn($id) => $id > 0);
    if (!in_array($sportType, ['football', 'volleyball', 'icehockey', 'basketball', 'handball', 'badminton'], true)) {
        $sportType = 'football';
    }
    if ($name === '' || empty($ligaIds)) { flash(t('hl_flash_name_required'), 'error'); redirect('?action=ligaklassen'); }
    $db = getDB();
    try {
        $db->beginTransaction();
        $ins = $db->prepare('INSERT INTO ' . tbl('liga_klassen') . ' (name,sport_type,beschreibung) VALUES (?,?,?)');
        $ins->execute([$name, $sportType, '']);
        $klasseId = (int)$db->lastInsertId();
        $phs = implode(',', array_fill(0, count($ligaIds), '?'));
        // Nur Ligen DERSELBEN Sportart tatsaechlich zuordnen - zweite
        // Absicherung gegen einen manipulierten POST-Request.
        $db->prepare('UPDATE ' . tbl('liga') . ' SET klasse_id=? WHERE id IN (' . $phs . ') AND sport_type=?')
           ->execute([$klasseId, ...$ligaIds, $sportType]);
        $db->commit();
        flash(t('lk_flash_suggestion_accepted', ['n' => count($ligaIds)]));
    } catch (\Throwable $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        flash(t('flash_error_prefix', ['msg' => $e->getMessage()]), 'error');
    }
    redirect('?action=ligaklassen');
}
