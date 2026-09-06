<?php
/**
 * Project: LMOnext
 * Filename: addon/liga-klassen-rekorde/lmo-rekorde.php
 * Fileversion: 1.12.0
 *
 * PHP version 8.2
 *
 * @author    Dietmar Kersting <webmaster@liga-manager-online.org>
 * @author    Torsten Hofmann <entwickler@bastel-code.de>
 * @copyright 2026 Dietmar Kersting, Torsten Hofmann
 * @license   GPL-3.0-only
 *
 * ── Einbindung ────────────────────────────────────────────────────────────────
 *
 * Variante 1 (empfohlen) – per include() aus eigenem PHP-Code:
 *
 *   <?php
 *   $rk_klasse = 12;   // Liga-Klassen-ID (Pflicht, siehe Administrator → Liga-Klassen)
 *   include('/PfadZuLMOnext/addon/rekorde/lmo-rekorde.php');
 *
 * Variante 2 – per direkter URL / IFrame:
 *
 *   <iframe src="https://.../addon/rekorde/lmo-rekorde.php?rk_klasse=12"
 *           frameborder="0" width="900" height="700" scrolling="auto"></iframe>
 *
 * Steuerparameter (GET hat immer Vorrang vor vorher gesetzten PHP-Variablen):
 *   rk_klasse   Liga-Klassen-ID (Pflicht, siehe Administrator → Liga-Klassen).
 *               Eine Klasse bündelt mehrere Saison-Instanzen derselben
 *               Wettbewerbsserie, siehe lmonext_liga_klassen_konzept.md.
 *   rk_view     "meister" (Standard) oder "rekorde".
 *   rk_limit    Optional: Anzahl Einträge je Rekordliste (Standard: 10).
 *   rk_template Template-Name ohne Endung (Standard: "standard"), Datei
 *               muss unter /template/addon/rekorde/{name}.tpl.php liegen.
 *
 * ── Funktionsweise ────────────────────────────────────────────────────────────
 *
 * Zeigt zwei Ansichten für eine Liga-Klasse:
 * - Meisterliste: für jede Saison (Liga-Instanz) der Klasse, chronologisch
 *   sortiert, der Meister (Platz 1 der Endtabelle, über computeStandings()
 *   ermittelt - nicht redundant gespeichert, daher immer aktuell auch nach
 *   nachträglichen Ergebniskorrekturen in einer alten Saison).
 * - Rekordspiele: höchster Sieg, ergebnisreichste Partie (torreichste bzw.
 *   satzreichste, je nach Sportart - beides steckt generisch in denselben
 *   h_tore/g_tore-Feldern, siehe VolleyballProfile::getStandingsColumns(),
 *   dort "Sätze" statt "Tore"), höchstes Unentschieden, Partien ohne
 *   Gegentore/ohne eigene Tore je Team, sowie sieben Serienarten je Team
 *   (Sieg, Niederlage, Unentschieden, ohne Niederlage, ohne Sieg, ohne
 *   Gegentor, ohne eigenes Tor - siehe rkComputeStreak()) - jeweils ÜBER
 *   ALLE SAISONS DER KLASSE HINWEG, nicht
 *   nur pro Saison (siehe lmonext_liga_klassen_konzept.md, Antwort zu Frage
 *   1), aber mit Erkennung von Saison-Lücken (spielt ein Team in einer
 *   zwischenzeitlichen Saison der Klasse nicht mit, z.B. wegen Abstiegs,
 *   wird die Serie dort unterbrochen statt fälschlich durchgezählt). Alles
 *   wird bei jedem Aufruf per SQL/PHP live berechnet, keine eigene
 *   Cache-Tabelle (analog zur "Ewigen Tabelle", siehe addon/ewige/).
 *
 * Saison-Anzeige (z.B. beim "Zeitraum" einer Siegesserie): nutzt bevorzugt
 * das gepflegte liga.saison-Feld (siehe Administrator → Liga-Einstellungen
 * → Grundwerte). Ist es leer, wird ersatzweise ein Jahreszahl-Muster direkt
 * aus dem Liganamen extrahiert (rkExtractSeasonFromName() - Umkehrung von
 * ligaklassenSuggestStem() in admin/data_loader.php), z.B. "Volleyball
 * ... 2013/14 -X" → "2013/14". Funktioniert nur, wenn der Name tatsächlich
 * eine Jahreszahl enthält - für zuverlässige Ergebnisse bleibt das explizite
 * Saison-Feld die empfohlene Variante.
 */
declare(strict_types=1);

use LMOnext\Liga\LigaService;

$rkIsDirectCall = defined('LMO_ADDON_STANDALONE_CALL');

require_once __DIR__ . '/../../frontend/bootstrap.php';

// Standalone-Addon: eigene Sprachdateien explizit laden. Name muss dem
// manifest['name'] aus addon.json entsprechen ("liga-klassen-rekorde").
if (function_exists('addonManager')) {
    \addonManager()->loadLanguages('liga-klassen-rekorde');
}

// Zum Einbetten via iframe gedacht (siehe Docblock oben) - Frame-Schutz-Header
// von frontend/bootstrap.php wieder entfernen, sonst waere jede Einbettung
// blockiert (gleiches Vorgehen wie in allen anderen Embed-Addons).
if (!headers_sent()) {
    header_remove('X-Frame-Options');
    header_remove('Content-Security-Policy');
}

// ── Parameter einlesen ───────────────────────────────────────────────────────
$rkKlasseId = isset($_REQUEST['rk_klasse']) ? (int)$_REQUEST['rk_klasse'] : (int)($rk_klasse ?? 0);
$rkView     = isset($_REQUEST['rk_view'])   ? (string)$_REQUEST['rk_view'] : (string)($rk_view ?? 'meister');
$rkView     = in_array($rkView, ['meister', 'rekorde'], true) ? $rkView : 'meister';
$rkLimit    = isset($_REQUEST['rk_limit'])  ? max(1, (int)$_REQUEST['rk_limit']) : max(1, (int)($rk_limit ?? 10));
// rk_ajax=1: liefert NUR das HTML-Fragment der angeforderten Ansicht (kein
// Grundgeruest/Nav/Kopf) - wird vom Lazy-Load-JavaScript im Standard-Template
// verwendet (siehe unten), damit "Rekordspiele" erst bei Bedarf per Klick auf
// den Tab nachgeladen wird, statt beim ersten Seitenaufruf immer mitberechnet
// zu werden. Rueckmeldung aus dem Livesystem: bei Klassen mit vielen Saisons
// (z.B. 64 bei "1. Fussball Bundesliga") dauert die vollstaendige
// Rekordspiele-Berechnung spuerbar, das soll den ersten sichtbaren Aufbau der
// Seite (Meisterliste) nicht mehr blockieren.
$rkAjax     = !empty($_REQUEST['rk_ajax']);

$rkTemplate = isset($_REQUEST['rk_template']) ? (string)$_REQUEST['rk_template'] : (string)($rk_template ?? 'standard');
$rkTemplate = str_replace('..', '', basename($rkTemplate)); // Path-Traversal-Schutz

if ($rkIsDirectCall && !$rkAjax) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html>' . "\n" . '<html><head><meta charset="utf-8">'
        . '<title>Rekorde</title>'
        . '<style>html,body{margin:0;padding:8px;background:transparent;}</style>'
        . '</head><body>' . "\n";
} elseif ($rkAjax) {
    header('Content-Type: text/html; charset=utf-8');
}

if ($rkKlasseId <= 0) {
    echo '<p style="font-family:sans-serif;color:#697182;padding:12px">'
        . h(tf('liga_rekorde_missing_klasse')) . '</p>';
    if ($rkIsDirectCall && !$rkAjax) { echo "\n</body></html>"; }
    return;
}

