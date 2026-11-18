# lapasteflow – pembaruan WhatsApp

Folder ini ditarik Hostinger ke `public_html/_wa-update/` di lapasteflow.com.
`update.php` mengganti pengiriman WhatsApp platform dari Fonnte ke BEM WA Gateway
(wa.baliekamediatama.com) dengan menambal `notif.php` dan `platform.html`.
Cadangan otomatis di `data/backup-wa/`, kembalikan dengan `&undo=1`.

## OTP masuk (verifikasi 2 langkah)
`otp-update.php` memasang `files/otp.php` dan `files/assets/otp.js`, lalu menambal `api.php`, `.htaccess`,
`platform.html`, dan `app.html`. Kode 6 digit dikirim lewat WhatsApp (BEM WA Gateway, template `kode_otp`)
dengan email sebagai alternatif. Setelah kode benar, kotak OTP terlipat menjadi kubus Rubik lalu masuk ke dashboard.
Cadangan di `data/backup-otp/`, kembalikan dengan `&undo=1`. Atur di Konsol Platform → Keamanan masuk (OTP).

## OTP Konsol Platform
`potp-update.php` memperluas OTP ke masuk Konsol Platform: kode ke nomor WhatsApp OTP pemilik
(atau WhatsApp dukungan) dan email akun admin. Bila tidak ada saluran atau semua gagal mengirim,
pemilik tetap masuk dengan kata sandi supaya tidak terkunci. Cadangan di `data/backup-potp/`.
