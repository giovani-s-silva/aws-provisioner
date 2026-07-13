<?php

declare(strict_types=1);

namespace AwsProvisioner\Support;

/**
 * Resolves the caller's public IPv4 address, used to scope security group rules
 * (e.g. SSH) to "just my IP" instead of 0.0.0.0/0.
 */
final class PublicIp
{
    /** @param string|null $caBundlePath see ClientFactory — only needed where TLS is locally intercepted. */
    public static function resolve(?string $caBundlePath = null): string
    {
        $context = null;
        if ($caBundlePath !== null) {
            $context = stream_context_create([
                'ssl' => ['cafile' => $caBundlePath],
            ]);
        }

        $ip = trim((string) file_get_contents('https://checkip.amazonaws.com', false, $context));

        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new \RuntimeException('Could not resolve the public IP address from checkip.amazonaws.com.');
        }

        return $ip;
    }
}
