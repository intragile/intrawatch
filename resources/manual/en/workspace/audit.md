# Audit log

The audit log records who changed what in the workspace and when, whether in the interface or with an API token. Entries cannot be changed or deleted afterwards.

## Who can see it?

You can open the [[route:ws.audit|Audit log]] page with the **Admin** or **Owner** role. You find it in the menu, in the **Manage workspace** group, with the [[icon:mdi-file-document-outline]] icon. Through the API, a token with the **Read the audit log** (`audit:read`) ability can read it.

The log shows only events in the current workspace, newest first. If the list is long, use the **Load more** button at the bottom of the page to load older entries.

## Log columns

| Column | Content |
| --- | --- |
| Time | When the event happened. |
| Action | The technical name of the event, for example `monitor.updated`. |
| Actor | Who performed the action (see below). |
| Subject | The type and ID of the changed item, for example `monitor #12`. |
| Changes | The old and new values of the changed fields, in `old → new` form. |
| IP address | The address the request came from. |

The **Actor** column can show three kinds of entries:

- **User 5** (the number is the user's ID): a member performed the action in the interface.
- **Token 3, owner: user 5**: an API token performed the action. The first number is the token's ID, the second is the ID of the user who created the token.
- **System**: IntraWatch itself performed the action, not a person.

:::note

The values of passwords, tokens, secret keys and similar sensitive data never go into the log. Such fields do not appear in the **Changes** column either.

:::

## Recorded events

The log records the following actions, among others. The **Action** column shows the technical name.

| Area | Actions |
| --- | --- |
| Workspace | `workspace.created`, `workspace.updated` |
| Members | `member.invited`, `member.invitation_revoked`, `member.added`, `member.role_changed`, `member.removed` |
| API tokens | `token.created`, `token.revoked` |
| Monitors | `monitor.created`, `monitor.updated`, `monitor.deleted`, `monitor.check_requested`, `monitor.push_token_rotated` |
| Tags | `tag.created`, `tag.updated`, `tag.deleted` |
| Notification channels | `notification_channel.created`, `notification_channel.updated`, `notification_channel.deleted` |
| Incidents | `incident.created`, `incident.updated`, `incident.deleted`, `incident.update_added`, `incident.update_edited`, `incident.update_deleted` |
| Maintenance | `maintenance.created`, `maintenance.updated`, `maintenance.ended`, `maintenance.deleted` |
| Status pages | `status_page.created`, `status_page.updated`, `status_page.layout_replaced`, `status_page.deleted` |
| Import | `import.completed` |

`member.added` is logged when the invited person accepts the invitation. `monitor.check_requested` means someone requested an immediate check.

Events of your own account, such as signing in, changing your password (`auth.password_changed`) or signing out other devices (`auth.other_sessions_signed_out`, `auth.session_signed_out`), belong to no workspace, so they do not appear in this log.

## Filtering

Use the **Filters** section to narrow down the list. The filters you set take effect only after you click **Apply filters**.

- **Action**: the exact technical name of the action, for example `member.role_changed`. Partial matches do not work.
- **Actor type**: **User**, **API token** or **System**.
- **User ID**: the ID of the person behind the action. For a user action this is the user; for a token it is the token's owner. This way you can see all actions of a member at once, whether done in the interface or with their tokens.
- **Actor ID**: the actor's own ID, that is, the user's ID for a user and the token's ID for a token.

The **Clear** button empties the fields and shows the full list again.

If no entry matches the filters, you see **No audit events match.**

## Common questions

### Who changed this monitor?

Enter `monitor.updated` in the **Action** field and apply the filter. Find the monitor in the **Subject** column, and see what changed in the **Changes** column.

### What did a particular API token do?

Choose **API token** in the **Actor type** field, and enter the token's ID in the **Actor ID** field. You find the token's ID in the **Subject** column of the `token.created` entry. The [[manual:workspace/api-tokens|API tokens]] chapter covers tokens.

### Who got a new role?

Filter for the `member.role_changed` action. The **Changes** column shows the old and new role. The [[manual:workspace/workspace|Workspace and members]] chapter covers roles.
