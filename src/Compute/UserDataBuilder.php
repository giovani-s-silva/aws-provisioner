<?php

declare(strict_types=1);

namespace AwsProvisioner\Compute;

/**
 * Builds the EC2 User Data script for a given 'compute.runtime' configuration. Deliberately
 * generic -- no domain name, no ServerName tied to a specific site -- because the ALB already
 * owns domain/TLS routing; the instance just needs to answer HTTP on port 80 for whatever
 * Host header it's given. Meant as a working smoke test for the target group's health check,
 * not a production stack -- bring your own AMI (see 'compute.amiId') for that.
 */
final class UserDataBuilder
{
    /** @param array{type?: string, phpVersion?: string} $runtimePreferences */
    public function build(array $runtimePreferences, string $projectName): string
    {
        $type = $runtimePreferences['type'] ?? 'php-apache';

        return match ($type) {
            'none' => '',
            'php-apache' => $this->buildPhpApacheScript($runtimePreferences['phpVersion'] ?? '8.5', $projectName),
            default => throw new \RuntimeException(
                "Unknown compute.runtime.type '{$type}'. Accepted values: 'php-apache', 'none'."
            ),
        };
    }

    private function buildPhpApacheScript(string $phpVersion, string $projectName): string
    {
        $template = <<<'BASH'
        #!/bin/bash
        set -euo pipefail

        apt-get update
        add-apt-repository -y ppa:ondrej/php
        apt-get update
        apt-get install -y apache2 \
            php{{PHP_VERSION}} libapache2-mod-php{{PHP_VERSION}} \
            php{{PHP_VERSION}}-mysql php{{PHP_VERSION}}-curl php{{PHP_VERSION}}-zip

        a2enmod rewrite headers http2 php{{PHP_VERSION}}

        # Silences the "could not reliably determine the server's fully qualified domain name"
        # warning -- harmless, but there's no reason to leave a cosmetic warning in the logs.
        echo 'ServerName localhost' > /etc/apache2/conf-available/servername.conf
        a2enconf servername

        mkdir -p /var/www/{{PROJECT_NAME}}/public
        chown -R root:root /var/www/{{PROJECT_NAME}}
        chown -R www-data:www-data /var/www/{{PROJECT_NAME}}/public
        chmod -R 755 /var/www/{{PROJECT_NAME}}/public
        echo '<?php phpinfo(); ?>' > /var/www/{{PROJECT_NAME}}/public/index.php

        # No ServerName/ServerAlias here on purpose -- the ALB owns domain routing and TLS,
        # this is the only vhost on port 80 so it answers whatever Host header it's given.
        cat > /etc/apache2/sites-available/{{PROJECT_NAME}}.conf <<'VHOST'
        <VirtualHost *:80>
                ServerAdmin webmaster@localhost
                DocumentRoot /var/www/{{PROJECT_NAME}}/public
                AccessFileName .htaccess
                <Directory /var/www/{{PROJECT_NAME}}/public>
                        Options -Indexes +FollowSymLinks
                        AllowOverride All
                        Require all granted
                </Directory>
                ErrorLog ${APACHE_LOG_DIR}/error.log
                CustomLog ${APACHE_LOG_DIR}/access.log combined
        </VirtualHost>
        VHOST

        a2ensite {{PROJECT_NAME}}.conf
        a2dissite 000-default.conf
        service apache2 restart
        BASH;

        return strtr($template, [
            '{{PHP_VERSION}}' => $phpVersion,
            '{{PROJECT_NAME}}' => $projectName,
        ]);
    }
}
