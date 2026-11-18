<?php
/**
 * La Paste Bazzar Management – pembaruan: OTP masuk via WhatsApp / email + animasi kubus Rubik
 * Dijalankan sekali dari browser: /_wa-update/otp-update.php?k=KODE
 * - Menyalin otp.php dan assets/otp.js, menambal api.php, .htaccess, platform.html, app.html
 * - Cadangan di data/backup-otp/; gagal aman (tidak ada yang diubah bila satu penanda tidak cocok)
 * - &undo=1 mengembalikan cadangan terakhir
 */
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

const KEY_SHA256 = '99f65fd280f534cf55aa981f2d98ed09c0ae8208f7010cdf82f42c09e79c82db';
if (!hash_equals(KEY_SHA256, hash('sha256', (string)($_GET['k'] ?? '')))) { http_response_code(404); exit("Not found\n"); }

$root = dirname(__DIR__);
$src = __DIR__ . '/files';
$bak = $root . '/data/backup-otp';
$patch = ['api.php', '.htaccess', 'platform.html', 'app.html'];
$copy = ['otp.php' => 'otp.php', 'assets/otp.js' => 'assets/otp.js'];
function say(string $s): void { echo $s, "\n"; @flush(); }
function done(int $code, string $msg): never { say($msg); exit($code ? 1 : 0); }

foreach (array_merge($patch, ['data']) as $f) if (!file_exists("$root/$f")) done(1, "GAGAL: $f tidak ditemukan di folder induk.");
foreach ($copy as $s => $d) if (!is_file("$src/$s")) done(1, "GAGAL: berkas pembaruan files/$s tidak ada.");
if (!is_file("$root/notif.php") || !str_contains((string)file_get_contents("$root/notif.php"), 'WA_GW_URL')) done(1, 'GAGAL: pembaruan WhatsApp (BEM WA Gateway) belum diterapkan. Jalankan update.php terlebih dahulu.');

/* ---------- Kembalikan cadangan ---------- */
if (!empty($_GET['undo'])) {
    $dirs = glob("$bak/*", GLOB_ONLYDIR) ?: []; rsort($dirs);
    if (!$dirs) done(1, 'Tidak ada cadangan.');
    foreach ($patch as $f) if (is_file("{$dirs[0]}/" . str_replace('.', '_', $f) . '.bak')) { copy("{$dirs[0]}/" . str_replace('.', '_', $f) . '.bak', "$root/$f"); say("Dikembalikan: $f dari " . basename($dirs[0])); }
    foreach ($copy as $d) if (is_file("$root/$d") && !is_file("{$dirs[0]}/new_" . basename($d) . '.keep')) { @unlink("$root/$d"); say("Dihapus: $d"); }
    if (function_exists('opcache_reset')) @opcache_reset();
    done(0, 'Selesai. Versi sebelum OTP aktif kembali.');
}

$c = [];
foreach ($patch as $f) $c[$f] = (string)file_get_contents("$root/$f");
if (str_contains($c['api.php'], "require __DIR__ . '/otp.php';")) done(0, 'Pembaruan OTP sudah pernah diterapkan. Tidak ada yang diubah.');

$errors = [];
function rep1(string $s, string $old, string $new, string $label, array &$errors): string {
    $n = substr_count($s, $old);
    if ($n !== 1) { $errors[] = "$label: penanda ditemukan $n kali (harus 1)"; return $s; }
    return str_replace($old, $new, $s);
}

/* ================= api.php ================= */
$a = $c['api.php'];
$a = rep1($a, "require __DIR__ . '/assist.php';\n", "require __DIR__ . '/assist.php';\nrequire __DIR__ . '/otp.php';\n", 'api: require', $errors);
$a = rep1($a,
    "        session_regenerate_id(true);\n        \$_SESSION = ['uid' => [\$acc['kind'], \$acc['ref_id']], 'ws' => \$W['id'],",
    "        if ((\$otp = otp_begin(\$W, \$S, \$acc, \$rec)) !== null) out(\$otp);   // verifikasi 2 langkah: kode via WhatsApp / email\n        session_regenerate_id(true);\n        \$_SESSION = ['uid' => [\$acc['kind'], \$acc['ref_id']], 'ws' => \$W['id'],",
    'api: login', $errors);
$a = rep1($a, "    case 'logout': {\n",
    "    case 'otp_send': { need_post(); out(otp_resend(\$W, (string)(body()['channel'] ?? ''))); }\n    case 'otp_verify': { need_post(); out(otp_verify(\$W, (string)(body()['code'] ?? ''))); }\n\n    case 'logout': {\n",
    'api: endpoint', $errors);
$a = rep1($a, "        if (array_key_exists('requireVerify', \$n)) \$p['requireVerify'] = !empty(\$n['requireVerify']);\n",
    "        if (array_key_exists('requireVerify', \$n)) \$p['requireVerify'] = !empty(\$n['requireVerify']);\n        if (isset(\$n['otp']) && is_array(\$n['otp'])) \$p['otp'] = ['enabled' => !empty(\$n['otp']['enabled']), 'scope' => (\$n['otp']['scope'] ?? '') === 'staff' ? 'staff' : 'all'];\n",
    'api: p_settings', $errors);
