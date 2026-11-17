<?php
// src/Source/Hyperv/CollectorScript.php
//
// The PowerShell collector, handed out with the source's token in it.
//
// There is one copy of the script, in resources/collector/, and it is the
// same file that lives in the repository and gets edited. The only thing
// that happens on the way out is that the token placeholder is replaced, so
// a downloaded script and the committed one never drift apart.
//
// Downloaded with the token already in place rather than asking the person
// to paste it in: a step that can be skipped is a step that gets skipped,
// and then the upload cannot tell which source the file belongs to.

declare(strict_types=1);

namespace DcInfraSnap\Source\Hyperv;

use RuntimeException;
use SensitiveParameter;

final class CollectorScript
{
    public const PLACEHOLDER = '__SOURCE_TOKEN__';

    private const RELATIVE_PATH = '/resources/collector/dcinfrasnap-hyperv.ps1';

    public function __construct(private readonly string $root)
    {
    }

    public function path(): string
    {
        return $this->root . self::RELATIVE_PATH;
    }

    public function exists(): bool
    {
        return is_file($this->path()) && is_readable($this->path());
    }

    /**
     * The script as it should be saved on the host.
     *
     * The placeholder is replaced even when it appears more than once, and
     * its absence is an error rather than a silent no-op: a script without a
     * token produces files the upload cannot attribute.
     */
    public function forIntegration(int $integrationId, #[SensitiveParameter] string $secretKey): string
    {
        if (!$this->exists()) {
            throw new RuntimeException(
                'The collector script is missing from resources/collector/.'
            );
        }

        $script = file_get_contents($this->path());

        if ($script === false) {
            throw new RuntimeException('The collector script could not be read.');
        }

        if (!str_contains($script, self::PLACEHOLDER)) {
            throw new RuntimeException(
                'The collector script has no ' . self::PLACEHOLDER . ' to replace.'
            );
        }

        return str_replace(
            self::PLACEHOLDER,
            ImportToken::for($integrationId, $secretKey),
            $script
        );
    }

    /**
     * What the browser should call the downloaded file.
     *
     * A fixed name, and not one built from the source's: the page tells the
     * person to run ".\\dcinfrasnap-hyperv.ps1", and a download called
     * anything else makes that instruction wrong. The token inside the file
     * is what ties it to a source, so the name does not have to.
     *
     * Downloading the script for a second source into the same folder
     * therefore lands on the browser's own "(1)" suffix, which is visible
     * rather than silent.
     */
    public function downloadName(): string
    {
        return 'dcinfrasnap-hyperv.ps1';
    }
}
