<?php
/**
 * La Paste Bazzar Management – pembaruan: OTP juga untuk masuk Konsol Platform (pemilik layanan)
 * Dijalankan sekali dari browser: /_wa-update/potp-update.php?k=KODE
 * - Memperbarui otp.php dan assets/otp.js, menambal api.php, platform.html, app.html
 * - Cadangan di data/backup-potp/; gagal aman; &undo=1 mengembalikan cadangan terakhir
 */
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

const KEY_SHA256 = 'fd6ce3ff26e648907b43f893f1bf345b62595c1d34eeecd8c33c04ca3483f2b7';
if (!hash_equals(KEY_SHA256, hash('sha256', (string)($_GET['k'] ?? '')))) { http_response_code(404); exit("Not found\n"); }

$root = dirname(__DIR__);
$src = __DIR__ . '/files';
$bak = $root . '/data/backup-potp';
$all = ['api.php', 'platform.html', 'app.html', 'otp.php', 'assets/otp.js'];
function say(string $s): void { echo $s, "\n"; @flush(); }
function done(int $code, string $msg): never { say($msg); exit($code ? 1 : 0); }
$bn = fn(string $f) => str_replace(['/', '.'], '_', $f) . '.bak';

foreach ($all as $f) if (!is_file("$root/$f")) done(1, "GAGAL: $f tidak ditemukan. Jalankan otp-update.php terlebih dahulu.");
foreach (['otp.php', 'assets/otp.js'] as $f) if (!is_file("$src/$f")) done(1, "GAGAL: berkas pembaruan files/$f tidak ada.");

/* ---------- Kembalikan cadangan ---------- */
if (!empty($_GET['undo'])) {
    $dirs = glob("$bak/*", GLOB_ONLYDIR) ?: []; rsort($dirs);
    if (!$dirs) done(1, 'Tidak ada cadangan.');
    foreach ($all as $f) if (is_file("{$dirs[0]}/" . $bn($f))) { copy("{$dirs[0]}/" . $bn($f), "$root/$f"); say("Dikembalikan: $f dari " . basename($dirs[0])); }
    if (function_exists('opcache_reset')) @opcache_reset();
    done(0, 'Selesai. Versi sebelumnya (OTP hanya untuk aplikasi bazzar) aktif kembali.');
}

$c = [];
foreach (['api.php', 'platform.html', 'app.html'] as $f) $c[$f] = (string)file_get_contents("$root/$f");
if (!str_contains($c['api.php'], "require __DIR__ . '/otp.php';")) done(1, 'GAGAL: pembaruan OTP aplikasi bazzar belum diterapkan. Jalankan otp-update.php terlebih dahulu.');
if (str_contains($c['api.php'], 'potp_begin(')) done(0, 'Pembaruan OTP Konsol Platform sudah pernah diterapkan. Tidak ada yang diubah.');

$errors = [];
function rep1(string $s, string $old, string $new, string $label, array &$errors): string {
    $n = substr_count($s, $old);
    if ($n !== 1) { $errors[] = "$label: penanda ditemukan $n kali (harus 1)"; return $s; }
    return str_replace($old, $new, $s);
}

/* ================= api.php ================= */
$a = $c['api.php'];
$a = rep1($a, "        db()->prepare('UPDATE lp_admins SET failed = 0, locked_until = 0, last_login = ? WHERE id = ?')->execute([time(), \$ad['id']]);\n",
    "        db()->prepare('UPDATE lp_admins SET failed = 0, locked_until = 0, last_login = ? WHERE id = ?')->execute([time(), \$ad['id']]);\n        if ((\$otp = potp_begin(\$ad)) !== null) out(\$otp);   // verifikasi 2 langkah pemilik platform\n",
    'api: p_login', $errors);
$a = rep1($a, "    case 'p_logout': {",
    "    case 'p_otp_send': { need_post(); out(potp_resend((string)(body()['channel'] ?? ''))); }\n    case 'p_otp_verify': { need_post(); out(potp_verify((string)(body()['code'] ?? ''))); }\n    case 'p_logout': {",
    'api: endpoint', $errors);
$a = rep1($a, "\$p['otp'] = ['enabled' => !empty(\$n['otp']['enabled']), 'scope' => (\$n['otp']['scope'] ?? '') === 'staff' ? 'staff' : 'all'];",
    "\$p['otp'] = ['enabled' => !empty(\$n['otp']['enabled']), 'scope' => (\$n['otp']['scope'] ?? '') === 'staff' ? 'staff' : 'all', 'platform' => !empty(\$n['otp']['platform']), 'adminWa' => norm_wa((string)(\$n['otp']['adminWa'] ?? ''))];",
    'api: p_settings', $errors);
