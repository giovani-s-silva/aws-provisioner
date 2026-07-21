# AWS Provisioner

Command-line PHP tool to provision a repeatable, idempotent AWS environment — VPC, public/private subnets, security groups, an Application Load Balancer with an ACM/Route 53 certificate, and an Auto Scaling Group of EC2 instances behind it — from a single command, no more clicking through the Console in the right order.

## Status

Actively under construction. Nothing here should be considered production-ready yet.

- [x] Project structure and autoloading (PSR-4)
- [x] `VpcProvisioner` — creates VPC + Internet Gateway, idempotent
- [x] `AvailabilityZoneResolver` — validates the subnet count against the region's real AZs
- [x] `SecurityGroupProvisioner` / `NetworkAclProvisioner` — idempotent, rules driven by `config/settings.php`
- [x] `SubnetProvisioner` / `RouteTableProvisioner` — idempotent, includes the Internet Gateway route for public subnets
- [x] Unified CLI (`bin/provision.php`) orchestrating the network layer in the right order, with step selection and `--dry-run`
- [x] `LoadBalancerProvisioner` — ALB, target group, HTTP->HTTPS redirect, optional sticky sessions (`loadBalancer.stickiness` in `config/settings.php`), idempotent
- [x] `CertificateProvisioner` — requests one ACM certificate per domain (never a single certificate shared across domains, so a validation problem on one can't stall the others) and creates its DNS validation record via `Route53DnsProvider` or `CloudflareDnsProvider` (`acmDomains.<domain>.dnsProvider`, mix and match freely per domain); every issued certificate attaches to the ALB's HTTPS listener via SNI, so multiple unrelated domains — even across different DNS providers — can point at the same load balancer. Verified end-to-end against real domains, including Route 53 credentials from a separate AWS account.
- [x] Tier names (`web`, `db`, or however many/whatever you rename them to) are derived from `network.tiers` in `config/settings.php` instead of hardcoded — `TierConsistencyChecker` warns if a tier is missing from `securityGroups`/`networkAcls`/`routeTables`, or if one of those has an entry for a tier that doesn't exist
- [x] `LaunchTemplateProvisioner` / `AutoScalingGroupProvisioner` — EC2 instances behind the target group, managed by an Auto Scaling Group (`autoScaling.enabled`), attached directly via `TargetGroupARNs` so instances register/deregister automatically as they launch or terminate. The AMI is resolved automatically (always the latest one, via a public SSM parameter) from `compute.osFamily`, or you can bring your own via `compute.amiId`. The default runtime (`compute.runtime`) installs Apache + PHP as a working smoke test for the target group's health check — not meant to be a production stack as-is; set `runtime.type` to `'none'` when `amiId` points to an already-configured image. `minSize`/`maxSize` default to 1/1 on purpose, to avoid unexpected scaling costs — no CPU/request-based scaling policies are configured, that's a deliberate manual step for later.
- [ ] Alternative network profiles (no public IPv4 / CloudFront in front of a private network)
- [ ] Known limitation: the `load-balancer` step's own EC2 scan-and-register (every running instance in the VPC, regardless of role) still runs unconditionally as a fallback for instances outside any Auto Scaling Group. It's harmless alongside the ASG's own registration (registering an already-registered target is a no-op) but redundant once a project fully adopts Auto Scaling
- [ ] Possible improvement: nest each tier's security group/ACL/route table settings under its own entry in `network.tiers`, instead of repeating the tier name as a key across four separate top-level sections

## Requirements

- PHP >= 8.1
- Composer 2.x
- An AWS account (a new/test account is recommended while the project is under development)
- Only if you want the Load Balancer + ACM certificate step: a domain already set up as a Route 53 Hosted Zone, or a zone on Cloudflare (`acmDomains.<domain>.dnsProvider`). This tool does not register domains or move DNS delegation — that's always a manual, one-time step outside AWS. Leave `acmDomains` empty in `config/settings.php` to skip this step entirely and provision just the network.

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
                "elasticloadbalancing:RegisterTargets",
                "elasticloadbalancing:ModifyTargetGroupAttributes",
                "elasticloadbalancing:AddListenerCertificates"
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
        },
        {
            "Sid": "ComputeProvisioning",
            "Effect": "Allow",
            "Action": [
                "ec2:DescribeLaunchTemplates",
                "ec2:DescribeLaunchTemplateVersions",
                "ec2:CreateLaunchTemplate",
                "ec2:CreateLaunchTemplateVersion",
                "ec2:ModifyLaunchTemplate",
                "ec2:RunInstances",
                "ssm:GetParameter",
                "autoscaling:DescribeAutoScalingGroups",
                "autoscaling:CreateAutoScalingGroup",
                "autoscaling:UpdateAutoScalingGroup",
                "autoscaling:AttachLoadBalancerTargetGroups"
            ],
            "Resource": "*"
        },
        {
            "Sid": "AutoScalingServiceLinkedRole",
            "Effect": "Allow",
            "Action": "iam:CreateServiceLinkedRole",
            "Resource": "*",
            "Condition": {
                "StringEquals": {
                    "iam:AWSServiceName": "autoscaling.amazonaws.com"
                }
            }
        }
    ]
}
```

> Why no `iam:PassRole`? That's only required when the Launch Template attaches an instance profile (an IAM role) to the instances themselves — this tool doesn't do that, so it's left out. Needed only if you customize the Launch Template to add one.

> Why `ec2:RunInstances` under `ComputeProvisioning`? Amazon EC2 Auto Scaling checks that the calling IAM identity is authorized to use a Launch Template at `CreateAutoScalingGroup`/`UpdateAutoScalingGroup` time — even though the actual instances are launched later by Auto Scaling's own service-linked role, not this one. Without it: `AccessDenied: You are not authorized to use launch template`.

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

For domains validated via `'dnsProvider' => 'cloudflare'` instead of Route 53, there's no AWS IAM policy involved at all — set `CLOUDFLARE_API_TOKEN` in `.env` instead, created at Cloudflare under **My Profile → API Tokens → Create Token**, with **Zone / DNS / Edit** permission scoped to the zones (domains) this project needs (not the legacy Global API Key, which grants full account access).

Step by step in the AWS Console: **IAM → Users → Create user** → no console access, just "Access key - Programmatic access" → **Attach policy directly** → paste the JSON above as an inline policy → generate the Access Key and paste it into `.env`.

## Credential security

- `.env` accepts either a regular IAM user Access Key (`AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY`), or a temporary one — the same two variables filled with short-lived values, plus `AWS_SESSION_TOKEN` — generated with `aws sts get-session-token --duration-seconds 3600`. The temporary option still relies on the long-lived key to generate the session, but keeps that long-lived key out of `.env` and limits the exposure window if the temporary credentials ever leak.
- Whichever you use: deactivate the Access Key in the IAM console whenever you're not actively running the tool, and delete it once you're done provisioning. Reactivating a deactivated key takes seconds if you need it again — there's no upside to leaving one active between sessions.
- `.env` being gitignored only protects against accidentally committing the key — it does nothing for a key that's simply sitting active and unused. Treat "deactivate when idle" as the real boundary, not the `.gitignore` entry.

## Usage

```bash
php bin/provision.php               # runs every step, in order
php bin/provision.php subnets       # runs just one step (and whatever it needs from context)
php bin/provision.php --dry-run     # prints the steps that would run, without calling AWS
```

Steps implemented so far, in execution order: `vpc`, `internet-gateway`, `security-groups`, `network-acls`, `subnets`, `route-tables`, `certificate` (only registered when `acmDomains` isn't empty), `load-balancer` (only registered when `loadBalancer.enabled` is true), `auto-scaling` (only registered when `autoScaling.enabled` is true — requires `loadBalancer.enabled`).

The `certificate` step requests one ACM certificate per configured domain and creates its DNS validation CNAME at that domain's `dnsProvider` (Route 53 or Cloudflare), but validation isn't instant — AWS can take several minutes to confirm it. Run the step again later to check on it; once a certificate reports `ISSUED`, the next run of the `load-balancer` step attaches it to the HTTPS listener automatically (all issued certificates attach via SNI, so multiple domains can share the same load balancer). Requires the domain to already exist as a Route 53 Hosted Zone or Cloudflare zone (see "Required IAM permissions" above).

The `auto-scaling` step creates a Launch Template and an Auto Scaling Group attached to the load balancer's target group. Changing `compute` settings (a new `amiId`, a different `instanceType`) and running the step again creates a new Launch Template *version* and sets it as default — existing instances keep running as they are; only new ones (scale-out, replacements) pick up the change. To roll it out to already-running instances, trigger an EC2 instance refresh yourself (not something this tool automates).

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
├── Compute/              AMI resolution, User Data, Launch Template, Auto Scaling Group
├── Certificates/         ACM + Route 53/Cloudflare DNS validation
└── Provisioning/         Orchestrates the execution order between the steps above
```

## License

MIT.
