<?php
declare(strict_types=1);

namespace ProCast\Support;

/** Live health ping for the forecasting (ML) engine. Transport injectable for tests. */
final class HealthCheck
{
    /** @var (callable(string):array{0:int,1:string,2:float})|null  returns [httpCode, body, latencyMs] */
    private static $transport = null;

    public static function setTransport(?callable $t): void
    {
        self::$transport = $t;
    }

    /** @return array{status:string,up:bool,latency_ms:?int,http_code:?int,model_available:?bool,detail:string,checked_at:string} */
    public static function forecastingEngine(): array
    {
        $url = (string)Env::get('ML_API_HEALTH_URL', 'https://pos-ml-api.onrender.com/health');
        $checkedAt = gmdate('c');

        try {
            [$code, $body, $ms] = self::$transport !== null ? (self::$transport)($url) : self::curl($url);
        } catch (\Throwable $t) {
            return ['status' => 'down', 'up' => false, 'latency_ms' => null, 'http_code' => null, 'model_available' => null, 'detail' => 'Probe failed', 'checked_at' => $checkedAt];
        }

        if ($code === 0) {
            return ['status' => 'down', 'up' => false, 'latency_ms' => null, 'http_code' => null, 'model_available' => null, 'detail' => 'Unreachable (timeout or DNS). Render free instances may be cold-starting.', 'checked_at' => $checkedAt];
        }

        $up = $code >= 200 && $code < 300;
        $model = null;
        $json = json_decode($body, true);
        if (is_array($json)) {
            foreach (['model_loaded', 'models_loaded', 'model_available', 'model_ready', 'ready'] as $k) {
                if (array_key_exists($k, $json)) {
                    $model = (bool)$json[$k];
                    break;
                }
            }
            if ($model === null && isset($json['status']) && is_string($json['status'])) {
                $model = in_array(strtolower($json['status']), ['ok', 'healthy', 'up', 'ready'], true) ? true : null;
            }
        }
        $lat = (int)round($ms);
        $status = !$up ? 'down' : ($lat > 2500 ? 'degraded' : 'operational');
        return [
            'status' => $status, 'up' => $up, 'latency_ms' => $lat, 'http_code' => $code,
            'model_available' => $model, 'detail' => $up ? 'Responding' : 'HTTP ' . $code, 'checked_at' => $checkedAt,
        ];
    }

    /** @return array{0:int,1:string,2:float} */
    private static function curl(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ]);
        $t0 = microtime(true);
        $body = curl_exec($ch);
        $ms = (microtime(true) - $t0) * 1000;
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, is_string($body) ? substr($body, 0, 4096) : '', $ms];
    }
}