if ($rkAjax) {
    // Nur das Fragment - siehe rkRenderBody(). Wird per fetch() ins bereits
    // geladene Grundgeruest der Seite eingesetzt (siehe {LAZY_JS} im Template).
    // Eigene Berechnungszeit-Zeile fuer DIESEN (separaten, per fetch() erst
    // nach dem ersten Seitenaufbau ausgeloesten) Request - gleiches Muster
    // wie TemplateEngine::render() im Hauptsystem (footer_render_time,
    // Referenzzeitpunkt $_SERVER['REQUEST_TIME_FLOAT']), damit sich Meister-
    // und Rekorde-Ladezeit getrennt beurteilen lassen (siehe Rueckmeldung
    // "immer noch sehr langsam" - mit dieser Anzeige laesst sich einordnen,
    // ob der erste Seitenaufbau ODER das Nachladen der Rekordspiele-Ansicht
    // der eigentliche Engpass ist).
    echo rkRenderBody($rkKlasseId, $rkView, $rkLimit);
    echo rkRenderTimeHtml();
    return;
}

/**
 * Berechnet das URL-Präfix zum Projekt-Root, damit Links auf die öffentliche
 * liga.php-Seite unabhängig davon funktionieren, ob dieses Addon direkt
 * aufgerufen (dann 2 Verzeichnisebenen unterhalb des Projekt-Roots, braucht
 * "../../") oder aus einem Skript im Projekt-Root heraus per include()
 * eingebunden wird (dann bereits im Projekt-Root-Kontext, kein Präfix nötig).
 * Gleiche Logik wie rlProjectRootUrlPrefix() in addon/relegation/ und die
 * entsprechenden Funktionen in mini/tabellenrechner/viewer - eigener
 * Funktionsname (rk-Präfix), damit mehrere Addons parallel eingebunden
 * werden können, ohne sich mit gleichnamigen Funktionen zu überschreiben.
 */
function rkProjectRootUrlPrefix() : string
{
    static $prefix = null;
    if ($prefix !== null) {
        return $prefix;
    }

    if (defined('LMO_ADDON_WEB_BASE')) {
        $prefix = rtrim(dirname(rtrim(LMO_ADDON_WEB_BASE, '/'), 2), '/') . '/';
        return $prefix;
    }

    $projectRootDisk = rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/');
    $scriptFilename  = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $scriptName      = (string)($_SERVER['SCRIPT_NAME'] ?? '');

    if ($scriptFilename !== '' && $scriptName !== '' && str_ends_with($scriptFilename, $scriptName)) {
        $documentRootDisk = substr($scriptFilename, 0, -strlen($scriptName));
        $documentRootDisk = rtrim($documentRootDisk, '/');
        if ($documentRootDisk !== '' && str_starts_with($projectRootDisk, $documentRootDisk)) {
            $prefix = substr($projectRootDisk, strlen($documentRootDisk)) . '/';
            return $prefix;
        }
    }

    $isDirectCall = defined('LMO_ADDON_STANDALONE_CALL');
    $prefix = $isDirectCall ? '../../' : '';
    return $prefix;
}

echo renderRekordeView($rkKlasseId, $rkView, $rkLimit, $rkTemplate);

if ($rkIsDirectCall) {
    echo "\n</body></html>";
}

// ════════════════════════════════════════════════════════════════════════════
//  Funktionen
// ════════════════════════════════════════════════════════════════════════════

/**
 * Fallback, falls das strukturierte Saison-Feld (liga.saison) nicht gepflegt
 * ist: extrahiert ein saisonartiges Muster (Jahreszahl oder "2013/14" o.ä.)
 * direkt aus dem Liganamen. Umkehrung von ligaklassenSuggestStem() in
 * admin/data_loader.php (dort wird dasselbe Muster ENTFERNT, um den
 * Wettbewerbsstamm zu finden - hier wird es stattdessen EXTRAHIERT). Rein
 * heuristisch: liefert einen Best-Effort-Anzeigetext, ersetzt das gepflegte
 * Saison-Feld nicht vollständig (z.B. "Frühjahr 2025" oder unübliche
 * Bezeichnungen ohne Jahreszahl werden nicht erkannt) - das explizite Feld
 * bleibt daher die empfohlene, zuverlässigere Variante.
 */
function rkExtractSeasonFromName(string $name) : string
{
    if (preg_match('/\b((19|20)\d{2}\s*[\/\-]\s*(\d{2}|\d{4}))\b/u', $name, $m)) {
        return preg_replace('/\s+/u', '', $m[1]);
    }
    if (preg_match('/\b((19|20)\d{2})\b/u', $name, $m)) {
        return $m[1];
    }
    return '';
}

/**
 * Liest die Saison einer Liga-Zeile: bevorzugt das gepflegte liga.saison-Feld,
 * fällt bei leerem Wert auf rkExtractSeasonFromName() zurück (siehe dort).
 */
function rkSeasonOf(array $l) : string
{
    $saison = trim((string)($l['saison'] ?? ''));
    return $saison !== '' ? $saison : rkExtractSeasonFromName((string)($l['name'] ?? ''));
}

/**
 * Laedt Klassen-Stammdaten (Name/Sportart). Gibt null zurueck, wenn die
 * Klasse nicht existiert.
 */
function rkGetKlasse(int $klasseId) : ?array
{
    try {
        $db = getDB();
        $s = $db->prepare('SELECT id,name,sport_type FROM ' . tbl('liga_klassen') . ' WHERE id=?');
        $s->execute([$klasseId]);
        $row = $s->fetch();
        return $row !== false ? $row : null;
    } catch (\Throwable) {
        return null;
    }
}

/**
 * Ordnet einen Saison-Text (z.B. "1993/94", "2020", "2014-15") einem
 * sortierbaren Jahreszahl-Schluessel zu (Startjahr der Saison). Nicht
 * erkennbare Texte fallen auf 0 zurueck (sortieren dann vor allem anderen -
 * seltener Randfall, siehe rkSeasonOf()).
 */
function rkSeasonSortKey(string $saison) : int
{
    if (preg_match('/(\d{4})/', $saison, $m)) {
        return (int)$m[1];
    }
    return 0;
}

/**
 * Alle Saison-Instanzen (liga-Zeilen) einer Klasse, nach der TATSAECHLICHEN
 * Saison sortiert (Jahreszahl aus rkSeasonOf(), siehe dort) - NICHT nach
 * liga.datum. liga.datum ist nur der Erstellungszeitpunkt des Datensatzes
 * (z.B. Importzeitpunkt) und entspricht bei nachtraeglich/historisch
 * importierten Daten NICHT zwangslaeufig der echten zeitlichen Reihenfolge
 * der Saison - wurde z.B. die Saison 1977/78 vor 1976/77 importiert, waere
 * die Sortierung nach datum genau verkehrt herum (im Livesystem als
 * Screenshot bestaetigt: "1977/78 – 1976/77" statt umgekehrt). Dient sowohl
 * der Meisterliste als auch als Grundlage fuer die Rekordspiele-Berechnung
 * ueber alle Saisons hinweg - siehe auch rkLaengsteSiegesserie(), die diese
 * Reihenfolge zusaetzlich fuer die Erkennung von Saison-Luecken braucht.
 */
function rkGetKlasseLigen(int $klasseId) : array
{
    static $cache = [];
    if (isset($cache[$klasseId])) {
        return $cache[$klasseId];
    }
    try {
        $db = getDB();
        $s = $db->prepare('SELECT id,name,saison,datum FROM ' . tbl('liga') . ' WHERE klasse_id=?');
        $s->execute([$klasseId]);
        $rows = $s->fetchAll();
    } catch (\Throwable) {
        return $cache[$klasseId] = [];
    }
    usort($rows, function (array $a, array $b) : int {
        $cmp = rkSeasonSortKey(rkSeasonOf($a)) <=> rkSeasonSortKey(rkSeasonOf($b));
        // Bei gleichem/unerkennbarem Saisonjahr (z.B. zwei Turniere im selben
        // Jahr) faellt die Sortierung auf datum als Tie-Breaker zurueck,
        // statt eine willkuerliche PHP-usort-Reihenfolge zu riskieren.
        return $cmp !== 0 ? $cmp : strcmp((string)$a['datum'], (string)$b['datum']);
    });
    return $cache[$klasseId] = $rows;
}

