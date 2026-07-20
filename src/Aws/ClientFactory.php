<?php

declare(strict_types=1);

namespace AwsProvisioner\Aws;

use Aws\Acm\AcmClient;
use Aws\AutoScaling\AutoScalingClient;
use Aws\Ec2\Ec2Client;
use Aws\ElasticLoadBalancingV2\ElasticLoadBalancingV2Client;
use Aws\Route53\Route53Client;
use Aws\Ssm\SsmClient;

/**
 * Builds AWS SDK clients from the credentials/settings resolved by Config\Settings.
 * Single place that knows how a client is constructed — nothing else in src/ should call `new Ec2Client(...)` directly.
 */
final class ClientFactory
{
    public function __construct(
        private readonly array $credentials,
        private readonly string $region,
        private readonly ?string $caBundlePath = null,
    ) {
    }

    public function ec2(): Ec2Client
    {
        return new Ec2Client($this->baseConfig());
    }

    public function acm(): AcmClient
    {
        return new AcmClient($this->baseConfig());
    }

    public function elasticLoadBalancingV2(): ElasticLoadBalancingV2Client
    {
        return new ElasticLoadBalancingV2Client($this->baseConfig());
    }

    public function autoScaling(): AutoScalingClient
    {
        return new AutoScalingClient($this->baseConfig());
    }

    /** Used to resolve the latest AMI ID for a given OS family -- see Compute\AmiResolver. */
    public function ssm(): SsmClient
    {
        return new SsmClient($this->baseConfig());
    }

    /** Route 53 credentials/region can differ from the main account, so this one is separate on purpose. */
    public function route53(array $route53Credentials): Route53Client
    {
        return new Route53Client([
            'region' => $this->region,
            'version' => 'latest',
            'credentials' => $route53Credentials,
        ] + $this->sslOptions());
    }

    private function baseConfig(): array
    {
        return [
            'region' => $this->region,
            'version' => 'latest',
            'credentials' => $this->credentials,
        ] + $this->sslOptions();
    }

    /**
     * On a clean machine this stays empty and the SDK verifies TLS normally.
     * Only set when an antivirus/proxy intercepts HTTPS locally (see README) — points
     * 'verify' at a CA bundle instead of disabling certificate validation altogether.
     */
    private function sslOptions(): array
    {
        if ($this->caBundlePath === null) {
            return [];
        }

        return ['http' => ['verify' => $this->caBundlePath]];
    }
}
