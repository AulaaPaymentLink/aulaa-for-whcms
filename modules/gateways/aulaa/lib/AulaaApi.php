<?php
/**
 * Aulaa Payment Gateway API Client for WHMCS.
 *
 * @package    Aulaa_WHMCS
 * @author     Aulaa.co
 * @copyright  2026 Aulaa.co
 * @license    MIT
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

class AulaaApi
{
    /** @var string Base URL API Aulaa (misal: https://api.aulaa.co/v1) */
    private $apiBase;

    /** @var string API Key merchant (Bearer token) */
    private $apiKey;

    /** @var string Versi plugin untuk User-Agent */
    const VERSION = '1.0.0';

    /**
     * @param string $apiBase
     * @param string $apiKey
     */
    public function __construct($apiBase, $apiKey)
    {
        $this->apiBase = rtrim(trim((string)$apiBase), '/');
        $this->apiKey  = trim((string)$apiKey);
    }

    /**
     * Buat invoice/pembayaran baru di Aulaa.
     *
     * @param array $params [ 'order_id' => string, 'amount' => int, 'redirect_url' => string ]
     * @return array Response payload dari Aulaa (berisi id, status, order_id, charged_amount, dll.)
     * @throws Exception Jika validasi gagal atau request HTTP bermasalah
     */
    public function createPayment(array $params)
    {
        if (empty($params['order_id'])) {
            throw new Exception('order_id wajib diisi untuk membuat pembayaran Aulaa.');
        }

        $amount = (int)($params['amount'] ?? 0);
        if ($amount < 1) {
            throw new Exception('Nominal pembayaran minimal adalah Rp 1.');
        }

        $body = [
            'order_id'     => (string)$params['order_id'],
            'amount'       => $amount,
            'redirect_url' => isset($params['redirect_url']) ? (string)$params['redirect_url'] : '',
        ];

        return $this->request('POST', '/payments', $body);
    }

    /**
     * Ambil status pembayaran berdasarkan ID invoice Aulaa.
     *
     * @param string $invoiceId ID invoice Aulaa (contoh: inv_xxxx)
     * @return array
     * @throws Exception
     */
    public function getPayment($invoiceId)
    {
        $invoiceId = trim((string)$invoiceId);
        if (empty($invoiceId)) {
            throw new Exception('ID invoice Aulaa tidak boleh kosong.');
        }

        return $this->request('GET', '/payments/' . rawurlencode($invoiceId));
    }

    /**
     * Eksekusi HTTP request menggunakan cURL.
     *
     * @param string $method GET atau POST
     * @param string $path Path endpoint (diawali '/')
     * @param array|null $body Payload JSON (opsional)
     * @return array
     * @throws Exception
     */
    private function request($method, $path, ?array $body = null)
    {
        if (empty($this->apiKey)) {
            throw new Exception('API Key Aulaa belum dikonfigurasi di pengaturan Payment Gateway WHMCS.');
        }

        $url = $this->apiBase . $path;

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: Aulaa-WHMCS/' . self::VERSION,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            $jsonPayload = json_encode($body ?? []);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        } else {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        }

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError) {
            throw new Exception('Koneksi ke API Aulaa gagal (cURL): ' . $curlError);
        }

        $decoded = json_decode((string)$response, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            $errorMessage = 'Gagal menghubungi server Aulaa (HTTP ' . $httpCode . ')';
            if (is_array($decoded) && !empty($decoded['error'])) {
                $errorMessage = (string)$decoded['error'];
            } elseif (is_array($decoded) && !empty($decoded['message'])) {
                $errorMessage = (string)$decoded['message'];
            }
            throw new Exception($errorMessage, $httpCode);
        }

        if (!is_array($decoded)) {
            throw new Exception('Format respons API Aulaa tidak valid (bukan JSON valid).');
        }

        return $decoded;
    }
}
