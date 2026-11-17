<?php
/**
 * La Paste Bazzar Management – pembaruan WhatsApp: Fonnte -> BEM WA Gateway (wa.baliekamediatama.com)
 * Dijalankan sekali dari browser: /_wa-update/update.php?k=KODE
 * - Menambal notif.php dan platform.html di folder induk (dengan cadangan di data/backup-wa/)
 * - Gagal aman: bila satu penanda tidak cocok atau hasil PHP tidak valid, tidak ada berkas yang diubah
 * - &undo=1 mengembalikan cadangan terakhir
 */
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

const KEY_SHA256 = 'f3948b3bd21715457b9b25d5ecd77fc7b333049aeb4b3e1d0a5a9f3723b90060';
if (!hash_equals(KEY_SHA256, hash('sha256', (string)($_GET['k'] ?? '')))) { http_response_code(404); exit("Not found\n"); }

$root = dirname(__DIR__);
$bak = $root . '/data/backup-wa';
$files = ['notif.php', 'platform.html'];
function say(string $s): void { echo $s, "\n"; @flush(); }
function done(int $code, string $msg): never { say($msg); exit($code ? 1 : 0); }

if (!is_file("$root/notif.php") || !is_file("$root/platform.html") || !is_dir("$root/data")) done(1, 'GAGAL: folder induk bukan instalasi La Paste Bazzar (notif.php/platform.html/data tidak ditemukan).');

/* ---------- Kembalikan cadangan ---------- */
if (!empty($_GET['undo'])) {
    $dirs = glob("$bak/*", GLOB_ONLYDIR) ?: []; rsort($dirs);
    if (!$dirs) done(1, 'Tidak ada cadangan.');
    foreach ($files as $f) if (is_file("{$dirs[0]}/$f")) { copy("{$dirs[0]}/$f", "$root/$f"); say("Dikembalikan: $f dari " . basename($dirs[0])); }
    if (function_exists('opcache_reset')) @opcache_reset();
    @unlink("$bak/applied.lock");
    done(0, 'Selesai. Versi sebelumnya aktif kembali.');
}

$notif = (string)file_get_contents("$root/notif.php");
$pf = (string)file_get_contents("$root/platform.html");
if (str_contains($notif, 'WA_GW_URL') && str_contains($pf, 'name="waProv"')) {
    foreach (['cek.php', 'cek2.php'] as $c) if (is_file("$root/$c") && @unlink("$root/$c")) say("Dihapus: $c");
    done(0, 'Pembaruan sudah pernah diterapkan. Tidak ada yang diubah.');
}

$errors = [];
/** Ganti tepat satu kemunculan teks; catat bila tidak ditemukan. */
function rep1(string $s, string $old, string $new, string $label, array &$errors): string {
    $n = substr_count($s, $old);
    if ($n !== 1) { $errors[] = "$label: penanda ditemukan $n kali (harus 1)"; return $s; }
    return str_replace($old, $new, $s);
}

/* ================= notif.php ================= */
$notif = rep1($notif, " * Notifikasi tagihan sewa ke mitra: email (SMTP/mail) & WhatsApp (Fonnte).",
    " * Notifikasi tagihan sewa ke mitra: email (SMTP/mail) & WhatsApp (BEM WA Gateway wa.baliekamediatama.com, atau Fonnte).",
    'notif: komentar', $errors);

