<?php
// src/Source/Brocade/SshTransport.php
//
// Runs commands on a Brocade switch over SSH.
//
// Why the ssh client rather than a PHP SSH library: the application has no
// third-party PHP dependencies and runs without a vendor/ folder, and the
// ssh client handles the legacy key exchanges that old FOS releases insist on
// through ordinary command-line options. The cost is a subprocess per
// collection, which is nothing next to the login itself.
//
// THE PASSWORD NEVER APPEARS ON A COMMAND LINE. sshpass -p puts it in the
// process list, where any user on the host can read it with ps. It is passed
// through the environment instead and read with sshpass -e.
//
// ONE LOGIN PER COLLECTION. A Brocade login takes a second or two, so the
// commands are sent as one semicolon-separated batch and the combined output
// is split afterwards (see FosSession). Measured on FOS 9.2.2d: the batch
// form returns every command's output in order.

declare(strict_types=1);

namespace DcInfraSnap\Source\Brocade;

use SensitiveParameter;

// Not final so a test can substitute a transport that replays recorded
// output instead of connecting to a switch.
class SshTransport
{
    /** Seconds to wait for the TCP connection and the login. */
    private const CONNECT_TIMEOUT = 15;

    /**
     * Algorithms current OpenSSH has disabled but old FOS releases still
     * require. Added only on the retry, never on the first attempt: enabling
     * them everywhere would silently weaken connections to switches that do
     * not need them.
     */
    private const LEGACY_OPTIONS = [
        'KexAlgorithms=+diffie-hellman-group1-sha1,diffie-hellman-group14-sha1',
        'HostKeyAlgorithms=+ssh-rsa,ssh-dss',
        'PubkeyAcceptedAlgorithms=+ssh-rsa',
        'Ciphers=+aes128-cbc,3des-cbc',
        'MACs=+hmac-sha1',
    ];

    /** Whether the last successful connection needed the legacy set. */
    private bool $usedLegacy = false;

    private int $commandCount = 0;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        #[SensitiveParameter] private readonly string $password,
        private readonly bool $verifyHostKey = false,
        private readonly int $timeout = 120,