/**
 * Prueft, ob in einer Liga bereits ALLE Partien ein Ergebnis haben (siehe
 * LigaService::getAllSpieltage() - liefert je Spieltag "gespielt" (Anzahl
 * Partien MIT Ergebnis) und "partie_count" (Anzahl Partien insgesamt)).
 * Eine Liga ganz ohne Spieltage/Partien gilt NICHT als abgeschlossen (leere
 * Liga waere sonst faelschlich als "komplett" durchgerutscht).
 */
function rkIsLigaComplete(array $allSpieltage) : bool
{
    if (empty($allSpieltage)) {
        return false;
    }
    $gespielt = 0;
    $gesamt   = 0;
    foreach ($allSpieltage as $st) {
        $gespielt += (int)($st['gespielt'] ?? 0);
        $gesamt   += (int)($st['partie_count'] ?? 0);
    }
    return $gesamt > 0 && $gespielt === $gesamt;
}

/**
 * Laedt Spieltage + Partien + Teams + Optionen fuer ALLE Ligen einer Klasse
 * IN JEWEILS EINER EINZIGEN ABFRAGE (statt pro Liga einzeln), damit
 * rkMeisterliste() nicht Dutzende/Hunderte Saisons einzeln nacheinander
 * abfragen muss.
 *
 * Hintergrund (Performance-Bug, im Livesystem als Screenshot mit "493.188
 * sek." Berechnungszeit bestaetigt): LigaService::getAllLigaPartien() ruft
 * fuer jeden Spieltag EINZELN eine eigene Datenbankabfrage auf (N+1-Problem)
 * - bei einer Klasse mit 63 Saisons a ca. 34 Spieltagen macht das ueber 2000
 * einzelne Datenbankabfragen nur fuer die Partien, dazu je einmal pro Saison
 * getAllSpieltage()/getLigaOptions()/getLigaTeamsList() obendrauf. Diese
 * Funktion ist als generische Basisfunktion fuer den ganzen Kern des
 * Systems geschrieben (jede Liga-Detailseite ruft sie fuer GENAU EINE Liga
 * auf, dort faellt das kaum auf) und wird hier bewusst NICHT angefasst, um
 * nichts anderes im Livesystem zu riskieren - stattdessen baut diese Funktion
 * die semantisch gleichwertigen Datenstrukturen ausschliesslich innerhalb
 * dieses Addons ueber Massenabfragen (WHERE liga_id/spieltag_id IN (...))
 * nach, sodass LigaService::computeStandings() (die eigentliche, bereits
 * bewaehrte Tabellenberechnung) unveraendert weiterverwendet werden kann -
 * nur die Zulieferung der Rohdaten wird beschleunigt.
 *
 * @return array [
 *   'spieltageByLiga' => [ligaId => [...wie getAllSpieltage()...]],
 *   'partienByLiga'   => [ligaId => [...wie getAllLigaPartien()...]],
 *   'teamsByLiga'     => [ligaId => [...wie getLigaTeamsList()...]],
 *   'optionsByLiga'   => [ligaId => [...wie getLigaOptions()...]],
 * ]
 */
function rkBulkLoadStandingsData(array $ligen) : array
{
    $ligaIds = array_map(fn(array $l) => (int)$l['id'], $ligen);
    $out = ['spieltageByLiga' => [], 'partienByLiga' => [], 'teamsByLiga' => [], 'optionsByLiga' => []];
    foreach ($ligaIds as $lid) {
        $out['spieltageByLiga'][$lid] = [];
        $out['partienByLiga'][$lid]   = [];
        $out['teamsByLiga'][$lid]     = [];
        $out['optionsByLiga'][$lid]   = [];
    }
    if (empty($ligaIds)) {
        return $out;
    }

    try {
        $db = getDB();
        $phs = implode(',', array_fill(0, count($ligaIds), '?'));

        // ── Spieltage (fuer Vollstaendigkeits-Check + hoechste Spieltagnummer) ──
        $sSt = $db->prepare(
            'SELECT s.id, s.liga_id, s.nummer, s.start,
                    SUM(CASE WHEN p.h_tore IS NOT NULL THEN 1 ELSE 0 END) AS gespielt,
                    COUNT(p.id) AS partie_count
               FROM ' . tbl('liga_spieltage') . ' s
               LEFT JOIN ' . tbl('liga_partien') . ' p ON p.spieltag_id = s.id
              WHERE s.liga_id IN (' . $phs . ')
              GROUP BY s.id, s.liga_id, s.nummer, s.start
              ORDER BY s.liga_id, s.nummer'
        );
        $sSt->execute($ligaIds);
        $spieltagNummerById = [];
        foreach ($sSt->fetchAll() as $row) {
            $lid = (int)$row['liga_id'];
            $out['spieltageByLiga'][$lid][] = $row;
            $spieltagNummerById[(int)$row['id']] = (int)$row['nummer'];
        }

        // ── Partien ueber ALLE Spieltage ALLER Ligen in EINER Abfrage ───────────
        // (statt einer Abfrage PRO Spieltag - das war der eigentliche Engpass,
        // siehe Docblock oben). Gleiche Spalten wie
        // SpieltagRepositoryTrait::getSpieltagPartien(), damit computeStandings()
        // exakt dieselbe Datenform bekommt wie beim normalen Einzel-Liga-Aufruf.
        $hasStatusColumn = false;
        $hasExtraDataColumn = false;
        try { $db->query('SELECT status FROM ' . tbl('liga_partien') . ' LIMIT 0'); $hasStatusColumn = true; } catch (\Throwable) {}
        try { $db->query('SELECT extra_data FROM ' . tbl('liga_partien') . ' LIMIT 0'); $hasExtraDataColumn = true; } catch (\Throwable) {}
        $statusSelect    = $hasStatusColumn ? ', p.status' : '';
        $extraDataSelect = $hasExtraDataColumn ? ', p.extra_data' : '';

        $sPa = $db->prepare(
            'SELECT p.id, p.spieltag_id, p.heim_id, p.gast_id, p.heim_label, p.gast_label,
                    p.h_tore, p.g_tore, p.zeit, p.spiel_nr' . $statusSelect . $extraDataSelect . ',
                    th.name AS heim_name, tg.name AS gast_name,
                    th.kurz AS heim_kurz, tg.kurz AS gast_kurz,
                    s.liga_id
               FROM ' . tbl('liga_partien') . ' p
               JOIN ' . tbl('liga_spieltage') . ' s ON s.id = p.spieltag_id
               LEFT JOIN ' . tbl('teams_global') . ' th ON th.id = p.heim_id
               LEFT JOIN ' . tbl('teams_global') . ' tg ON tg.id = p.gast_id
              WHERE s.liga_id IN (' . $phs . ')
              ORDER BY s.liga_id, s.nummer,
                       CAST(SUBSTRING_INDEX(p.spiel_nr, "_", 1) AS UNSIGNED),
                       CAST(SUBSTRING_INDEX(p.spiel_nr, "_", -1) AS UNSIGNED)'
        );
        $sPa->execute($ligaIds);
        foreach ($sPa->fetchAll() as $row) {
            $lid = (int)$row['liga_id'];
            $row['_spieltag_nummer'] = $spieltagNummerById[(int)$row['spieltag_id']] ?? 0;
            if (!$hasStatusColumn) { $row['status'] = 0; }
            unset($row['liga_id']);
            $out['partienByLiga'][$lid][] = $row;
        }

        // ── Teams (liga_teams + teams_global) ────────────────────────────────
        $sTe = $db->prepare(
            'SELECT lt.liga_id, tg.id, tg.name, tg.kurz, tg.mittel
               FROM ' . tbl('liga_teams') . ' lt
               JOIN ' . tbl('teams_global') . ' tg ON tg.id = lt.team_id
              WHERE lt.liga_id IN (' . $phs . ')
              ORDER BY lt.liga_id, lt.id'
        );
        $sTe->execute($ligaIds);
        foreach ($sTe->fetchAll() as $row) {
            $lid = (int)$row['liga_id'];
            unset($row['liga_id']);
            $out['teamsByLiga'][$lid][] = $row;
        }
        // Fallback fuer Ligen ohne liga_teams-Eintraege (aeltere Importe, siehe
        // LigaService::getLigaTeamsListUncached()) - einzeln nachladen, betrifft
        // im Regelfall nur vereinzelte Ligen, nicht die ganze Klasse.
        foreach ($ligaIds as $lid) {
            if (empty($out['teamsByLiga'][$lid])) {
                $out['teamsByLiga'][$lid] = LigaService::getLigaTeamsList($lid);
            }
        }

        // ── Liga-Optionen (Punkteregeln etc.) ────────────────────────────────
        $sOp = $db->prepare(
            'SELECT liga_id, option_key, option_value FROM ' . tbl('liga_options') . '
              WHERE liga_id IN (' . $phs . ')'
        );
        $sOp->execute($ligaIds);
        foreach ($sOp->fetchAll() as $row) {
            $lid = (int)$row['liga_id'];
            $out['optionsByLiga'][$lid][$row['option_key']] = $row['option_value'];
        }
    } catch (\Throwable) {
        // Bei irgendeinem Fehler (z.B. abweichendes Schema) leere Strukturen
        // zurueckgeben - rkMeisterliste() faellt dann pro Liga auf 0 Zeilen
        // zurueck statt mit einem Fehler abzubrechen.
    }

    return $out;
}

