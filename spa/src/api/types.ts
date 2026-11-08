import type { components, paths } from './schema'

type JsonBody<R> = R extends { content: { 'application/json': infer B } } ? B : never
type Ok<P> = P extends { 200: infer R } ? JsonBody<R> : never
type Item<B> = B extends { data: Array<infer I> } ? I : B extends { data: infer D } ? D : never

type GetOk<P extends keyof paths> = paths[P] extends { get: { responses: infer R } } ? Ok<R> : never
type JsonRequest<O> = O extends { requestBody?: infer R } ? JsonBody<NonNullable<R>> : never

export type Schemas = components['schemas']

export type Me = Item<GetOk<'/me'>>
export type WorkspaceSummary = Me['workspaces'][number]
export type Ability = keyof WorkspaceSummary['can']
/** A monitor list item, with the heartbeats the list asks for. */
export type ListedMonitor = Item<GetOk<'/workspaces/{workspace}/monitors'>>
export type Monitor = Omit<ListedMonitor, 'recent_beats'>
export type MonitorType = Schemas['MonitorType']
export type MonitorStatus = Schemas['MonitorStatus']
export type ChannelType = Schemas['ChannelType']
export type AlertKind = Schemas['AlertKind']
export type IncidentImpact = Schemas['IncidentImpact']
export type IncidentStatus = Schemas['IncidentStatus']
export type MaintenanceStrategy = Schemas['MaintenanceStrategy']
export type TokenItem = Item<GetOk<'/workspaces/{workspace}/tokens'>>
export type Invitation = Item<GetOk<'/workspaces/{workspace}/invitations'>>
export type AuditEntry = Item<GetOk<'/workspaces/{workspace}/audit'>>
export type AlertEvent = Item<GetOk<'/workspaces/{workspace}/alerts'>>
export type MonitorRollup = Item<GetOk<'/workspaces/{workspace}/monitors/{monitor}/rollups'>>
export type MonitorEvent = Item<GetOk<'/workspaces/{workspace}/monitors/{monitor}/events'>>
export type CheckResult = Item<GetOk<'/workspaces/{workspace}/monitors/{monitor}/results'>>
export type ChannelSummary = Item<GetOk<'/workspaces/{workspace}/notification-channels'>>
export type IncidentSummary = Item<GetOk<'/workspaces/{workspace}/incidents'>>
export type Incident = Item<GetOk<'/workspaces/{workspace}/incidents/{incident}'>>
export type IncidentTimelineEntry = paths['/workspaces/{workspace}/incidents/{incident}/updates']['post']['responses'][201]['content']['application/json']['data']
export type Maintenance = Item<GetOk<'/workspaces/{workspace}/maintenances'>>
export type StatusPageSummary = Item<GetOk<'/workspaces/{workspace}/status-pages'>>
export type StatusPage = Item<GetOk<'/workspaces/{workspace}/status-pages/{statusPage}'>>
export type Tag = Item<GetOk<'/workspaces/{workspace}/tags'>>
export type ImportRun = Item<GetOk<'/workspaces/{workspace}/imports'>>
export type ImportReportData = NonNullable<ImportRun['report']>
export type ImportEntryData = ImportReportData['entries'][number]

export type MonitorTypeInfo = GetOk<'/monitor-types'>[number]
export type ChannelTypeInfo = GetOk<'/notification-channel-types'>[number]

export type MonitorCreateBody = JsonRequest<paths['/workspaces/{workspace}/monitors']['post']>
export type MonitorUpdateBody = JsonRequest<paths['/workspaces/{workspace}/monitors/{monitor}']['patch']>
export type ChannelCreateBody = JsonRequest<paths['/workspaces/{workspace}/notification-channels']['post']>
export type ChannelUpdateBody = JsonRequest<paths['/workspaces/{workspace}/notification-channels/{channel}']['patch']>
export type IncidentCreateBody = JsonRequest<paths['/workspaces/{workspace}/incidents']['post']>
export type IncidentUpdateBody = JsonRequest<paths['/workspaces/{workspace}/incidents/{incident}']['patch']>
export type IncidentUpdateCreateBody = JsonRequest<paths['/workspaces/{workspace}/incidents/{incident}/updates']['post']>
export type MaintenanceCreateBody = JsonRequest<paths['/workspaces/{workspace}/maintenances']['post']>
export type MaintenanceUpdateBody = JsonRequest<paths['/workspaces/{workspace}/maintenances/{maintenance}']['patch']>
export type StatusPageCreateBody = JsonRequest<paths['/workspaces/{workspace}/status-pages']['post']>
export type StatusPageUpdateBody = JsonRequest<paths['/workspaces/{workspace}/status-pages/{statusPage}']['patch']>
export type StatusPageLayoutBody = JsonRequest<paths['/workspaces/{workspace}/status-pages/{statusPage}/layout']['put']>
