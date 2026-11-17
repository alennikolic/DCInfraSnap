<?php
// src/Report/Zip.php
//
// A ZIP archive built by hand, because a .docx is a ZIP of XML parts and the
// container has no zip extension.
//
// The alternative was adding the extension to docker/php/Dockerfile and
// rebuilding the image. This is the other choice Alen made: about a hundred
// lines of our own, and no rebuild.
//
// WHAT A ZIP IS, as far as this needs to care. For each file: a local header
// followed by the bytes. Then a central directory, one record per file,
// repeating most of the header and adding where the local one started. Then
// an end-of-central-directory record saying how many entries there are and
// where the directory begins. A reader can work from the directory alone,
// which is why the offsets in it have to be right.
//
// DEFLATE WHEN ZLIB IS THERE. gzdeflate() produces exactly the raw deflate
// stream method 8 wants, and zlib is compiled into PHP almost everywhere. If
// it is ever missing, every entry is stored instead: a bigger file that every
// reader still opens. The CRC and the uncompressed size are the same either
// way, which is what keeps the fallback honest.
//
// WHAT THIS DELIBERATELY DOES NOT DO: Zip64, encryption, directory entries,
// streaming. A report is a handful of XML parts well under the 4 GB and
// 65535-entry limits where any of that would start to matter.

declare(strict_types=1);

namespace DcInfraSnap\Report;

use RuntimeException;

final class Zip
{
    private const METHOD_STORE   = 0;
    private const METHOD_DEFLATE = 8;

    /** Version needed to extract: 2.0, which is what deflate requires. */
    private const VERSION = 20;

    private string $entries = '';

    /** @var list<string> central directory records, in the order added */
    private array $directory = [];

    private int $offset = 0;

    /** @var list<string> names already added, so a part cannot be added twice */
    private array $names = [];

    private readonly int $dosTime;
    private readonly int $dosDate;

    /**
     * @param int|null $timestamp the modification time stamped on every
     *                            entry; now when not given
     */
    public function __construct(?int $timestamp = null)
    {
        $timestamp ??= time();

        $parts = getdate($timestamp);

        // DOS packs a timestamp into two 16-bit words, with two-second
        // resolution and an epoch of 1980. A date before that cannot be
        // represented, so it is clamped rather than wrapping into nonsense.
        $year = max(1980, (int) $parts['year']);

        $this->dosTime = ((int) $parts['hours'] << 11)
            | ((int) $parts['minutes'] << 5)
            | intdiv((int) $parts['seconds'], 2);

        $this->dosDate = (($year - 1980) << 9)
            | ((int) $parts['mon'] << 5)
            | (int) $parts['mday'];
    }

    /**
     * Add one file.
     *
     * @param string $name    the path inside the archive, e.g. word/document.xml
     * @param string $content the bytes
     */
    public function add(string $name, string $content): self
    {
        if (in_array($name, $this->names, true)) {
            throw new RuntimeException("The archive already contains {$name}.");
        }

        $this->names[] = $name;

        $crc            = crc32($content);
        $uncompressed   = strlen($content);
        $method         = self::METHOD_STORE;
        $payload        = $content;

        if (function_exists('gzdeflate')) {
            // Level 9: the parts are small and this runs once per download.
            $deflated = gzdeflate($content, 9);

            // Deflate can come out larger than the input on tiny or already
            // dense data, in which case storing it is both smaller and
            // simpler.
            if ($deflated !== false && strlen($deflated) < $uncompressed) {
                $method  = self::METHOD_DEFLATE;
                $payload = $deflated;
            }
        }

        $compressed = strlen($payload);

        $header = pack(
            'VvvvvvVVVvv',
            0x04034b50,          // local file header signature
            self::VERSION,       // version needed to extract
            0,                   // general purpose flags
            $method,
            $this->dosTime,
            $this->dosDate,
            $crc,
            $compressed,
            $uncompressed,
            strlen($name),
            0                    // extra field length
        ) . $name;

        $this->entries .= $header . $payload;

        $this->directory[] = pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50,          // central directory signature
            self::VERSION,       // version made by
            self::VERSION,       // version needed to extract
            0,                   // general purpose flags
            $method,
            $this->dosTime,
            $this->dosDate,
            $crc,
            $compressed,
            $uncompressed,
            strlen($name),
            0,                   // extra field length
            0,                   // file comment length
            0,                   // disk number start
            0,                   // internal file attributes
            0,                   // external file attributes
            $this->offset        // where this entry's local header begins
        ) . $name;

        $this->offset += strlen($header) + $compressed;

        return $this;
    }

    /**
     * The finished archive.
     */
    public function finish(): string
    {
        if ($this->directory === []) {
            throw new RuntimeException('The archive is empty.');
        }

        $directory = implode('', $this->directory);
        $count     = count($this->directory);

        $end = pack(
            'VvvvvVVv',
            0x06054b50,            // end of central directory signature
            0,                     // this disk's number
            0,                     // the disk the directory starts on
            $count,                // entries on this disk
            $count,                // entries in total
            strlen($directory),
            $this->offset,         // where the directory begins
            0                      // archive comment length
        );

        return $this->entries . $directory . $end;
    }
}
