# kisantra/watchpost-laravel

Mengirim error, health check, dan heartbeat dari website Laravel ke **Kisantra Watchpost**.

- **Error**: setiap exception yang dilaporkan Laravel dikirim setelah response selesai, jadi pengunjung tidak menunggu. Lokasi yang dipakai adalah baris pertama di kode Anda sendiri, bukan di `vendor/`.
- **Heartbeat**: dikirim tiap menit lewat scheduler, membawa health check database, cache, queue, dan storage. Kalau heartbeat berhenti, Watchpost tahu cron atau scheduler mati.
- **Aman**: password, token, cookie, header, dan isi form tidak pernah dikirim. Parameter URL yang sensitif diganti `[disaring]`.
- **Tidak mengganggu**: timeout 2 detik; kalau Watchpost tidak bisa dihubungi, laporan dilewati diam-diam. Error yang sama paling banyak dikirim sekali per 10 detik.

Laravel 10 sampai 13, PHP 8.1 ke atas.

## Pemasangan

1. Di Watchpost, buka **Website → Tambah website**. Setelah disimpan, salin `WATCHPOST_URL` dan `WATCHPOST_TOKEN` yang ditampilkan (token hanya muncul sekali).

2. Di `composer.json` website, tambahkan repositori privat package ini:

   ```json
   "repositories": [
       { "type": "vcs", "url": "https://github.com/Kisantra/watchpost-laravel.git" }
   ]
   ```

   Karena repo-nya privat, server yang menjalankan `composer install` butuh token GitHub yang punya akses ke org Kisantra (cukup sekali per server):

   ```bash
   composer config --global github-oauth.github.com ghp_...
   ```

3. Pasang:

   ```bash
   composer require kisantra/watchpost-laravel
   ```

4. Tambahkan ke `.env` website:

   ```env
   WATCHPOST_URL=https://watchpost.domainanda.com
   WATCHPOST_TOKEN=wp_...
   ```

5. Pastikan scheduler website berjalan (biasanya sudah ada di crontab):

   ```cron
   * * * * * cd /path/ke/website && php artisan schedule:run >> /dev/null 2>&1
   ```

6. Uji koneksinya:

   ```bash
   php artisan watchpost:test
   ```

   Hasilnya harus "terkirim" untuk heartbeat dan error uji. Error uji akan muncul di Watchpost; tandai selesai setelah dicek.

## Pengaturan lain (opsional)

| `.env` | Bawaan | Fungsi |
|---|---|---|
| `WATCHPOST_ENVIRONMENTS` | `production` | Environment yang mengirim laporan, pisahkan dengan koma |
| `WATCHPOST_ENABLED` | `true` | Matikan semua laporan tanpa mencopot package |
| `WATCHPOST_HEARTBEAT` | `true` | Matikan heartbeat tiap menit |
| `WATCHPOST_RELEASE` | commit git | Label versi yang tampil di setiap error |
| `WATCHPOST_QUEUE_LIMIT` | `100` | Job tertunda di atas angka ini dianggap bermasalah |
| `WATCHPOST_STORAGE_LIMIT` | `90` | Pemakaian disk (%) di atas angka ini dianggap bermasalah |
| `WATCHPOST_TIMEOUT` | `2` | Batas waktu kirim, dalam detik |

Untuk mengubah daftar kata yang disaring atau health check mana yang aktif:

```bash
php artisan vendor:publish --tag=watchpost-config
```

## Perintah

| Perintah | Fungsi |
|---|---|
| `php artisan watchpost:test` | Cek koneksi: kirim satu heartbeat dan satu error uji |
| `php artisan watchpost:heartbeat` | Kirim heartbeat sekarang (otomatis tiap menit lewat scheduler) |

## Pengembangan

```bash
composer install
composer test
```
