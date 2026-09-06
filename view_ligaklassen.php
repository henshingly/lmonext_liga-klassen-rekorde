<?php
/**
 * Project: LMOnext
 * Filename: addon/liga-klassen-rekorde/view_ligaklassen.php
 * Fileversion: 1.2.0
 *
 * PHP version 8.2
 *
 * @author    Dietmar Kersting <webmaster@liga-manager-online.org>
 * @author    Torsten Hofmann <entwickler@bastel-code.de>
 * @copyright 2026 Dietmar Kersting, Torsten Hofmann
 * @license   GPL-3.0-only
 *
 * View: Liga-Klassen - bündelt mehrere Saison-Instanzen derselben
 * Wettbewerbsserie (z.B. "Volleyball 3.Liga Nord Männer" über mehrere Jahre)
 * als Grundlage für Meisterliste/Rekordspiele (siehe addon/rekorde/) und
 * künftig Auf-/Absteiger. Beitrag: Konzeptdiskussion 17.08.2026, siehe
 * lmonext_liga_klassen_konzept.md.
 */

$klassen       = $ligaKlassenData['klassen']       ?? [];
$ligenByKlasse = $ligaKlassenData['ligenByKlasse']  ?? [];
$unassigned    = $ligaKlassenData['unassigned']     ?? [];
$suggestions   = $ligaKlassenData['suggestions']    ?? [];

$sportLabels = [];
foreach (\LMOnext\Sport\SportRegistry::all() as $sp) { $sportLabels[$sp->getKey()] = $sp->getLabel(); }
?>

<div style="display:flex;gap:10px;align-items:center;margin-bottom:16px;flex-wrap:wrap">
  <button class="btn btn-muted" onclick="openKlasseEdit(0,'','football','')"><?= h(t('lk_btn_new')) ?></button>
  <span style="font-size:.83rem;color:var(--muted);margin-left:auto">
    <?= h(t('lk_summary_line', ['n' => count($klassen)])) ?>
  </span>
</div>

<?php if (!empty($suggestions)) { ?>
<div class="card" style="margin-bottom:16px;border:1px solid var(--accent)">
  <h2 style="font-size:.95rem;margin-bottom:4px">💡 <?= h(t('lk_suggestions_heading')) ?></h2>
  <p style="font-size:.82rem;color:var(--muted);margin-bottom:14px"><?= h(t('lk_suggestions_hint')) ?></p>
  <?php foreach ($suggestions as $sg) { ?>
  <div style="border:1px solid var(--border);border-radius:var(--radius);padding:10px 14px;margin-bottom:10px">
    <form method="post" action="?action=accept_klasse_suggestion">
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:8px">
        <span class="chip chip-blue" style="font-size:.72rem"><?= h($sportLabels[$sg['sport_type']] ?? $sg['sport_type']) ?></span>
        <input type="text" name="klasse_name" value="<?= h($sg['stem']) ?>" required
               style="flex:1;min-width:220px;background:var(--bg);border:1px solid var(--border);color:var(--text);
                      border-radius:var(--radius);padding:6px 10px;font-size:.85rem">
        <input type="hidden" name="klasse_sport" value="<?= h($sg['sport_type']) ?>">
        <button type="submit" class="btn btn-success btn-sm"><?= h(t('lk_btn_accept_suggestion', ['n' => count($sg['ligen'])])) ?></button>
      </div>
      <div style="font-size:.78rem;color:var(--muted)">
        <?= implode(' · ', array_map(fn($l) => h($l['name']), $sg['ligen'])) ?>
      </div>
      <?php foreach ($sg['ligen'] as $l) { ?>
      <input type="hidden" name="liga_ids[]" value="<?= (int)$l['id'] ?>">
      <?php } ?>
      <?= csrfField() ?>
    </form>
  </div>
  <?php } ?>
</div>
<?php } ?>

