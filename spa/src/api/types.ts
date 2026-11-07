import type { components, paths } from './schema'

type JsonBody<R> = R extends { content: { 'application/json': infer B } } ? B : never
type Ok<P> = P extends { 200: infer R } ? JsonBody<R> : never
type Item<B> = B extends { data: Array<infer I> } ? I : B extends { data: infer D } ? D : never

type GetOk<P extends keyof paths> = paths[P] extends { get: { responses: infer R } } ? Ok<R> : never

export type Schemas = components['schemas']

export type Me = Item<GetOk<'/me'>>
export type WorkspaceSummary = Me['workspaces'][number]
export type Ability = keyof WorkspaceSummary['can']
export type Monitor = Item<GetOk<'/workspaces/{workspace}/monitors'>>
export type MonitorType = Schemas['MonitorType']
export type MonitorStatus = Schemas['MonitorStatus']
export type ChannelType = Schemas['ChannelType']
export type AlertKind = Schemas['AlertKind']
export type IncidentImpact = Schemas['IncidentImpact']
