<?php
session_start();

$configFile = __DIR__ . '/admin-config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    exit('Hiányzik az admin-config.php (admin jelszó hash). Töltsd fel FTP-n a szerverre.');
}
require $configFile;

$contentFile = __DIR__ . '/content.json';
$defaultFile = __DIR__ . '/content.default.json';
$uploadsDir  = __DIR__ . '/uploads/';

if (!is_dir($uploadsDir)) {
    mkdir($uploadsDir, 0755, true);
}

function load_content($contentFile, $defaultFile) {
    $file = file_exists($contentFile) ? $contentFile : $defaultFile;
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function save_content($contentFile, $data) {
    file_put_contents(
        $contentFile,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check() {
    return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']);
}

$isLoggedIn = !empty($_SESSION['admin_logged_in']);

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: admin.php');
    exit;
}

$loginError = '';
if (!$isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    if (password_verify($_POST['password'] ?? '', ADMIN_PASSWORD_HASH)) {
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        header('Location: admin.php');
        exit;
    }
    sleep(1);
    $loginError = 'Hibás jelszó.';
}

if (!$isLoggedIn) {
    ?><!DOCTYPE html>
<html lang="hu"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin belépés</title>
<style>
  body{font-family:sans-serif;background:#faf7f2;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}
  form{background:#fff;padding:40px 36px;border-radius:10px;box-shadow:0 20px 50px -25px rgba(0,0,0,.3);width:100%;max-width:340px;}
  h1{font-size:1.3rem;margin:0 0 20px;}
  input{width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;font-size:1rem;box-sizing:border-box;margin-bottom:14px;}
  button{width:100%;padding:11px;background:#a9714f;color:#fff;border:none;border-radius:6px;font-size:0.95rem;cursor:pointer;}
  .err{color:#c0392b;font-size:0.9rem;margin-bottom:14px;}
</style></head><body>
<form method="post">
  <h1>Esküvő admin</h1>
  <?php if ($loginError): ?><div class="err"><?= htmlspecialchars($loginError) ?></div><?php endif; ?>
  <input type="hidden" name="action" value="login">
  <input type="password" name="password" placeholder="Jelszó" autofocus required>
  <button type="submit">Belépés</button>
</form>
</body></html><?php
    exit;
}

$content = load_content($contentFile, $defaultFile);
$saveMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_text') {
        $content['brandInitials'] = trim($_POST['brandInitials'] ?? $content['brandInitials']);
        $content['names']['first'] = trim($_POST['name_first'] ?? '');
        $content['names']['second'] = trim($_POST['name_second'] ?? '');
        $content['eventDate'] = trim($_POST['eventDate'] ?? '');
        $content['eventDay'] = trim($_POST['eventDay'] ?? '');
        $content['eventDateTime'] = trim($_POST['eventDateTime'] ?? '');
        $content['heroSubtitle'] = trim($_POST['heroSubtitle'] ?? '');

        $content['story']['eyebrow'] = trim($_POST['story_eyebrow'] ?? '');
        $content['story']['title'] = trim($_POST['story_title'] ?? '');
        $content['story']['artMark'] = trim($_POST['story_artMark'] ?? '');
        $paras = preg_split('/\r?\n/', trim($_POST['story_paragraphs'] ?? ''));
        $content['story']['paragraphs'] = array_values(array_filter(array_map('trim', $paras), fn($p) => $p !== ''));

        $content['ceremony']['eyebrow'] = trim($_POST['ceremony_eyebrow'] ?? '');
        $content['ceremony']['title'] = trim($_POST['ceremony_title'] ?? '');
        $content['ceremony']['intro'] = trim($_POST['ceremony_intro'] ?? '');
        $timeline = [];
        foreach (($_POST['timeline'] ?? []) as $row) {
            $time = trim($row['time'] ?? '');
            $title = trim($row['title'] ?? '');
            $desc = trim($row['desc'] ?? '');
            if ($time === '' && $title === '' && $desc === '') continue;
            $timeline[] = ['time' => $time, 'title' => $title, 'desc' => $desc];
        }
        $content['ceremony']['timeline'] = $timeline;

        $content['venue']['eyebrow'] = trim($_POST['venue_eyebrow'] ?? '');
        $content['venue']['title'] = trim($_POST['venue_title'] ?? '');
        $cards = [];
        foreach (($_POST['venue'] ?? []) as $row) {
            $name = trim($row['name'] ?? '');
            $role = trim($row['role'] ?? '');
            $a1 = trim($row['address1'] ?? '');
            $a2 = trim($row['address2'] ?? '');
            $map = trim($row['mapUrl'] ?? '');
            if ($name === '' && $role === '' && $a1 === '' && $a2 === '') continue;
            $cards[] = ['role' => $role, 'name' => $name, 'address1' => $a1, 'address2' => $a2, 'mapUrl' => $map ?: '#'];
        }
        $content['venue']['cards'] = $cards;

        $content['gallery']['eyebrow'] = trim($_POST['gallery_eyebrow'] ?? '');
        $content['gallery']['title'] = trim($_POST['gallery_title'] ?? '');
        $content['gallery']['intro'] = trim($_POST['gallery_intro'] ?? '');
        $deleteSrcs = $_POST['gallery_delete'] ?? [];
        $keptImages = [];
        foreach (($_POST['gallery'] ?? []) as $row) {
            $src = $row['src'] ?? '';
            $alt = trim($row['alt'] ?? '');
            if (in_array($src, $deleteSrcs, true)) {
                $realPath = realpath(__DIR__ . '/' . $src);
                if ($realPath && strpos($realPath, realpath($uploadsDir)) === 0 && is_file($realPath)) {
                    unlink($realPath);
                }
                continue;
            }
            $keptImages[] = ['src' => $src, 'alt' => $alt];
        }
        $content['gallery']['images'] = $keptImages;

        $content['rsvp']['eyebrow'] = trim($_POST['rsvp_eyebrow'] ?? '');
        $content['rsvp']['title'] = trim($_POST['rsvp_title'] ?? '');
        $content['rsvp']['deadlineText'] = trim($_POST['rsvp_deadlineText'] ?? '');

        $content['footer']['line'] = trim($_POST['footer_line'] ?? '');

        save_content($contentFile, $content);
        $saveMessage = 'Mentve!';
    }

    if ($action === 'upload_image' && !empty($_FILES['images']['name'][0])) {
        $allowedExt = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
        $count = count($_FILES['images']['name']);
        $uploaded = 0;
        for ($i = 0; $i < $count; $i++) {
            if ($_FILES['images']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $tmp = $_FILES['images']['tmp_name'][$i];
            $origName = $_FILES['images']['name'][$i];
            $size = $_FILES['images']['size'][$i];
            if ($size > 10 * 1024 * 1024) continue;

            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (!isset($allowedExt[$ext])) continue;

            $imgInfo = @getimagesize($tmp);
            if ($imgInfo === false) continue;
            $mimeOk = in_array($imgInfo['mime'], $allowedExt, true);
            if (!$mimeOk) continue;

            $safeName = 'img_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $destPath = $uploadsDir . $safeName;
            if (move_uploaded_file($tmp, $destPath)) {
                $altGuess = pathinfo($origName, PATHINFO_FILENAME);
                $content['gallery']['images'][] = ['src' => 'uploads/' . $safeName, 'alt' => $altGuess];
                $uploaded++;
            }
        }
        save_content($contentFile, $content);
        $saveMessage = $uploaded . ' kép feltöltve.';
    }

    if ($action === 'reset_content') {
        $content = json_decode(file_get_contents($defaultFile), true);
        save_content($contentFile, $content);
        $saveMessage = 'Visszaállítva az alapértelmezett tartalomra.';
    }
}

$token = csrf_token();
function v($val) { return htmlspecialchars($val ?? '', ENT_QUOTES, 'UTF-8'); }
?><!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Esküvő admin</title>
<style>
  :root{ --accent:#a9714f; --bg:#faf7f2; --card:#fff; --line:#e2d8c9; --ink:#2e2a26; --ink-soft:#6b6258; }
  *{box-sizing:border-box;}
  body{ margin:0; background:var(--bg); color:var(--ink); font-family:-apple-system,'Segoe UI',sans-serif; }
  header{ background:#fff; border-bottom:1px solid var(--line); padding:16px 24px; display:flex; justify-content:space-between; align-items:center; position:sticky; top:0; z-index:5; }
  header h1{ font-size:1.15rem; margin:0; }
  header a{ color:var(--ink-soft); text-decoration:none; font-size:0.85rem; }
  .wrap{ max-width:900px; margin:0 auto; padding:24px; }
  .card{ background:var(--card); border:1px solid var(--line); border-radius:10px; padding:24px; margin-bottom:24px; }
  .card h2{ font-size:1.05rem; margin:0 0 16px; }
  label{ display:block; font-size:0.78rem; color:var(--ink-soft); margin:14px 0 4px; }
  label:first-child{ margin-top:0; }
  input[type=text], input[type=datetime-local], textarea{
    width:100%; padding:9px 10px; border:1px solid var(--line); border-radius:6px; font-size:0.92rem; font-family:inherit;
  }
  textarea{ resize:vertical; min-height:70px; }
  .row2{ display:grid; grid-template-columns:1fr 1fr; gap:16px; }
  .repeat-item{ border:1px dashed var(--line); border-radius:8px; padding:14px; margin-bottom:12px; position:relative; }
  .repeat-item .rm{ position:absolute; top:8px; right:8px; background:#c0392b; color:#fff; border:none; border-radius:4px; width:24px; height:24px; cursor:pointer; font-size:0.8rem; }
  .add-btn{ background:none; border:1px dashed var(--accent); color:var(--accent); padding:9px 14px; border-radius:6px; cursor:pointer; font-size:0.85rem; }
  .save-btn{ background:var(--accent); color:#fff; border:none; padding:12px 22px; border-radius:6px; font-size:0.9rem; cursor:pointer; }
  .msg{ background:#e8f5e9; color:#2e7d32; padding:12px 16px; border-radius:8px; margin-bottom:20px; font-size:0.9rem; }
  .gallery-list{ display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:14px; }
  .gitem{ border:1px solid var(--line); border-radius:8px; padding:10px; }
  .gitem img{ width:100%; aspect-ratio:1; object-fit:cover; border-radius:6px; margin-bottom:8px; }
  .gitem label{ font-size:0.7rem; }
  .gitem .del{ display:flex; align-items:center; gap:6px; font-size:0.78rem; margin-top:8px; }
  .upload-box{ border:2px dashed var(--line); border-radius:8px; padding:20px; text-align:center; margin-bottom:16px; }
  .danger-link{ color:#c0392b; font-size:0.8rem; }
  .sticky-actions{ position:sticky; bottom:0; background:linear-gradient(transparent, var(--bg) 30%); padding:16px 0; }
</style>
</head>
<body>
<header>
  <h1>Esküvő admin — Böbe &amp; Sándor</h1>
  <a href="admin.php?logout=1">Kijelentkezés</a>
</header>
<div class="wrap">
  <?php if ($saveMessage): ?><div class="msg"><?= v($saveMessage) ?></div><?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= v($token) ?>">
    <input type="hidden" name="action" value="upload_image">
    <div class="card">
      <h2>Galéria — új képek feltöltése</h2>
      <div class="upload-box">
        <input type="file" name="images[]" accept="image/png,image/jpeg,image/webp,image/gif" multiple required>
      </div>
      <button type="submit" class="save-btn">Feltöltés</button>
    </div>
  </form>

  <form method="post" id="mainForm">
    <input type="hidden" name="csrf" value="<?= v($token) ?>">
    <input type="hidden" name="action" value="save_text">

    <div class="card">
      <h2>Alapadatok</h2>
      <div class="row2">
        <div>
          <label>Menyasszony neve</label>
          <input type="text" name="name_first" value="<?= v($content['names']['first']) ?>">
        </div>
        <div>
          <label>Vőlegény neve</label>
          <input type="text" name="name_second" value="<?= v($content['names']['second']) ?>">
        </div>
      </div>
      <label>Monogram (fejléc/lábléc)</label>
      <input type="text" name="brandInitials" value="<?= v($content['brandInitials']) ?>">
      <div class="row2">
        <div>
          <label>Dátum (megjelenített szöveg)</label>
          <input type="text" name="eventDate" value="<?= v($content['eventDate']) ?>">
        </div>
        <div>
          <label>Nap neve (pl. Péntek)</label>
          <input type="text" name="eventDay" value="<?= v($content['eventDay']) ?>">
        </div>
      </div>
      <label>Pontos időpont (a visszaszámlálóhoz)</label>
      <input type="datetime-local" name="eventDateTime" value="<?= v($content['eventDateTime']) ?>">
      <label>Bevezető szöveg a főoldalon</label>
      <textarea name="heroSubtitle"><?= v($content['heroSubtitle']) ?></textarea>
    </div>

    <div class="card">
      <h2>Történetünk</h2>
      <div class="row2">
        <div>
          <label>Felirat (eyebrow)</label>
          <input type="text" name="story_eyebrow" value="<?= v($content['story']['eyebrow']) ?>">
        </div>
        <div>
          <label>Cím</label>
          <input type="text" name="story_title" value="<?= v($content['story']['title']) ?>">
        </div>
      </div>
      <label>Kis jelzés a képen (pl. "2015 — ∞")</label>
      <input type="text" name="story_artMark" value="<?= v($content['story']['artMark']) ?>">
      <label>Szövegbekezdések (soronként külön bekezdés)</label>
      <textarea name="story_paragraphs" style="min-height:140px;"><?= v(implode("\n", $content['story']['paragraphs'])) ?></textarea>
    </div>

    <div class="card">
      <h2>Szertartás &amp; napirend</h2>
      <div class="row2">
        <div>
          <label>Felirat (eyebrow)</label>
          <input type="text" name="ceremony_eyebrow" value="<?= v($content['ceremony']['eyebrow']) ?>">
        </div>
        <div>
          <label>Cím</label>
          <input type="text" name="ceremony_title" value="<?= v($content['ceremony']['title']) ?>">
        </div>
      </div>
      <label>Bevezető szöveg</label>
      <textarea name="ceremony_intro"><?= v($content['ceremony']['intro']) ?></textarea>

      <div id="timelineList">
        <?php foreach ($content['ceremony']['timeline'] as $t): ?>
        <div class="repeat-item">
          <button type="button" class="rm" onclick="this.parentElement.remove()">✕</button>
          <label>Időpont</label>
          <input type="text" name="timeline[][time]" value="<?= v($t['time']) ?>" placeholder="pl. 16:00">
          <label>Esemény neve</label>
          <input type="text" name="timeline[][title]" value="<?= v($t['title']) ?>">
          <label>Leírás</label>
          <textarea name="timeline[][desc]"><?= v($t['desc']) ?></textarea>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="add-btn" onclick="addTimelineItem()">+ Új napirendi pont</button>
    </div>

    <div class="card">
      <h2>Helyszín</h2>
      <div class="row2">
        <div>
          <label>Felirat (eyebrow)</label>
          <input type="text" name="venue_eyebrow" value="<?= v($content['venue']['eyebrow']) ?>">
        </div>
        <div>
          <label>Cím</label>
          <input type="text" name="venue_title" value="<?= v($content['venue']['title']) ?>">
        </div>
      </div>
      <div id="venueList">
        <?php foreach ($content['venue']['cards'] as $c): ?>
        <div class="repeat-item">
          <button type="button" class="rm" onclick="this.parentElement.remove()">✕</button>
          <label>Szerepkör (pl. "Szertartás")</label>
          <input type="text" name="venue[][role]" value="<?= v($c['role']) ?>">
          <label>Helyszín neve</label>
          <input type="text" name="venue[][name]" value="<?= v($c['name']) ?>">
          <label>Cím</label>
          <input type="text" name="venue[][address1]" value="<?= v($c['address1']) ?>">
          <label>Kiegészítő infó</label>
          <input type="text" name="venue[][address2]" value="<?= v($c['address2']) ?>">
          <label>Térkép link (URL)</label>
          <input type="text" name="venue[][mapUrl]" value="<?= v($c['mapUrl']) ?>">
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="add-btn" onclick="addVenueItem()">+ Új helyszín</button>
    </div>

    <div class="card">
      <h2>Galéria — szövegek és meglévő képek</h2>
      <div class="row2">
        <div>
          <label>Felirat (eyebrow)</label>
          <input type="text" name="gallery_eyebrow" value="<?= v($content['gallery']['eyebrow']) ?>">
        </div>
        <div>
          <label>Cím</label>
          <input type="text" name="gallery_title" value="<?= v($content['gallery']['title']) ?>">
        </div>
      </div>
      <label>Bevezető szöveg</label>
      <textarea name="gallery_intro"><?= v($content['gallery']['intro']) ?></textarea>

      <label>Feltöltött képek</label>
      <div class="gallery-list">
        <?php foreach ($content['gallery']['images'] as $img): ?>
        <div class="gitem">
          <img src="<?= v($img['src']) ?>" alt="">
          <input type="hidden" name="gallery[][src]" value="<?= v($img['src']) ?>">
          <label>Alt szöveg</label>
          <input type="text" name="gallery[][alt]" value="<?= v($img['alt']) ?>">
          <div class="del">
            <input type="checkbox" name="gallery_delete[]" value="<?= v($img['src']) ?>" id="del_<?= md5($img['src']) ?>">
            <label for="del_<?= md5($img['src']) ?>" style="margin:0;">Törlés</label>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($content['gallery']['images'])): ?>
          <p style="color:var(--ink-soft);font-size:0.85rem;">Még nincs feltöltött kép — a főoldalon egyelőre díszítő ikonok jelennek meg helyettük.</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <h2>Részvétel visszaigazolása (RSVP)</h2>
      <div class="row2">
        <div>
          <label>Felirat (eyebrow)</label>
          <input type="text" name="rsvp_eyebrow" value="<?= v($content['rsvp']['eyebrow']) ?>">
        </div>
        <div>
          <label>Cím</label>
          <input type="text" name="rsvp_title" value="<?= v($content['rsvp']['title']) ?>">
        </div>
      </div>
      <label>Határidő szövege</label>
      <textarea name="rsvp_deadlineText"><?= v($content['rsvp']['deadlineText']) ?></textarea>
    </div>

    <div class="card">
      <h2>Lábléc</h2>
      <label>Lábléc szövege</label>
      <input type="text" name="footer_line" value="<?= v($content['footer']['line']) ?>">
    </div>

    <div class="sticky-actions">
      <button type="submit" class="save-btn">Összes szöveg mentése</button>
    </div>
  </form>

  <p style="margin-top:32px;">
    <a class="danger-link" href="#" onclick="if(confirm('Biztosan visszaállítod az összes szöveget és galériát az alapértelmezettre? A feltöltött képek fájljai megmaradnak, de a lista törlődik.')){document.getElementById('resetForm').submit();} return false;">Alapértelmezett tartalom visszaállítása</a>
  </p>
  <form method="post" id="resetForm" style="display:none;">
    <input type="hidden" name="csrf" value="<?= v($token) ?>">
    <input type="hidden" name="action" value="reset_content">
  </form>
</div>

<script>
function addTimelineItem(){
  var wrap = document.createElement('div');
  wrap.className = 'repeat-item';
  wrap.innerHTML = '<button type="button" class="rm" onclick="this.parentElement.remove()">✕</button>' +
    '<label>Időpont</label><input type="text" name="timeline[][time]" placeholder="pl. 16:00">' +
    '<label>Esemény neve</label><input type="text" name="timeline[][title]">' +
    '<label>Leírás</label><textarea name="timeline[][desc]"></textarea>';
  document.getElementById('timelineList').appendChild(wrap);
}
function addVenueItem(){
  var wrap = document.createElement('div');
  wrap.className = 'repeat-item';
  wrap.innerHTML = '<button type="button" class="rm" onclick="this.parentElement.remove()">✕</button>' +
    '<label>Szerepkör</label><input type="text" name="venue[][role]">' +
    '<label>Helyszín neve</label><input type="text" name="venue[][name]">' +
    '<label>Cím</label><input type="text" name="venue[][address1]">' +
    '<label>Kiegészítő infó</label><input type="text" name="venue[][address2]">' +
    '<label>Térkép link (URL)</label><input type="text" name="venue[][mapUrl]">';
  document.getElementById('venueList').appendChild(wrap);
}
</script>
</body>
</html>
