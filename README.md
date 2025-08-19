# Benchmark — Advanced (PHP)

Deskripsi singkat
-----------------

`benchmark.php` adalah skrip PHP sederhana namun komprehensif untuk melakukan pengukuran performa server pada beberapa subsistem: MySQL, disk I/O, CPU, memori, dan latensi jaringan. Dikembangkan agar dapat dijalankan di lingkungan minimal (kompatibel dengan PHP 5.3+). Hasil pengukuran ditampilkan lewat UI berbasis HTML dan dapat diunduh sebagai JSON.

Fitur utama
-----------
- Form berbasis GET untuk konfigurasi cepat (server name, environment, kredensial MySQL, ukuran file disk, jumlah operasi acak, dsb.).
- Deteksi informasi sistem dasar: versi PHP, jumlah core CPU, apakah berjalan di container.
- Benchmark MySQL: single inserts, transactional inserts, bulk inserts, selects.
- Benchmark disk: sequential read/write pada beberapa block size, random read/write.
- Benchmark CPU: perhitungan Pi sederhana dan sieve of Eratosthenes.
- Benchmark memori: alokasi masif dan peak usage.
- Benchmark jaringan: latency TCP connect ke host:port yang dikonfigurasi.
- Skor teragregasi dan rekomendasi optimasi otomatis berdasarkan hasil.
- Ekspor hasil ke JSON dan opsi untuk mendownloadnya.

Persyaratan
-----------
- PHP 5.3 atau lebih baru (skrip ditulis untuk kompatibilitas turunannya).
- Webserver (Apache/Nginx/IIS) atau PHP built-in server untuk menayangkan file.
- MySQL server (digunakan DB `testdb` oleh skrip). Jika tidak ada, skrip akan mencoba membuat database tersebut — butuh user yang punya hak.
- Izin menulis file di direktori tempat skrip dijalankan (untuk file disk benchmark sementara).

Catatan teknis / keamanan penting
--------------------------------
- Skrip menggunakan ekstensi dan fungsi lama `mysql_*` (deprecated sejak PHP 5.5 dan dihapus pada PHP 7). Untuk lingkungan modern, pertimbangkan migrasi ke `mysqli` atau `PDO`.
- Nama basis data dipaksa menjadi `testdb` oleh skrip. Jangan jalankan skrip ini pada server produksi tanpa meninjau kode — skrip akan membuat/dropping tabel dalam DB tersebut.
- Kredensial MySQL dapat diberikan lewat form GET. Jangan mengekspos server publik tanpa proteksi karena kredensial akan lewat URL.
- Skrip menulis file sementara (`benchmark_testfile.tmp`) untuk pengujian disk dan akan mencoba menghapusnya setelah berjalan.

Additional cleanup behavior
--------------------------
- Setelah menyelesaikan pengujian MySQL, skrip akan mencoba menghapus tabel sementara yang dibuat untuk run tersebut (nama seperti `benchmark_<timestamp>`). Ini dilakukan agar database `testdb` kembali bersih setelah benchmark.
- Hasil JSON akan menyertakan properti `mysql.table_dropped` yang berisi `true` jika tabel berhasil dihapus, `false` jika penghapusan gagal, atau `null` jika lingkungan runtime tidak mendukung fungsi `mysql_*` (mis. PHP7+ tanpa ekstensi kompatibel).

Cara menjalankan
---------------
1) Salin seluruh folder/`benchmark.php` ke server PHP Anda.
2) Buka lewat web browser: misal jika pakai built-in server PHP di Windows PowerShell:

```powershell
# buka terminal di folder y:\benchmark
php -S localhost:8000
# lalu buka http://localhost:8000/benchmark.php
```

3) Isi form yang tersedia (atau tambahkan parameter GET) lalu klik "Run Benchmark".

Parameter URL / form
--------------------
- `server_name`: Nama server yang ditampilkan di hasil.
- `env`: Environment (baremetal, docker, vm).
- `total`: Jumlah baris/queries MySQL yang akan diuji (default 1000).
- `disk_mb`: Ukuran file disk untuk pengujian (MB) — default 50.
- `rnd_ops`: Jumlah operasi acak untuk random read/write — default 500.
- `net_host`: Host:port untuk tes koneksi TCP (misal `google.com:80`).
- `db_host`, `db_port`, `db_user`, `db_pass`: Kredensial koneksi MySQL. Nama DB di-form tampil sebagai `testdb` (readonly).
- `run=1`: Param yang dipakai untuk menjalankan benchmark (form sudah menyertakan tombol Run).