<?php if (empty($klassen)) { ?>
<div class="card">
  <p class="text-muted" style="font-size:.9rem"><?= h(t('lk_empty_hint')) ?></p>
</div>
<?php } else {
    $klassenBySport = [];
    foreach ($klassen as $k) { $klassenBySport[$k['sport_type']][] = $k; }
    foreach ($klassenBySport as $sportKey => $sportKlassen) { ?>
<h2 style="font-size:.9rem;color:var(--muted);margin:18px 0 8px"><?= h($sportLabels[$sportKey] ?? $sportKey) ?></h2>
<div class="card" style="padding:12px;margin-bottom:12px">
  <?php foreach ($sportKlassen as $k) {
      $kid = (int)$k['id'];
      $members = $ligenByKlasse[$kid] ?? [];
      usort($members, fn($a, $b) => strcmp((string)$a['datum'], (string)$b['datum'])); ?>
  <details style="margin-bottom:6px">
    <summary style="list-style:none;display:flex;align-items:center;gap:8px;
                    background:var(--surface2);border:1px solid var(--border);
                    border-radius:var(--radius);padding:8px 12px;cursor:pointer;user-select:none">
      <span style="font-size:1rem">🏆</span>
      <span style="color:var(--muted);font-size:.78rem;font-family:monospace;cursor:pointer"
            onclick="event.stopPropagation();navigator.clipboard.writeText('<?= $kid ?>');this.textContent='✓ kopiert';setTimeout(()=>{this.textContent='#<?= $kid ?>'},1200)"
            title="<?= h(t('lk_copy_id')) ?>">#<?= $kid ?></span>
      <div style="flex:1">
        <strong style="font-size:.9rem"><?= h($k['name']) ?></strong>
        <span style="font-size:.75rem;color:var(--muted);margin-left:6px">
          (<?= (int)$k['liga_count'] === 1 ? h(t('lk_count_saisons_one')) : h(t('lk_count_saisons_many', ['n' => (int)$k['liga_count']])) ?>)
        </span>
      </div>
      <div style="display:flex;gap:6px" onclick="event.stopPropagation()">
        <button class="btn btn-muted btn-sm"
                onclick="openKlasseEdit(<?= $kid ?>,<?= h(json_encode($k['name'])) ?>,<?= h(json_encode($k['sport_type'])) ?>,<?= h(json_encode($k['beschreibung'])) ?>)">✏️</button>
        <form method="post" action="?action=delete_liga_klasse" style="display:inline"
              onsubmit="return confirm('<?= h(t('lk_confirm_delete')) ?>')">
          <input type="hidden" name="klasse_id" value="<?= $kid ?>">
          <button type="submit" class="btn btn-danger btn-sm">🗑</button>
        <?= csrfField() ?></form>
      </div>
    </summary>
    <div style="margin-left:8px;border-left:2px solid var(--border);padding-left:4px;margin-top:2px">
      <?php if (empty($members)) { ?>
      <p style="font-size:.8rem;color:var(--muted);padding:6px 12px"><?= h(t('lk_no_seasons_yet')) ?></p>
      <?php } ?>
      <?php foreach ($members as $l) { ?>
      <div style="display:flex;align-items:center;gap:8px;padding:5px 12px;border-bottom:1px solid var(--border);background:var(--bg)">
        <input type="text" name="saison[<?= (int)$l['id'] ?>]" value="<?= h($l['saison'] ?? '') ?>"
               placeholder="<?= h(t('ls_placeholder_saison')) ?>" form="bulk-saison-<?= $kid ?>"
               style="width:90px;flex-shrink:0;background:var(--bg);border:1px solid var(--border);color:var(--text);
                      border-radius:var(--radius);padding:4px 8px;font-size:.78rem;font-family:monospace">
        <a href="?action=liga_detail&id=<?= (int)$l['id'] ?>"
           style="color:var(--accent);text-decoration:none;font-size:.87rem;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($l['name']) ?></a>
        <form method="post" action="?action=assign_liga_klasse" style="display:inline;flex-shrink:0">
          <input type="hidden" name="liga_id" value="<?= (int)$l['id'] ?>">
          <input type="hidden" name="klasse_id" value="">
          <input type="hidden" name="saison" value="<?= h($l['saison'] ?? '') ?>">
          <button type="submit" class="btn btn-muted btn-sm" style="padding:2px 8px;font-size:.75rem"><?= h(t('lk_btn_unassign')) ?></button>
        <?= csrfField() ?></form>
      </div>
      <?php } ?>
<?php if (!empty($members)) { ?>
      <!-- Sammelformular fuer alle Saison-Felder oben (HTML5 form=""-Attribut,
           damit keine verschachtelten <form>-Tags noetig sind - die
           Saison-Inputs liegen physisch in den Zeilen oben, gehoeren aber zu
           diesem Formular). Ein Klick speichert ALLE Saisons dieser Klasse
           auf einmal, statt Zeile fuer Zeile einzeln bestaetigen zu muessen. -->
      <form id="bulk-saison-<?= $kid ?>" method="post" action="?action=bulk_update_saison" style="padding:8px 12px">
        <input type="hidden" name="klasse_id" value="<?= $kid ?>">
        <button type="submit" class="btn btn-success btn-sm">💾 <?= h(t('lk_btn_save_all_saisons')) ?></button>
      <?= csrfField() ?></form>
<?php } ?>
    </div>
  </details>
  <?php } ?>
</div>
<?php } } ?>

