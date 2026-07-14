<?php

declare(strict_types=1);

// Copy this file to "settings.php" and adjust it for the current account/project.
// Nothing here is secret (that lives in .env) — this is just naming/topology preferences.

// Change only this variable to reuse the project on another account/environment — every
// resource name below (VPC, security groups, ACLs) is derived from it automatically.
$projectName = 'myapp';

return [
    'projectName' => $projectName,

    'region' => 'sa-east-1',

    // How the network is exposed to the internet. Only "public-private-ipv4" is implemented
    // today (public + private subnet, optional NAT Gateway, ALB with a public IPv4 — the
    // classic model). "private-with-cloudfront" (fully private ALB, CloudFront in front via
    // a VPC Origin — no public IP cost, more secure) comes later without breaking this key.
    'networkProfile' => 'public-private-ipv4',

    'network' => [
        'cidrBlock' => '10.0.0.0/16',

        // How many subnets (= how many Availability Zones) to use per tier.
        // Validated at runtime against how many AZs the region above actually has
        // (not every region has 3+) — if you ask for more than exists, the tool
        // warns instead of failing partway through.
        'subnetsPerTier' => 2,

        // Size of each subnet (/24 = 256 IPs, plenty for most cases).
        // Each subnet's CIDR block is calculated automatically from 'cidrBlock'
        // above — no need to list them by hand, works for 1, 3, 6, or however
        // many AZs you set in 'subnetsPerTier'.
        'subnetMaskBits' => 24,

        'tiers' => [
            'web' => [
                'mapPublicIpOnLaunch' => true,
            ],
            'db' => [
                'mapPublicIpOnLaunch' => false,
            ],
        ],
    ],

    'securityGroups' => [
        'web' => [
            'name' => "sg_{$projectName}_web",
            'description' => 'Allow HTTP/HTTPS for everyone, SSH for my IP',
            // 'source' accepts: 'my-ip' (resolved at runtime), an explicit CIDR,
            // or 'security-group:web'/'security-group:db' to allow another security
            // group from this same config instead of an IP.
            'ingress' => [
                ['protocol' => 'tcp', 'port' => 22, 'source' => 'my-ip'],
                ['protocol' => 'tcp', 'port' => 80, 'source' => '0.0.0.0/0'],
                ['protocol' => 'tcp', 'port' => 443, 'source' => '0.0.0.0/0'],
            ],
        ],
        'db' => [
            'name' => "sg_{$projectName}_db",
            'description' => 'Allow port 3306 from the web security group and my IP',
            'ingress' => [
                ['protocol' => 'tcp', 'port' => 3306, 'source' => 'security-group:web'],
                ['protocol' => 'tcp', 'port' => 3306, 'source' => 'my-ip'],
            ],
        ],
    ],

    // 'servicePorts' = ports this tier accepts connections on (inbound rule).
    // 'outboundPorts' = ports this tier needs to reach on another tier/service
    // (outbound rule). The ephemeral return-traffic ports (1024-65535) are added
    // automatically in both directions — without them, response traffic is silently
    // dropped (that was exactly what was missing from the database ACL in the old code).
    'networkAcls' => [
        'web' => [
            'name' => "acl-{$projectName}-web",
            'servicePorts' => [22, 80, 443],
            'outboundPorts' => [3306],
        ],
        'db' => [
            'name' => "acl-{$projectName}-db",
            'servicePorts' => [3306],
            'outboundPorts' => [],
        ],
    ],

    // The 'web' table gets the 0.0.0.0/0 route to the Internet Gateway (that's what
    // actually makes the subnet public); 'db' has no internet route by default.
    'routeTables' => [
        'web' => ['name' => "rt-{$projectName}-web"],
        'db' => ['name' => "rt-{$projectName}-db"],
    ],

    // Leave empty to skip provisioning any ALB/certificate (e.g. the domain isn't ready
    // at your DNS provider yet, or TLS will be handled elsewhere, like CloudFront).
    // 'dnsProvider' picks where each domain gets validated. Only 'route53' is implemented
    // today — the domain MUST already exist as a Hosted Zone there (this tool doesn't
    // register domains or move DNS delegation, that's always manual, outside AWS).
    // 'cloudflare' is a reserved value for when that second option gets implemented.
    'acmDomains' => [
        // 'example.com' => ['dnsProvider' => 'route53', 'subdomain' => '*'],
    ],
];
