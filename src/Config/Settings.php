<?php

declare(strict_types=1);

namespace AwsProvisioner\Config;

use Dotenv\Dotenv;

/**
 * Typed access to everything that changes between AWS accounts/projects:
 * credentials (from .env) and resource preferences (from config/settings.php).
 * This is the only class that reads $_ENV or includes config/settings.php.
 */
final class Settings
{
    private function __construct(
        private readonly array $credentials,
        private readonly array $preferences,
    ) {
    }

    public static function load(string $envPath, string $settingsPath): self
    {
        if (is_file($envPath)) {
            Dotenv::createImmutable(dirname($envPath), basename($envPath))->safeLoad();
        }

        if (!is_file($settingsPath)) {
            throw new \RuntimeException(
                "Settings file not found: {$settingsPath}. "
                . 'Copy config/settings.example.php to config/settings.php first.'
            );
        }

        $preferences = require $settingsPath;

        $credentials = array_filter([
            'key' => $_ENV['AWS_ACCESS_KEY_ID'] ?? '',
            'secret' => $_ENV['AWS_SECRET_ACCESS_KEY'] ?? '',
            'token' => $_ENV['AWS_SESSION_TOKEN'] ?? '',
        ], static fn (string $value): bool => $value !== '');

        return new self($credentials, $preferences);
    }

    public function awsCredentials(): array
    {
        return $this->credentials;
    }

    public function route53Credentials(): array
    {
        return array_filter([
            'key' => $_ENV['AWS_ROUTE53_ACCESS_KEY_ID'] ?? '',
            'secret' => $_ENV['AWS_ROUTE53_SECRET_ACCESS_KEY'] ?? '',
        ], static fn (string $value): bool => $value !== '');
    }

    public function cloudflareApiToken(): string
    {
        return $_ENV['CLOUDFLARE_API_TOKEN'] ?? '';
    }

    /** Only needed on machines where an antivirus/proxy intercepts TLS (see README troubleshooting). */
    public function caBundlePath(): ?string
    {
        $path = $_ENV['SSL_CA_BUNDLE_PATH'] ?? '';

        return $path !== '' ? $path : null;
    }

    public function region(): string
    {
        return $this->preferences['region'] ?? 'us-east-1';
    }

    public function projectName(): string
    {
        return $this->preferences['projectName'] ?? 'myapp';
    }

    public function networkProfile(): string
    {
        return $this->preferences['networkProfile'] ?? 'public-private-ipv4';
    }

    public function vpcPreferences(): array
    {
        return $this->preferences['network'] ?? [];
    }

    public function securityGroupPreferences(): array
    {
        return $this->preferences['securityGroups'] ?? [];
    }

    public function networkAclPreferences(): array
    {
        return $this->preferences['networkAcls'] ?? [];
    }

    public function routeTablePreferences(): array
    {
        return $this->preferences['routeTables'] ?? [];
    }

    public function loadBalancerPreferences(): array
    {
        return $this->preferences['loadBalancer'] ?? ['enabled' => false];
    }

    public function acmDomainList(): array
    {
        return $this->preferences['acmDomains'] ?? [];
    }
}
