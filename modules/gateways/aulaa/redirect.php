<?php
/**
 * Handler Checkout Aulaa untuk WHMCS.
 *
 * Menerima permintaan bayar dari viewinvoice.php, memvalidasi otentikasi,
 * membuat transaksi di Aulaa lewat `POST /v1/payments`, lalu mengarahkan
 * browser pelanggan ke `payment_link` yang dikembalikan Aulaa.
 *
 * Catatan penting soal idempotensi: `order_id` dibuat stabil per invoice WHMCS.
 * Dokumentasi Aulaa melarang membuat `order_id` baru untuk pesanan yang sama
 * karena pelanggan bisa tertagih dua kali. Bila `order_id` sudah terpakai,
 * Aulaa menjawab 409 Conflict beserta `payment_id` transaksi yang sudah ada;
 * modul ini membaca status transaksi itu dan melanjutkan sesi pembayaran yang
 * sama bila masih `pending`.
 *
 * @package    Aulaa_WHMCS
 * @author     Aulaa.co
 * @copyright  2026 Aulaa.co
 * @license    MIT
 * @link       https://aulaa.co/dokumentasi/api-v1/buat-pembayaran
 */

require_once __DIR__ . '/../../../init.php';

use WHMCS\Database\Capsule;

App::load_function('gateway');
App::load_function('invoice');

require_once __DIR__ . '/lib/AulaaApi.php';

/** @var int Berapa banyak order_id yang boleh dicoba bila transaksi lama sudah mati */
const AULAA_MAX_ORDER_ATTEMPTS = 5;

$gatewayModuleName = 'aulaa';
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    http_response_code(403);
    die("Modul pembayaran Aulaa belum diaktifkan di WHMCS.");
}

$invoiceId = (int)($_GET['invoiceid'] ?? $_POST['invoiceid'] ?? 0);
$token     = (string)($_GET['token'] ?? $_POST['token'] ?? '');

if ($invoiceId <= 0) {
    http_response_code(400);
    die("Parameter ID Invoice tidak valid.");
}

$invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
if (!$invoice) {
    http_response_code(404);
    die("Invoice #" . htmlspecialchars((string)$invoiceId) . " tidak ditemukan.");
}

$systemUrl   = rtrim($gatewayParams['systemurl'], '/') . '/';
$invoiceUrl  = $systemUrl . 'viewinvoice.php?id=' . $invoiceId;

// Invoice yang sudah lunas tidak perlu transaksi baru
if (strtolower((string)$invoice->status) === 'paid') {
    header("Location: " . $invoiceUrl);
    exit;
}

// Validasi otentikasi: pelanggan yang login sebagai pemilik invoice, ATAU
// pembawa signed token yang kita terbitkan sendiri di aulaa_link().
$webhookSecret = (string)($gatewayParams['webhookSecret'] ?? '');
$isUserOwner   = (isset($_SESSION['uid']) && (int)$_SESSION['uid'] === (int)$invoice->userid);
$isTokenValid  = false;

if ($webhookSecret !== '' && $token !== '') {
    $expectedToken = hash_hmac('sha256', 'aulaa|invoice|' . (int)$invoice->id, $webhookSecret);
    $isTokenValid  = hash_equals($expectedToken, $token);
}

if (!$isUserOwner && !$isTokenValid) {
    http_response_code(403);
    die("Akses ditolak: Verifikasi token otentikasi tidak sah.");
}

// Hitung sisa tagihan yang belum dibayar
$paidAmount = (float)Capsule::table('tblaccounts')->where('invoiceid', $invoiceId)->sum('amountin');
$balanceDue = (float)$invoice->total - $paidAmount;
$amount     = (int)round($balanceDue);

if ($amount < 1) {
    header("Location: " . $invoiceUrl);
    exit;
}

// Host pembayaran yang diizinkan, dipakai untuk memvalidasi payment_link
$payBase = !empty($gatewayParams['payBase']) ? (string)$gatewayParams['payBase'] : 'https://payment.aulaa.co';
$payHost = (string)parse_url($payBase, PHP_URL_HOST);
if ($payHost === '') {
    // payBase mungkin diisi tanpa skema, mis. "payment.aulaa.co"
    $payHost = trim(explode('/', preg_replace('#^[a-z0-9+.-]+://#i', '', trim($payBase)))[0]);
}

$baseOrderRef = 'whmcs-' . $invoiceId;
$orderRef     = $baseOrderRef;
$paymentLink  = '';
$aulaaId      = '';

