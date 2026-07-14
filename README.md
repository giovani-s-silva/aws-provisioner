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
- [ ] Load Balancer (ALB) + ACM certificate (Route 53 DNS validation), wired into the CLI
- [ ] Alternative network profiles (no public IPv4 / CloudFront in front of a private network)

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

The policy below covers what is implemented so far (VPC, Internet Gateway, Security Groups, Network ACLs, Subnets and Route Tables). It will grow as more parts are implemented — this README is updated alongside the code.

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
                "ec2:CreateRoute"
            ],
            "Resource": "*"
        }
    ]
}
```

> Why `Resource: "*"` even under "least privilege"? EC2 network creation actions (`CreateVpc`, `CreateInternetGateway`, etc.) don't support restricting to a specific ARN — the resource doesn't exist yet at authorization time. AWS recommends `*` for this group of actions and using tags/conditions to restrict what can actually be restricted.

Step by step in the AWS Console: **IAM → Users → Create user** → no console access, just "Access key - Programmatic access" → **Attach policy directly** → paste the JSON above as an inline policy → generate the Access Key and paste it into `.env`.

## Usage

```bash
php bin/provision.php               # runs every step, in order
php bin/provision.php subnets       # runs just one step (and whatever it needs from context)
php bin/provision.php --dry-run     # prints the steps that would run, without calling AWS
```

Steps implemented so far, in execution order: `vpc`, `internet-gateway`, `security-groups`, `network-acls`, `subnets`, `route-tables`. The Load Balancer and ACM/Route 53 steps are not wired in yet (see Status above).

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