$c['api.php'] = $a;

/* ================= platform.html ================= */
$p = $c['platform.html'];
$p = rep1($p, 'Konsol Platform ini tidak memakai OTP, jadi Anda selalu bisa menonaktifkannya dari sini.',
    'Untuk Konsol Platform, kode dikirim ke nomor WhatsApp di bawah dan ke email akun Anda. Bila keduanya tidak bisa dikirimi, Konsol Platform tetap bisa dimasuki dengan kata sandi supaya Anda tidak pernah terkunci.',
    'platform: teks', $errors);
$p = rep1($p, 'Hanya pengelola bazzar (tim internal)</option></select></label></div>',
    'Hanya pengelola bazzar (tim internal)</option></select></label>' . "\n" .
    '      <label class="check"><input type="checkbox" name="otpPf" ${(P.otp||{}).platform!==false?\'checked\':\'\'}> Wajibkan OTP saat masuk Konsol Platform (pemilik layanan)</label>' . "\n" .
    '      <label class="field"><span>Nomor WhatsApp OTP pemilik platform</span><input name="otpWa" value="${esc((P.otp||{}).adminWa||\'\')}" placeholder="${esc(P.supportWa||\'08…\')}" inputmode="tel" autocomplete="off"><small class="muted">Kosongkan untuk memakai WhatsApp dukungan (${esc(P.supportWa||\'belum diisi\')}). Kode juga bisa dikirim ke email akun Konsol Platform.</small></label></div>',
    'platform: kartu OTP', $errors);
$p = rep1($p, "otp:{enabled:fd.get('otpOn')==='on',scope:fd.get('otpScope')||'all'}",
    "otp:{enabled:fd.get('otpOn')==='on',scope:fd.get('otpScope')||'all',platform:fd.get('otpPf')==='on',adminWa:fd.get('otpWa')||''}",
    'platform: simpan', $errors);
$v = substr(md5_file("$src/assets/otp.js"), 0, 8);
$p = rep1($p, '</body>', "<script src=\"assets/otp.js?v=$v\"></script>\n</body>", 'platform: script OTP', $errors);
$c['platform.html'] = $p;

/* ================= app.html: versi skrip baru ================= */
$n = 0;
$c['app.html'] = preg_replace('~assets/otp\.js\?v=[0-9a-f]{8}~', "assets/otp.js?v=$v", $c['app.html'], -1, $n);
if ($n !== 1) $errors[] = "app: skrip OTP ditemukan $n kali (harus 1)";

/* ---------- Validasi ---------- */
foreach (['api.php' => $c['api.php'], 'otp.php' => (string)file_get_contents("$src/otp.php")] as $f => $code) {
    try { token_get_all($code, TOKEN_PARSE); } catch (Throwable $e) { $errors[] = "$f tidak valid: " . $e->getMessage(); }
}
if ($errors) { say('GAGAL – tidak ada berkas yang diubah:'); foreach ($errors as $e) say('  - ' . $e); exit(1); }

/* ---------- Cadangan & tulis ---------- */
$dir = $bak . '/' . date('Ymd-His');
if (!is_dir($dir) && !mkdir($dir, 0750, true)) done(1, 'GAGAL: tidak bisa membuat folder cadangan.');
foreach ($all as $f) if (!copy("$root/$f", "$dir/" . $bn($f))) done(1, "GAGAL mencadangkan $f. Tidak ada berkas yang diubah.");
function put(string $path, string $data): bool { $tmp = dirname($path) . '/.' . basename($path) . '.tmp-' . bin2hex(random_bytes(4)); if (file_put_contents($tmp, $data, LOCK_EX) !== strlen($data)) { @unlink($tmp); return false; } return rename($tmp, $path); }
$out = ['otp.php' => (string)file_get_contents("$src/otp.php"), 'assets/otp.js' => (string)file_get_contents("$src/assets/otp.js"), 'platform.html' => $c['platform.html'], 'app.html' => $c['app.html'], 'api.php' => $c['api.php']];
foreach ($out as $f => $data) { if (!put("$root/$f", $data)) done(1, "GAGAL menulis $f. Jalankan &undo=1 untuk mengembalikan cadangan."); say("Diperbarui: $f"); }
if (function_exists('opcache_reset')) @opcache_reset();
say('Cadangan: data/backup-potp/' . basename($dir));
say('');
say('BERHASIL. OTP kini juga berlaku untuk masuk Konsol Platform.');
say('Atur nomor WhatsApp OTP di Konsol Platform -> Pengaturan -> Keamanan masuk (OTP).');