$notif = rep1($notif,
    "function wa_platform(): array { return array_replace(['token' => '', 'share' => true, 'quota' => 0], platform()['wa'] ?? []); }",
    <<<'PHP'
/* BEM WA Gateway: gateway WhatsApp Cloud API resmi PT Bali Eka Mediatama. Token yang tersimpan berbentuk "bem:<API key aplikasi>". */
const WA_GW_URL = 'https://wa.baliekamediatama.com';
function wa_platform(): array {
    $w = array_replace(['token' => '', 'share' => true, 'quota' => 0, 'sender' => ''], platform()['wa'] ?? []);
    $t = (string)$w['token'];
    $w['provider'] = str_starts_with($t, 'bem:') ? 'bem' : ($t !== '' ? 'fonnte' : '');
    $w['gwUrl'] = WA_GW_URL;
    return $w;
}
/** Kirim teks lewat BEM WA Gateway (/api/notify). Gateway memilih template resmi: kode OTP -> kode_otp, lainnya -> notifikasi_layanan. */
function wa_gw_send(string $key, string $to, string $msg): array {
    $key = trim($key);
    if ($key === '') return [false, 'API key BEM WA Gateway belum diisi'];
    $ch = curl_init(WA_GW_URL . '/api/notify');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $key, 'X-API-Key: ' . $key],
        CURLOPT_POSTFIELDS => json_encode(['to' => norm_wa($to), 'message' => $msg], JSON_UNESCAPED_UNICODE)]);
    $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
    if ($res === false) return [false, 'BEM WA Gateway tidak dapat dihubungi: ' . $err];
    $j = json_decode((string)$res, true);
    if ($code === 401) return [false, 'API key BEM WA Gateway ditolak. Periksa key aplikasi lapasteflow di Konsol Platform.'];
    if (is_array($j)) {
        $ok = $code >= 200 && $code < 300 && ($j['ok'] ?? $j['status'] ?? true) !== false;
        return [$ok, $ok ? '' : 'BEM WA Gateway: ' . mb_substr((string)($j['error'] ?? $j['reason'] ?? ('HTTP ' . $code)), 0, 250)];
    }
    return [false, 'BEM WA Gateway: respons tidak dikenal (HTTP ' . $code . ')'];
}
PHP,
    'notif: wa_platform', $errors);

$notif = rep1($notif,
    "function send_wa(string \$token, string \$to, string \$msg): array {\n    if (\$token === '') return [false, 'Token WhatsApp (Fonnte) belum diatur'];",
    "function send_wa(string \$token, string \$to, string \$msg): array {\n    if (\$token === '') return [false, 'WhatsApp belum diatur (BEM WA Gateway / Fonnte)'];\n    if (str_starts_with(\$token, 'bem:')) return wa_gw_send(substr(\$token, 4), \$to, \$msg);",
    'notif: send_wa', $errors);

/* ================= platform.html ================= */
$newCard = <<<'HTML'
<div class="card wa-cfg"><style>.wa-cfg:has([name=waProv][value=bem]:checked) .wa-f,.wa-cfg:has([name=waProv][value=fonnte]:checked) .wa-b,.wa-cfg:has([name=waProv][value=off]:checked) .wa-f,.wa-cfg:has([name=waProv][value=off]:checked) .wa-b{display:none}.wa-opt{display:flex;gap:10px;flex-wrap:wrap;margin:10px 0 12px}.wa-opt>label{flex:1 1 200px;display:flex;gap:10px;align-items:flex-start;border:1px solid var(--line);border-radius:12px;padding:10px 12px;cursor:pointer}.wa-opt>label:has(input:checked){border-color:var(--accent);box-shadow:0 0 0 2px color-mix(in srgb,var(--accent) 25%,transparent)}.wa-opt input{margin-top:3px}.wa-opt small{display:block;color:var(--muted);font-weight:400;line-height:1.4;margin-top:2px}</style>
      <div class="spread"><h2>WhatsApp platform (API)</h2>${P.wa.provider==='bem'?'<span class="pill ok">Aktif: BEM WA Gateway</span>':P.wa.provider==='fonnte'?'<span class="pill warn">Aktif: Fonnte</span>':'<span class="pill">Belum aktif</span>'}</div>
      <p class="small muted">Satu jalur WhatsApp untuk seluruh platform: siaran ke pengelola bazzar, tautan aktivasi &amp; lupa sandi, serta (bila dibagikan) tagihan, pengingat, kuitansi, kontrak, dan dokumen PDF dari setiap bazzar. Bazzar yang mengisi token sendiri tetap memakai nomornya sendiri.</p>
      <div class="wa-opt" role="radiogroup" aria-label="Penyedia WhatsApp"><label><input type="radio" name="waProv" value="bem" ${P.wa.provider!=='fonnte'?'checked':''}><span><b>BEM WA Gateway</b> <span class="pill ok">Disarankan</span><small>wa.baliekamediatama.com: WhatsApp Business resmi PT Bali Eka Mediatama, cukup API key aplikasi</small></span></label><label><input type="radio" name="waProv" value="fonnte" ${P.wa.provider==='fonnte'?'checked':''}><span><b>Fonnte</b><small>Cadangan: token perangkat dari fonnte.com</small></span></label><label><input type="radio" name="waProv" value="off"><span><b>Nonaktif</b><small>WhatsApp platform tidak dipakai</small></span></label></div>
      <div class="wa-b"><div class="two"><label class="field"><span>Alamat gateway</span><input value="${esc(P.wa.gwUrl||'https://wa.baliekamediatama.com')}" readonly></label><label class="field"><span>API key aplikasi lapasteflow</span><input name="waKey" value="${P.wa.provider==='bem'?esc(P.wa.token||''):''}" autocomplete="off" spellcheck="false" placeholder="API key dari kredensial-wa-gateway.md"></label></div><small class="muted">Pesan dikirim lewat template resmi <b>notifikasi_layanan</b>, jadi tetap sampai walau penerima belum pernah chat. API key disimpan di server dan hanya ditampilkan 4 karakter terakhirnya.</small></div>
      <div class="wa-f"><label class="field"><span>Token perangkat Fonnte</span><input name="waToken" value="${P.wa.provider==='fonnte'?esc(P.wa.token||''):''}" autocomplete="off" placeholder="Token dari fonnte.com, menu Device"></label></div>
      <div class="two" style="margin-top:10px"><label class="field"><span>Nama pengirim (opsional)</span><input name="waSender" value="${esc(P.wa.sender||'')}" placeholder="La Paste Bazzar"></label></div>
