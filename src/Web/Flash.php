<?php
// src/Web/Flash.php
//
// One-shot messages that survive a redirect.
//
// Every form posts and then redirects, so the browser's back button and a
// refresh never re-submit it. The result of the action therefore has to
// outlive the redirect, which is what this is for: written before the
// redirect, read once on the next page, then gone.

declare(strict_types=1);

namespace DcInfraSnap\Web;

final class Flash
{
    public const SUCCESS = 'success';
    public const ERROR   = 'error';
    public const INFO    = 'info';

    public static function add(string $type, string $message): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function success(string $message): void
    {
        self::add(self::SUCCESS, $message);
    }

    public static function error(string $message): void
    {
        self::add(self::ERROR, $message);
    }

    public static function info(string $message): void
    {
        self::add(self::INFO, $message);
    }

    /**
     * Everything pending, cleared as it is read.
     *
     * @return list<array{type: string, message: string}>
     */
    public static function take(): array
    {
        $messages = $_SESSION['flash'] ?? [];

        unset($_SESSION['flash']);

        return is_array($messages) ? array_values($messages) : [];
    }
}
