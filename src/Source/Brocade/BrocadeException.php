<?php
// src/Source/Brocade/BrocadeException.php
//
// Anything that goes wrong talking to a Brocade switch.
//
// The named constructors exist so the message says what to do about it.
// "Connection failed" is useless when the real answers are "the password is
// wrong", "the host key changed" and "this switch is too old to negotiate a
// modern key exchange" — three different problems with three different fixes.

declare(strict_types=1);

namespace DcInfraSnap\Source\Brocade;

use RuntimeException;

final class BrocadeException extends RuntimeException
{
    public static function unreachable(string $host, string $detail): self
    {
        return new self("Cannot reach {$host}: {$detail}.");
    }

    public static function badCredentials(string $host): self
    {
        return new self("{$host} rejected the credentials: wrong username or password.");
    }

    public static function hostKeyChanged(string $host): self
    {
        return new self(
            "The SSH host key of {$host} does not match the one recorded. Either the switch "
            . 'was replaced or reinstalled, or the connection is being intercepted. Turn off '
            . 'host key verification for this source only if you know which it is.'
        );
    }

    public static function hostKeyUnknown(string $host): self
    {
        return new self(
            "The SSH host key of {$host} is not yet known. Turn off host key verification for "
            . 'this source, or add the key to the container first.'
        );
    }

    /**
     * No shared algorithm. Old FOS releases offer only key exchanges and
     * ciphers that current OpenSSH has disabled by default.
     */
    public static function noSharedAlgorithm(string $host, string $detail): self
    {
        return new self(
            "{$host} and the SSH client share no usable algorithm, even with the legacy set "
            . "enabled: {$detail}"
        );
    }

    public static function timedOut(string $host, int $seconds): self
    {
        return new self("{$host} did not finish within {$seconds} seconds.");
    }

    public static function missingTool(string $tool): self
    {
        return new self(
            "{$tool} is not installed in the container. Rebuild the image: "
            . 'docker compose up -d --build'
        );
    }

    /**
     * The switch answered, but not like a Brocade.
     */
    public static function notBrocade(string $host): self
    {
        return new self(
            "{$host} accepted the login but did not answer like a Brocade switch: the reply to "
            . 'switchshow carries no switchName or switchWwn. Check that this is an FC switch '
            . 'and not something else on port 22.'
        );
    }

    public static function commandFailed(string $command, string $detail): self
    {
        return new self("The switch rejected \"{$command}\": {$detail}");
    }
}
