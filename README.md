# kisantra/watchpost-laravel

Mengirim error, health check, dan heartbeat dari website Laravel ke **Kisantra Watchpost**.

- **Error**: setiap exception yang dilaporkan Laravel dikirim setelah response selesai, jadi pengunjung tidak menunggu. Lokasi yang dipakai adalah baris pertama di kode Anda sendiri, bukan di `vendor/`.
- **Heartbeat**: dikirim tiap menit lewat scheduler, membawa health check database, cache, queue, dan storage. Kalau heartbeat berhenti, Watchpost tahu cron atau scheduler mati.
- **Aman**: password, token, cookie, header, dan isi form tidak pernah dikirim. Parameter URL yang sensitif diganti `[disaring]`.
- **Screenshot dan jejak**: skrip kecil di setiap halaman mencatat 10 halaman/klik/request terakhir. Saat sebuah request gagal dengan error 500, skrip memotret halaman yang sedang dilihat pengguna. Keduanya muncul di error dan tiketnya di Watchpost.
- **Laporan bug**: laporan yang dikirim pengguna lewat form website bisa diteruskan menjadi tiket di Watchpost, lengkap dengan platform asal, halaman, dan jejaknya.
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
| `WATCHPOST_TICKETS` | `true` | Matikan penerusan laporan bug ke Watchpost |

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

## Meneruskan laporan bug

Sejak v1.2.0. Website yang punya form "lapor bug" bisa meneruskan laporannya ke Watchpost (yang sudah punya endpoint `/api/ingest/tickets`). Laporan menjadi tiket **Perbaikan bug** di website ini. Kalau dikirim dari halaman error, laporan menempel ke tiket error itu, jadi satu masalah tetap satu tiket.

Kirim dari job antrean, karena unggahan lampiran bisa makan beberapa detik. `reportBug()` tidak pernah melempar exception. Hasil `null` berarti belum terkirim, jadi coba lagi nanti. Mengirim ulang `reference` yang sama aman: Watchpost mengembalikan tiket yang sudah ada.

```php
use Kisantra\Watchpost\BugReport;
use Kisantra\Watchpost\Facades\Watchpost;

// Di dalam job antrean:
if (! Watchpost::reportsBugs()) {
    return; // environment ini tidak melapor, atau WATCHPOST_TICKETS=false
}

$receipt = Watchpost::reportBug(new BugReport(
    reference: 'laporan-'.$laporan->id,     // unik di website ini
    title: $laporan->judul,
    description: $laporan->isi,
    reporterName: $laporan->user->name,
    reporterEmail: $laporan->user->email,
    priority: 'high',                       // low, normal, high, urgent
    company: $perusahaan?->name,            // untuk website multi-perusahaan
    pageUrl: $laporan->halaman,             // path + query + #hash
    traceId: $laporan->trace_id,            // dari halaman error, lihat di bawah
    errorId: $laporan->kode_error,
    breadcrumbs: $laporan->jejak ?? [],     // dari window.Watchpost.trail()
    userAgent: $laporan->user_agent,
    attachmentPath: $laporan->lampiran ? Storage::disk('public')->path($laporan->lampiran) : null,
    attachmentName: $laporan->nama_lampiran,
));

if ($receipt === null) {
    $this->release(60); // coba lagi semenit lagi
    return;
}

$laporan->update(['watchpost_ticket' => $receipt->number, 'watchpost_url' => $receipt->url]);
```

- **Platform asal** tercatat otomatis: website pemilik token, ditambah nama aplikasi (`APP_NAME`), `APP_URL`, environment, dan commit.
- **Prioritas** memakai istilah Watchpost (`low`, `normal`, `high`, `urgent`). Petakan istilah website Anda ke sana. Nilai lain melempar `InvalidArgumentException`, karena itu kesalahan di kode website, bukan gangguan jaringan.
- **Lampiran**: JPG, PNG, atau PDF paling besar 5 MB. Kalau berkasnya tidak ada, laporan tetap dikirim tanpa lampiran.
- Nilai yang akan ditolak Watchpost (email tidak valid, trace id bukan UUID, langkah jejak yang rusak, teks terlalu panjang) dibuang atau dipotong, supaya laporannya tetap sampai. Parameter URL yang sensitif diganti `[disaring]`.

**Jejak langkah.** Skrip browser menyediakan `window.Watchpost.trail()`, yaitu langkah-langkah terakhir di tab itu. Kirim bersama isi form:

```js
const jejak = window.Watchpost?.trail() ?? [];
```

**Dari halaman error.** `Watchpost::traceId()` adalah trace id request yang sedang berjalan, sama dengan yang dibawa error dan screenshot-nya di Watchpost. Taruh di halaman error, lalu kirim bersama laporan. Watchpost akan menempelkan laporan ke tiket error itu.

**Nilai tambahan di setiap error.** Misalnya kode error yang ditampilkan halaman error website Anda, supaya laporan pengguna bisa dicocokkan dengan error di Watchpost:

```php
// AppServiceProvider::boot()
Watchpost::context(fn () => ['error_id' => request()->attributes->get('error_id')]);
```

Paling banyak 20 nilai, berupa teks atau angka, masing-masing 255 karakter. Callback yang gagal dilewati tanpa mengganggu laporan error-nya.

### Status tiket kembali ke website

Sejak v1.3.0. Tiap menit, scheduler menjalankan `watchpost:sync-tickets`. Perintah ini menanyakan ke Watchpost tiket mana dari laporan website ini yang berubah (status, penugasan, komentar), lalu memicu event `TicketUpdated` untuk setiap tiket. Watchpost hanya ditanya kalau website punya listener untuk event itu, jadi website yang tidak memakainya tidak mengirim apa-apa.

```php
use Kisantra\Watchpost\Events\TicketUpdated;

// AppServiceProvider::boot(), atau listener biasa di app/Listeners
Event::listen(function (TicketUpdated $event) {
    // $event->status: open, in_progress, resolved, ignored
    // $event->references: reference yang Anda kirim lewat BugReport
    foreach ($event->references as $reference) {
        // cari laporannya, bandingkan statusnya, beri tahu pelapor bila berubah
    }
});
```

- Tiket yang sama bisa datang lebih dari sekali dengan status yang sama (misalnya karena ada komentar baru), jadi **bandingkan dulu** sebelum mengirim notifikasi.
- Posisi terakhir disimpan di cache (`watchpost:tickets:since`). Kalau cache dikosongkan, perintah mulai lagi dari 24 jam terakhir.
- Listener yang gagal dilaporkan sebagai error biasa, dan tiket lain tetap diproses.
- Listener berjalan di proses scheduler, tanpa request web. Kalau website Anda multi-tenant, siapkan konteksnya sendiri di listener.

## Perintah

| Perintah | Fungsi |
|---|---|
| `php artisan watchpost:test` | Cek koneksi: kirim satu heartbeat dan satu error uji |
| `php artisan watchpost:heartbeat` | Kirim heartbeat sekarang (otomatis tiap menit lewat scheduler) |
| `php artisan watchpost:sync-tickets` | Ambil perubahan status tiket laporan bug sekarang (otomatis tiap menit lewat scheduler) |

## Pengembangan

```bash
composer install
composer test
```
