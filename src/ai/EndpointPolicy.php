<?php

namespace Tahadudhiya\AltMonitor\ai;

use Craft;

/**
 * Decides whether AltMonitor may connect to an AI endpoint, so image data only ever goes to a
 * service someone deliberately trusted.
 *
 * Only `http`/`https` base URLs without credentials, query or fragment are accepted, and only for
 * a host on the allowlist, which is set in `config/alt-monitor.php` alone. Loopback and private
 * addresses are allowed, since a local Ollama is the point; addresses no AI service lives at
 * (unspecified, link-local and cloud metadata, multicast, reserved) are refused even for an
 * allowed host. Connections are pinned to the addresses that were checked, so DNS cannot change
 * where a request goes between the check and the connection.
 */
final class EndpointPolicy
{
    /** Ranges never connected to, whatever host resolves to them. */
    private const BLOCKED_RANGES = [
        '0.0.0.0/8',
        '169.254.0.0/16',
        '100.100.100.200/32',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '::/128',
        'fe80::/10',
        'ff00::/8',
        'fd00:ec2::254/128',
    ];

    /** @var list<string> */
    private readonly array $allowedHosts;

    /**
     * @param list<string> $allowedHosts
     * @param (callable(string): list<string>)|null $resolver Resolves a host name to its addresses.
     */
    public function __construct(array $allowedHosts, private $resolver = null)
    {
        $this->allowedHosts = array_map(static fn(string $host): string => self::normalizeHost($host), $allowedHosts);
    }

    /**
     * Why the URL may not be used, from the URL alone; null when it may.
     */
    public function problem(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return Craft::t('alt-monitor', 'The endpoint must be a full URL, such as http://localhost:11434.');
        }

        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return Craft::t('alt-monitor', 'The endpoint must use http or https.');
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path'] ?? '', ['', '/'], true)) {
            return Craft::t('alt-monitor', 'The endpoint must be a base URL alone, without a path, credentials, query or fragment.');
        }

        $host = self::normalizeHost($parts['host']);

        if (!in_array($host, $this->allowedHosts, true)) {
            return Craft::t('alt-monitor', 'The host “{host}” is not allowed. Add it to allowedAiHosts in config/alt-monitor.php to trust it.', ['host' => $host]);
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false && self::isBlocked($host)) {
            return Craft::t('alt-monitor', 'The endpoint’s address is one AltMonitor never connects to.');
        }

        return null;
    }

    /**
     * The addresses the URL's host resolves to, every one of them checked.
     *
     * @return list<string>
     * @throws \InvalidArgumentException with a message safe to show, when the URL may not be used.
     */
    public function resolve(string $url): array
    {
        $problem = $this->problem($url);

        if ($problem !== null) {
            throw new \InvalidArgumentException($problem);
        }

        $host = self::normalizeHost((string)parse_url($url, PHP_URL_HOST));
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolver ?? self::lookup(...))($host);

        if ($addresses === []) {
            throw new \InvalidArgumentException(Craft::t('alt-monitor', 'The host “{host}” could not be resolved.', ['host' => $host]));
        }

        foreach ($addresses as $address) {
            if (self::isBlocked($address)) {
                throw new \InvalidArgumentException(Craft::t('alt-monitor', 'The endpoint’s address is one AltMonitor never connects to.'));
            }
        }

        return $addresses;
    }

    public static function isBlocked(string $address): bool
    {
        $packed = @inet_pton($address);

        if ($packed === false) {
            return true;
        }

        // An IPv4-mapped IPv6 address is judged as the IPv4 address it carries.
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            $packed = substr($packed, 12);
        }

        foreach (self::BLOCKED_RANGES as $range) {
            [$network, $bits] = explode('/', $range);
            $networkPacked = (string)inet_pton($network);

            if (strlen($networkPacked) === strlen($packed) && self::inRange($packed, $networkPacked, (int)$bits)) {
                return true;
            }
        }

        return false;
    }

    private static function inRange(string $address, string $network, int $bits): bool
    {
        $bytes = intdiv($bits, 8);

        if (substr($address, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }

        $remainder = $bits % 8;

        if ($remainder === 0) {
            return true;
        }

        $mask = 0xff << (8 - $remainder) & 0xff;

        return (ord($address[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }

    private static function normalizeHost(string $host): string
    {
        return strtolower(trim($host, '[] '));
    }

    /**
     * @return list<string>
     */
    private static function lookup(string $host): array
    {
        $addresses = gethostbynamel($host) ?: [];
        $records = @dns_get_record($host, DNS_AAAA) ?: [];

        foreach ($records as $record) {
            if (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}
