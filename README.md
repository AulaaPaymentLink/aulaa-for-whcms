# Aulaa Payment Gateway untuk WHMCS

Modul resmi integrasi sistem pembayaran **[Aulaa](https://aulaa.co)** untuk **WHMCS** (Web Host Manager Complete Solution). Modul ini memungkinkan bisnis hosting, domain, dan layanan IT Anda menerima pembayaran otomatis dengan metode lengkap di Indonesia: **QRIS**, **Virtual Account** (BCA, Mandiri, BNI, BRI, Permata, CIMB, dll.), **E-Wallet** (GoPay, ShopeePay, OVO, DANA), dan gerai retail.

---

## Fitur Utama

- 🚀 **Integrasi Fleksibel & Terpusat**: Pelanggan memilih metode pembayaran langsung di antarmuka checkout Aulaa yang responsif dan modern.
- ⚡ **Update Status Otomatis (Instant Webhook)**: Tagihan WHMCS otomatis ditandai **Lunas (Paid)** begitu pembayaran diverifikasi oleh Aulaa secara real-time.
- 🔒 **Keamanan Tingkat Tinggi**:
  - Verifikasi keaslian webhook menggunakan tanda tangan kriptografis **HMAC-SHA256**.
  - Perlindungan URL checkout dengan signed HMAC token untuk mencegah manipulasi ID tagihan.
  - Penanganan transaksi idempoten (*anti-duplicate payment*).
- 🔄 **Opsi Auto-Redirect**: Pelanggan dapat langsung diarahkan ke halaman pembayaran Aulaa saat membuka halaman tagihan (`viewinvoice.php`).
- 🛡️ **Validasi Mata Uang**: Memastikan hanya invoice bermata uang Rupiah (`IDR`) yang diproses melalui gateway ini.
- 📝 **Gateway Logging Lengkap**: Setiap tahapan transaksi dan webhook tercatat rapi di menu *WHMCS Gateway Log* untuk kemudahan audit.

---

## Prasyarat Sistem

- **WHMCS**: Versi 7.x, 8.x, atau yang lebih baru.
- **PHP**: Versi 7.4 s/d 8.3+.
- **Ekstensi PHP**: `curl`, `json`, `openssl`, `hash`.
- Akun aktif di **[Aulaa.co](https://aulaa.co)** beserta project yang sudah dibuat (Mode Sandbox / Live).

---

## Struktur File Modul

```text
aulaa-for-whcms/
├── modules/
│   └── gateways/
│       ├── aulaa.php                     # Entrypoint modul WHMCS (Metadata, Config, Link Bayar)
│       ├── aulaa/
│       │   ├── lib/
│       │   │   └── AulaaApi.php          # Klien REST API cURL Aulaa
│       │   ├── images/
│       │   │   └── logo.svg              # Logo resmi Aulaa
│       │   └── redirect.php              # Handler checkout aman ke payment.aulaa.co
│       └── callback/
│           └── aulaa.php                 # Webhook Listener (Verifikasi signature & auto-mark Paid)
├── README.md                             # Dokumentasi & panduan
└── LICENSE                               # Lisensi MIT
```

---

## Langkah Instalasi

1. **Unduh / Salin File Modul**:
   Salin folder `modules` yang ada di repositori ini langsung ke direktori utama (root) instalasi WHMCS Anda:
   ```bash
   cp -r modules/ /path/ke/whmcs/modules/
   ```

2. **Pastikan Struktur Folder Sesuai**:
   - `/path/ke/whmcs/modules/gateways/aulaa.php`
   - `/path/ke/whmcs/modules/gateways/aulaa/redirect.php`
   - `/path/ke/whmcs/modules/gateways/aulaa/lib/AulaaApi.php`
   - `/path/ke/whmcs/modules/gateways/callback/aulaa.php`

---

## Konfigurasi

### 1. Pengaturan di WHMCS Admin

1. Buka Admin WHMCS Anda.
2. Masuk ke menu **Setup** &rarr; **Payments** &rarr; **Payment Gateways** (atau **Configuration** &rarr; **System Settings** &rarr; **Payment Gateways** pada WHMCS 8+).
3. Buka tab **All Payment Gateways**, cari **Aulaa Payment Gateway**, lalu klik untuk mengaktifkannya.
4. Pada tab **Manage Existing Gateways**, atur konfigurasi berikut:
   - **Show on Order Form**: Centang jika ingin Aulaa tampil sebagai pilihan metode pembayaran saat checkout pemesanan.
   - **Display Name**: Nama yang tampil ke pelanggan, misal: `Aulaa (QRIS, VA, E-Wallet)`.
   - **API Key**: Masukkan API Key dari Dashboard Aulaa (`pgw_id_...`).
   - **Webhook Secret**: Masukkan Webhook Secret project Aulaa Anda.
   - **API Base URL**: `https://api.aulaa.co/v1` *(biarkan default)*.
   - **Payment Page URL**: `https://payment.aulaa.co` *(biarkan default)*.
   - **Auto Redirect**: Centang jika ingin pelanggan otomatis dialihkan ke halaman bayar saat membuka invoice.
5. Klik **Save Changes**.

### 2. Pengaturan Callback URL di Dashboard Aulaa

1. Masuk ke **[Dashboard Aulaa](https://aulaa.co)** &rarr; Pilih **Project** Anda.
2. Buka menu **Pengaturan Project** (Project Settings).
3. Pada kolom **Callback URL / Webhook URL**, masukkan URL callback WHMCS Anda:
   ```text
   https://domain-whmcs-anda.com/modules/gateways/callback/aulaa.php
   ```
   *(Ganti `https://domain-whmcs-anda.com/` dengan URL instalasi WHMCS Anda yang menggunakan HTTPS).*
4. Simpan perubahan.

---

## Pengujian Transaksi (Testing)

### Mode Sandbox
- Mode Sandbox atau Live diatur langsung dari **Dashboard Aulaa** pada pengaturan status Project Anda.
- Gunakan kredensial Project Sandbox untuk melakukan pengetesan.
- Saat pembayaran QRIS / VA dibuka di sandbox, Anda dapat menggunakan fitur simulator pembayaran di Dashboard Aulaa untuk menyelesaikan transaksi uji coba.

### Mode Produksi (Live)
- Pastikan project Anda di Dashboard Aulaa telah disetujui (*Approved*) dan berstatus **Live**.
- Ganti **API Key** dan **Webhook Secret** di WHMCS dengan kredensial Live Anda.

---

## Audit & Troubleshooting

- **Memeriksa Log Transaksi**:
  Di WHMCS Admin, navigasi ke menu **Billing** &rarr; **Gateway Log**. Semua interaksi pemanggilan API dan penerimaan notifikasi webhook Aulaa dicatat lengkap dengan statusnya (`Successful`, `Invalid Signature`, dsb.).
- **Tanda Tangan Tidak Valid (Invalid Signature)**:
  Pastikan nilai **Webhook Secret** yang dimasukkan di pengaturan WHMCS sama persis dengan yang tertera pada project di Dashboard Aulaa.
- **Invoice Tidak Berubah Menjadi Paid**:
  1. Pastikan Callback URL di Dashboard Aulaa dapat diakses dari internet publik (tidak terhalang Cloudflare Bot Fight Mode / firewall blocking).
  2. Pastikan mata uang invoice adalah **IDR**.

---

## Lisensi

Didistribusikan di bawah lisensi [MIT](LICENSE). Dikembangkan oleh tim [Aulaa.co](https://aulaa.co).
