# DNS-01 providers for the Let's Encrypt ClusterIssuers (acme.yaml.tftpl).
# ../kate.yaml defines them by name under acme.providers; a site picks one with
# acme.provider in projects/<name>/kate.yaml, and ./kate labels its Certificate
# typo3-kate/dns-provider=<name>. Each provider becomes a solver that matches
# that label, and the default provider also the solver without a label, so
# Certificates from before (and their saved copies) keep working.

locals {
  dns_provider_types = ["hetzner", "cloudflare", "route53", "digitalocean", "rfc2136"]

  # Every provider with every field, so they share one type
  dns_explicit = {
    for name, p in try(local.kate.acme.providers, {}) : name => {
      type              = try(tostring(p.type), "")
      token             = try(tostring(p.token), "")
      api_token         = try(tostring(p.apiToken), "")
      region            = try(tostring(p.region), "")
      access_key_id     = try(tostring(p.accessKeyId), "")
      secret_access_key = try(tostring(p.secretAccessKey), "")
      hosted_zone_id    = try(tostring(p.hostedZoneId), "")
      nameserver        = try(tostring(p.nameserver), "")
      tsig_key_name     = try(tostring(p.tsigKeyName), "")
      tsig_algorithm    = try(tostring(p.tsigAlgorithm), "HMACSHA256")
      tsig_secret       = try(tostring(p.tsigSecret), "")
      secret            = "dns-${name}"
    }
  }
  dns_providers = local.dns_explicit
  dns_names     = keys(local.dns_providers)

  # acme.defaultProvider, else "hetzner", else the only one
  dns_default = try(tostring(local.kate.acme.defaultProvider), null) != null ? tostring(local.kate.acme.defaultProvider) : (
    contains(local.dns_names, "hetzner") ? "hetzner" : (length(local.dns_names) == 1 ? local.dns_names[0] : "")
  )

  # The fields a provider type needs (validated on terraform_data.platform)
  dns_missing = {
    for name, p in local.dns_providers : name => [
      for field, value in {
        hetzner      = { token = p.token }
        cloudflare   = { apiToken = p.api_token }
        route53      = { region = p.region, accessKeyId = p.access_key_id, secretAccessKey = p.secret_access_key }
        digitalocean = { token = p.token }
        rfc2136      = { nameserver = p.nameserver, tsigKeyName = p.tsig_key_name, tsigSecret = p.tsig_secret }
      }[contains(local.dns_provider_types, p.type) ? p.type : "hetzner"] : field if nonsensitive(sensitive(value) == "")
    ]
  }

  dns_secrets = [
    for name, p in local.dns_providers : {
      apiVersion = "v1"
      kind       = "Secret"
      metadata   = { name = p.secret, namespace = "cert-manager" }
      type       = "Opaque"
      stringData = {
        hetzner      = { token = p.token }
        cloudflare   = { "api-token" = p.api_token }
        route53      = { "access-key-id" = p.access_key_id, "secret-access-key" = p.secret_access_key }
        digitalocean = { "access-token" = p.token }
        rfc2136      = { "tsig-secret" = p.tsig_secret }
      }[contains(local.dns_provider_types, p.type) ? p.type : "hetzner"]
    }
  ]

  dns_solver = {
    for name, p in local.dns_providers : name => {
      hetzner = { dns01 = { webhook = {
        groupName  = "acme.hetzner.com"
        solverName = "hetzner"
        config     = { tokenSecretKeyRef = { name = p.secret, key = "token" } }
      } } }
      cloudflare = { dns01 = { cloudflare = {
        apiTokenSecretRef = { name = p.secret, key = "api-token" }
      } } }
      route53 = { dns01 = { route53 = merge({
        region                   = p.region
        accessKeyIDSecretRef     = { name = p.secret, key = "access-key-id" }
        secretAccessKeySecretRef = { name = p.secret, key = "secret-access-key" }
      }, p.hosted_zone_id != "" ? { hostedZoneID = p.hosted_zone_id } : {}) } }
      digitalocean = { dns01 = { digitalocean = {
        tokenSecretRef = { name = p.secret, key = "access-token" }
      } } }
      rfc2136 = { dns01 = { rfc2136 = {
        nameserver          = p.nameserver
        tsigKeyName         = p.tsig_key_name
        tsigAlgorithm       = p.tsig_algorithm
        tsigSecretSecretRef = { name = p.secret, key = "tsig-secret" }
      } } }
    }[contains(local.dns_provider_types, p.type) ? p.type : "hetzner"]
  }

  # One solver per provider for its label, plus the default without a selector
  dns_solvers = concat(
    [for name in local.dns_names : merge(
      { selector = { matchLabels = { "typo3-kate/dns-provider" = name } } },
      local.dns_solver[name]
    )],
    local.dns_default != "" && contains(local.dns_names, local.dns_default) ? [local.dns_solver[local.dns_default]] : []
  )
}
