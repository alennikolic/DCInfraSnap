<?php
// src/Source/Hyperv/HypervException.php
//
// What went wrong with an uploaded collector file, in words that say what to
// do about it. These messages reach the person who uploaded the file, so they
// name the file and the reason rather than a parser internal.

declare(strict_types=1);

namespace DcInfraSnap\Source\Hyperv;

use RuntimeException;

final class HypervException extends RuntimeException
{
    public static function unreadable(string $label): self
    {
        return new self("{$label} could not be read.");
    }

    public static function empty(string $label): self
    {
        return new self("{$label} is empty.");
    }

    public static function notJson(string $label, string $reason): self
    {
        return new self("{$label} is not valid JSON: {$reason}");
    }

    public static function notACollectorFile(string $label): self
    {
        return new self(
            "{$label} was not produced by the DCInfraSnap Hyper-V collector. "
            . 'Download the script from the integration and run that.'
        );
    }

    public static function unsupportedSchema(string $label, int $found, int $supported): self
    {
        return new self(
            "{$label} uses payload schema {$found}, and this build understands {$supported}. "
            . 'Download the collector script again.'
        );
    }

    public static function missing(string $label, string $what): self
    {
        return new self("{$label} is incomplete: {$what} is missing.");
    }

    public static function mixedKinds(): self
    {
        return new self(
            'The uploaded files do not describe the same thing: some were '
            . 'collected from a cluster node and some from a standalone host.'
        );
    }

    public static function differentClusters(string $a, string $b): self
    {
        return new self(
            "The uploaded files come from two different clusters ({$a} and {$b}). "
            . 'One integration is one cluster.'
        );
    }

    public static function duplicateNode(string $host): self
    {
        return new self(
            "There are two files from {$host}. Remove the older one before importing."
        );
    }

    public static function severalStandaloneHosts(): self
    {
        return new self(
            'A standalone source is one host, but the uploaded files come from '
            . 'several. Add one integration per host.'
        );
    }
}
