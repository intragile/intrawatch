# Workspace and members

A workspace is the shared space for monitors, channels, status pages and their history. This chapter explains roles, inviting members and the workspace settings.

## What is a workspace?

Every monitor, notification channel, incident, maintenance and status page belongs to exactly one workspace. Workspaces are separate from each other: what you see in one does not appear in another.

If you are a member of several workspaces, use the **Workspace** switcher at the top of the left-hand menu to switch between them. When you switch, IntraWatch stays on the same list page where possible.

## Creating a workspace

If your instance settings allow it, the [[icon:mdi-plus]] **Create workspace** button appears next to the **Workspace** switcher. Enter the **Workspace name**, then click **Create**. You become the owner of the new workspace.

If the button does not appear, you cannot create workspaces on this instance. In that case, ask the owner or an admin of an existing workspace to invite you.

## Roles

Each member has one role in a workspace. Roles build on each other: a higher role allows everything a lower one does.

| Action | Viewer | Editor | Admin | Owner |
| --- | --- | --- | --- | --- |
| View monitors, channels, alert history, incidents, maintenance and status pages | yes | yes | yes | yes |
| View the member list | yes | yes | yes | yes |
| Create your own API tokens | yes | yes | yes | yes |
| Create and change monitors, channels, incidents, maintenance and status pages | no | yes | yes | yes |
| Invite members, change their role, remove them | no | no | yes | yes |
| View the audit log | no | no | yes | yes |
| Change the workspace name and time zone | no | no | yes | yes |
| Import from Uptime Kuma | no | no | yes | yes |
| Grant the Owner role, change or remove an owner | no | no | no | yes |
| Delete the workspace | no | no | no | yes |

You can see your own role at the bottom of the menu, below your name.

:::note

An API token's abilities can never go beyond its creator's role either. Details: [[manual:workspace/api-tokens|API tokens]].

:::

## Inviting a member

You can invite members with the **Admin** or **Owner** role.

:::steps

1. Open the [[route:ws.members|Members]] page.
2. In the **Invite a member** section, enter the **Email**.
3. Choose the **Role**. By default it is **Viewer**.
4. Click **Send invitation**.

:::

IntraWatch sends an invitation link to that address. By default the link is valid for 7 days; the **Pending invitations** table shows the exact expiry. The invited person joins by signing in with that email address, opening the link and clicking **Accept invitation**. The invitation email is written in the language of the inviter's interface.

:::warning

An invitation does not create an account. If the invited person does not have an account on the IntraWatch instance yet, ask the instance admin to create one.

:::

If you invite the same address again, the new invitation replaces the earlier one, and the old link stops working. You cannot invite an address that already belongs to a member.

## Pending invitations

The **Pending invitations** table shows invitations that have not been accepted yet. The **Expires** column shows how long the link is valid. Expired invitations are marked **Expired**.

To revoke an invitation, use the [[icon:mdi-email-remove]] **Revoke invitation** icon. The link stops working immediately. Only an owner can revoke an invitation for the Owner role.

## Changing a role and removing a member

As an admin or owner, you see a role picker in every row of the members table. The new role takes effect as soon as you choose it.

To remove a member, use the [[icon:mdi-account-minus]] **Remove member** icon. After you confirm, that person loses access to the workspace.

A few rules protect owners:

- Only an owner can grant the Owner role, whether by invitation or by changing a role.
- Only an owner can change or remove an existing owner.
- A workspace must always have at least one owner. The last owner cannot be removed, and their role cannot be lowered.

## Workspace settings

On the [[route:ws.settings|Workspace settings]] page you find these fields:

- **Name**: the display name of the workspace.
- **Slug (cannot be changed)**: the identifier the workspace uses in addresses, for example in API calls.
- **Time zone**: dates, maintenance windows and reports are shown in this time zone.

Save your changes with the **Save** button. You need at least the **Admin** role for this. With a lower role you can only view the settings.

## Deleting the workspace

Only owners see the **Delete workspace** section.

:::warning

Deleting the workspace permanently removes its monitors, channels, status pages and history. You cannot undo the deletion.

:::

In the confirmation window, type the workspace's name in the **Workspace name** field, then click **Delete**.