/**
 * Meisterliste: fuer jede ABGESCHLOSSENE Saison der Klasse der Meister
 * (Platz 1 der Endtabelle). Der Meister wird NICHT redundant gespeichert,
 * sondern bei jedem Aufruf ueber computeStandings() ermittelt - bleibt
 * dadurch auch nach nachtraeglichen Ergebniskorrekturen in einer alten
 * Saison automatisch korrekt (siehe lmonext_liga_klassen_konzept.md,
 * Abschnitt 3).
 *
 * Laufende/unvollstaendige Saisons (noch nicht alle Partien ausgetragen,
 * siehe rkIsLigaComplete()) werden bewusst NICHT in die Liste aufgenommen -
 * der aktuelle Tabellenerste einer laufenden Saison ist kein Meister, auch
 * wenn er gerade Platz 1 belegt (im Livesystem als Screenshot bestaetigt:
 * eine noch laufende Saison zeigte faelschlich schon einen "Meister" mit
 * 0 Punkten/Spielen).
 *
 * Laedt die Rohdaten ueber rkBulkLoadStandingsData() (wenige Massenabfragen
 * ueber ALLE Saisons der Klasse hinweg) statt wie zuvor pro Saison einzeln -
 * siehe dortigen Docblock fuer den Performance-Hintergrund.
 */
function rkMeisterliste(int $klasseId) : array
{
    $ligen = rkGetKlasseLigen($klasseId);
    $bulk  = rkBulkLoadStandingsData($ligen);
    $out = [];
    foreach ($ligen as $l) {
        $ligaId = (int)$l['id'];
        $allSpieltage = $bulk['spieltageByLiga'][$ligaId] ?? [];
        if (!rkIsLigaComplete($allSpieltage)) {
            continue;
        }
        $opts    = $bulk['optionsByLiga'][$ligaId] ?? [];
        $teams   = $bulk['teamsByLiga'][$ligaId] ?? [];
        $partien = $bulk['partienByLiga'][$ligaId] ?? [];
        $maxNr   = LigaService::getMaxSpieltagNummer($allSpieltage);
        $rows    = LigaService::computeStandings($teams, $partien, $opts, $ligaId, 'overall', $maxNr);
        $out[] = [
            'liga_id'   => $ligaId,
            'liga_name' => $l['name'],
            'saison'    => rkSeasonOf($l),
            'meister'   => $rows[0] ?? null,
        ];
    }
    return $out;
}

/**
 * Alle gespielten Partien ueber alle Saisons einer Klasse hinweg, chronologisch
 * sortiert (nach der tatsaechlichen Saison, siehe rkGetKlasseLigen()), dann
 * Spieltag-Nummer, dann Partie-ID als stabiler Tie-Breaker). Grundlage fuer
 * alle sieben Rekord-/Serienarten (siehe rkHoechsterSieg(),
 * rkErgebnisreichstePartie(), rkComputeStreak()).
 *
 * Statisch pro Request zwischengespeichert (Schluessel: Klassen-ID): ohne
 * diesen Cache wuerde jede der sieben Auswertungen die komplette Partien-
 * Liste (potenziell tausende Zeilen ueber viele Saisons, z.B. 64 Saisons bei
 * "1. Fussball Bundesliga") JEDES MAL erneut per Datenbankabfrage laden -
 * spuerbar langsam bei grossen Klassen (im Livesystem als Rueckmeldung
 * bestaetigt: "dauert ziemlich lang"). Der Cache lebt nur fuer die Dauer
 * eines einzelnen HTTP-Requests (kein persistenter/geteilter Cache noetig),
 * da jeder Request ohnehin frisch von PHP neu ausgefuehrt wird.
 */
function rkGetAllPartienChronological(int $klasseId) : array
{
    static $cache = [];
    if (isset($cache[$klasseId])) {
        return $cache[$klasseId];
    }

    $ligen = rkGetKlasseLigen($klasseId);
    if (empty($ligen)) {
        return $cache[$klasseId] = [];
    }
    try {
        $db = getDB();
        $all = [];
        foreach ($ligen as $l) {
            $ligaId = (int)$l['id'];
            $rows = $db->prepare(
                'SELECT p.heim_id,p.gast_id,p.h_tore,p.g_tore,s.nummer AS spieltag_nr,
                        th.name AS heim_name, tg.name AS gast_name
                   FROM ' . tbl('liga_partien') . ' p
                   JOIN ' . tbl('liga_spieltage') . ' s ON s.id = p.spieltag_id
                   LEFT JOIN ' . tbl('teams_global') . ' th ON th.id = p.heim_id
                   LEFT JOIN ' . tbl('teams_global') . ' tg ON tg.id = p.gast_id
                  WHERE s.liga_id = ?
                    AND p.heim_id IS NOT NULL AND p.gast_id IS NOT NULL
                    AND p.h_tore IS NOT NULL AND p.g_tore IS NOT NULL
                  ORDER BY s.nummer ASC, p.id ASC'
            );
            $rows->execute([$ligaId]);
            foreach ($rows->fetchAll() as $r) {
                $r['liga_id']   = $ligaId;
                $r['liga_name'] = $l['name'];
                $r['saison']    = rkSeasonOf($l);
                $all[] = $r;
            }
        }
        // bereits saisonweise chronologisch, Saisons selbst schon sortiert (rkGetKlasseLigen)
        return $cache[$klasseId] = $all;
    } catch (\Throwable) {
        return $cache[$klasseId] = [];
    }
}

/**
 * Top N Partien nach Tordifferenz (hoechster Sieg), ueber alle Saisons einer
 * Klasse hinweg.
 */
function rkHoechsterSieg(int $klasseId, int $limit) : array
{
    $partien = rkGetAllPartienChronological($klasseId);
    foreach ($partien as &$p) {
        $p['diff'] = abs((int)$p['h_tore'] - (int)$p['g_tore']);
    }
    unset($p);
    usort($partien, fn($a, $b) => $b['diff'] <=> $a['diff']);
    return array_slice($partien, 0, $limit);
}

/**
 * Top N ergebnisreichste Partien (Summe beider Werte) - je nach Sportart
 * "torreichste" (Fussball etc.) oder "satzreichste" (Volleyball) Partie,
 * beides steckt generisch in denselben h_tore/g_tore-Feldern.
 */
function rkErgebnisreichstePartie(int $klasseId, int $limit) : array
{
    $partien = rkGetAllPartienChronological($klasseId);
    foreach ($partien as &$p) {
        $p['summe'] = (int)$p['h_tore'] + (int)$p['g_tore'];
    }
    unset($p);
    usort($partien, fn($a, $b) => $b['summe'] <=> $a['summe']);
    return array_slice($partien, 0, $limit);
}

/**
 * Top N ergebnisreichste UNENTSCHIEDEN (h_tore === g_tore), nach Summe
 * sortiert - z.B. 4:4 vor 3:3 vor 1:1.
 */
