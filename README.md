# Aulaa Payment Link untuk WHMCS

Modul resmi integrasi **[Aulaa](https://aulaa.co)** untuk **WHMCS** (Web Host Manager Complete Solution). Modul ini memungkinkan bisnis hosting, domain, dan layanan IT Anda menerima pembayaran otomatis dengan metode lengkap di Indonesia: **QRIS**, **Virtual Account** (BCA, Mandiri, BNI, BRI, Permata, CIMB Niaga, Maybank, dll.), **E-Wallet** (GoPay, ShopeePay, OVO, DANA, LinkAja), **gerai retail** (Alfamart, Indomaret), serta **kartu kredit/debit**.

---

## Cara Kerja

Aulaa adalah layanan **payment link**. Pembayaran selalu diselesaikan di **halaman pembayaran Aulaa**, bukan di halaman WHMCS Anda. Alurnya:

1. Pelanggan membuka invoice di WHMCS dan menekan **Bayar Sekarang via Aulaa**.
2. Server WHMCS Anda memanggil `POST /v1/payments` dengan `order_id` dan `amount`.
3. Aulaa membalas dengan `id` transaksi dan **`payment_link`**.
4. Modul mengarahkan pelanggan ke `payment_link` tersebut.
5. Pelanggan memilih metode dan membayar di halaman Aulaa.
6. Aulaa mengirim **webhook** ke WHMCS; invoice otomatis ditandai **Paid**, lalu pelanggan dialihkan kembali ke halaman invoice.

> **Perubahan penting (30 Oktober 2026).** Aulaa menghapus field `payment_number` dari response API dan menggantinya dengan `payment_link`. Nomor Virtual Account, string QRIS, link e-wallet, dan kode bayar gerai retail **tidak lagi dikirim lewat API** — semuanya ditampilkan oleh halaman pembayaran Aulaa. Modul ini (v1.1.0) mengambil URL pembayaran langsung dari field `payment_link` sesuai kontrak baru tersebut.

---

## Fitur Utama

- 🚀 **Checkout Terpusat di Aulaa**: Pelanggan memilih metode pembayaran di halaman Aulaa yang responsif; Anda tidak perlu membuat halaman pembayaran sendiri.
- ⚡ **Update Status Otomatis (Webhook)**: Invoice WHMCS otomatis ditandai **Paid** begitu pembayaran diverifikasi Aulaa.
- 🔒 **Keamanan**:
  - Verifikasi webhook dengan **HMAC-SHA256** atas *raw body*, perbandingan *constant-time*.
  - Link checkout di halaman invoice dilindungi signed HMAC token agar ID invoice tidak bisa dimanipulasi.
  - `payment_link` dari Aulaa divalidasi (wajib HTTPS + host sesuai konfigurasi) sebelum pelanggan dialihkan, sebagai perlindungan *open redirect*.
- 🧾 **Anti Tagihan Ganda**: `order_id` stabil per invoice. Bila pelanggan menekan Bayar berulang kali, modul **melanjutkan sesi pembayaran yang sama** alih-alih membuat transaksi baru.
- 🔄 **Opsi Auto-Redirect**: Pelanggan dapat langsung diarahkan ke halaman pembayaran saat membuka `viewinvoice.php`.
- 🛡️ **Validasi Mata Uang & Nominal**: Hanya invoice **IDR** yang diproses, dan nominal divalidasi terhadap batas Aulaa sebelum request dikirim.
- 📝 **Gateway Logging Lengkap**: Setiap tahap transaksi dan webhook tercatat di **WHMCS Gateway Log**.

---

## Prasyarat Sistem

- **WHMCS**: Versi 7.x, 8.x, atau yang lebih baru.
- **PHP**: Versi 7.4 s/d 8.3+.
- **Ekstensi PHP**: `curl`, `json`, `openssl`, `hash`.
- Akun aktif di **[Aulaa.co](https://aulaa.co)** dengan **project bertipe API**.

> **Project harus bertipe API.** Project tipe *No-Code* tidak bisa memakai API dan tidak mengirim webhook (notifikasinya lewat email). Tipe project diatur saat membuat project dan bisa diubah dari halaman project — mengubah tipe membuat project ditinjau ulang oleh Aulaa.

---

## Struktur File Modul

```text
aulaa-for-whcms/
├── modules/
│   └── gateways/
│       ├── aulaa.php                     # Entrypoint modul WHMCS (metadata, config, tombol bayar)
│       ├── aulaa/
│       │   ├── lib/
│       │   │   └── AulaaApi.php          # Klien REST API Aulaa + validasi payment_link
│       │   ├── images/
│       │   │   └── logo.svg              # Logo resmi Aulaa
│       │   └── redirect.php              # Membuat transaksi & mengarahkan ke payment_link
│       └── callback/
│           └── aulaa.php                 # Webhook listener (verifikasi signature & auto-mark Paid)
├── README.md
└── LICENSE
```

---

## Langkah Instalasi

1. **Salin file modul** ke direktori utama (root) instalasi WHMCS Anda:

   ```bash
   cp -r modules/ /path/ke/whmcs/modules/
   ```

2. **Pastikan struktur folder sesuai**:
   - `/path/ke/whmcs/modules/gateways/aulaa.php`
   - `/path/ke/whmcs/modules/gateways/aulaa/redirect.php`
   - `/path/ke/whmcs/modules/gateways/aulaa/lib/AulaaApi.php`
   - `/path/ke/whmcs/modules/gateways/callback/aulaa.php`

---

## Konfigurasi

### 1. Pengaturan di WHMCS Admin

1. Buka Admin WHMCS Anda.
2. Masuk ke **Setup** → **Payments** → **Payment Gateways** (atau **Configuration** → **System Settings** → **Payment Gateways** pada WHMCS 8+).
3. Buka tab **All Payment Gateways**, cari **Aulaa Payment Link**, lalu klik untuk mengaktifkannya.
4. Pada tab **Manage Existing Gateways**, atur konfigurasi berikut:

   | Field | Keterangan |
   |---|---|
   | **Show on Order Form** | Centang bila Aulaa ingin tampil sebagai pilihan saat checkout pemesanan. |
   | **Display Name** | Nama yang tampil ke pelanggan, mis. `Aulaa (QRIS, VA, E-Wallet)`. |
   | **API Key** | API Key project Aulaa, format `pgw_id_...`. |
   | **Webhook Secret** | Webhook Secret project Aulaa. |
   | **API Base URL** | `https://api.aulaa.co/v1` *(biarkan default)*. |
   | **Payment Host** | `https://payment.aulaa.co` *(biarkan default)*. Dipakai untuk **memvalidasi** `payment_link` sebelum pelanggan dialihkan. |
   | **Auto Redirect** | Centang bila pelanggan ingin langsung dialihkan ke halaman bayar saat membuka invoice. |

5. Klik **Save Changes**.

**Mendapatkan kredensial:** Login ke [Dashboard Aulaa](https://aulaa.co) → menu **Projects** → pilih project Anda. API Key dibuat di bagian **API Keys** (hanya ditampilkan **satu kali** saat dibuat — simpan dengan aman). Webhook Secret ada di bagian **Webhook**, dan bisa dirotasi tanpa memicu review ulang project.

### 2. Pengaturan Callback URL di Dashboard Aulaa

1. Masuk ke [Dashboard Aulaa](https://aulaa.co) → **Projects** → pilih project Anda.
2. Pada bagian **Webhook**, isi kolom **Callback URL** dengan:

   ```text
   https://domain-whmcs-anda.com/modules/gateways/callback/aulaa.php
   ```

   *(Ganti dengan URL instalasi WHMCS Anda yang menggunakan HTTPS.)*
3. Simpan perubahan.

> Callback URL berlaku **per project** untuk semua transaksi project tersebut dan tidak bisa diganti per transaksi. Mengganti callback URL pada project yang sudah **approved** memicu review ulang: project turun ke status *revision* dan mode Live dimatikan sementara.

---

## Detail Teknis

### Batas nominal

Aulaa menerima transaksi **Rp1.000 sampai Rp500.000.000**. Modul memvalidasi sisa tagihan terhadap batas ini sebelum memanggil API, sehingga invoice di luar batas langsung menampilkan pesan yang jelas alih-alih error `400` dari server. Masing-masing metode pembayaran juga punya `min_amount`/`max_amount` sendiri yang bisa dilihat lewat `GET /v1/payments/methods`.

### Format `order_id` dan anti tagihan ganda

Modul mengirim `order_id` yang **stabil per invoice**: `whmcs-{invoiceId}`.

`order_id` bersifat unik per project di Aulaa, jadi pengiriman ulang dijawab **409 Conflict** beserta `payment_id` transaksi yang sudah ada — bukan membuat transaksi kedua. Modul memanfaatkan ini:

| Status transaksi lama | Tindakan modul |
|---|---|
| `pending`, nominal sama | Melanjutkan sesi yang sama — pelanggan diarahkan ke `payment_link` transaksi itu. Tercatat sebagai *Payment Resumed*. |
| `pending`, nominal berbeda | Transaksi lama dibatalkan lewat `POST /v1/payments/{id}/cancel`, lalu transaksi baru dibuat dengan nominal terkini. Mencegah pelanggan membayar nominal basi (mis. setelah ada pembayaran manual sebagian) dan mencegah dua link aktif sekaligus. Tercatat sebagai *Stale Payment Cancelled*. |
| `paid` | Tidak membuat transaksi baru; pelanggan dikembalikan ke invoice. Tercatat sebagai *Already Paid At Aulaa*. |
| `expired`, `cancelled`, `failed`, `refunded` | Transaksi lama sudah tertutup, jadi modul membuat transaksi baru dengan `order_id` berikutnya (`whmcs-{invoiceId}-r2`, `-r3`, maksimal 5 percobaan). |
| status lain | Berhenti dengan pesan error, agar tidak berisiko membuat tagihan kedua. |

Webhook listener mengenali ketiga bentuk `order_id`: `whmcs-{id}`, `whmcs-{id}-r{n}`, dan format lama `whmcs-{id}-{hex}` dari modul v1.0.x — sehingga transaksi yang masih berjalan saat upgrade tidak kehilangan notifikasinya.

### Webhook

Aulaa mengirim `POST` ke callback URL dengan payload **flat** (tanpa pembungkus `event`); jenis notifikasi dibedakan dari field `status`:

```json
{
  "order_id": "whmcs-1024",
  "amount": 50000,
  "currency": "IDR",
  "country_code": "ID",
  "status": "paid",
  "payment_method": "qris",
  "formatted_amount": "Rp 50.000",
  "paid_at": "2026-06-27T07:15:32Z"
}
```

Signature dikirim pada header **`X-Webhook-Signature`**: HMAC-SHA256 (hex, tanpa prefix) atas **raw body**, ditandatangani dengan Webhook Secret project. Modul memverifikasinya sebelum memproses apa pun.

Endpoint harus menjawab `2xx`. Bila gagal, Aulaa mencoba ulang hingga **4 kali total** (percobaan awal + 3 ulangan) dengan jeda **+1 menit, +5 menit, +30 menit**. Karena itu handler bersifat idempoten terhadap `order_id` (lewat `checkCbTransID`).

> Payload webhook tidak memuat `fee_amount`, jadi biaya layanan Aulaa dicatat `0` pada pembayaran WHMCS dan tetap terlihat di Dashboard Aulaa.

### Penanganan status

| Status | Perilaku modul |
|---|---|
| `pending` | Dicatat di Gateway Log, invoice tidak berubah. |
| `paid` | `addInvoicePayment()` dipanggil; invoice jadi **Paid** bila lunas. Nominal dibandingkan dengan sisa tagihan WHMCS, selisih dicatat sebagai *Amount Mismatch*. |
| `expired`, `cancelled` | Dicatat sebagai *Cancelled/Expired*. |
| `failed` | Dicatat sebagai *Failed*. |
| `refunded` | Dicatat sebagai *Refunded (Needs Manual Action)*. **Invoice WHMCS tidak diubah otomatis** — lakukan refund/kredit manual agar jejak akuntansi jelas. |

---

## Pengujian Transaksi (Testing)

### Mode Sandbox

1. Pastikan switch project di Dashboard Aulaa pada posisi **Sandbox**, lalu buat transaksi seperti biasa dari invoice WHMCS.
2. Halaman pembayaran akan menampilkan **kode pembayaran dummy** (mis. `SBX1A2B3C4D`), atau kode QR untuk QRIS.
3. Buka halaman simulasi: **<https://api.aulaa.co/payment-simulation>**
4. Masukkan kode tersebut atau `id` pembayaran (atau scan QR untuk QRIS), lalu klik **Bayar (Sukses)** atau **Simulasikan Gagal**.
5. Status transaksi berubah dan webhook dikirim ke WHMCS — persis seperti produksi.

Transaksi sandbox tidak diteruskan ke payment provider asli, tidak masuk ke saldo/pendapatan, dan ditampilkan terpisah di dashboard. Simulator hanya bekerja untuk transaksi Sandbox.

### Mode Produksi (Live)

- Pastikan project Anda sudah disetujui (*Approved*) dan berstatus **Live** (perlu review project + KYC).
- Ganti **API Key** dan **Webhook Secret** di WHMCS dengan kredensial Live.

---

## Audit & Troubleshooting

- **Log transaksi**: WHMCS Admin → **Billing** → **Gateway Log**. Semua pemanggilan API dan webhook tercatat lengkap dengan statusnya (*Payment Initiated*, *Payment Resumed*, *Successful*, *Invalid Signature*, dsb.).

| Gejala | Kemungkinan penyebab |
|---|---|
| **Invalid Signature** | Nilai **Webhook Secret** di WHMCS tidak sama persis dengan yang ada di project Dashboard Aulaa (mis. baru dirotasi). |
| **Invoice tidak jadi Paid** | Callback URL tidak bisa diakses dari internet publik (terhalang Cloudflare Bot Fight Mode / firewall), atau mata uang invoice bukan IDR. Cek juga Gateway Log untuk entri *Already Paid At Aulaa* — artinya pembayaran sudah masuk di Aulaa tetapi webhook belum sampai. |
| **Response Aulaa tidak menyertakan payment_link** | Project bukan bertipe **API**, atau modul masih versi lama. |
| **HTTP 403 saat membuat transaksi** | Project belum **Live** atau sedang disuspend (mis. mencoba transaksi produksi sebelum Go Live). |
| **HTTP 401** | API Key tidak valid, tidak aktif, kedaluwarsa, atau tidak terkirim. |
| **Nominal di luar batas** | Sisa tagihan di luar rentang Rp1.000–Rp500.000.000, atau di luar batas metode yang dipilih. |
| **HTTP 429** | Terlalu banyak pengecekan status untuk satu pembayaran (batas 30 kali per menit). Tunggu sesuai header `Retry-After`. |

---

## Referensi

- [Dokumentasi Aulaa](https://aulaa.co/dokumentasi)
- [API Call — alur dan contoh kode](https://aulaa.co/dokumentasi/api-v1/apa-itu-api-call)
- [Buat Pembayaran (`POST /v1/payments`)](https://aulaa.co/dokumentasi/api-v1/buat-pembayaran)
- [Cek Status Pembayaran (`GET /v1/payments/{id}`)](https://aulaa.co/dokumentasi/api-v1/cek-status)
- [Webhook & verifikasi signature](https://aulaa.co/dokumentasi/api-v1/webhook)
- [Kode metode pembayaran](https://aulaa.co/dokumentasi/api-v1/metode-pembayaran)
- [Kode error API v1](https://aulaa.co/dokumentasi/api-v1/kode-error)
- [Simulasi pembayaran (Sandbox)](https://aulaa.co/dokumentasi/mulai/sandbox)

---

## Lisensi

Didistribusikan di bawah lisensi [MIT](LICENSE). Dikembangkan oleh tim [Aulaa.co](https://aulaa.co).