HTML;
$cnt = 0;
$pf = preg_replace_callback('~<div class="card"><h2>WhatsApp platform \(API\)</h2>.*?placeholder="La Paste Bazzar"></label></div>~s', fn() => $newCard, $pf, -1, $cnt);
if ($cnt !== 1) $errors[] = "platform: kartu WhatsApp ditemukan $cnt kali (harus 1)";

$pf = rep1($pf, "wa:{token:fd.get('waToken'),", "wa:{token:waTok(fd),", 'platform: simpan', $errors);

$waTok = <<<'JS'
const waTok=fd=>{const p=fd.get('waProv')||'bem',cur=(D&&D.platform&&D.platform.wa)||{},M='••••';if(p==='off')return '';
  if(p==='bem'){const k=String(fd.get('waKey')||'').trim().replace(/^bem:/,'');if(!k)throw new Error('Isi API key aplikasi lapasteflow dari BEM WA Gateway.');if(k.startsWith(M)){if(cur.provider!=='bem')throw new Error('Tempel API key BEM WA Gateway terlebih dahulu.');return k}return 'bem:'+k}
  const t=String(fd.get('waToken')||'').trim();if(!t)throw new Error('Isi token Fonnte.');if(t.startsWith(M)&&cur.provider!=='fonnte')throw new Error('Isi token Fonnte terlebih dahulu.');return t};
JS;
$pf = rep1($pf, "\nconst wa62=", "\n" . $waTok . "\nconst wa62=", 'platform: waTok', $errors);

/* ---------- Validasi ---------- */
try { token_get_all($notif, TOKEN_PARSE); } catch (Throwable $e) { $errors[] = 'notif.php hasil tambalan tidak valid: ' . $e->getMessage(); }
if ($errors) { say('GAGAL – tidak ada berkas yang diubah:'); foreach ($errors as $e) say('  - ' . $e); exit(1); }

/* ---------- Cadangan & tulis ---------- */
$dir = $bak . '/' . date('Ymd-His');
if (!is_dir($dir) && !mkdir($dir, 0750, true)) done(1, 'GAGAL: tidak bisa membuat folder cadangan data/backup-wa.');
foreach ($files as $f) if (!copy("$root/$f", "$dir/$f")) done(1, "GAGAL: tidak bisa mencadangkan $f. Tidak ada berkas yang diubah.");
foreach (['notif.php' => $notif, 'platform.html' => $pf] as $f => $c) {
    $tmp = "$root/.$f.tmp-" . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $c, LOCK_EX) !== strlen($c) || !rename($tmp, "$root/$f")) { @unlink($tmp); done(1, "GAGAL menulis $f. Jalankan lagi dengan &undo=1 untuk mengembalikan cadangan."); }
    say("Diperbarui: $f");
}
if (function_exists('opcache_invalidate')) @opcache_invalidate("$root/notif.php", true);
file_put_contents("$bak/applied.lock", date('c'));
foreach (['cek.php', 'cek2.php'] as $c) if (is_file("$root/$c") && @unlink("$root/$c")) say("Dihapus: $c (berkas pemeriksaan lama)");
say('Cadangan: data/backup-wa/' . basename($dir));
say('');
say('BERHASIL. Buka Konsol Platform -> Pengaturan -> WhatsApp platform (API), pilih BEM WA Gateway,');
say('tempel API key aplikasi lapasteflow, Simpan, lalu tekan "Kirim tes WhatsApp".');
