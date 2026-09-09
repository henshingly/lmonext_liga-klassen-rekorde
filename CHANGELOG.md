# Changelog: liga-klassen-rekorde-Addon (LMOnext)

Kombiniertes Addon aus zwei ursprünglich getrennten Paketen (liga-klassen +
rekorde), auf Nutzerwunsch zu einem gemeinsamen Addon zusammengelegt, da
rekorde ohnehin zwingend von liga-klassen abhing.

**Liga-Klassen:** bündelt mehrere Saison-Instanzen derselben Wettbewerbsserie
(z.B. "Volleyball 3.Liga Nord Männer" über mehrere Jahre).

**Rekorde:** Meisterliste und Rekordspiele (höchster Sieg, torreichste
Partie, Serien u.v.m.) für eine komplette Liga-Klasse über alle Saisons
hinweg, aufbauend auf den Liga-Klassen.

Stammt aus einem separaten Chat/Session (Konzeptdiskussion 17.08.2026, siehe
lmonext_liga_klassen_konzept.md) und wurde nachträglich für den Addon-Manager
verpackt, ohne die 1.9.1-beta-Core-Dateien anzufassen.

## Version 1.6.1 (KRITISCHER Bugfix)

- KRITISCHER Bugfix in der neuen Kategorie "Längste Serie an Tabellenplatz 1" (gemeldet: FC Bayern zeigte eine Serie von 374 Spieltagen über 2012/13 - 2022/23 - rechnerisch exakt 11 Meistersaisons a 34 Spieltage): computeStandings() (Core, src/Liga/StandingsTrait.php) berechnet IMMER die Tabelle aus ALLEN übergebenen, bereits gespielten Partien - der $currentSpieltag-Parameter filtert die Partien entgegen der Erwartung NICHT nach Spieltag, sondern steuert ausschließlich, ob eine "ab Spieltag X" greifende Strafpunkte-Regel zu diesem Zeitpunkt der Saison schon wirksam ist. Ohne eigene Vorfilterung lieferte deshalb jeder Aufruf trotz unterschiedlichem Spieltag-Parameter immer dieselbe, komplette Saison-Endtabelle zurück - der Meister einer Saison erschien dadurch bei JEDEM Spieltag dieser Saison als "Erster", nicht nur ab dem Spieltag, an dem er die Tabelle tatsächlich anführte. lmo-rekorde.php 1.13.1: die Partien-Liste wird jetzt selbst kumulativ (nach Spieltagnummer gruppiert, pro Spieltag nur um die neuen Partien erweitert statt bei jedem Aufruf komplett neu gefiltert - O(Spiele) statt O(Spieltage × Spiele) je Saison) auf Spiele bis einschließlich dem jeweiligen Spieltag eingegrenzt, bevor sie an computeStandings() übergeben wird. Bereits gespielte Einzelpartien eines (noch) unvollständigen Spieltags fließen weiterhin korrekt in die Tabelle eines späteren, vollständigen Spieltags ein - nur die Auswertung (wer führt an) wird bei einem unvollständigen Spieltag selbst übersprungen.
- Gegen den Addon-Manager-Sicherheitsscanner erneut getestet: keine Treffer.

## Version 1.6.0 (Neues Feature)

- Neue Rekordkategorie "Längste Serie an Tabellenplatz 1" (lmo-rekorde.php 1.13.0): neue Funktion rkLaengsteTabellenfuehrungSerie() berechnet, im Unterschied zu den bisherigen sieben Serienarten (die auf einzelnen Spielen basieren), die Tabelle nach jedem VOLLSTÄNDIGEN Spieltag jeder Saison der Klasse (über LigaService::computeStandings(), dieselbe bereits bewährte Berechnung wie bei der Meisterliste) und findet je Team die längste Serie an aufeinanderfolgenden Spieltagen auf Platz 1 - mit derselben Saison-Lücken-Erkennung wie alle anderen Serienarten (rkComputeStreak()). Unvollständige Spieltage werden übersprungen, nicht gewertet und unterbrechen die Serie nicht. Nutzt ausschließlich bereits per rkBulkLoadStandingsData() im Speicher geladene Rohdaten - keine zusätzlichen Datenbankabfragen, computeStandings() selbst verifiziert als reine In-Memory-Berechnung, daher trotz vieler Aufrufe (einer je vollständigem Spieltag) unkritisch für die Laufzeit auch bei Klassen mit vielen Saisons.
- Neue Sprachschlüssel: liga_rekorde_heading_tabellenfuehrung.
- Gegen den Addon-Manager-Sicherheitsscanner getestet: keine Treffer.

## Version 1.5.3 (Bugfix)