function rkHoechstesUnentschieden(int $klasseId, int $limit) : array
{
    $partien = rkGetAllPartienChronological($klasseId);
    $draws = array_values(array_filter($partien, fn(array $p) : bool => (int)$p['h_tore'] === (int)$p['g_tore']));
    foreach ($draws as &$p) {
        $p['summe'] = (int)$p['h_tore'] + (int)$p['g_tore'];
    }
    unset($p);
    usort($draws, fn($a, $b) => $b['summe'] <=> $a['summe']);
    return array_slice($draws, 0, $limit);
}

/**
 * Je Team die Anzahl Partien OHNE Gegentor ("clean sheets"), ueber alle
 * Saisons der Klasse hinweg, absteigend sortiert.
 *
 * @return array Liste mit ['team_id','team_name','anzahl'], absteigend
 */
function rkOhneGegentore(int $klasseId, int $limit) : array
{
    $partien = rkGetAllPartienChronological($klasseId);
    $byTeam = [];
    foreach ($partien as $p) {
        $heimId = (int)$p['heim_id'];
        $gastId = (int)$p['gast_id'];
        if ((int)$p['g_tore'] === 0) {
            $byTeam[$heimId]['name']  = $p['heim_name'] ?? '';
            $byTeam[$heimId]['count'] = ($byTeam[$heimId]['count'] ?? 0) + 1;
        }
        if ((int)$p['h_tore'] === 0) {
            $byTeam[$gastId]['name']  = $p['gast_name'] ?? '';
            $byTeam[$gastId]['count'] = ($byTeam[$gastId]['count'] ?? 0) + 1;
        }
    }
    return rkSortedTeamCounts($byTeam, $limit);
}

/**
 * Je Team die Anzahl Partien OHNE EIGENES Tor (torlos/erfolglos vorne), ueber
 * alle Saisons der Klasse hinweg, absteigend sortiert.
 *
 * @return array Liste mit ['team_id','team_name','anzahl'], absteigend
 */
function rkOhneEigeneTore(int $klasseId, int $limit) : array
{
    $partien = rkGetAllPartienChronological($klasseId);
    $byTeam = [];
    foreach ($partien as $p) {
        $heimId = (int)$p['heim_id'];
        $gastId = (int)$p['gast_id'];
        if ((int)$p['h_tore'] === 0) {
            $byTeam[$heimId]['name']  = $p['heim_name'] ?? '';
            $byTeam[$heimId]['count'] = ($byTeam[$heimId]['count'] ?? 0) + 1;
        }
        if ((int)$p['g_tore'] === 0) {
            $byTeam[$gastId]['name']  = $p['gast_name'] ?? '';
            $byTeam[$gastId]['count'] = ($byTeam[$gastId]['count'] ?? 0) + 1;
        }
    }
    return rkSortedTeamCounts($byTeam, $limit);
}

/**
 * Gemeinsame Hilfsfunktion fuer rkOhneGegentore()/rkOhneEigeneTore() (und
 * wiederverwendbar fuer aehnliche kuenftige Team-Zaehl-Statistiken): baut aus
 * einem [teamId => ['name'=>...,'count'=>...]]-Array eine absteigend
 * sortierte, auf $limit begrenzte Ergebnisliste. Bei Gleichstand alphabetisch
 * nach Teamnamen (stabile, nachvollziehbare Reihenfolge).
 */
function rkSortedTeamCounts(array $byTeam, int $limit) : array
{
    $results = [];
    foreach ($byTeam as $teamId => $d) {
        $results[] = ['team_id' => $teamId, 'team_name' => $d['name'], 'anzahl' => $d['count']];
    }
    usort($results, function (array $a, array $b) : int {
        $cmp = $b['anzahl'] <=> $a['anzahl'];
        return $cmp !== 0 ? $cmp : strcmp($a['team_name'], $b['team_name']);
    });
    return array_slice($results, 0, $limit);
}

/**
 * Baut je Team eine chronologische Ergebnis-Historie (W/D/L je Spiel, plus
 * "conceded"/"scored" - hat das Team in diesem Spiel ein Gegentor kassiert
 * bzw. selbst getroffen - Heim- und Auswaertsspiele gemischt in der
 * Reihenfolge, in der sie tatsaechlich stattfanden) - gemeinsame
 * Datengrundlage fuer alle sieben Serienarten (Sieg/Niederlage/
 * Unentschieden/ohne Niederlage/ohne Sieg/ohne Gegentor/ohne eigenes Tor),
 * damit die Ergebnis-Klassifizierung nicht mehrfach dupliziert wird.
 */
function rkBuildTeamResultHistory(int $klasseId) : array
{
    static $cache = [];
    if (isset($cache[$klasseId])) {
        return $cache[$klasseId];
    }
    $partien = rkGetAllPartienChronological($klasseId);
    $byTeam = [];
    foreach ($partien as $p) {
        $heimId = (int)$p['heim_id'];
        $gastId = (int)$p['gast_id'];
        $hTore  = (int)$p['h_tore'];
        $gTore  = (int)$p['g_tore'];
        $ligaId = (int)$p['liga_id'];

        $heimResult = $hTore > $gTore ? 'W' : ($hTore < $gTore ? 'L' : 'D');
        $gastResult = $gTore > $hTore ? 'W' : ($gTore < $hTore ? 'L' : 'D');

        $byTeam[$heimId]['name'] = $p['heim_name'] ?? '';
        $byTeam[$heimId]['spiele'][] = [
            'result' => $heimResult, 'conceded' => $gTore > 0, 'scored' => $hTore > 0,
            'saison' => $p['saison'], 'liga_id' => $ligaId,
        ];
        $byTeam[$gastId]['name'] = $p['gast_name'] ?? '';
        $byTeam[$gastId]['spiele'][] = [
            'result' => $gastResult, 'conceded' => $hTore > 0, 'scored' => $gTore > 0,
            'saison' => $p['saison'], 'liga_id' => $ligaId,
        ];
    }
    return $cache[$klasseId] = $byTeam;
}

/**
 * Generischer Serien-Finder: laengste Serie je Team, ueber Saisongrenzen der
 * Klasse hinweg, WENN die beiden Saisons in der Klasse tatsaechlich
 * unmittelbar aufeinander folgen (siehe Luecken-Erkennung unten). $counts
 * bekommt das komplette Spiel-Array aus rkBuildTeamResultHistory() (Keys:
 * 'result'/'conceded'/'scored'/...) und entscheidet, ob es die laufende
 * Serie fortsetzt (true) oder abbricht (false) - so werden mit derselben
 * Funktion alle sieben Serienarten (Sieg/Niederlage/Unentschieden/ohne
 * Niederlage/ohne Sieg/ohne Gegentor/ohne eigenes Tor) abgedeckt, ohne die
 * Kernlogik mehrfach zu duplizieren.
 *
 * Saison-Luecken-Erkennung: spielt ein Team z.B. 1993/94 in dieser Klasse,
 * dann (Abstieg) einige Saisons NICHT, und kehrt erst 1997/98 zurueck, darf
 * ein passendes Ergebnis am Ende von 1993/94 NICHT einfach mit einem
 * passenden Ergebnis zu Beginn von 1997/98 zu einer durchgehenden Serie
 * verknuepft werden, auch wenn es in der Team-eigenen Ergebnisliste die zwei
 * direkt aufeinanderfolgenden Eintraege sind (dazwischen liegende Saisons
 * ohne Teilnahme des Teams hinterlassen ja keine eigenen Spiele in dieser
 * Liste). Geprueft wird das anhand der POSITION der jeweiligen Liga-Saison
 * in der Klasse insgesamt (siehe rkGetKlasseLigen()) - folgen zwei Spiele
 * eines Teams aus unterschiedlichen Saisons, aber die Saisons sind in der
 * Klasse nicht direkt benachbart (mind. eine andere Saison der Klasse liegt
 * dazwischen), wird die Serie unterbrochen. Im Livesystem bestaetigt: eine
 * zuvor faelschlich durchgehende 8er-Siegesserie über vier Saisons wurde
 * dadurch korrekt in ihre echten Teil-Serien aufgeteilt.
 *
 * @return array Liste je Team mit laengster Serie, absteigend sortiert:
 *               ['team_id','team_name','laenge','von_saison','bis_saison']
 */
