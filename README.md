# AWS Provisioner

Command-line PHP tool to provision a repeatable, idempotent AWS network environment — VPC, public/private subnets, security groups, and an Application Load Balancer with an ACM/Route 53 certificate — from a single command, no more clicking through the Console in the right order.

## Status

Actively under construction. Nothing here should be considered production-ready yet.

- [x] Project structure and autoloading (PSR-4)
- [x] `VpcProvisioner` — creates VPC + Internet Gateway, idempotent
- [x] `AvailabilityZoneResolver` — validates the subnet count against the region's real AZs
- [x] `SecurityGroupProvisioner` / `NetworkAclProvisioner` — idempotent, rules driven by `config/settings.php`
- [x] `SubnetProvisioner` / `RouteTableProvisioner` — idempotent, includes the Internet Gateway route for public subnets
- [x] Unified CLI (`bin/provision.php`) orchestrating the network layer in the right order, with step selection and `--dry-run`
- [x] `LoadBalancerProvisioner` — ALB, target group, HTTP->HTTPS redirect, idempotent
- [x] `CertificateProvisioner` / `Route53DnsProvider` — requests the ACM certificate and creates its DNS validation record; the HTTPS listener is attached automatically once the certificate is ISSUED. Verified end-to-end against a real domain, including Route 53 credentials from a separate AWS account.
- [x] Tier names (`web`, `db`, or however many/whatever you rename them to) are derived from `network.tiers` in `config/settings.php` instead of hardcoded — `TierConsistencyChecker` warns if a tier is missing from `securityGroups`/`networkAcls`/`routeTables`, or if one of those has an entry for a tier that doesn't exist
- [ ] Alternative network profiles (no public IPv4 / CloudFront in front of a private network)
- [ ] Known limitation: the `load-balancer` step registers *every* running EC2 instance in the VPC as a target, regardless of role. Once Auto Scaling is introduced, attaching the ASG directly to the target group (`TargetGroupARNs`) is the correct mechanism — it registers/deregisters instances automatically as they launch or terminate, making this manual scan mostly relevant only for standalone instances outside any ASG
- [ ] Possible improvement: nest each tier's security group/ACL/route table settings under its own entry in `network.tiers`, instead of repeating the tier name as a key across four separate top-level sections

## Requirements

- PHP >= 8.1
- Composer 2.x
- An AWS account (a new/test account is recommended while the project is under development)
- Only if you want the Load Balancer + ACM certificate step: a domain already set up as a Route 53 Hosted Zone. This tool does not register domains or move DNS delegation — that's always a manual, one-time step outside AWS. Leave `acmDomains` empty in `config/settings.php` to skip this step entirely and provision just the network.

## Installation

```bash
composer install
cp .env.example .env
cp config/settings.example.php config/settings.php
```

Edit `.env` with your credentials (see "Required IAM permissions" below before generating the keys) and `config/settings.php` with your environment's preferences (project name, region, CIDRs, how many subnets per tier).

Neither file is version-controlled — each keeps its own local copy.

## Required IAM permissions

**Never use your AWS root account credentials here.** Create a dedicated IAM user, with programmatic access only (Access Key), and attach a policy with the minimum permissions required — not `AdministratorAccess`.

