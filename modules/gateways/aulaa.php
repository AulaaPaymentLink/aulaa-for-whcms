<?php
/**
 * Modul Payment Link Aulaa untuk WHMCS.
 *
 * Mengintegrasikan layanan payment link Aulaa (QRIS, Virtual Account, E-Wallet,
 * Alfamart/Indomaret, dan kartu kredit/debit) ke dalam sistem penagihan WHMCS.
 * Pembayaran selalu diselesaikan di halaman pembayaran Aulaa, dan modul ini
 * mengarahkan pelanggan ke `payment_link` yang dikembalikan API Aulaa.
 *
 * @package    Aulaa_WHMCS
 * @author     Aulaa.co
 * @copyright  2026 Aulaa.co
 * @license    MIT
 * @link       https://aulaa.co/dokumentasi
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Config\Setting;

/**
 * Mendefinisikan metadata modul gateway WHMCS.
 *
 * @return array
 */
function aulaa_MetaData()
{
    return [
        'DisplayName'                 => 'Aulaa Payment Link',
        'APIVersion'                  => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage'            => false,
    ];
}

/**
 * Mendefinisikan field konfigurasi di halaman Admin WHMCS:
 * Setup -> Payments -> Payment Gateways.
 *
 * @return array
 */
function aulaa_config()
{
    $systemUrl   = Setting::getValue('SystemURL') ?: '';
    $callbackUrl = rtrim($systemUrl, '/') . '/modules/gateways/callback/aulaa.php';

    return [
        'FriendlyName' => [
            'Type'  => 'System',
            'Value' => 'Aulaa (QRIS, Virtual Account, E-Wallet, Kartu)',
        ],
        'apiKey' => [
            'FriendlyName' => 'API Key',
            'Type'         => 'password',
            'Size'         => '50',
            'Default'      => '',
            'Description'  => 'API Key project Aulaa Anda (format <code>pgw_id_...</code>). Ambil dari Dashboard Aulaa &rarr; Projects &rarr; bagian API Keys. '
                              . '<strong>Project harus bertipe API</strong> &mdash; project tipe No-Code tidak bisa memakai API.',
        ],
        'webhookSecret' => [
            'FriendlyName' => 'Webhook Secret',
            'Type'         => 'password',
            'Size'         => '50',
            'Default'      => '',
            'Description'  => 'Webhook Secret project Aulaa (Dashboard Aulaa &rarr; Projects &rarr; bagian Webhook). '
                              . 'Dipakai memverifikasi signature HMAC-SHA256 webhook, sekaligus menandatangani link bayar di halaman invoice.',
        ],
        'apiBase' => [
            'FriendlyName' => 'API Base URL',
            'Type'         => 'text',
            'Size'         => '50',
            'Default'      => 'https://api.aulaa.co/v1',
            'Description'  => 'Default: <code>https://api.aulaa.co/v1</code>. Jangan ubah kecuali diminta oleh Aulaa.',
        ],
        'payBase' => [
            'FriendlyName' => 'Payment Host',
            'Type'         => 'text',
            'Size'         => '50',
            'Default'      => 'https://payment.aulaa.co',
            'Description'  => 'Default: <code>https://payment.aulaa.co</code>. Host ini dipakai untuk <strong>memvalidasi</strong> '
                              . '<code>payment_link</code> yang dikembalikan Aulaa sebelum pelanggan dialihkan, sebagai perlindungan open redirect. '
                              . 'URL halaman pembayaran sendiri selalu berasal dari response API, bukan dirakit oleh modul ini.',
        ],
        'autoRedirect' => [
            'FriendlyName' => 'Otomatis Alihkan (Auto Redirect)',
            'Type'         => 'yesno',
            'Description'  => 'Centang untuk langsung mengarahkan pelanggan ke halaman pembayaran Aulaa saat invoice dibuka.',
        ],
        'instructions' => [
            'FriendlyName' => 'Konfigurasi Webhook di Aulaa',
            'Type'         => 'textarea',
            'Rows'         => '3',
            'Description'  => 'Salin URL berikut ke kolom <strong>Callback URL</strong> project Anda di Dashboard Aulaa '
                              . '(Projects &rarr; bagian Webhook):<br>'
                              . '<code style="font-size:13px; color:#2563eb; user-select:all;">' . htmlspecialchars($callbackUrl) . '</code>'
                              . '<br><small>Mengganti callback URL pada project yang sudah <em>approved</em> memicu review ulang oleh Aulaa.</small>',
        ],
    ];
}

