<?php

declare(strict_types=1);

/** Consistent JSON response envelope. */
class Response
{
    public static function json($data = null, int $status = 200, ?string $error = null): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success' => $error === null,
            'data'    => $data,
            'error'   => $error,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function ok($data = null, int $status = 200): void
    {
        self::json($data, $status);
    }

    public static function created($data = null): void
    {
        self::json($data, 201);
    }

    public static function error(string $message, int $status = 400, $data = null): void
    {
        self::json($data, $status, $message);
    }

    /** Stream an array of rows as an Excel-friendly CSV download. */
    public static function csv(string $filename, array $headers, array $rows): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fprintf($out, "\xEF\xBB\xBF"); // BOM so Excel reads UTF-8
        fputcsv($out, $headers);
        foreach ($rows as $r) {
            fputcsv($out, $r);
        }
        fclose($out);
        exit;
    }
}