function rkComputeStreak(int $klasseId, int $limit, callable $counts) : array
{
    $ligen = rkGetKlasseLigen($klasseId);
    $ligaPosition = [];
    foreach ($ligen as $idx => $l) {
        $ligaPosition[(int)$l['id']] = $idx;
    }

    $byTeam = rkBuildTeamResultHistory($klasseId);

    $results = [];
    foreach ($byTeam as $teamId => $data) {
        $current = 0;
        $currentVon = null;
        $best = 0;
        $bestVon = null;
        $bestBis = null;
        $prevLigaId = null;
        foreach ($data['spiele'] as $spiel) {
            $ligaId = $spiel['liga_id'];
            if ($prevLigaId !== null && $ligaId !== $prevLigaId) {
                $prevPos = $ligaPosition[$prevLigaId] ?? null;
                $curPos  = $ligaPosition[$ligaId] ?? null;
                // Saison gewechselt UND die neue Saison folgt in der Klasse
                // NICHT unmittelbar auf die vorherige -> Luecke, Serie bricht
                // ab (auch wenn das aktuelle Ergebnis fuer sich passen wuerde).
                if ($prevPos === null || $curPos === null || $curPos !== $prevPos + 1) {
                    $current = 0;
                    $currentVon = null;
                }
            }
            if ($counts($spiel)) {
                if ($current === 0) { $currentVon = $spiel['saison']; }
                $current++;
                if ($current > $best) {
                    $best = $current;
                    $bestVon = $currentVon;
                    $bestBis = $spiel['saison'];
                }
            } else {
                $current = 0;
                $currentVon = null;
            }
            $prevLigaId = $ligaId;
        }
        if ($best > 0) {
            $results[] = [
                'team_id'    => $teamId,
                'team_name'  => $data['name'],
                'laenge'     => $best,
                'von_saison' => $bestVon,
                'bis_saison' => $bestBis,
            ];
        }
    }
    usort($results, fn($a, $b) => $b['laenge'] <=> $a['laenge']);
    return array_slice($results, 0, $limit);
}

function rkLaengsteSiegesserie(int $klasseId, int $limit) : array
{
    return rkComputeStreak($klasseId, $limit, fn(array $s) => $s['result'] === 'W');
}

function rkLaengsteNiederlagenserie(int $klasseId, int $limit) : array
{
    return rkComputeStreak($klasseId, $limit, fn(array $s) => $s['result'] === 'L');
}

function rkLaengsteUnentschiedenserie(int $klasseId, int $limit) : array
{
    return rkComputeStreak($klasseId, $limit, fn(array $s) => $s['result'] === 'D');
}

function rkLaengsteOhneNiederlageSerie(int $klasseId, int $limit) : array
{
    return rkComputeStreak($klasseId, $limit, fn(array $s) => $s['result'] !== 'L');
}

function rkLaengsteOhneSiegSerie(int $klasseId, int $limit) : array
{
    return rkComputeStreak($klasseId, $limit, fn(array $s) => $s['result'] !== 'W');
}

/**
 * Laengste Serie ohne Gegentor je Team (aufeinanderfolgende Spiele mit 0
 * Gegentoren), ueber Saisongrenzen der Klasse hinweg (mit derselben
 * Luecken-Erkennung wie alle anderen Serienarten, siehe rkComputeStreak()).
 */
function rkLaengsteOhneGegentoreSerie(int $klasseId, int $limit) : array
{
    return rkComputeStreak($klasseId, $limit, fn(array $s) => !$s['conceded']);
}

/**
 * Laengste Serie ohne eigenes Tor je Team (aufeinanderfolgende Spiele ohne
 * selbst erzieltes Tor), ueber Saisongrenzen der Klasse hinweg.
 */
function rkLaengsteOhneEigeneToreSerie(int $klasseId, int $limit) : array
{
    return rkComputeStreak($klasseId, $limit, fn(array $s) => !$s['scored']);
}

/**
 * Laedt das Template.
 */
function rkLoadTemplate(string $templateName) : string
{
    $templatePath = __DIR__ . '/templates/' . $templateName . '.tpl.php';
    if (!is_file($templatePath)) {
        $templatePath = __DIR__ . '/templates/standard.tpl.php';
    }
    return (string)file_get_contents($templatePath);
}

/**
 * Baut die Meisterliste als HTML-Tabelle: Kopfleiste "Meister-Historie" +
 * Tabelle mit den vollstaendigen Saison-Werten des jeweiligen Meisters (Sp/
 * S/U/N/Tore/Diff/Pkt bei Fussball, ohne "U" bei Volleyball usw. - die
 * Spalten kommen dynamisch aus SportProfile::getStandingsColumns(), damit
 * sich das automatisch an die Sportart der Klasse anpasst).
 */
function rkRenderMeisterlisteHtml(int $klasseId) : string
{
    $liste = rkMeisterliste($klasseId);
    if (empty($liste)) {
        return '<p class="rk-empty">' . h(tf('liga_rekorde_keine_saisons')) . '</p>';
    }
    // Neueste Saison zuerst (Meisterliste liest sich chronologisch rueckwaerts
    // natuerlicher als eine Chronik-Ansicht).
    $liste = array_reverse($liste);

    $klasse = rkGetKlasse($klasseId);
    $sportProfile = \LMOnext\Sport\SportRegistry::get($klasse['sport_type'] ?? 'football');
    $statCols = $sportProfile->getStandingsColumns(); // sp/s/[u]/n/tore/diff/pkt, sportartabhaengig

    $html = '<div class="rk-meister-bar">' . h(tf('liga_rekorde_meister_historie')) . '</div>'
        . '<table class="rk-table rk-meister-table"><thead><tr>'
        . '<th>#</th><th>' . h(tf('liga_rekorde_col_meister')) . '</th>'
        . '<th>' . h(tf('liga_rekorde_col_saison')) . '</th>';
    foreach ($statCols as $col) {
        $html .= '<th>' . h($col['label']) . '</th>';
    }
    $html .= '</tr></thead><tbody>';

    $rank = 1;
    $championIds = [];
    $titleCounts = [];
    foreach ($liste as $row) {
        $m = $row['meister'];
        $html .= '<tr><td>🏆 ' . $rank . '</td>';
        if ($m !== null) {
            $championIds[(int)$m['id']] = true;
            $tid = (int)$m['id'];
            if (!isset($titleCounts[$tid])) {
                $titleCounts[$tid] = ['name' => $m['name'], 'count' => 0];
            }
            $titleCounts[$tid]['count']++;
            // Langform des Teamnamens (m['name']) statt Kurzform (m['kurz']) -
            // auf Nutzerwunsch, siehe Changelog.
            $html .= '<td class="rk-meister">' . h($m['name']) . '</td>'
                . '<td><a href="' . h(rkProjectRootUrlPrefix() . 'liga.php?id=' . (int)$row['liga_id']) . '">'
                . h($row['saison'] !== '' ? $row['saison'] : $row['liga_name']) . '</a></td>';
            foreach ($statCols as $col) {
                $val = \LMOnext\Liga\LigaService::resolveStandingsCell($m, $col['key']);
                $cls = $col['key'] === 'pkt' ? ' class="rk-value"' : '';
                $html .= '<td' . $cls . '>' . h($val) . '</td>';
            }
        } else {
            $html .= '<td colspan="' . (2 + count($statCols)) . '">–</td>';
        }
        $html .= '</tr>';
        $rank++;
    }
    $html .= '</tbody></table>'
        . '<div class="rk-meister-footer">' . h(tf('liga_rekorde_meister_footer', [
            'meister' => count($championIds),
            'ligen'   => count($liste),
        ])) . '</div>';

    // ── Statistik: wie oft war wer Meister? ─────────────────────────────────
    // Absteigend nach Titelanzahl, bei Gleichstand alphabetisch nach Teamnamen
    // (stabile, nachvollziehbare Reihenfolge statt zufaelliger Insertion-Order).
    uasort($titleCounts, function (array $a, array $b) : int {
        $cmp = $b['count'] <=> $a['count'];
        return $cmp !== 0 ? $cmp : strcmp($a['name'], $b['name']);
    });
    $html .= '<h3 class="rk-subheading">' . h(tf('liga_rekorde_meister_statistik_heading')) . '</h3>'
        . '<table class="rk-table"><thead><tr>'
        . '<th>#</th><th>' . h(tf('liga_rekorde_col_team')) . '</th>'
        . '<th>' . h(tf('liga_rekorde_col_anzahl_titel')) . '</th>'
        . '</tr></thead><tbody>';
    $rank = 1;
    foreach ($titleCounts as $t) {
        $html .= '<tr>'
            . '<td>' . $rank . '</td>'
            . '<td>' . h($t['name']) . '</td>'
            . '<td class="rk-value">' . (int)$t['count'] . '</td>'
            . '</tr>';
        $rank++;
    }
    $html .= '</tbody></table>';

    return $html;
}

