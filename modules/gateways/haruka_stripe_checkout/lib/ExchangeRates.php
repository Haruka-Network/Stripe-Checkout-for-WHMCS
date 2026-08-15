<?php
/**
 * Haruka Stripe Checkout for WHMCS
 *
 * Fetches the community exchange-rate tables and converts between the WHMCS
 * invoice currency and the currency charged on Stripe.
 *
 * Tables are fetched on demand — only when the customer clicks the pay
 * button, never while rendering invoice pages — and memoised for the
 * duration of the request. No database persistence.
 *
 * @see https://github.com/DyAxy/NewExchangeRatesTable
 * @license MIT
 */

namespace HarukaStripeCheckout;

class ExchangeRates
{
    const BASE_URL = 'https://raw.githubusercontent.com/DyAxy/NewExchangeRatesTable/main/data/';

    /** @var array<string,array> Tables already fetched during this request */
    private static $tables = [];

    /**
     * Exchange rate multiplier: amount_in_$to = amount_in_$from * rate.
     *
     * @param string $from   Source ISO currency code (WHMCS invoice currency)
     * @param string $to     Target ISO currency code (Stripe charge currency)
     * @param string $source One of Config::EXCHANGE_SOURCES keys
     * @return float
     * @throws GatewayException when no usable rate can be determined
     */
    public static function rate($from, $to, $source)
    {
        $from = strtoupper(trim((string) $from));
        $to = strtoupper(trim((string) $to));

        if ($from === '' || $to === '') {
            throw new GatewayException('Missing currency code for exchange rate lookup.', 'error_exchange');
        }
        if ($from === $to) {
            return 1.0;
        }

        $table = self::table($source);

        if (!isset($table[$from]) || !isset($table[$to])) {
            throw new GatewayException(
                sprintf('Currency %s or %s missing from "%s" rate table.', $from, $to, $source),
                'error_exchange'
            );
        }

        $fromRate = $table[$from];
        $toRate = $table[$to];

        if (!is_numeric($fromRate) || !is_numeric($toRate) || $fromRate <= 0 || $toRate <= 0) {
            throw new GatewayException(
                sprintf('Invalid rate values for %s/%s in "%s" table.', $from, $to, $source),
                'error_exchange'
            );
        }

        return (float) $toRate / (float) $fromRate;
    }

    /**
     * @param string $source
     * @return array<string,float> Currency code => rate against the table base
     * @throws GatewayException
     */
    private static function table($source)
    {
        if (!isset(self::$tables[$source])) {
            self::$tables[$source] = self::fetch($source);
        }

        return self::$tables[$source];
    }

    /**
     * @param string $source
     * @return array<string,float>
     * @throws GatewayException
     */
    private static function fetch($source)
    {
        $url = self::BASE_URL . rawurlencode($source) . '.json';

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_ENCODING => '',
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'WHMCS-HarukaStripeCheckout/2.0',
            CURLOPT_FAILONERROR => true,
        ]);

        $body = curl_exec($curl);
        $error = curl_error($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($body === false) {
            throw new GatewayException('Exchange rate fetch failed: ' . $error, 'error_exchange');
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new GatewayException('Exchange rate fetch returned HTTP ' . $httpCode, 'error_exchange');
        }

        $json = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($json['data']) || !is_array($json['data'])) {
            throw new GatewayException('Malformed exchange rate table.', 'error_exchange');
        }

        return $json['data'];
    }
}