try {
    $apiBase = !empty($gatewayParams['apiBase']) ? (string)$gatewayParams['apiBase'] : 'https://api.aulaa.co/v1';

    // Gagal cepat dengan pesan yang jelas bila nominal di luar batas Aulaa,
    // tanpa menunggu 400 Bad Request dari server.
    AulaaApi::assertAmountInRange($amount);

    $api = new AulaaApi($apiBase, (string)$gatewayParams['apiKey']);

    for ($attempt = 1; $attempt <= AULAA_MAX_ORDER_ATTEMPTS; $attempt++) {
        $orderRef = ($attempt === 1) ? $baseOrderRef : $baseOrderRef . '-r' . $attempt;

        try {
            // `payment_method` sengaja TIDAK dikirim, supaya pelanggan memilih
            // sendiri metode pembayaran di halaman Aulaa dari metode yang aktif
            // pada project. Mengisi field ini akan mengunci transaksi ke satu
            // metode saja, jadi jangan ditambahkan di sini.
            $res = $api->createPayment([
                'order_id'     => $orderRef,
                'amount'       => $amount,
                'redirect_url' => $invoiceUrl,
            ]);

            $aulaaId     = (string)($res['id'] ?? '');
            $paymentLink = AulaaApi::extractPaymentLink($res, $payHost);

            logTransaction(
                $gatewayParams['name'],
                [
                    'action'         => 'create_payment',
                    'whmcs_invoice'  => $invoiceId,
                    'aulaa_order_id' => $orderRef,
                    'aulaa_id'       => $aulaaId,
                    'amount'         => $amount,
                    'status'         => (string)($res['status'] ?? ''),
                    'payment_link'   => $paymentLink,
                    'expired_at'     => (string)($res['expired_at'] ?? ''),
                    'is_test'        => !empty($res['is_test']) ? 'yes' : 'no',
                ],
                'Payment Initiated'
            );
            break;

        } catch (AulaaApiException $e) {
            // Selain 409, tidak ada yang bisa dipulihkan di sini
            if ($e->getHttpCode() !== 409) {
                throw $e;
            }

            $existingId = trim((string)$e->getBodyField('payment_id', ''));
            if ($existingId === '') {
                throw $e;
            }

            // order_id ini sudah dipakai. Baca transaksi yang sudah ada, lalu
            // lanjutkan sesi pembayarannya bila masih bisa dibayar.
            $existing = $api->getPayment($existingId);
            $status   = strtolower(trim((string)($existing['status'] ?? '')));

            if ($status === 'pending') {
                $existingAmount = (int)($existing['amount'] ?? 0);

                // Sesi lama hanya aman dilanjutkan bila nominalnya masih sama
                // dengan sisa tagihan saat ini. Bila berbeda -- misalnya admin
                // mencatat pembayaran manual sebagian setelah transaksi dibuat --
                // link lama dibatalkan dulu supaya pelanggan tidak membayar
                // nominal yang keliru dan tidak ada dua link aktif sekaligus.
                if ($existingAmount !== $amount) {
                    $api->cancelPayment($existingId);

                    logTransaction(
                        $gatewayParams['name'],
                        [
                            'action'          => 'cancel_stale_payment',
                            'whmcs_invoice'   => $invoiceId,
                            'aulaa_order_id'  => $orderRef,
                            'aulaa_id'        => $existingId,
                            'previous_amount' => $existingAmount,
                            'current_amount'  => $amount,
                            'note'            => 'Nominal tagihan berubah sejak transaksi dibuat. Transaksi lama '
                                                 . 'dibatalkan, transaksi baru dibuat dengan nominal terkini.',
                        ],
                        'Stale Payment Cancelled'
                    );
                    continue;
                }

                $aulaaId     = $existingId;
                $paymentLink = AulaaApi::extractPaymentLink($existing, $payHost);

                logTransaction(
                    $gatewayParams['name'],
                    [
                        'action'         => 'resume_payment',
                        'whmcs_invoice'  => $invoiceId,
                        'aulaa_order_id' => $orderRef,
                        'aulaa_id'       => $aulaaId,
                        'amount'         => $amount,
                        'status'         => $status,
                        'payment_link'   => $paymentLink,
                        'expired_at'     => (string)($existing['expired_at'] ?? ''),
                        'note'           => 'Melanjutkan sesi pembayaran yang masih pending (anti tagihan ganda).',
                    ],
                    'Payment Resumed'
                );
                break;
            }

            if ($status === 'paid') {
                // Sudah dibayar di sisi Aulaa. Penandaan lunas adalah tugas
                // webhook; jangan membuat transaksi kedua.
                logTransaction(
                    $gatewayParams['name'],
                    [
                        'action'         => 'already_paid',
                        'whmcs_invoice'  => $invoiceId,
                        'aulaa_order_id' => $orderRef,
                        'aulaa_id'       => $existingId,
                        'status'         => $status,
                        'paid_at'        => (string)($existing['paid_at'] ?? ''),
                        'note'           => 'Transaksi Aulaa sudah lunas. Bila invoice WHMCS masih unpaid, '
                                            . 'periksa pengiriman webhook ke callback URL.',
                    ],
                    'Already Paid At Aulaa'
                );

                header("Location: " . $invoiceUrl);
                exit;
            }

            // expired / cancelled / failed / refunded: transaksi lama sudah
            // tertutup, jadi aman membuat transaksi baru dengan order_id berikutnya.
            if (in_array($status, ['expired', 'cancelled', 'failed', 'refunded'], true)) {
                logTransaction(
                    $gatewayParams['name'],
                    [
                        'action'         => 'retry_new_order_id',
                        'whmcs_invoice'  => $invoiceId,
                        'aulaa_order_id' => $orderRef,
                        'aulaa_id'       => $existingId,
                        'status'         => $status,
                        'attempt'        => $attempt,
                    ],
                    'Retrying (Previous ' . ucfirst($status) . ')'
                );
                continue;
            }

            // Status tidak dikenal: lebih baik berhenti daripada berisiko
            // membuat tagihan kedua.
            throw new AulaaApiException(sprintf(
                'Transaksi Aulaa untuk order_id %s berstatus "%s" yang tidak dikenal modul ini. '
                . 'Silakan hubungi dukungan Aulaa.',
                $orderRef,
                $status
            ), 409, $existing);
        }
    }

    if ($paymentLink === '') {
        throw new AulaaApiException(
            'Tidak berhasil menyiapkan link pembayaran setelah ' . AULAA_MAX_ORDER_ATTEMPTS
            . ' percobaan. Silakan coba beberapa saat lagi.'
        );
    }

    // Arahkan pelanggan ke halaman pembayaran Aulaa
    header("Location: " . $paymentLink, true, 303);
    exit;

} catch (Exception $e) {
    logTransaction(
        $gatewayParams['name'],
        [
            'action'         => 'create_payment_error',
            'whmcs_invoice'  => $invoiceId,
            'aulaa_order_id' => $orderRef,
            'amount'         => $amount,
            'http_code'      => ($e instanceof AulaaApiException) ? $e->getHttpCode() : null,
            'error'          => $e->getMessage(),
        ],
        'Payment Creation Failed'
    );

    $errorMessage = htmlspecialchars($e->getMessage());
    $invoiceLink  = htmlspecialchars($invoiceUrl);
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Gagal Memulai Pembayaran Aulaa</title>
        <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                background-color: #f3f4f6;
                color: #1f2937;
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
                margin: 0;
                padding: 1rem;
            }
            .card {
                background: #ffffff;
                border-radius: 12px;
                box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.08), 0 8px 10px -6px rgba(0, 0, 0, 0.05);
                max-width: 480px;
                width: 100%;
                padding: 2rem;
                text-align: center;
                box-sizing: border-box;
            }
            .icon-wrapper {
                width: 56px;
                height: 56px;
                background-color: #fee2e2;
                color: #ef4444;
                border-radius: 50%;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                margin-bottom: 1.25rem;
            }
            .icon-wrapper svg {
                width: 28px;
                height: 28px;
            }
            h1 {
                font-size: 1.25rem;
                font-weight: 700;
                margin: 0 0 0.5rem 0;
                color: #111827;
            }
            p.desc {
                font-size: 0.925rem;
                color: #4b5563;
                margin: 0 0 1.25rem 0;
                line-height: 1.5;
            }
            .error-box {
                background-color: #fef2f2;
                border: 1px solid #fecaca;
                color: #991b1b;
                border-radius: 8px;
                padding: 0.75rem 1rem;
                font-size: 0.85rem;
                text-align: left;
                margin-bottom: 1.5rem;
                word-break: break-word;
            }
            .btn {
                display: inline-block;
                width: 100%;
                background-color: #2563eb;
                color: #ffffff;
                text-decoration: none;
                font-weight: 600;
                font-size: 0.95rem;
                padding: 0.75rem 1.25rem;
                border-radius: 8px;
                box-sizing: border-box;
                transition: background-color 0.15s ease;
            }
            .btn:hover {
                background-color: #1d4ed8;
            }
        </style>
    </head>
    <body>
        <div class="card">
            <div class="icon-wrapper">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
            <h1>Gagal Memproses Pembayaran</h1>
            <p class="desc">Terjadi kendala saat menghubungkan tagihan Anda ke halaman pembayaran Aulaa.</p>
            <div class="error-box">
                <strong>Detail Error:</strong><br>
                <?= $errorMessage ?>
            </div>
            <a href="<?= $invoiceLink ?>" class="btn">Kembali ke Invoice #<?= (int)$invoiceId ?></a>
        </div>
    </body>
    </html>
    <?php
    exit;
}
