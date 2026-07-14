<?php

declare(strict_types=1);

// Copie este arquivo para "settings.php" e ajuste para a conta/projeto atual.
// Nada aqui é segredo (isso fica no .env) — são só preferências de nomenclatura e topologia.

// Troque só esta variável para reaproveitar o projeto em outra conta/ambiente — todos os
// nomes de recursos abaixo (VPC, security groups, ACLs) são derivados dela automaticamente.
$projectName = 'myapp';

return [
    'projectName' => $projectName,

    'region' => 'sa-east-1',

    // Como a rede é exposta à internet. Hoje só "public-private-ipv4" está implementado
    // (subnet pública + privada, NAT Gateway opcional, ALB com IPv4 público — o modelo
    // clássico). "private-with-cloudfront" (ALB 100% privado, CloudFront na frente via
    // VPC Origin — sem custo de IP público, mais seguro) entra depois sem quebrar esta chave.
    'networkProfile' => 'public-private-ipv4',

    'network' => [
        'cidrBlock' => '10.0.0.0/16',

        // Quantas subnets (= quantas Availability Zones) usar por camada.
        // É validado em tempo de execução contra o número real de AZs disponíveis
        // na região escolhida acima (nem toda região tem 3+ AZs) — se pedir mais
        // do que existe, a ferramenta avisa em vez de falhar no meio da criação.
        'subnetsPerTier' => 2,

        // Tamanho de cada subnet (/24 = 256 IPs, de sobra pra maioria dos casos).
        // Os blocos CIDR de cada subnet são calculados automaticamente a partir de
        // 'cidrBlock' acima — não precisa listar manualmente, funciona pra 1, 3, 6
        // ou quantas AZs você configurar em 'subnetsPerTier'.
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
            // 'source' aceita: 'my-ip' (resolvido em tempo de execução), um CIDR
            // explícito, ou 'security-group:web'/'security-group:db' pra liberar
            // outro security group deste mesmo config em vez de um IP.
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

    // 'servicePorts' = portas que esta camada aceita conexão (regra de entrada).
    // 'outboundPorts' = portas que esta camada precisa acessar em outra camada/serviço
    // (regra de saída). As portas efêmeras (1024-65535) de resposta são adicionadas
    // automaticamente nos dois sentidos — sem isso, tráfego de resposta é descartado
    // silenciosamente (era exatamente o que faltava no ACL do banco no código antigo).
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

    // A tabela 'web' recebe a rota 0.0.0.0/0 pro Internet Gateway (é o que torna a
    // subnet pública de fato); a 'db' fica sem rota de saída pra internet por padrão.
    'routeTables' => [
        'web' => ['name' => "rt-{$projectName}-web"],
        'db' => ['name' => "rt-{$projectName}-db"],
    ],

    // Deixe vazio pra não provisionar ALB/certificado nenhum (ex.: domínio ainda não está
    // pronto no seu provedor de DNS, ou o TLS vai ser resolvido em outro lugar, tipo CloudFront).
    // 'dnsProvider' escolhe onde validar cada domínio. Hoje só 'route53' está implementado —
    // o domínio PRECISA já existir como Hosted Zone lá (a ferramenta não registra domínios
    // nem transfere DNS, isso é sempre manual, fora da AWS). 'cloudflare' é valor reservado
    // pra quando essa segunda opção for implementada.
    'acmDomains' => [
        // 'example.com' => ['dnsProvider' => 'route53', 'subdomain' => '*'],
    ],
];
