# kisantra/watchpost-laravel

Mengirim error, health check, dan heartbeat dari website Laravel ke **Kisantra Watchpost**.

- **Error**: setiap exception yang dilaporkan Laravel dikirim setelah response selesai, jadi pengunjung tidak menunggu. Lokasi yang dipakai adalah baris pertama di kode Anda sendiri, bukan di `vendor/`.
- **Heartbeat**: dikirim tiap menit lewat scheduler, membawa health check database, cache, queue, dan storage. Kalau heartbeat berhenti, Watchpost tahu cron atau scheduler mati.
- **Aman**: password, token, cookie, header, dan isi form tidak pernah dikirim. Parameter URL yang sensitif diganti `[disaring]`.
- **Screenshot dan jejak**: skrip kecil di setiap halaman mencatat 10 halaman/klik/request terakhir. Saat sebuah request gagal dengan error 500, skrip memotret halaman yang sedang dilihat pengguna. Keduanya muncul di error dan tiketnya di Watchpost.
- **Tidak mengganggu**: timeout 2 detik; kalau Watchpost tidak bisa dihubungi, laporan dilewati diam-diam. Error yang sama paling banyak dikirim sekali per 10 detik.

Laravel 10 sampai 13, PHP 8.1 ke atas.

## Pemasangan

1. Di Watchpost, buka **Website → Tambah website**. Setelah disimpan, salin `WATCHPOST_URL` dan `WATCHPOST_TOKEN` yang ditampilkan (token hanya muncul sekali).

2. Daftarkan repositori package ini di `composer.json` website, lalu pasang:

   ```bash
   composer config repositories.watchpost vcs https://github.com/Kisantra/watchpost-laravel.git
   composer require kisantra/watchpost-laravel:^1.0
   ```

3. Tambahkan ke `.env` website:

   ```env
   WATCHPOST_URL=https://watchpost.domainanda.com
   WATCHPOST_TOKEN=wp_...
   ```

4. Pastikan scheduler website berjalan (biasanya sudah ada di crontab):

   ```cron
   * * * * * cd /path/ke/website && php artisan schedule:run >> /dev/null 2>&1
   ```

5. Uji koneksinya:

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
| `WATCHPOST_BROWSER` | `true` | Skrip browser (jejak dan screenshot) di setiap halaman |
| `WATCHPOST_SCREENSHOTS` | `true` | Matikan screenshot saja; jejak tetap dikirim |
| `WATCHPOST_BREADCRUMBS` | `10` | Jumlah langkah terakhir yang diingat per tab |

Untuk mengubah daftar kata yang disaring atau health check mana yang aktif:

```bash
php artisan vendor:publish --tag=watchpost-config
```

## Screenshot dan jejak

Package menambahkan satu tag `<script>` ke setiap halaman HTML (grup middleware `web`), dimuat dari website itu sendiri di `/watchpost/browser.js`, tanpa CDN dan tanpa build.

- **Jejak**: halaman yang dibuka, tombol/tautan yang diklik, form yang dikirim, dan request yang gagal atau mengubah data. Hanya path URL yang dicatat, tanpa query string. Isi form tidak pernah dicatat.
- **Screenshot**: diambil saat request `fetch`/XHR ke website ini dibalas 500, misalnya aksi Inertia atau Livewire. Modal error yang muncul sesudahnya tidak ikut terpotret. Form biasa yang langsung pindah ke halaman error tidak bisa dipotret, tetapi jejaknya tetap dikirim.
- Keduanya dikirim browser ke `/watchpost/client` di website ini, lalu diteruskan ke Watchpost dengan token website setelah response selesai. Token tidak pernah sampai ke browser.
- Setiap request membawa trace id (header `X-Watchpost-Trace`) yang juga ikut di laporan error, sehingga screenshot menempel di kejadian yang tepat.
- Screenshot **tidak disamarkan**. Area yang tidak boleh terpotret bisa diberi atribut `data-watchpost-hide`. Watchpost menyimpan screenshot 14 hari, lalu menghapusnya.
- Jika website memakai CSP dengan nonce dari Vite, tag skrip ikut memakai nonce itu.

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