/**
 * Baut eine der drei Rekordlisten als HTML-Tabelle (gemeinsames Markup fuer
 * "hoechster Sieg" und "ergebnisreichste Partie" - beide sind Partien-Listen
 * mit denselben Spalten, nur unterschiedlich sortiert/mit unterschiedlichem
 * Zusatzwert).
 */
function rkRenderPartienListeHtml(array $partien, string $valueLabel, string $valueKey) : string
{
    if (empty($partien)) {
        return '<p class="rk-empty">' . h(tf('liga_rekorde_keine_spiele')) . '</p>';
    }
    $html = '<table class="rk-table"><thead><tr>'
        . '<th>#</th><th>' . h(tf('liga_rekorde_col_saison')) . '</th>'
        . '<th>' . h(tf('liga_rekorde_col_begegnung')) . '</th>'
        . '<th>' . h(tf('liga_rekorde_col_ergebnis')) . '</th>'
        . '<th>' . h($valueLabel) . '</th>'
        . '</tr></thead><tbody>';
    $rank = 1;
    foreach ($partien as $p) {
        $html .= '<tr>'
            . '<td>' . $rank . '</td>'
            . '<td>' . h($p['saison'] !== '' ? $p['saison'] : '–') . '</td>'
            . '<td>' . h($p['heim_name'] ?? '?') . ' – ' . h($p['gast_name'] ?? '?') . '</td>'
            . '<td>' . (int)$p['h_tore'] . ':' . (int)$p['g_tore'] . '</td>'
            . '<td class="rk-value">' . (int)$p[$valueKey] . '</td>'
            . '</tr>';
        $rank++;
    }
    $html .= '</tbody></table>';
    return $html;
}

/**
 * Baut eine Team-Zaehl-Tabelle (# | Team | Anzahl) - fuer rkOhneGegentore()/
 * rkOhneEigeneTore() (Ergebnisse aus rkSortedTeamCounts()).
 */
function rkRenderTeamCountHtml(array $rows, string $valueLabel) : string
{
    if (empty($rows)) {
        return '<p class="rk-empty">' . h(tf('liga_rekorde_keine_spiele')) . '</p>';
    }
    $html = '<table class="rk-table"><thead><tr>'
        . '<th>#</th><th>' . h(tf('liga_rekorde_col_team')) . '</th>'
        . '<th>' . h($valueLabel) . '</th>'
        . '</tr></thead><tbody>';
    $rank = 1;
    foreach ($rows as $r) {
        $html .= '<tr>'
            . '<td>' . $rank . '</td>'
            . '<td>' . h($r['team_name']) . '</td>'
            . '<td class="rk-value">' . (int)$r['anzahl'] . '</td>'
            . '</tr>';
        $rank++;
    }
    $html .= '</tbody></table>';
    return $html;
}

/**
 * Baut eine Serien-Liste (Sieg/Niederlage/Unentschieden/ohne Niederlage/ohne
 * Sieg - alle fünf teilen sich dasselbe Markup) als HTML-Tabelle.
 */
function rkRenderSerieHtml(array $serien) : string
{
    if (empty($serien)) {
        return '<p class="rk-empty">' . h(tf('liga_rekorde_keine_spiele')) . '</p>';
    }
    $html = '<table class="rk-table"><thead><tr>'
        . '<th>#</th><th>' . h(tf('liga_rekorde_col_team')) . '</th>'
        . '<th>' . h(tf('liga_rekorde_col_serie')) . '</th>'
        . '<th>' . h(tf('liga_rekorde_col_zeitraum')) . '</th>'
        . '</tr></thead><tbody>';
    $rank = 1;
    foreach ($serien as $s) {
        $zeitraum = ($s['von_saison'] !== '' ? h($s['von_saison']) : '–');
        if (($s['von_saison'] ?? '') !== ($s['bis_saison'] ?? '')) {
            $zeitraum .= ' – ' . ($s['bis_saison'] !== '' ? h($s['bis_saison']) : '–');
        }
        $html .= '<tr>'
            . '<td>' . $rank . '</td>'
            . '<td>' . h($s['team_name']) . '</td>'
            . '<td class="rk-value">' . (int)$s['laenge'] . '</td>'
            . '<td>' . $zeitraum . '</td>'
            . '</tr>';
        $rank++;
    }
    $html .= '</tbody></table>';
    return $html;
}

/**
 * Baut NUR den Inhalts-HTML-Teil einer Ansicht (Meisterliste ODER Rekorde),
 * ohne Grundgeruest/Nav/Kopf - wird sowohl von renderRekordeView() (erster,
 * vollstaendiger Seitenaufbau) als auch direkt bei rk_ajax=1-Anfragen
 * verwendet (siehe Skript-Kopf oben), damit dieselbe Berechnung nicht an
 * zwei Stellen dupliziert werden muss.
 */
function rkRenderBody(int $klasseId, string $view, int $limit) : string
{
    if ($view === 'meister') {
        return rkRenderMeisterlisteHtml($klasseId);
    }
    $hoechsterSieg   = rkHoechsterSieg($klasseId, $limit);
    $ergebnisreich   = rkErgebnisreichstePartie($klasseId, $limit);
    $hoechstesUnent  = rkHoechstesUnentschieden($klasseId, $limit);
    $ohneGegentore   = rkOhneGegentore($klasseId, $limit);
    $ohneEigeneTore  = rkOhneEigeneTore($klasseId, $limit);
    $siegSerie       = rkLaengsteSiegesserie($klasseId, $limit);
    $niederlageSerie = rkLaengsteNiederlagenserie($klasseId, $limit);
    $unentschSerie   = rkLaengsteUnentschiedenserie($klasseId, $limit);
    $ohneNiederlage  = rkLaengsteOhneNiederlageSerie($klasseId, $limit);
    $ohneSieg        = rkLaengsteOhneSiegSerie($klasseId, $limit);
    $serieOhneGegentore  = rkLaengsteOhneGegentoreSerie($klasseId, $limit);
    $serieOhneEigeneTore = rkLaengsteOhneEigeneToreSerie($klasseId, $limit);
    return
        '<h3 class="rk-subheading">' . h(tf('liga_rekorde_heading_hoechster_sieg')) . '</h3>'
        . rkRenderPartienListeHtml($hoechsterSieg, tf('liga_rekorde_col_diff'), 'diff')
        . '<h3 class="rk-subheading">' . h(tf('liga_rekorde_heading_ergebnisreich')) . '</h3>'
        . rkRenderPartienListeHtml($ergebnisreich, tf('liga_rekorde_col_summe'), 'summe')
        . '<h3 class="rk-subheading">' . h(tf('liga_rekorde_heading_hoechstes_unentschieden')) . '</h3>'
        . rkRenderPartienListeHtml($hoechstesUnent, tf('liga_rekorde_col_summe'), 'summe')
        . '<h3 class="rk-subheading">' . h(tf('liga_rekorde_heading_ohne_gegentore')) . '</h3>'
        . rkRenderTeamCountHtml($ohneGegentore, tf('liga_rekorde_col_anzahl_spiele'))
        . '<h3 class="rk-subheading">' . h(tf('liga_rekorde_heading_ohne_eigene_tore')) . '</h3>'
        . rkRenderTeamCountHtml($ohneEigeneTore, tf('liga_rekorde_col_anzahl_spiele'))
        . '<h3 class="rk-subheading">' . h(tf('liga_rekorde_heading_siegesserie')) . '</h3>'
        . rkRenderSerieHtml($siegSerie)
        . '<h3 class="rk-subheading">' . h(tf('liga_rekorde_heading_ohne_niederlage')) . '</h3>'
        . rkRenderSerieHtml($ohneNiederlage)
        . '<h3 class="rk-subheading">' . h(tf('liga_rekorde_heading_ohne_sieg')) . '</h3>'
        . rkRenderSerieHtml($ohneSieg)
        . '<h3 class="rk-subheading">' . h(tf('liga_rekorde_heading_niederlageserie')) . '</h3>'
        . rkRenderSerieHtml($niederlageSerie)
        . '<h3 class="rk-subheading">' . h(tf('liga_rekorde_heading_unentschieden')) . '</h3>'
        . rkRenderSerieHtml($unentschSerie)
        . '<h3 class="rk-subheading">' . h(tf('liga_rekorde_heading_serie_ohne_gegentore')) . '</h3>'
        . rkRenderSerieHtml($serieOhneGegentore)
        . '<h3 class="rk-subheading">' . h(tf('liga_rekorde_heading_serie_ohne_eigene_tore')) . '</h3>'
        . rkRenderSerieHtml($serieOhneEigeneTore);
}

