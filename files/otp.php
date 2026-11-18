<?php // lp-build: saas-2026.10
/**
 * OTP masuk (verifikasi 2 langkah) – La Paste Bazzar Management
 * Setelah kata sandi benar, kode 6 digit dikirim ke WhatsApp (BEM WA Gateway, template resmi kode_otp; atau Fonnte)
 * dengan email sebagai alternatif. Sesi baru dibuat setelah kode cocok.
 */
declare(strict_types=1);

const OTP_TTL = 300;        // kode berlaku 5 menit
const OTP_RESEND = 30;      // jeda kirim ulang (detik)
const OTP_MAX_TRY = 5;      // salah kode maksimal per kode
const OTP_MAX_SEND = 5;     // pengiriman maksimal per proses masuk
const OTP_WINDOW = 900;     // proses masuk harus selesai dalam 15 menit

function otp_conf(): array {
    $c = array_replace(['enabled' => true, 'scope' => 'all'], platform()['otp'] ?? []);
    return ['enabled' => !empty($c['enabled']), 'scope' => $c['scope'] === 'staff' ? 'staff' : 'all'];
}
function otp_mask_wa(string $w): string { return strlen($w) < 8 ? $w : substr($w, 0, 4) . str_repeat('•', strlen($w) - 7) . substr($w, -3); }
function otp_mask_email(string $e): string { [$u, $d] = array_pad(explode('@', $e, 2), 2, ''); return mb_substr($u, 0, 2) . '•••@' . $d; }

/** Saluran yang bisa dipakai akun ini: ['wa' => 62…, 'email' => …] */
function otp_channels(array $W, array $acc): array {
    $ch = [];
    $wa = norm_wa((string)($acc['wa'] ?? ''));
    if (strlen($wa) >= 10 && wa_route($W['id'], null, 'akun-otp')[0] !== '') $ch['wa'] = $wa;
    $em = strtolower(trim((string)($acc['email'] ?? '')));
    if (filter_var($em, FILTER_VALIDATE_EMAIL)) $ch['email'] = $em;
    return $ch;
}

/** Dipanggil dari login setelah kata sandi & status akun lolos. null = OTP tidak dipakai, langsung masuk. */
function otp_begin(array $W, array $S, array $acc, array $rec): ?array {
    $c = otp_conf();
    if (!$c['enabled'] || ($c['scope'] === 'staff' && $acc['kind'] === 'tenant')) return null;
    $ch = otp_channels($W, $acc);
    if (!$ch) { error_log('[lapaste otp] tidak ada saluran OTP untuk akun ' . $acc['id'] . ', masuk tanpa OTP'); return null; }
    $_SESSION['otp'] = ['ws' => $W['id'], 'acc' => (int)$acc['id'], 'kind' => $acc['kind'], 'ref' => $acc['ref_id'], 'mc' => (int)$acc['must_change'],
        'name' => $acc['kind'] === 'user' ? (string)($rec['name'] ?? '') : (string)($rec['owner'] ?? ''), 'role' => $acc['kind'] === 'user' ? (string)($rec['role'] ?? '') : 'Mitra Tenant',
        'bz' => (string)($S['company']['name'] ?? $W['name']), 'ch' => $ch, 'h' => '', 'exp' => 0, 'tries' => 0, 'sends' => 0, 'last' => 0, 'via' => '', 'born' => time(), 'salt' => bin2hex(random_bytes(16))];
    $first = isset($ch['wa']) ? 'wa' : 'email';
    [$ok, $err] = otp_deliver($first, $S);
    if (!$ok && count($ch) > 1) { $other = $first === 'wa' ? 'email' : 'wa'; [$ok, $err2] = otp_deliver($other, $S); if (!$ok) $err .= ' · ' . $err2; }
    if (!$ok) { error_log('[lapaste otp] gagal kirim: ' . $err); return ['ok' => true, 'otp' => true, 'sent' => false] + otp_public() + ['error' => 'Kode belum terkirim. Coba kirim ulang atau pilih saluran lain. Bila tetap gagal, hubungi pengelola bazzar.']; }
    return ['ok' => true, 'otp' => true, 'sent' => true] + otp_public();
}

