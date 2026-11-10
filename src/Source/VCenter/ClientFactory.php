<?php
// src/Source/VCenter/ClientFactory.php
//
// Assembles a client for one source.
//
// There is nothing to choose between here yet — every vCenter is read over
// the vSphere Web Services API (vim25, /sdk). The factory exists anyway, for
// two reasons: a test substitutes the transport through soapTransport(), and
// when other kinds of source arrive this is where their client gets picked.
//
// Why vim25 and not the REST API: vim25 is the full API and REST is a subset
// of it. REST does not return VM templates, has no host hardware, needs one
// call per machine for the instance UUID and another for guest data, only
// exists from 7.0U2, and caps out at 4000 objects.

declare(strict_types=1);

namespace DcInfraSnap\Source\VCenter;

use DcInfraSnap\Integration\Integration;
use SensitiveParameter;

class ClientFactory
{
    public function __construct(
        private readonly int $timeout = 30,
    ) {
    }

    /**
     * A client for this source, not yet signed in.
     */
    public function make(
        Integration $integration,
        #[SensitiveParameter] string $password,
    ): Vim25Client {
        return new Vim25Client(
            $this->soapTransport($integration),
            $integration->username,
            $password,
        );
    }

    /**
     * A signed-in client, ready to work.
     */
    public function connect(
        Integration $integration,
        #[SensitiveParameter] string $password,
    ): Vim25Client {
        $client = $this->make($integration, $password);
        $client->login();

        return $client;
    }

    /**
     * The transport for one source.
     *
     * Its own method so the factory can be tested: a test substitutes a
     * transport that replays a recorded response instead of calling a real
     * vCenter. That is also why this class is not final.
     */
    protected function soapTransport(Integration $integration): SoapTransport
    {
        return new SoapTransport(
            baseUrl: $integration->baseUrl(),
            verifyPeer: $integration->verifyPeer,
            host: $integration->hostname,
            timeout: $this->timeout,
        );
    }
}
