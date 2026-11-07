import type { Component } from 'vue'
import type { MonitorType } from '../../../api/types'
import CronFields from './CronFields.vue'
import DnsFields from './DnsFields.vue'
import DomainFields from './DomainFields.vue'
import GroupFields from './GroupFields.vue'
import HttpFields from './HttpFields.vue'
import PingFields from './PingFields.vue'
import PushFields from './PushFields.vue'
import TcpFields from './TcpFields.vue'
import TlsFields from './TlsFields.vue'

/** The field group of each monitor type; a type without a form is a compile error. */
export const FORMS: Record<MonitorType, Component> = {
  http: HttpFields,
  tcp: TcpFields,
  ping: PingFields,
  dns: DnsFields,
  tls: TlsFields,
  domain: DomainFields,
  push: PushFields,
  cron: CronFields,
  group: GroupFields,
}

/**
 * The `config.*` and `secrets.*` keys each form renders inline. A validation error under any other
 * key is shown in the form-level alert instead of being dropped.
 */
export const FORM_KEYS: Record<MonitorType, { config: string[], secrets: string[] }> = {
  http: {
    config: [
      'url',
      'method',
      'headers',
      'body',
      'accepted_status',
      'max_redirects',
      'keyword',
      'json_query',
      'header_checks',
      'auth',
      'mtls',
      'proxy',
      'tls_verify',
      'tls_checks',
      'tls_expiry_days',
      'domain_expiry',
    ],
    secrets: [
      'basic_password',
      'bearer_token',
      'mtls_cert',
      'mtls_key',
      'proxy_password',
      'secret_headers',
    ],
  },
  tcp: {
    config: ['host', 'port'],
    secrets: [],
  },
  ping: {
    config: ['host', 'count'],
    secrets: [],
  },
  dns: {
    config: [
      'name',
      'record_type',
      'resolver',
      'expected',
      'match',
      'watch_changes',
    ],
    secrets: [],
  },
  tls: {
    config: [
      'host',
      'port',
      'starttls',
      'servername',
      'expiry_days',
      'degraded_below_days',
      'check_protocols',
      'check_ciphers',
      'watch_certificate',
    ],
    secrets: [],
  },
  domain: {
    config: ['domain', 'expiry_days', 'degraded_below_days'],
    secrets: [],
  },
  push: {
    config: ['grace_seconds'],
    secrets: [],
  },
  cron: {
    config: [
      'schedule',
      'timezone',
      'grace_seconds',
      'max_runtime_seconds',
    ],
    secrets: [],
  },
  group: {
    config: ['mode', 'quorum'],
    secrets: [],
  },
}
