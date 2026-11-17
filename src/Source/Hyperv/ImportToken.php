<?php
// src/Source/Hyperv/ImportToken.php
//
// The token that ties a collector file to the source it belongs to.
//
// The likeliest mistake with an offline source is a human one: collecting a
// lab cluster and uploading it into the production source. Nothing in the
// file itself prevents that — two clusters look alike — so the script that
// gets downloaded from a source carries a token, writes it into its output,
// and the upload refuses a file whose token belongs somewhere else.
//
// DERIVED, NOT STORED. The token is an HMAC of the source's id under
// SECRET_KEY, so there is no column to add, nothing to rotate and nothing to
// leak from the database that is not already protected by the key. Change
// SECRET_KEY and every old file stops matching, which is the same thing that
// already happens to stored credentials.
//
// It is not a secret in the sense a password is: it says "this file was
// collected for that source", not "whoever holds this may write". Uploading
// still requires signing in.

declare(strict_types=1);

namespace DcInfraSnap\Source\Hyperv;

use SensitiveParameter;

final class ImportToken
{
    /** What the script writes when it was run without being downloaded. */
    public const MANUAL = 'MANUAL';

    private const PREFIX = 'dcinfrasnap-hyperv-import:';

    /** Long enough that it cannot be guessed, short enough to read in a file. */
    private const LENGTH = 32;

    public static function for(int $integrationId, #[SensitiveParameter] string $secretKey): string
    {
        return substr(
            hash_hmac('sha256', self::PREFIX . $integrationId, $secretKey),
            0,
            self::LENGTH
        );
    }

    /**
     * Whether a token found in a file belongs to this source.
     *
     * MANUAL is accepted deliberately: the script can be run straight from
     * the repository, which is exactly what happens while the integration is
     * being set up. The caller says so out loud rather than treating it as
     * verified.
     */
    public static function matches(
        ?string $found,
        int $integrationId,
        #[SensitiveParameter] string $secretKey,
    ): bool {
        if ($found === null || $found === '') {
            return false;
        }

        if ($found === self::MANUAL) {
            return true;
        }

        return hash_equals(self::for($integrationId, $secretKey), $found);
    }

    public static function isManual(?string $found): bool
    {
        return $found === null || $found === '' || $found === self::MANUAL;
    }
}