/** Info aman untuk browser (tujuan disamarkan). */
function otp_public(): array {
    $o = $_SESSION['otp'] ?? []; $ch = $o['ch'] ?? [];
    $mask = fn($k) => $k === 'wa' ? otp_mask_wa((string)$ch['wa']) : otp_mask_email((string)$ch['email']);
    return ['via' => (string)($o['via'] ?? ''), 'to' => !empty($o['via']) ? $mask($o['via']) : '', 'channels' => array_map(fn($k) => ['id' => $k, 'to' => $mask($k)], array_keys($ch)),
        'expires' => max(0, (int)($o['exp'] ?? 0) - time()), 'resendIn' => max(0, OTP_RESEND - (time() - (int)($o['last'] ?? 0))), 'name' => explode(' ', trim((string)($o['name'] ?? '')))[0]];
}

function otp_hash(string $code): string { return hash_hmac('sha256', $code, (string)($_SESSION['otp']['salt'] ?? '') . '|' . (string)($_SESSION['csrf'] ?? '')); }

/** Buat kode baru dan kirim lewat $via. */
function otp_deliver(string $via, array $S): array {
    $o = &$_SESSION['otp'];
    if (empty($o['ch'][$via])) return [false, 'Saluran tidak tersedia'];
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $o['h'] = otp_hash($code); $o['exp'] = time() + OTP_TTL; $o['tries'] = 0; $o['sends']++; $o['last'] = time();
    $to = (string)$o['ch'][$via]; $bz = (string)$o['bz'];
    if ($via === 'wa') {
        [$tok, $why] = wa_route((string)$o['ws'], null, 'akun-otp');
        if ($tok === '') return [false, $why];
        if (str_starts_with($tok, 'bem:')) [$ok, $err] = otp_gw_send(substr($tok, 4), $to, $code);
        else [$ok, $err] = send_wa($tok, $to, "Kode masuk $bz: *$code*\nBerlaku 5 menit. Jangan berikan kode ini kepada siapa pun, termasuk pengelola.");
    } else {
        $body = "Halo " . ($o['name'] ?: 'Bapak/Ibu') . ",\n\nKode masuk Anda: $code\nBerlaku: 5 menit\n\nMasukkan kode ini di halaman masuk $bz. Jangan berikan kode ini kepada siapa pun, termasuk pengelola.\nJika Anda tidak sedang mencoba masuk, segera ganti kata sandi Anda.\n\n— $bz";
        [$ok, $err] = send_email($to, "Kode masuk $code – $bz", $body, $bz, '', null, ['kind' => 'akun-otp', 'brand' => $bz, 'brandLogo' => brand_logo_path($S)]);
    }
    if ($ok) $o['via'] = $via;
    return [$ok, (string)$err];
}

/** Template autentikasi resmi lewat BEM WA Gateway (tombol Salin Kode). */
function otp_gw_send(string $key, string $to, string $code): array {
    $ch = curl_init(WA_GW_URL . '/api/send-otp');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . trim($key), 'X-API-Key: ' . trim($key)],
        CURLOPT_POSTFIELDS => json_encode(['to' => norm_wa($to), 'code' => $code, 'reference' => 'lapasteflow-otp'])]);
    $res = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
    if ($res === false) return [false, 'BEM WA Gateway tidak dapat dihubungi: ' . $err];
    if ($http === 401) return [false, 'API key BEM WA Gateway ditolak'];
    $j = json_decode((string)$res, true);
    $ok = $http >= 200 && $http < 300 && is_array($j) && ($j['ok'] ?? $j['status'] ?? true) !== false;
    return [$ok, $ok ? '' : 'BEM WA Gateway: ' . mb_substr((string)(is_array($j) ? ($j['error'] ?? $j['reason'] ?? '') : '') ?: 'HTTP ' . $http, 0, 200)];
}

function otp_pending(array $W): array {
    $o = $_SESSION['otp'] ?? null;
    if (!$o || ($o['ws'] ?? '') !== $W['id'] || time() - (int)$o['born'] > OTP_WINDOW) { unset($_SESSION['otp']); fail('Sesi verifikasi berakhir. Silakan masuk ulang.', 410, 'otp_expired'); }
    return $o;
}