- KRITISCHER Bugfix (gemeldet: Standalone-Aufruf über addon-run.php lieferte einen komplett leeren `<body>`, ohne jede sichtbare Fehlermeldung - Ursache erst über das Server-Error-Log gefunden: "file_get_contents(.../templates/standard.tpl.php): Failed to open stream"): der Template-Ordner hieß versehentlich "template" (Singular) statt "templates" (Plural, wie in allen anderen Standalone-Addons dieses Systems konsistent verwendet - mini, viewer, ewige, relegation, tabellenrechner). Ordner korrekt umbenannt. file_get_contents() gibt bei einer fehlenden Datei nur eine PHP-Warning aus (nicht sichtbar auf der Seite) und liefert false zurück - (string)false wurde zu einem leeren String, wodurch renderRekordeView() am Ende komplett leer blieb.
- Zusätzlich (lmo-rekorde.php 1.12.1, Verteidigung in der Tiefe): rkLoadTemplate() zeigt jetzt eine sichtbare Fehlermeldung, falls selbst der Fallback "standard.tpl.php" fehlen sollte, statt eines stillen, leeren Strings - damit ein ähnliches Problem künftig sofort auf der Seite erkennbar wäre.
- Gegen den Addon-Manager-Sicherheitsscanner erneut getestet: keine Treffer im gesamten Addon-Paket.

## Version 1.4.0

- Sammel-Bearbeitung: neue Aktion "Alle Saisons speichern" je Klasse -
  speichert alle sichtbaren Saison-Felder einer Klasse in einem Rutsch,
  statt jede Zeile einzeln bestätigen zu müssen (auf Nutzerwunsch, war bei
  Klassen mit vielen Saisons - z.B. 64 bei "1. Fussball Bundesliga" - zu
  langwierig). Technisch über das HTML5 form=""-Attribut gelöst (Saison-
  Inputs bleiben visuell in ihrer Zeile, gehören aber alle zu einem
  gemeinsamen Formular am Ende der Liste) - vermeidet verschachtelte
  &lt;form&gt;-Tags. Neuer Handler-Block "bulk_update_saison", aktualisiert
  nur Ligen, die tatsächlich der angegebenen Klasse angehören.

## Version 1.3.0

- Neues Feature: Saison bereits zugeordneter Ligen ist jetzt direkt in der
  Klassen-Detailliste bearbeitbar (Textfeld + 💾-Button je Zeile), statt
  nur als reiner Text angezeigt zu werden. Vorher gab es dafür keine
  Möglichkeit außer "Entfernen" + Neuzuordnung über den Bereich "Ligen ohne
  Klasse" (was den Umweg über ein komplettes Entkoppeln erfordert hätte).

## Version 1.2.0

- WICHTIGER Bugfix (Ursache für "Klasse existiert laut Duplikat-Fehler,
  erscheint aber nicht in der Liste"): AddonManager::bootAdmin() lädt
  Admin-Handler per require_once INNERHALB einer Klassenmethode, nicht im
  globalen Scope von admin.php. $ligaKlassenData wurde dadurch nur lokal in
  bootAdmin() gesetzt und war in view_ligaklassen.php (das admin.php direkt
  im globalen Scope einbindet) unsichtbar - dort dann still null, keine
  Fehlermeldung, nur eine leer wirkende Liste trotz vorhandener Daten in der
  DB. Fix: explizite global-Deklaration vor der Zuweisung. Betraf JEDE
  Nutzung der Liga-Klassen-Übersicht, unabhängig von DB-Restores o.ä. - der
  vorherige DB-Restore-Verdacht (Version 1.1.0) war eine Randnotiz, nicht
  die eigentliche Ursache.

## Version 1.1.0

- Debugging-Fix für den gemeldeten Fall "Klasse existiert laut Duplikat-
  Fehler bereits, wird aber in der Liste nicht angezeigt" (z.B. nach einem
  DB-Restore aus einer anderen LMOnext-Installation): die Klassen-Listen-
  Abfrage fing bisher JEDEN Fehler still ab und zeigte einfach eine leere
  Liste, ohne Hinweis auf den eigentlichen Grund. Zeigt jetzt eine sichtbare
  Fehlermeldung mit der echten DB-Fehlermeldung, zusätzlich Logging via
  error_log().
- Migrations-Flag robuster gemacht: verlässt sich nicht mehr blind auf das
  "erledigt"-Settings-Flag (das bei einem DB-Restore aus einer anderen
  Installation mitkopiert werden kann, ohne dass die eigentliche
  liga_klassen-Tabelle/die saison+klasse_id-Spalten auf DIESER Datenbank
  tatsächlich existieren) - verifiziert stattdessen zusätzlich per
  günstigem SHOW TABLES/SHOW COLUMNS, ob das Schema wirklich vorhanden ist,
  und holt die Migration bei Bedarf nach.

## Version 1.0.1

- Bugfix: admin_nav in addon.json war fälschlich als Map (Schlüssel = Aktion)
  statt als Liste (jedes Element mit eigenem "action"-Feld) formatiert -
  AddonManager::getNavItems() erwartet zwingend eine Liste. Dadurch erschien
  der Menüpunkt "Liga-Klassen" trotz aktiviertem Addon nirgends in der
  Admin-Navigation. Keine Datenänderung, reine Manifest-Korrektur.

## Version 1.0.0