Contoh URL lengkap

```
http://localhost:8000/benchmark.php?run=1&server_name=lab01&env=baremmetal&total=2000&disk_mb=100&rnd_ops=1000&net_host=google.com:80&db_host=127.0.0.1&db_port=3306&db_user=root&db_pass=secret
```

Apa yang dihasilkan (output)
----------------------------
- Halaman HTML interaktif yang menampilkan:
  - Overall score (0..100) dan skor per subsistem (MySQL, Disk, CPU, Memory).
  - Detail waktu eksekusi untuk setiap pengujian (detik / MB/s / ms/op).
  - Kartu rekomendasi optimasi otomatis berdasarkan hasil dan referensi internal.
  - Box JSON berisi objek hasil lengkap yang dapat diunduh.

Struktur JSON (ringkas)

{
  "meta": { server_name, env, sys, run_at },
  "mysql": { connected, table, times: { single_insert, tx_insert, bulk_insert, select }, ... },
  "disk": { seq: [...], rnd: [...], meta: {...} },
  "cpu": { pi_avg_s, sieve_time_s },
  "memory": { alloc_time_s, peak_mb },
  "network": { target, latencies_ms: [...] },
  "scores": { mysql, disk, cpu, memory, overall }
}

Known issues & catatan implementasi
----------------------------------
- Skrip menggunakan fungsi `mysql_connect` / `mysql_query` dll.; pada PHP modern (7+) fungsi ini tidak tersedia. Jika Anda menjalankan PHP 7+, ubah implementasi MySQL ke `mysqli` atau `PDO`.
- Ada referensi variabel `$storage_type` di kode (digunakan untuk memilih referensi disk). Namun variabel ini tidak didefinisikan secara eksplisit dalam skrip — ini hanya untuk konteks rekomendasi. Jika Anda ingin hasil disk score akurat, set `\$storage_type` sebelum bagian scoring (mis. `HDD` / `SSD` / `NVMe`).
- Untuk pengujian besar (total baris MySQL sangat banyak atau disk_mb besar) sesuaikan `max_execution_time` dan `memory_limit` atau jalankan pengujian lebih kecil agar tidak melebihi batas runtime PHP.
- Skrip melakukan penulisan file sementara di folder kerja saat ini. Pastikan user PHP memiliki izin tulis.

Troubleshooting cepat
---------------------
- "Gagal koneksi MySQL": Periksa `db_host`, `db_port`, `db_user`, `db_pass` dan pastikan server MySQL menerima koneksi dari host tersebut.
- Timeout saat koneksi jaringan: `net_host` mungkin tidak dapat diakses atau port ditutup; coba host lain (mis. `google.com:80`).
- Error permission saat membuat file: jalankan skrip di direktori dengan izin tulis untuk user PHP.
- Jika Anda mendapat error fungsi `mysql_*` tidak ditemukan: gunakan PHP versi 5.x atau ubah skrip ke `mysqli`/`PDO`.

Saran migrasi singkat
---------------------
- Ganti semua panggilan `mysql_*` dengan `mysqli_*` atau `PDO`. Ini juga membantu untuk menghindari masalah kompatibilitas dan meningkatkan keamanan (prepared statements).
- Pertimbangkan menambah mode non-interaktif/CLI agar bisa menjalankan benchmark secara otomatis dan mengumpulkan hasil ke sistem observability (Prometheus/Elasticsearch).

Contributing
------------
Perbaikan dan PR diterima. Area cepat untuk sumbangan:
- Migrasi ke `mysqli`/`PDO`.
- Tambah opsi konfigurasi melalui file (bukan GET) dan validasi input.
- Tambah lebih banyak workload (fio wrapper, ioping, sysbench) dan integrasi output.

License
-------
File ini disertakan tanpa lisensi spesifik; jika ingin menggunakan ulang, disarankan menambahkan lisensi MIT atau sesuai kebijakan proyek Anda.

Catatan akhir
------------
Gunakan skrip ini untuk pengujian di lingkungan uji atau staging. Jangan jalankan langsung di produksi tanpa meninjau perubahan yang dilakukan pada database atau pengaturan sistem.

--
Generated README untuk `benchmark.php` — sesuaikan lebih lanjut bila ada kebutuhan audit atau integrasi otomatis.
