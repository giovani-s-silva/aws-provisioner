# AWS Provisioner

Command-line PHP tool to provision a repeatable, idempotent AWS network environment (VPC, public/private subnets, security groups, load balancer, ACM certificates and S3 bucket) from a single command — no more clicking through the Console in the right order.

## Status

Actively under construction. Nothing here should be considered production-ready yet.

- [x] Project structure and autoloading (PSR-4)
- [x] `VpcProvisioner` — creates VPC + Internet Gateway, idempotent
- [x] `AvailabilityZoneResolver` — validates the subnet count against the region's real AZs
- [x] `SecurityGroupProvisioner` / `NetworkAclProvisioner` — idempotent, rules driven by `config/settings.php`
- [x] `SubnetProvisioner` / `RouteTableProvisioner` — idempotent, includes the Internet Gateway route for public subnets
- [ ] Load Balancer (ALB) + ACM certificate
- [ ] S3 bucket
- [ ] Unified CLI (`bin/provision.php`) orchestrating everything in the right order
- [ ] Alternative network profiles (no public IPv4 / CloudFront in front of a private network)

## Requirements

- PHP >= 8.1
- Composer 2.x
- An AWS account (a new/test account is recommended while the project is under development)

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

Still under construction — the unified CLI (`php bin/provision.php`) will be the single entry point once every provisioner is ready. For now, each class in `src/` can be exercised on its own.

## Architecture

```
bin/provision.php        CLI entry point (Symfony Console)
config/settings.php       Non-secret preferences per account/project (names, CIDRs, region)
.env                       Credentials (never version-controlled)
src/
├── Aws/                  Builds the AWS SDK clients
├── Config/               Loads .env + settings.php
├── Network/              VPC, Security Groups, ACLs, Subnets, Route Tables, Peering
├── LoadBalancer/         Application Load Balancer
├── Storage/              S3 bucket
├── Certificates/         ACM + DNS validation (Route 53 or Cloudflare)
└── Provisioning/         Orchestrates the execution order between the steps above
```

## License

MIT.