        // Injectable so a test can point at a stub instead of a real client.
        private readonly string $sshBinary = 'ssh',
        private readonly string $sshpassBinary = 'sshpass',
    ) {
    }

    public function usedLegacyAlgorithms(): bool
    {
        return $this->usedLegacy;
    }

    public function commandCount(): int
    {
        return $this->commandCount;
    }

    /**
     * Run one or more FOS commands in a single login and return the combined
     * output.
     *
     * @param list<string> $commands
     */
    public function run(array $commands): string
    {
        if ($commands === []) {
            return '';
        }

        foreach ($commands as $command) {
            // The commands come from a fixed list in the collector, never
            // from a request. Checking anyway costs nothing and means a typo
            // cannot turn into something the switch executes.
            if (preg_match('/^[a-zA-Z][\w -]*$/', $command) !== 1) {
                throw BrocadeException::commandFailed($command, 'not a plain FOS command.');
            }
        }

        $this->commandCount += count($commands);

        $batch = implode('; ', $commands);

        // First attempt with the client's own defaults.
        [$stdout, $stderr, $exit] = $this->execute($batch, legacy: false);

        if ($exit === 0 && trim($stdout) !== '') {
            return $stdout;
        }

        // A failure to agree on algorithms is the one worth retrying: it says
        // the switch is old, not that anything is wrong.
        if (self::looksLikeAlgorithmMismatch($stderr)) {
            [$stdout, $stderr, $exit] = $this->execute($batch, legacy: true);

            if ($exit === 0 && trim($stdout) !== '') {
                $this->usedLegacy = true;

                return $stdout;
            }

            if (self::looksLikeAlgorithmMismatch($stderr)) {
                throw BrocadeException::noSharedAlgorithm($this->host, self::firstLine($stderr));
            }
        }

        throw $this->translate($stderr, $exit);
    }

    // -----------------------------------------------------------------

    /**
     * @return array{0: string, 1: string, 2: int} stdout, stderr, exit code
     */
    private function execute(string $batch, bool $legacy): array
    {
        $options = [
            'ConnectTimeout=' . self::CONNECT_TIMEOUT,

            // One prompt only. Without this a wrong password is retried three
            // times before failing, which on a switch with account lockout is
            // how a typo locks the account.
            'NumberOfPasswordPrompts=1',

            'PreferredAuthentications=password,keyboard-interactive',
            'PubkeyAuthentication=no',
            'LogLevel=ERROR',
        ];

        if ($this->verifyHostKey) {
            $options[] = 'StrictHostKeyChecking=yes';
        } else {
            // Not written to a known_hosts file at all: the container is
            // rebuilt regularly, and a file that silently resets would make
            // verification look enabled while proving nothing.
            $options[] = 'StrictHostKeyChecking=no';
            $options[] = 'UserKnownHostsFile=/dev/null';
            $options[] = 'GlobalKnownHostsFile=/dev/null';
        }

        if ($legacy) {
            foreach (self::LEGACY_OPTIONS as $option) {
                $options[] = $option;
            }
        }

        $argv = [$this->sshpassBinary, '-e', $this->sshBinary];

        foreach ($options as $option) {
            $argv[] = '-o';
            $argv[] = $option;
        }

        $argv[] = '-p';
        $argv[] = (string) $this->port;
        $argv[] = $this->username . '@' . $this->host;
        $argv[] = $batch;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open(
            $argv,
            $descriptors,
            $pipes,
            null,

            // A deliberately small environment: the password, plus the two
            // variables the client genuinely needs.
            //
            // PATH is not optional. With an empty environment the child
            // cannot resolve "sshpass" or "ssh" by name, and the failure
            // reads as an unreachable switch rather than a missing PATH.
            // HOME is what ssh uses to find its own configuration.
            [
                'SSHPASS' => $this->password,
                'PATH'    => getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
                'HOME'    => getenv('HOME') ?: '/tmp',
            ],
        );

        if (!is_resource($process)) {
            throw BrocadeException::missingTool($this->sshpassBinary . ' or ' . $this->sshBinary);
        }

        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout   = '';
        $stderr   = '';
        $deadline = microtime(true) + $this->timeout;

        while (true) {
            $status = proc_get_status($process);

            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);

            if (!$status['running']) {
                break;
            }

            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                throw BrocadeException::timedOut($this->host, $this->timeout);
            }

            // Short sleep rather than a blocking read: a blocking read on one
            // pipe can deadlock while the other pipe fills and the child
            // waits for it to drain.
            usleep(50_000);
        }

        // Anything written between the last read and the exit.
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($process);

        return [$stdout, $stderr, $exit];
    }

    private static function looksLikeAlgorithmMismatch(string $stderr): bool
    {
        foreach ([
            'no matching key exchange method',
            'no matching host key type',
            'no matching cipher',
            'no matching MAC',
            'Unable to negotiate',
        ] as $needle) {
            if (stripos($stderr, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function translate(string $stderr, int $exit): BrocadeException
    {
        $text = strtolower($stderr);

        // sshpass reports authentication failure as exit code 5.
        if ($exit === 5 || str_contains($text, 'permission denied')) {
            return BrocadeException::badCredentials($this->host);
        }

        if (str_contains($text, 'remote host identification has changed')) {
            return BrocadeException::hostKeyChanged($this->host);
        }

        if (str_contains($text, 'host key verification failed')) {
            return BrocadeException::hostKeyUnknown($this->host);
        }

        if (str_contains($text, 'connection refused')) {
            return BrocadeException::unreachable($this->host, 'connection refused on port ' . $this->port);
        }

        if (str_contains($text, 'could not resolve') || str_contains($text, 'name or service not known')) {
            return BrocadeException::unreachable($this->host, 'the name does not resolve');
        }

        if (str_contains($text, 'connection timed out') || str_contains($text, 'operation timed out')) {
            return BrocadeException::unreachable($this->host, 'timed out');
        }

        if (str_contains($text, 'no route to host')) {
            return BrocadeException::unreachable($this->host, 'no route to host');
        }

        $detail = self::firstLine($stderr);

        return BrocadeException::unreachable(
            $this->host,
            $detail === '' ? 'ssh exited with code ' . $exit : $detail
        );
    }

    private static function firstLine(string $text): string
    {
        foreach (preg_split('/\R/', trim($text)) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '') {
                return mb_substr($line, 0, 200);
            }
        }

        return '';
    }
}
