<?php
// src/Source/Brocade/ClientFactory.php
//
// Assembles a client for one Brocade switch.
//
// Mirrors the vCenter factory, for the same two reasons: a test substitutes
// the transport through sshTransport(), and when a second kind of FC switch
// arrives this is where its client gets chosen.

declare(strict_types=1);

namespace DcInfraSnap\Source\Brocade;

use DcInfraSnap\Integration\Integration;
use SensitiveParameter;

class ClientFactory
{
    public function __construct(
        private readonly int $timeout = 120,
    ) {
    }

    public function make(
        Integration $integration,
        #[SensitiveParameter] string $password,
    ): FosClient {
        return new FosClient($this->sshTransport($integration, $password));
    }

    /**
     * Its own method so a test can replay recorded output instead of
     * connecting to a switch. That is also why this class is not final.
     */
    protected function sshTransport(
        Integration $integration,
        #[SensitiveParameter] string $password,
    ): SshTransport {
        return new SshTransport(
            host: $integration->hostname,
            port: $integration->port,
            username: $integration->username,
            password: $password,

            // The column means "verify the peer's identity"; for SSH that is
            // the host key rather than a certificate.
            verifyHostKey: $integration->verifyPeer,

            timeout: $this->timeout,
        );
    }
}