<h2 style="font-size:.9rem;color:var(--muted);margin:18px 0 8px"><?= h(t('lk_unassigned_heading')) ?></h2>
<div class="card" style="padding:12px">
  <?php if (empty($unassigned)) { ?>
  <p style="font-size:.85rem;color:var(--muted)"><?= h(t('lk_unassigned_empty')) ?></p>
  <?php } else { foreach ($unassigned as $l) { ?>
  <div style="display:flex;align-items:center;gap:8px;padding:6px 10px;border:1px solid var(--border);border-radius:var(--radius);margin-bottom:6px;flex-wrap:wrap">
    <span class="chip chip-blue" style="font-size:.7rem"><?= h($sportLabels[$l['sport_type']] ?? $l['sport_type']) ?></span>
    <a href="?action=liga_detail&id=<?= (int)$l['id'] ?>"
       style="color:var(--accent);text-decoration:none;font-size:.87rem;flex:1;min-width:180px"><?= h($l['name']) ?></a>
    <form method="post" action="?action=assign_liga_klasse" style="display:flex;gap:6px;align-items:center">
      <input type="hidden" name="liga_id" value="<?= (int)$l['id'] ?>">
      <input type="text" name="saison" value="<?= h($l['saison'] ?? '') ?>" placeholder="<?= h(t('ls_placeholder_saison')) ?>"
             style="width:90px;background:var(--bg);border:1px solid var(--border);color:var(--text);
                    border-radius:var(--radius);padding:4px 8px;font-size:.78rem">
      <select name="klasse_id"
              style="background:var(--bg);border:1px solid var(--border);color:var(--text);
                     border-radius:var(--radius);padding:4px 8px;font-size:.78rem">
        <option value=""><?= h(t('lk_select_klasse')) ?></option>
        <?php foreach ($klassen as $k) { if ($k['sport_type'] !== $l['sport_type']) continue; ?>
        <option value="<?= (int)$k['id'] ?>"><?= h($k['name']) ?></option>
        <?php } ?>
      </select>
      <button type="submit" class="btn btn-success btn-sm" style="padding:3px 10px;font-size:.75rem"><?= h(t('common_save')) ?></button>
    <?= csrfField() ?></form>
  </div>
  <?php } } ?>
</div>

<!-- Klasse-Modal -->
<div id="klasse-modal" style="display:none;position:fixed;inset:0;background:#000a;z-index:9999;
                               align-items:center;justify-content:center">
  <div style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
              padding:24px 28px;width:100%;max-width:460px;margin:16px">
    <h2 style="font-size:1rem;margin-bottom:16px" id="km-title"><?= h(t('lk_btn_new')) ?></h2>
    <form method="post" action="?action=save_liga_klasse">
      <input type="hidden" name="klasse_id" id="km-id" value="0">
      <div style="margin-bottom:12px">
        <label style="font-size:.78rem;color:var(--muted);display:block;margin-bottom:4px"><?= h(t('teams_field_name_required')) ?></label>
        <input type="text" name="klasse_name" id="km-name" required
               style="width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);
                      border-radius:var(--radius);padding:7px 12px;font-size:.88rem">
      </div>
      <div style="margin-bottom:12px">
        <label style="font-size:.78rem;color:var(--muted);display:block;margin-bottom:4px"><?= h(t('ls_label_sportart')) ?></label>
        <select name="klasse_sport" id="km-sport"
                style="width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);
                       border-radius:var(--radius);padding:6px 10px;font-size:.85rem">
          <?php foreach (\LMOnext\Sport\SportRegistry::all() as $sp) { ?>
          <option value="<?= h($sp->getKey()) ?>"><?= h($sp->getLabel()) ?></option>
          <?php } ?>
        </select>
      </div>
      <div style="margin-bottom:16px">
        <label style="font-size:.78rem;color:var(--muted);display:block;margin-bottom:4px"><?= h(t('arch_label_description')) ?></label>
        <input type="text" name="klasse_beschr" id="km-beschr"
               style="width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);
                      border-radius:var(--radius);padding:7px 12px;font-size:.88rem">
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end">
        <button type="button" class="btn btn-muted btn-sm"
                onclick="document.getElementById('klasse-modal').style.display='none'"><?= h(t('common_cancel')) ?></button>
        <button type="submit" class="btn btn-success btn-sm"><?= h(t('common_save')) ?></button>
      </div>
    <?= csrfField() ?></form>
  </div>
</div>

<script>
const i18nLk = {
  titleNew:  <?= json_encode(t('lk_btn_new')) ?>,
  titleEdit: <?= json_encode(t('lk_modal_title_edit')) ?>,
};
function openKlasseEdit(id, name, sport, beschr) {
  document.getElementById('km-id').value     = id;
  document.getElementById('km-name').value   = name;
  document.getElementById('km-beschr').value = beschr;
  const sel = document.getElementById('km-sport');
  for (let i = 0; i < sel.options.length; i++) {
    if (sel.options[i].value === sport) { sel.selectedIndex = i; break; }
  }
  document.getElementById('km-title').textContent = id > 0 ? i18nLk.titleEdit : i18nLk.titleNew;
  document.getElementById('klasse-modal').style.display = 'flex';
  setTimeout(() => document.getElementById('km-name').focus(), 50);
}
document.getElementById('klasse-modal').addEventListener('click', function (e) {
  if (e.target === this) this.style.display = 'none';
});
</script>