/**
 * Berechnungszeit-Zeile, gleiches Muster wie TemplateEngine::render() im
 * Hauptsystem (footer_render_time-Sprachschluessel, Referenzzeitpunkt
 * $_SERVER['REQUEST_TIME_FLOAT'] - vom Webserver beim Requestempfang
 * gesetzt, misst also die GESAMTE PHP-Ausfuehrungszeit dieses Requests, nicht
 * nur einen Teilausschnitt). Wird sowohl an das Ende der vollstaendigen
 * Seite (renderRekordeView()) als auch an jedes per rk_ajax=1 nachgeladene
 * Fragment angehaengt (siehe Skript-Kopf) - getrennt betrachtet laesst sich
 * damit einordnen, ob der erste Seitenaufbau (Meisterliste) oder das
 * Nachladen der Rekordspiele-Ansicht der eigentliche Engpass ist.
 */
function rkRenderTimeHtml() : string
{
    $startTime = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
    return '<p class="rk-rendertime">' . h(tf('footer_render_time', [
        'sekunden' => number_format(microtime(true) - $startTime, 4, '.', ''),
    ])) . '</p>';
}

/**
 * Baut die komplette Seite (Grundgeruest + Nav + Inhalt) inkl. Lazy-Load fuer
 * die Rekordspiele-Ansicht.
 *
 * WICHTIG: der erste, synchron im PHP-Request berechnete Inhalt ist IMMER
 * die Meisterliste, unabhaengig vom angeforderten $view - die (bei Klassen
 * mit vielen Saisons spuerbar langsamere, da sieben Auswertungen ueber alle
 * Partien) Rekordspiele-Berechnung wird per JavaScript per fetch() erst NACH
 * dem ersten sichtbaren Seitenaufbau nachgeladen (rk_ajax=1, siehe
 * Skript-Kopf und rkRenderBody()). War $view='rekorde' angefordert (z.B. der
 * Besucher hatte zuletzt auf diesen Tab geklickt, oder ein Embed verlinkt
 * direkt dorthin), startet dieses Nachladen automatisch beim Seitenaufbau,
 * zeigt aber sofort ein Lade-Element statt die Seite zu blockieren.
 */
function renderRekordeView(int $klasseId, string $view, int $limit, string $templateName) : string
{
    $klasse = rkGetKlasse($klasseId);
    if ($klasse === null) {
        return '<p style="font-family:sans-serif;color:#697182;padding:12px">'
            . h(tf('liga_rekorde_klasse_nicht_gefunden')) . '</p>';
    }

    $meisterHtml = rkRenderMeisterlisteHtml($klasseId);

    $navHtml = '<a href="#" data-rk-view="meister" class="rk-tab' . ($view === 'meister' ? ' rk-tab-active' : '') . '">'
        . h(tf('liga_rekorde_tab_meister')) . '</a>'
        . '<a href="#" data-rk-view="rekorde" class="rk-tab' . ($view === 'rekorde' ? ' rk-tab-active' : '') . '">'
        . h(tf('liga_rekorde_tab_rekorde')) . '</a>';

    // Selbstreferenzierender AJAX-Fetch-Pfad für den Tab-Wechsel: über den
    // zentralen Controller addon-run.php (der einzige noch erlaubte
    // Aufrufweg) muss dieser Pfad zwingend die addon=/file=-Parameter
    // mitführen, sonst bricht der Folgeaufruf mit einem 400-Fehler ab
    // (siehe addon-run.php) - eine reine "?query"-URL würde vom Browser
    // sonst gegen die AKTUELLE Seiten-URL aufgelöst und dabei genau diese
    // Parameter verlieren.
    if (defined('LMO_ADDON_STANDALONE_CALL') && isset($_GET['addon'], $_GET['file'])) {
        $rkFetchBase = 'addon-run.php?addon=' . rawurlencode((string)$_GET['addon']) . '&file=' . rawurlencode((string)$_GET['file']) . '&';
    } else {
        $rkFetchBase = '?';
    }
    $rkFetchBaseJs = json_encode($rkFetchBase, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $lazyJs = '<script>(function(){'
        . 'var klasse=' . (int)$klasseId . ',limit=' . (int)$limit . ';'
        . 'var fetchBase=' . $rkFetchBaseJs . ';'
        . 'var meisterHtml=' . json_encode($meisterHtml, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';'
        . 'var cache={meister:meisterHtml};'
        . 'var bodyEl=document.getElementById("rk-body");'
        . 'var tabs=document.querySelectorAll("[data-rk-view]");'
        . 'var loadingHtml=' . json_encode('<p class="rk-empty">' . h(tf('liga_rekorde_wird_geladen')) . '</p>', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';'
        . 'var errorHtml=' . json_encode('<p class="rk-empty">' . h(tf('liga_rekorde_ladefehler')) . '</p>', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';'
        . 'function showView(view){'
        .   'tabs.forEach(function(t){t.classList.toggle("rk-tab-active",t.dataset.rkView===view);});'
        .   'if(cache[view]!==undefined){bodyEl.innerHTML=cache[view];return;}'
        .   'bodyEl.innerHTML=loadingHtml;'
        .   'fetch(fetchBase+"rk_klasse="+klasse+"&rk_view="+view+"&rk_limit="+limit+"&rk_ajax=1")'
        .     '.then(function(r){return r.text();})'
        .     '.then(function(html){cache[view]=html;bodyEl.innerHTML=html;})'
        .     '.catch(function(){bodyEl.innerHTML=errorHtml;});'
        . '}'
        . 'tabs.forEach(function(t){t.addEventListener("click",function(e){e.preventDefault();showView(t.dataset.rkView);});});'
        . (($view === 'rekorde') ? 'showView("rekorde");' : '')
        . '})();</script>';

    $skeleton = rkLoadTemplate($templateName);
    $skeleton = str_replace('{KLASSE_NAME}', h($klasse['name']), $skeleton);
    $skeleton = str_replace('{NAV}',         $navHtml, $skeleton);
    $skeleton = str_replace('{BODY}',        $meisterHtml, $skeleton);
    $skeleton = str_replace('{LAZY_JS}',     $lazyJs, $skeleton);
    $skeleton = str_replace('{COPYRIGHT}',   LigaService::renderCopyrightNotice('rekorde'), $skeleton);
    $skeleton = str_replace('{RENDER_TIME}', rkRenderTimeHtml(), $skeleton);
    return $skeleton;
}
