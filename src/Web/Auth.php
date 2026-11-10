<?php
// src/Web/Auth.php
//
// Sign-in for the interface.
//
// One shared password, hashed with bcrypt and kept in APP_PASSWORD_HASH.
// There are no user accounts yet — that comes later, and when it does, this
// class is the only place the web layer asks "who is this", so the change
// stays contained.
//
// No password means the interface stays closed rather than open. A tool that
// lists every machine in the data centre should not be reachable by anyone
// who knows the address.

declare(strict_types=1);

namespace DcInfraSnap\Web;

use SensitiveParameter;

final class Auth
{
    /** Attempts allowed before the form stops accepting input for a while. */
    private const MAX_ATTEMPTS = 5;

    /** How long that lockout lasts, in seconds. */
    private const LOCKOUT_SECONDS = 60;

    public function __construct(
        #[SensitiveParameter] private readonly string $passwordHash,
        private readonly bool $isDev = true,
    ) {
    }

    // -----------------------------------------------------------------
    // Session
    // -----------------------------------------------------------------

    public function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',

            // Turn this on once the application is served over HTTPS. Set to
            // true here and in docker/php/php.ini at the same time.
            'secure'   => false,

            'path'     => '/',
        ]);

        session_name('dcinfrasnap');
        session_start();
    }

    // -----------------------------------------------------------------
    // State
    // -----------------------------------------------------------------

    /** Whether a password is configured at all. */
    public function isConfigured(): bool
    {
        return $this->passwordHash !== '';
    }

    public function isLoggedIn(): bool
    {
        return ($_SESSION['authenticated'] ?? false) === true;
    }

    /** Seconds left on the lockout, or 0 when there is none. */
    public function lockedForSeconds(): int
    {
        $until = (int) ($_SESSION['lockout_until'] ?? 0);

        return max(0, $until - time());
    }

    // -----------------------------------------------------------------
    // Signing in and out
    // -----------------------------------------------------------------

    /**
     * Check a password and, on success, mark the session as signed in.
     */
    public function attempt(#[SensitiveParameter] string $password): bool
    {
        if (!$this->isConfigured() || $this->lockedForSeconds() > 0) {
            return false;
        }

        if (!password_verify($password, $this->passwordHash)) {
            $attempts = (int) ($_SESSION['failed_attempts'] ?? 0) + 1;

            $_SESSION['failed_attempts'] = $attempts;

            if ($attempts >= self::MAX_ATTEMPTS) {
                $_SESSION['lockout_until']   = time() + self::LOCKOUT_SECONDS;
                $_SESSION['failed_attempts'] = 0;
            }

            return false;
        }

        // New session ID on sign-in, so a session ID captured beforehand is
        // worthless afterwards.
        session_regenerate_id(true);

        $_SESSION['authenticated']   = true;
        $_SESSION['logged_in_at']    = time();
        $_SESSION['failed_attempts'] = 0;
        $_SESSION['lockout_until']   = 0;

        return true;
    }

    public function logout(): void
    {
        // Keep the chosen language across sign-out: it is a display setting,
        // not a credential.
        $locale = $_SESSION['locale'] ?? null;

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();

            setcookie(
                session_name(),
                '',
                [
                    'expires'  => time() - 42000,
                    'path'     => $params['path'],
                    'domain'   => $params['domain'],
                    'secure'   => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => $params['samesite'] ?? 'Lax',
                ]
            );
        }

        session_destroy();

        if ($locale !== null) {
            $this->startSession();
            $_SESSION['locale'] = $locale;
        }
    }

    // -----------------------------------------------------------------
    // CSRF
    // -----------------------------------------------------------------

    /**
     * The token for this session, created on first use.
     *
     * One token per session rather than one per form: simpler, and it does
     * not break when someone has two tabs open.
     */
    public function csrfToken(): string
    {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['csrf_token'];
    }

    public function csrfValid(?string $token): bool
    {
        if ($token === null || !isset($_SESSION['csrf_token'])) {
            return false;
        }

        return hash_equals((string) $_SESSION['csrf_token'], $token);
    }
}
