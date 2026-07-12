<?php

declare(strict_types=1);

// Copie este arquivo para "settings.php" e ajuste para a conta/projeto atual.
// Nada aqui é segredo (isso fica no .env) — são só preferências de nomenclatura e topologia.

return [
    // Usado para derivar o nome de todos os recursos: vpc-{projectName}, sg_{projectName}_web, etc.
    // Troque só isso aqui para reaproveitar o projeto em outra conta/ambiente.
    'projectName' => 'myapp',

    'region' => 'sa-east-1',

    // Como a rede é exposta à internet. Hoje só "public-private-ipv4" está implementado
    // (subnet pública + privada, NAT Gateway, ALB com IPv4 público — o modelo clássico).
    // Perfis futuros, sem custo de IP público (Load Balancer sem IPv4, ou tudo privado
    // atrás de um CloudFront VPC Origin), entram depois sem quebrar esta chave.
    'networkProfile' => 'public-private-ipv4',

    'network' => [
        'cidrBlock' => '10.0.0.0/16',

        // Quantas subnets (= quantas Availability Zones) usar por camada.
        // É validado em tempo de execução contra o número real de AZs disponíveis
        // na região escolhida acima (nem toda região tem 3+ AZs) — se pedir mais
        // do que existe, a ferramenta avisa em vez de falhar no meio da criação.
        'subnetsPerTier' => 2,

        'tiers' => [
            'web' => [
                'cidrBlocks' => ['10.0.10.0/24', '10.0.11.0/24', '10.0.12.0/24'],
                'mapPublicIpOnLaunch' => true,
            ],
            'db' => [
                'cidrBlocks' => ['10.0.13.0/24', '10.0.14.0/24', '10.0.15.0/24'],
                'mapPublicIpOnLaunch' => false,
            ],
        ],
    ],

    // Cada domínio aponta pro provider de DNS que vai validar o certificado ACM.
    'acmDomains' => [
        // 'example.com' => ['dnsProvider' => 'route53', 'subdomain' => '*'],
        // 'example.net' => ['dnsProvider' => 'cloudflare', 'subdomain' => '*'],
    ],
];