$a = rep1($a, "\$p['requireVerify'] = require_verify();\n", "\$p['requireVerify'] = require_verify(); \$p['otp'] = otp_conf();\n", 'api: p_overview', $errors);
$c['api.php'] = $a;

/* ================= .htaccess ================= */
$c['.htaccess'] = rep1($c['.htaccess'], '|assist)\.php$', '|assist|otp)\.php$', 'htaccess: blokir otp.php', $errors);

/* ================= platform.html ================= */
$card = <<<'HTML'
<div class="card"><h2>Keamanan masuk (OTP)</h2><p class="small muted">Setelah kata sandi benar, pengguna memasukkan kode 6 digit yang dikirim ke WhatsApp (lewat WhatsApp platform di atas, template resmi <b>kode_otp</b>) dengan email sebagai alternatif. Kode berlaku 5 menit dan maksimal 5 kali salah. Akun tanpa WhatsApp maupun email yang valid tetap masuk dengan kata sandi saja. Konsol Platform ini tidak memakai OTP, jadi Anda selalu bisa menonaktifkannya dari sini.</p>
      <label class="check"><input type="checkbox" name="otpOn" ${(P.otp||{enabled:true}).enabled?'checked':''}> Wajibkan OTP saat masuk aplikasi bazzar</label>
      <label class="field"><span>Berlaku untuk</span><select name="otpScope"><option value="all" ${(P.otp||{}).scope!=='staff'?'selected':''}>Semua pengguna (pengelola &amp; mitra)</option><option value="staff" ${(P.otp||{}).scope==='staff'?'selected':''}>Hanya pengelola bazzar (tim internal)</option></select></label></div>
      <div class="card"><h2>Verifikasi akun</h2>
HTML;
$c['platform.html'] = rep1($c['platform.html'], '<div class="card"><h2>Verifikasi akun</h2>', $card, 'platform: kartu OTP', $errors);
$c['platform.html'] = rep1($c['platform.html'], "requireVerify:fd.get('requireVerify')==='on'}", "requireVerify:fd.get('requireVerify')==='on',otp:{enabled:fd.get('otpOn')==='on',scope:fd.get('otpScope')||'all'}}", 'platform: simpan OTP', $errors);

/* ================= app.html ================= */
$v = substr(md5_file("$src/assets/otp.js"), 0, 8);
$c['app.html'] = rep1($c['app.html'], '</body>', "<script src=\"assets/otp.js?v=$v\"></script>\n</body>", 'app: script OTP', $errors);

/* ---------- Validasi ---------- */
foreach (['api.php' => $c['api.php'], 'otp.php' => (string)file_get_contents("$src/otp.php")] as $f => $code) {
    try { token_get_all($code, TOKEN_PARSE); } catch (Throwable $e) { $errors[] = "$f tidak valid: " . $e->getMessage(); }
}
if ($errors) { say('GAGAL – tidak ada berkas yang diubah:'); foreach ($errors as $e) say('  - ' . $e); exit(1); }

/* ---------- Cadangan & tulis ---------- */
$dir = $bak . '/' . date('Ymd-His');
if (!is_dir($dir) && !mkdir($dir, 0750, true)) done(1, 'GAGAL: tidak bisa membuat folder cadangan.');
foreach ($patch as $f) if (!copy("$root/$f", "$dir/" . str_replace('.', '_', $f) . '.bak')) done(1, "GAGAL mencadangkan $f. Tidak ada berkas yang diubah.");
foreach ($copy as $d) if (is_file("$root/$d")) { copy("$root/$d", "$dir/old_" . basename($d) . '.bak'); file_put_contents("$dir/new_" . basename($d) . '.keep', '1'); }
function put(string $path, string $data): bool { $tmp = dirname($path) . '/.' . basename($path) . '.tmp-' . bin2hex(random_bytes(4)); if (file_put_contents($tmp, $data, LOCK_EX) !== strlen($data)) { @unlink($tmp); return false; } return rename($tmp, $path); }
foreach ($copy as $s => $d) { if (!put("$root/$d", (string)file_get_contents("$src/$s"))) done(1, "GAGAL menyalin $d. Jalankan &undo=1."); say("Ditambahkan: $d"); }
foreach (['.htaccess', 'platform.html', 'app.html', 'api.php'] as $f) { if (!put("$root/$f", $c[$f])) done(1, "GAGAL menulis $f. Jalankan &undo=1 untuk mengembalikan cadangan."); say("Diperbarui: $f"); }
if (function_exists('opcache_reset')) @opcache_reset();
say('Cadangan: data/backup-otp/' . basename($dir));
say('');
say('BERHASIL. OTP masuk aktif untuk semua pengguna aplikasi bazzar.');
say('Atur di Konsol Platform -> Pengaturan -> Keamanan masuk (OTP).');
