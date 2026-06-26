<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Http\Identification;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;

/**
 * DomainTenantIdentifier — the storefront model: the tenant is the left-most
 * Host label once a configured base domain suffix is stripped.
 *
 *     acme.shop.localhost    ->  "acme"
 *     globex.shop.example    ->  "globex"
 *     shop.localhost         ->  ""      (apex = central / no tenant)
 *
 * This stage only PRODUCES the candidate id from the host; whether that id is a
 * real, routable tenant is decided downstream by the TenantRegistry (an unknown
 * id throws UnknownTenantException -> 404). No auth is involved, so domain mode
 * works for anonymous storefront traffic.
 *
 * Base domains are configured via TENANCY_BASE_DOMAINS (comma-separated). With
 * no base domain configured, the left-most label of a multi-label host is used.
 *
 * RESERVED sub-domains (www, api, admin, …) are NOT tenants: they map to the
 * central connection (return '') so infrastructure/marketing hosts keep working
 * instead of 404-ing as an unknown tenant. Configure via TENANCY_RESERVED_SUBDOMAINS.
 */
final class DomainTenantIdentifier implements TenantIdentifier
{
    /** @var string[] lower-cased base domains, longest first */
    private array $baseDomains;

    /** @var array<string,true> reserved labels that resolve to central, not a tenant */
    private array $reserved;

    /**
     * @param string[] $baseDomains e.g. ['shop.localhost', 'shop.example']
     * @param string[] $reserved    sub-domain labels that are never tenants (www, api, …)
     */
    public function __construct(array $baseDomains, array $reserved = [])
    {
        $normalised = array_map(
            static fn (string $d): string => strtolower(trim($d, '. ')),
            $baseDomains,
        );

        // Longest base domain first so 'a.shop.localhost' strips '.shop.localhost'
        // before a shorter, accidentally-matching suffix.
        usort($normalised, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $this->baseDomains = array_values(array_filter($normalised));

        $this->reserved = [];
        foreach ($reserved as $label) {
            $label = strtolower(trim($label));
            if ($label !== '') {
                $this->reserved[$label] = true;
            }
        }
    }

    public function identify(Request $request): string
    {
        $host = $this->normaliseHost($request->host());
        if ($host === '') {
            return '';
        }

        $label = $this->candidateLabel($host);

        // Reserved infra/marketing sub-domains are central, never a tenant.
        if ($label === null || isset($this->reserved[$label])) {
            return '';
        }

        return $label;
    }

    private function candidateLabel(string $host): ?string
    {
        foreach ($this->baseDomains as $base) {
            if ($host === $base) {
                return null; // apex == central, no tenant
            }

            if (str_ends_with($host, '.' . $base)) {
                $sub = substr($host, 0, -\strlen('.' . $base));

                return explode('.', $sub)[0] ?: null;
            }
        }

        // No base domain matched — treat the left-most label as the candidate,
        // but only when the host actually has more than one label.
        $first = explode('.', $host)[0];

        return $first !== $host ? $first : null;
    }

    private function normaliseHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('/:\d+$/', '', $host) ?? $host; // strip port
        $host = trim($host, '.');                            // strip trailing dot

        return $host;
    }
}
