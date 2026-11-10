# API tokens

With an API token, a script or integration can call the IntraWatch API on your behalf, limited to a single workspace and to the abilities you choose. This chapter shows you how to create, use and revoke tokens.

## What is a token for?

A token is useful when a program needs to reach IntraWatch without signing in. For example, a deployment script that creates a monitor, or an internal dashboard that reads the state of your monitors.

Every token:

- acts on your behalf and belongs to a single workspace, the one where you created it;
- gets only the abilities you chose when you created it;
- can never grant more rights than its creator has.

The [[route:ws.tokens|API tokens]] page shows only your own tokens in the current workspace. You cannot see or manage other members' tokens.

## Creating a token

:::steps

1. Open the [[route:ws.tokens|API tokens]] page.
2. In the **Create a token** section, enter a descriptive **Name**, for example which script or system uses it.
3. If you want to use the token only for a while, set **Expires at (optional, your local time)**. The expiry cannot be in the past.
4. Under **Abilities**, tick what the token needs. You must choose at least one.
5. Click **Create token**.
6. In the **Your new token** window, copy the token with the [[icon:mdi-content-copy]] icon.
7. Click **I have copied it**.

:::

:::warning

The token is shown only once, right after you create it. You cannot retrieve it later. If you lose it, revoke it and create a new one.

:::

Treat the token like a password. Do not put it in a file under version control, and do not send it over an insecure channel.

## Abilities

Abilities decide what the token can do. Each one has a minimum role. You can grant only abilities that your own role allows. If you tick more than that, creating the token fails with an error.

| Ability in the interface | Technical name | Minimum role |
| --- | --- | --- |
| Read the workspace | `workspace:read` | Viewer |
| Read monitors | `monitors:read` | Viewer |
| Read notification channels | `notifications:read` | Viewer |
| Read incidents and maintenance | `incidents:read` | Viewer |
| Read status pages | `status-pages:read` | Viewer |
| Change monitors | `monitors:write` | Editor |
| Change notification channels | `notifications:write` | Editor |
| Change incidents and maintenance | `incidents:write` | Editor |
| Change status pages | `status-pages:write` | Editor |
| Manage the workspace | `workspace:manage` | Admin |
| Manage members | `members:manage` | Admin |
| Read the audit log | `audit:read` | Admin |

In the token list, the **Abilities** column shows the technical names.

### Grant only what is needed

Give each token only the abilities its job really needs. A dashboard that shows states needs only **Read monitors**. A script that creates monitors also needs **Change monitors**, but not member management. If a token leaks, the damage is smaller this way.

Create a separate token for each integration. That way you can revoke them one by one, and the audit log shows which token performed each action.

:::note

A token's rights follow its creator's current role. If your role is lowered later, the token's abilities that the new role no longer allows stop working immediately. If you are removed from the workspace, your tokens lose access too.

:::

## Using the token

Send the token in the `Authorization` header of every request, with the `Bearer` prefix. The API lives at the `/api/v1/` path after your instance's address. The workspace is identified by its slug, which you find on the [[route:ws.settings|Workspace settings]] page.

This example uses a token with the **Read monitors** ability to list the workspace's monitors:

```bash
curl -H "Authorization: Bearer $INTRAWATCH_TOKEN" \
     -H "Accept: application/json" \
     https://intrawatch.example.com/api/v1/workspaces/WORKSPACE-SLUG/monitors
```

If the token lacks the required ability, or its creator's role no longer allows it, the API rejects the request with a `403` response.

You cannot use a token to create or revoke other tokens, or to accept an invitation. For these, sign in to the interface.

## API reference

The full, machine-readable description of the API is available as an OpenAPI document at your instance's `/api/v1/openapi.json` address. You do not need a token for it. You can load the document into an OpenAPI viewer, or generate a client from it.

## Last use and expiry

In the token list, the **Last used** column shows when the token was last used. Tokens that have not been used yet show **Never used**. The **Expires** column shows the expiry time or **No expiry**. Expired tokens are marked [[icon:mdi-clock-alert-outline]] **Expired**.

## Revoking a token

Click the [[icon:mdi-key-remove]] **Revoke** icon in the token's row, then confirm with the **Revoke** button. Everything that uses the token stops working immediately. You cannot undo a revocation.

When you change or reset your password, all of your tokens in every workspace are revoked as well. Details: [[manual:account/account|Your account]].

Creating and revoking tokens are recorded in the [[manual:workspace/audit|Audit log]].