/**
 * Menghasilkan HTML tombol pembayaran pada halaman tagihan klien (viewinvoice.php).
 *
 * @param array $params Parameter invoice dan konfigurasi gateway dari WHMCS
 * @return string HTML formulir pembayaran
 */
function aulaa_link($params)
{
    // Aulaa memproses transaksi dalam Rupiah saja
    $currency = strtoupper((string)($params['currency'] ?? ''));
    if ($currency !== 'IDR') {
        return '<div class="alert alert-warning" style="margin:10px 0; text-align:left; font-size:13px;">' .
            '<strong>Peringatan Metode Pembayaran Aulaa:</strong><br>' .
            'Aulaa saat ini hanya menerima transaksi dalam mata uang <strong>IDR (Rupiah)</strong>.<br>' .
            'Invoice ini terbit dalam mata uang <code>' . htmlspecialchars($currency) . '</code>. Silakan pilih metode pembayaran lain.' .
            '</div>';
    }

    $invoiceId     = (int)$params['invoiceid'];
    $webhookSecret = (string)($params['webhookSecret'] ?? '');
    $systemUrl     = rtrim($params['systemurl'], '/') . '/';
    $redirectUrl   = $systemUrl . 'modules/gateways/aulaa/redirect.php';
    $autoRedirect  = !empty($params['autoRedirect']);

    $html = '<div class="aulaa-payment-box" style="margin: 10px 0; text-align: center;">';
    $html .= '<form id="aulaa-pay-form" method="POST" action="' . htmlspecialchars($redirectUrl) . '">';
    $html .= '<input type="hidden" name="invoiceid" value="' . $invoiceId . '" />';

    // Signed token mengesahkan bahwa link bayar ini memang diterbitkan oleh modul
    // untuk invoice tersebut, sehingga pelanggan yang belum login pun bisa
    // melanjutkan pembayaran tanpa membuka celah manipulasi ID invoice.
    // Nominal tidak ikut ditandatangani karena redirect.php selalu menghitung
    // ulang sisa tagihan dari database, bukan dari request.
    if ($webhookSecret !== '') {
        $token = hash_hmac('sha256', 'aulaa|invoice|' . $invoiceId, $webhookSecret);
        $html .= '<input type="hidden" name="token" value="' . htmlspecialchars($token) . '" />';
    }

    $html .= '<button type="submit" class="btn btn-primary btn-lg" style="' .
        'background-color: #2563eb; border-color: #1d4ed8; color: #ffffff; ' .
        'padding: 10px 22px; font-weight: 600; border-radius: 8px; font-size: 15px; ' .
        'display: inline-flex; align-items: center; justify-content: center; gap: 10px; cursor: pointer; ' .
        'box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.2); transition: all 0.2s ease;">' .
        '<svg width="22" height="22" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" style="vertical-align: middle;">' .
        '<rect width="48" height="48" rx="10" fill="#111827"/>' .
        '<path d="M13 16l7 8-7 8" stroke="#3B82F6" stroke-width="4.5" stroke-linecap="round" stroke-linejoin="round"/>' .
        '<path d="M22 16l7 8-7 8" stroke="#3B82F6" stroke-width="4.5" stroke-linecap="round" stroke-linejoin="round" opacity="0.55"/>' .
        '</svg>' .
        '<span>Bayar Sekarang via Aulaa</span>' .
        '</button>';

    $html .= '<div style="margin-top: 8px; font-size: 12px; color: #6b7280;">' .
        'QRIS &bull; Virtual Account &bull; E-Wallet &bull; Alfamart/Indomaret &bull; Kartu' .
        '</div>';

    $html .= '</form>';

    if ($autoRedirect) {
        $html .= '<div id="aulaa-redirect-notice" style="margin-top: 10px; font-size: 13px; color: #4b5563;">' .
            '<div class="spinner-border spinner-border-sm text-primary" role="status" style="margin-right: 5px; width: 14px; height: 14px; border-width: 2px;"></div>' .
            'Mengarahkan otomatis ke halaman pembayaran Aulaa... ' .
            '<small style="display:block; color:#9ca3af; margin-top:2px;">(Jika tidak beralih otomatis dalam beberapa detik, klik tombol di atas)</small>' .
            '</div>';
        $html .= '<script type="text/javascript">
            (function() {
                var aulaaRedirectTimer = setTimeout(function() {
                    var f = document.getElementById("aulaa-pay-form");
                    if (f) {
                        f.submit();
                    }
                }, 800);
            })();
        </script>';
    }

    $html .= '</div>';

    return $html;
}