/** Kirim ulang / pindah saluran. */
function otp_resend(array $W, string $via): array {
    $o = otp_pending($W);
    if (!isset($o['ch'][$via])) fail('Saluran tidak tersedia untuk akun ini.');
    if ((int)$o['sends'] >= OTP_MAX_SEND) fail('Batas pengiriman kode tercapai. Silakan masuk ulang beberapa saat lagi.', 429, 'otp_limit');
    $wait = OTP_RESEND - (time() - (int)$o['last']);
    if ($via === ($o['via'] ?? '') && $wait > 0) fail("Tunggu $wait detik sebelum mengirim ulang.", 429, 'otp_wait');
    [$S] = state_load();
    [$ok, $err] = otp_deliver($via, $S);
    if (!$ok) { error_log('[lapaste otp] gagal kirim: ' . $err); fail(($via === 'wa' ? 'WhatsApp' : 'Email') . ' belum terkirim. ' . (count($o['ch']) > 1 ? 'Coba saluran lain.' : 'Coba lagi atau hubungi pengelola bazzar.'), 502, 'otp_send'); }
    return ['ok' => true, 'otp' => true, 'sent' => true] + otp_public();
}

/** Cocokkan kode; bila benar, buat sesi seperti login biasa. */
function otp_verify(array $W, string $code): array {
    if (ip_blocked()) fail('Terlalu banyak percobaan dari jaringan ini. Coba lagi dalam 15 menit.', 429, 'rate');
    $o = otp_pending($W);
    $code = preg_replace('/\D/', '', $code);
    if ((int)$o['exp'] < time() || $o['h'] === '') fail('Kode sudah kedaluwarsa. Tekan "Kirim ulang".', 410, 'otp_old');
    if ((int)$o['tries'] >= OTP_MAX_TRY) { unset($_SESSION['otp']); fail('Terlalu banyak kode salah. Silakan masuk ulang.', 429, 'otp_expired'); }
    if (strlen($code) !== 6 || !hash_equals((string)$o['h'], otp_hash($code))) {
        $_SESSION['otp']['tries'] = (int)$o['tries'] + 1; ip_note();
        $left = OTP_MAX_TRY - (int)$_SESSION['otp']['tries'];
        if ($left <= 0) { unset($_SESSION['otp']); fail('Terlalu banyak kode salah. Silakan masuk ulang.', 429, 'otp_expired'); }
        fail("Kode tidak cocok. Sisa $left percobaan.", 401, 'otp_bad');
    }
    $acc = acct_row_id((int)$o['acc']);
    [$S] = state_load();
    $rec = $acc ? find($S[$acc['kind'] === 'user' ? 'users' : 'tenants'], $acc['ref_id']) : null;
    $okRec = $rec && ($acc['kind'] === 'user' ? !empty($rec['active']) : !in_array($rec['status'], ['rejected', 'suspended'], true));
    if (!$okRec) { unset($_SESSION['otp']); fail('Akun tidak aktif. Hubungi pengelola.', 403, 'inactive'); }
    $via = (string)$o['via'];
    session_regenerate_id(true);
    $_SESSION = ['uid' => [$acc['kind'], $acc['ref_id']], 'ws' => $W['id'], 'born' => time(), 'last' => time(), 'csrf' => bin2hex(random_bytes(32)), 'must_change' => (int)$acc['must_change']];
    db()->prepare('UPDATE lp_accounts SET last_login = ? WHERE id = ?')->execute([time(), $acc['id']]);
    $name = $acc['kind'] === 'user' ? $rec['name'] : $rec['owner'];
    with_state(function (&$S) use ($name, $acc, $rec, $via) { audit($S, $name, $acc['kind'] === 'user' ? $rec['role'] : 'Mitra Tenant', 'Masuk', "$name masuk dengan verifikasi OTP via " . ($via === 'wa' ? 'WhatsApp' : 'email') . ' dari IP ' . client_ip()); return ['save' => true]; });
    return ['ok' => true, 'user' => ['kind' => $acc['kind'], 'id' => $acc['ref_id']], 'mustChange' => (bool)$acc['must_change']];
}
