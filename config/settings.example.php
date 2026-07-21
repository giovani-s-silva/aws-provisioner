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

        // The names below ('web', 'db') are just examples — rename them, or add a third
        // (or fourth) tier, and everything else (security groups, ACLs, subnets, route
        // tables) picks it up automatically. This is the only place tier names are defined.
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
            // 80/443 here (not just 3306) because the instance itself is also an outbound
            // HTTP(S) client -- e.g. compute.runtime's package installation (apt, PPA) needs
            // to reach outside the VPC, separate from serving inbound traffic on those ports.
            'outboundPorts' => [80, 443, 3306],
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

    // Set 'enabled' to false to provision only the network layer, with no load balancer at
    // all. When true, whether it gets a public IP or stays fully private follows
    // 'networkProfile' above ('public-private-ipv4' -> internet-facing, 'private-with-cloudfront'
    // -> internal) — there's no separate public/private switch here, so the two settings can't
    // end up contradicting each other. 'tier' must match one of the keys under network.tiers.
    'loadBalancer' => [
        'enabled' => true,
        'name' => "lb-{$projectName}",
        'tier' => 'web',

        // Sticky sessions: routes a given client to the same target for the cookie's
        // lifetime, using a load-balancer-generated cookie. Off by default -- only turn
        // this on if your app keeps per-request state on the instance that served it
        // (e.g. PHP sessions stored on local disk instead of a shared store).
        'stickiness' => [
            'enabled' => false,
            'durationSeconds' => 86400,
        ],
    ],

    // EC2 instances behind the load balancer, managed by an Auto Scaling Group so unhealthy
    // instances get replaced automatically. Requires 'loadBalancer.enabled' above -- the ASG
    // attaches directly to its target group.
    'compute' => [
        // Which tier's subnets/security group the instances launch into. Must match one of
        // the keys under network.tiers (same idea as 'loadBalancer.tier' above).
        'tier' => 'web',

        'instanceType' => 't3.micro',

        // 'osFamily' picks which AMI gets resolved automatically (always the latest one,
        // via a public SSM parameter -- never a hardcoded AMI ID, since those go stale and
        // differ per region). Accepted values: 'ubuntu', 'amazon-linux'.
        'osFamily' => 'ubuntu',

        // Bring your own AMI instead (already fully configured, or built with Packer, or
        // whatever) -- this skips 'osFamily' resolution entirely. Pair this with
        // 'runtime.type' => 'none' below, unless your AMI is a plain/unconfigured Ubuntu or
        // Amazon Linux image and you actually want 'runtime' to provision it.
        'amiId' => null,

        'runtime' => [
            // 'php-apache' installs Apache + PHP + a placeholder page, just so the target
            // group's health check has something to find -- a smoke test, not meant to be
            // your production stack as-is. 'none' skips all software provisioning (use this
            // with a custom 'amiId' that's already configured).
            'type' => 'php-apache',
            'phpVersion' => '8.5',
        ],
    ],

    'autoScaling' => [
        'enabled' => false,

        // Keep min == max while testing -- no scale-out surprises, no unexpected cost. Raise
        // 'maxSize' once you're ready for real elasticity (this tool doesn't configure scaling
        // policies -- CPU/request-based scaling -- that's a deliberate manual step for later).
        'minSize' => 1,
        'maxSize' => 1,
        'desiredCapacity' => 1,
    ],

    // Leave empty to skip requesting any certificate (e.g. the domain isn't ready at your
    // DNS provider yet, or TLS will be handled elsewhere, like CloudFront) — the load balancer
    // above still gets provisioned with just the HTTP listener, no HTTPS.
    // 'dnsProvider' picks where each domain gets validated -- 'route53' or 'cloudflare'.
    // Either way, the domain MUST already exist there (Hosted Zone / zone) — this tool
    // doesn't register domains or move DNS delegation, that's always manual, outside AWS.
    // 'cloudflare' needs CLOUDFLARE_API_TOKEN set in .env (see .env.example). Mixing
    // providers across domains is fine -- each entry picks its own independently. Each
    // domain also gets its own ACM certificate, never one shared across domains -- a
    // validation problem on one domain then can't stall the others.
    'acmDomains' => [
        // 'example.com' => ['dnsProvider' => 'route53', 'subdomain' => '*'],
        // 'example.net' => ['dnsProvider' => 'cloudflare', 'subdomain' => '*'],
    ],
];