- Erste Veröffentlichung als gemeinsames Addon "liga-klassen-rekorde"
  (ersetzt die beiden getrennten Addons "liga-klassen" 1.0.0 und "rekorde"
  1.0.0 - siehe deren jeweilige addon.json/CHANGELOG.md für die
  Einzelhistorie vor der Zusammenlegung).
- Enthält eine einmalige, additive Schema-Migration (kein bestehendes
  Feld/keine bestehende Zeile wird verändert): legt bei erster Aktivierung
  die Tabelle liga_klassen an sowie zwei NULLABLE Spalten (saison, klasse_id)
  auf der Core-Tabelle liga. Wird per addon-eigenem Settings-Flag
  ("liga_klassen_schema_v1") genau einmal ausgeführt.
- Admin-Seite "Liga-Klassen" (Nav-Position 25): Klassen anlegen/bearbeiten/
  löschen, bestehende Ligen einer Klasse + Saison zuordnen, automatische
  Gruppierungsvorschläge für unzugeordnete Ligen.
- Standalone-Skript lmo-rekorde.php (Meisterliste + Rekordspiele je
  Liga-Klasse), per include() oder iframe einbindbar, mit eigenen
  Templates (templates/standard.tpl.php).
- Templates auf addon-lokales templates/ umgestellt (statt zentral im Core
  unter template/addon/rekorde/) - Addon ist damit vollständig eigenständig.
- Fehlenden loadLanguages()-Aufruf in lmo-rekorde.php ergänzt (derselbe
  Bugtyp wie zuvor bei den Addons viewer/ewige gefunden): die 36
  liga_rekorde_*-Sprachschlüssel lagen bisher direkt im LMOnext-Core und
  wurden nie automatisch geladen.
- HINWEIS: Die Convenience-Felder "Saison" und "Liga-Klasse" direkt auf der
  normalen Liga-Einstellungsseite (Core: admin/view_liga_settings.php) sind
  bewusst NICHT enthalten, da das eine Core-Datei-Änderung erfordert hätte.
  Die vollständige Zuordnungsfunktion ist stattdessen über die eigene
  "Liga-Klassen"-Seite dieses Addons verfügbar.

## Version 1.5.0 (Sicherheitsüberarbeitung)

- lmo-rekorde.php 1.12.0: Aufruf-Erkennung auf die neue Konstante
  LMO_ADDON_STANDALONE_CALL umgestellt (gesetzt vom neuen zentralen
  Controller /addon-run.php). Der direkte URL-Aufruf ist per
  addon/.htaccess jetzt komplett gesperrt - Einbettungen müssen ab sofort
  über /addon-run.php?addon=liga-klassen-rekorde&file=lmo-rekorde.php&...
  laufen, NICHT mehr über /addon/liga-klassen-rekorde/lmo-rekorde.php.
- Neues Manifest-Feld "standalone_entrypoints": ["lmo-rekorde.php"].
- Asset-Pfadauflösung (rkProjectRootUrlPrefix()) nutzt jetzt bevorzugt
  die vom Controller gelieferte LMO_ADDON_WEB_BASE.
- ZUSÄTZLICHER Bugfix (beim Umbau entdeckt): der AJAX-Fetch-Pfad für den
  Tab-Wechsel (Meisterliste/Rekorde) wurde bisher als reine "?query"-URL
  gebaut, die der Browser gegen die AKTUELLE Seiten-URL auflöst - über den
  neuen Controller aufgerufen hätte das die nötigen addon=/file=-Parameter
  verloren und wäre mit einem 400-Fehler fehlgeschlagen. Baut den
  Fetch-Pfad jetzt korrekt inkl. dieser Parameter.

**WICHTIG für bestehende Einbettungen:** URL wie oben anpassen, falls
bereits per iframe/URL extern eingebunden. Betrifft NUR den
Standalone-Aufruf des Rekorde-Skripts, NICHT die Admin-Seite
"Liga-Klassen" (die läuft weiterhin normal über das Admin-Menü).

## Version 1.5.1

- Ergänzung: "db_tables": ["liga_klassen"] im Manifest ergänzt (fehlte
  bisher komplett) - ermöglicht das neue "Daten löschen"-Feature im
  Addon-Manager (siehe Core-CHANGELOG.md src/Addon/AddonManager.php
  1.4.0). HINWEIS: löscht nur die eigene liga_klassen-Tabelle, NICHT die
  beiden per ALTER TABLE ergänzten Spalten (saison, klasse_id) auf der
  Core-Tabelle liga - das wäre ein zu invasiver Eingriff für eine
  automatische Funktion und bleibt bewusst außen vor (die Spalten sind
  nullable und ohne Funktion, solange das Addon deaktiviert bleibt).

## Version 1.5.2

- min_core_version von "1.9.0" auf "1.9.2" erhöht: das "Daten löschen"-
  Feature (siehe Version 1.5.1, db_tables-Ergänzung) setzt
  AddonManager::purgeData() voraus, das erst mit LMOnext-Core 1.9.2-beta
  ausgeliefert wird.
