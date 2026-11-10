<?php
// src/Core/Secret.php
//
// Encryption of stored credentials: libsodium secretbox (XSalsa20-Poly1305).
//
// Authenticated encryption, so a tampered ciphertext fails to decrypt rather
// than returning rubbish. The nonce is random per message and is stored in
// front of the ciphertext — it is not a secret, it only has to be unique.
//
// The key lives in SECRET_KEY, outside the database. Whoever gets hold of a
// database dump alone cannot read the passwords in it.

declare(strict_types=1);

namespace DcInfraSnap\Core;

use RuntimeException;
use SensitiveParameter;

final class Secret
{
    private readonly string $key;

    /**
     * @param string|null $base64Key 32 bytes, base64 encoded
     */
    public function __construct(#[SensitiveParameter] ?string $base64Key)
    {
        if ($base64Key === null || $base64Key === '') {
            throw new RuntimeException(
                'SECRET_KEY is not set. Generate one with: '
                . 'docker compose run --rm app php bin/dcinfrasnap key:generate'
            );
        }

        $key = base64_decode($base64Key, true);

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException(
                'SECRET_KEY must be ' . SODIUM_CRYPTO_SECRETBOX_KEYBYTES
                . ' bytes in base64. Generate a valid one with: '
                . 'docker compose run --rm app php bin/dcinfrasnap key:generate'
            );
        }

        $this->key = $key;
    }

    /**
     * A new key, ready to be pasted into .env.
     */
    public static function generateKey(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    /**
     * @return string nonce + ciphertext, as raw bytes
     */
    public function encrypt(#[SensitiveParameter] string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return $nonce . sodium_crypto_secretbox($plaintext, $nonce, $this->key);
    }

    public function decrypt(string $blob): string
    {
        if (strlen($blob) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Encrypted value is too short to be valid.');
        }

        $nonce      = substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);

        if ($plaintext === false) {
            // Almost always one thing: SECRET_KEY has changed since the value
            // was written.
            throw new RuntimeException(
                'Cannot decrypt the stored credential. SECRET_KEY has most likely '
                . 'changed since it was saved; the credential has to be entered again.'
            );
        }

        return $plaintext;
    }
}
