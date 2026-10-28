<?php

declare(strict_types=1);

namespace SqlCanvas\Database;

use SqlCanvas\Http\ApiException;
use SqlCanvas\Support\Config;

final readonly class DestinationPolicy
{
    public function __construct(private Config $config)
    {
    }

    public function assertAllowed(ConnectionProfile $profile): void
    {
        $hosts = array_map('strtolower', (array) $this->config->get('security.allowed_connection_hosts', []));
        if (!in_array(strtolower($profile->host), $hosts, true)) {
            throw new ApiException(
                'destination_denied',
                'This database host is not permitted by the SQL Canvas deployment policy.',
                403,
            );
        }

        $ports = array_map('intval', (array) $this->config->get('security.allowed_connection_ports', []));
        if (!in_array($profile->port, $ports, true)) {
            throw new ApiException('destination_denied', 'This database port is not permitted by the SQL Canvas deployment policy.', 403);
        }

        if ($profile->socket !== null && $this->config->get('security.allow_socket_connections', false) !== true) {
            throw new ApiException('socket_denied', 'Socket connections are disabled by deployment policy.', 403);
        }

        if ($this->isRemote($profile->host)
            && $this->config->get('security.require_tls_for_remote', true) === true
            && !$profile->tlsEnabled) {
            throw new ApiException('tls_required', 'Verified TLS is required for remote database connections.', 403);
        }

        if ($profile->tlsEnabled && ($profile->tlsCaPath === null || !is_file($profile->tlsCaPath))) {
            throw new ApiException('tls_ca_invalid', 'Choose a readable CA certificate file for verified TLS.', 422);
        }
    }

    private function isRemote(string $host): bool
    {
        return !in_array(strtolower($host), ['127.0.0.1', '::1', 'localhost'], true);
    }
}