The policy below covers what is implemented so far (VPC, Internet Gateway, Security Groups, Network ACLs, Subnets, Route Tables, and the Application Load Balancer). It will grow as more parts are implemented — this README is updated alongside the code.

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "NetworkProvisioning",
            "Effect": "Allow",
            "Action": [
                "ec2:DescribeAvailabilityZones",
                "ec2:DescribeVpcs",
                "ec2:CreateVpc",
                "ec2:ModifyVpcAttribute",
                "ec2:DescribeInternetGateways",
                "ec2:CreateInternetGateway",
                "ec2:AttachInternetGateway",
                "ec2:CreateTags",
                "ec2:DescribeSecurityGroups",
                "ec2:CreateSecurityGroup",
                "ec2:AuthorizeSecurityGroupIngress",
                "ec2:DescribeNetworkAcls",
                "ec2:CreateNetworkAcl",
                "ec2:CreateNetworkAclEntry",
                "ec2:ReplaceNetworkAclAssociation",
                "ec2:DescribeSubnets",
                "ec2:CreateSubnet",
                "ec2:ModifySubnetAttribute",
                "ec2:DescribeRouteTables",
                "ec2:CreateRouteTable",
                "ec2:AssociateRouteTable",
                "ec2:CreateRoute",
                "ec2:DescribeInstances",
                "ec2:DescribeAccountAttributes",
                "ec2:DescribeAddresses",
                "ec2:DescribeNetworkInterfaces",
                "ec2:DescribeVpcClassicLink",
                "ec2:DescribeClassicLinkInstances",
                "ec2:GetSecurityGroupsForVpc",
                "ec2:DescribeVpcPeeringConnections"
            ],
            "Resource": "*"
        },
        {
            "Sid": "LoadBalancerProvisioning",
            "Effect": "Allow",
            "Action": [
                "elasticloadbalancing:DescribeTargetGroups",
                "elasticloadbalancing:CreateTargetGroup",
                "elasticloadbalancing:DescribeLoadBalancers",
                "elasticloadbalancing:CreateLoadBalancer",
                "elasticloadbalancing:DescribeListeners",
                "elasticloadbalancing:CreateListener",
                "elasticloadbalancing:RegisterTargets"
            ],
            "Resource": "*"
        },
        {
            "Sid": "ElbServiceLinkedRole",
            "Effect": "Allow",
            "Action": "iam:CreateServiceLinkedRole",
            "Resource": "*",
            "Condition": {
                "StringEquals": {
                    "iam:AWSServiceName": "elasticloadbalancing.amazonaws.com"
                }
            }
        },
        {
            "Sid": "CertificateProvisioning",
            "Effect": "Allow",
            "Action": [
                "acm:ListCertificates",
                "acm:RequestCertificate",
                "acm:DescribeCertificate"
            ],
            "Resource": "*"
        }
    ]
}
```

> Why `Resource: "*"` even under "least privilege"? EC2 network creation actions (`CreateVpc`, `CreateInternetGateway`, etc.) don't support restricting to a specific ARN — the resource doesn't exist yet at authorization time. AWS recommends `*` for this group of actions and using tags/conditions to restrict what can actually be restricted.

> Why `iam:CreateServiceLinkedRole`? The very first time an Application Load Balancer is created in an account, AWS needs to create the `AWSServiceRoleForElasticLoadBalancing` service-linked role so ELB can manage resources (like network interfaces) on your behalf — `CreateLoadBalancer` fails with `AccessDenied` without it. The `Condition` restricts this permission to only that specific service-linked role, following the exact pattern from AWS's own `ElasticLoadBalancingFullAccess` managed policy.

If `AWS_ROUTE53_ACCESS_KEY_ID`/`AWS_ROUTE53_SECRET_ACCESS_KEY` in `.env` point to a **separate** IAM user (a different AWS account managing your DNS, for example), attach this policy to that user instead — it never needs the permissions above:

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "Route53DnsValidation",
            "Effect": "Allow",
            "Action": [
                "route53:ListHostedZonesByName",
                "route53:ListResourceRecordSets",
                "route53:ChangeResourceRecordSets"
            ],
            "Resource": "*"
        }
    ]
}
```

> Why the extra `ec2:Describe*` actions (`DescribeAccountAttributes`, `DescribeAddresses`, `DescribeNetworkInterfaces`, etc.)? `CreateLoadBalancer` performs several read-only EC2 checks internally (account limits, existing network interfaces, VPC peering, ClassicLink) before creating anything — these show up as separate `AccessDenied` errors one at a time if missing. This list matches AWS's own `ElasticLoadBalancingFullAccess` managed policy, minus the parts unrelated to this tool (Cognito authentication on the ALB, Outposts CoIP pools).

Step by step in the AWS Console: **IAM → Users → Create user** → no console access, just "Access key - Programmatic access" → **Attach policy directly** → paste the JSON above as an inline policy → generate the Access Key and paste it into `.env`.

## Usage

```bash
php bin/provision.php               # runs every step, in order
php bin/provision.php subnets       # runs just one step (and whatever it needs from context)
php bin/provision.php --dry-run     # prints the steps that would run, without calling AWS
```

Steps implemented so far, in execution order: `vpc`, `internet-gateway`, `security-groups`, `network-acls`, `subnets`, `route-tables`, `certificate` (only registered when `acmDomains` isn't empty), `load-balancer` (only registered when `loadBalancer.enabled` is true).

The `certificate` step requests the ACM certificate and creates its DNS validation CNAME in Route 53, but validation isn't instant — AWS can take several minutes to confirm it. Run the step again later to check on it; once it reports `ISSUED`, the next run of the `load-balancer` step attaches the HTTPS listener automatically. Requires the domain to already exist as a Route 53 Hosted Zone (see "Required IAM permissions" above).

`dev/verify-*.php` are separate, standalone scripts kept around on purpose for isolated debugging of a single provisioner against a real AWS account without going through the full CLI — they are not part of the tool's normal usage.

## Architecture

```
bin/provision.php        CLI entry point (Symfony Console)
config/settings.php       Non-secret preferences per account/project (names, CIDRs, region)
.env                       Credentials (never version-controlled)
src/
├── Aws/                  Builds the AWS SDK clients
├── Config/               Loads .env + settings.php
├── Network/              VPC, Security Groups, ACLs, Subnets, Route Tables
├── LoadBalancer/         Application Load Balancer
├── Certificates/         ACM + Route 53 DNS validation
└── Provisioning/         Orchestrates the execution order between the steps above
```

## License

MIT.
