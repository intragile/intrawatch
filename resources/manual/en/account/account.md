# Your account

On the **Account and security** page you can view your profile, manage two-factor authentication, change your password and see where you are signed in. From the account menu you can switch the theme and language, and sign out.

## The account menu

Click your name at the bottom of the left-hand menu to open the account menu. Here you find:

- your name and email address;
- [[icon:mdi-shield-account-outline]] **Account and security**: the [[route:account|Account and security]] page;
- the theme switch (see below);
- **Language**: the language of the interface;
- [[icon:mdi-logout]] **Sign out**.

Below your name you also see your role in the current workspace.

## Profile

The **Profile** section shows your **Name** and **Email**. You cannot change these in the interface. If you need to change them, contact the admin of your IntraWatch instance.

## Two-factor authentication

With two-factor authentication, signing in needs a six-digit, time-based code from an authenticator app in addition to your password. Any TOTP-based authenticator app that you install on your phone works. Next to the **Two-factor authentication** heading you see its state: [[icon:mdi-shield-check]] **On** or [[icon:mdi-shield-off-outline]] **Off**.

### Turning it on

:::steps

1. Open the [[route:account|Account and security]] page.
2. Click **Turn on**.
3. If IntraWatch asks, enter your password in the **Confirm your password** window, then click **Confirm**.
4. Scan the QR code with your authenticator app. If you cannot scan it, type in the key shown below the QR code by hand.
5. Enter the six-digit code from the app in the **Authentication code** field.
6. Click **Confirm and turn on**.

:::

If you stop the setup with the **Cancel** button, the half-finished setup is deleted, and two-factor authentication stays off.

### Recovery codes

After you turn it on, your **Recovery codes** appear. Each one lets you sign in once without the authenticator app, for example if you lose your phone.

:::warning

You see the recovery codes only now. Save them somewhere safe before you continue.

:::

Use the [[icon:mdi-content-copy]] **Copy** button to copy the codes to the clipboard, or the [[icon:mdi-download]] **Download** button to save them to a text file. When you are done, click **I have saved them**.

If your codes are used up or lost, click **New recovery codes** and confirm. Your old codes stop working, and the page shows the new ones once.

### Turning it off

Click **Turn off** and confirm. After that, your password is enough to sign in again. You can turn two-factor authentication back on at any time.

### Signing in with two-factor authentication

After you enter your password, the **Two-factor authentication** page appears. Enter the code from your authenticator app in the **Authentication code** field, then click **Verify**.

If you do not have your phone with you, click the **Use a recovery code instead** link, and enter one of your recovery codes. Each recovery code works only once.

:::tip

If you lose both your authenticator app and your recovery codes, you cannot sign in. In that case, the admin of your instance can reset your two-factor authentication.

:::

## Changing your password

You can change your password in the **Change password** section:

:::steps

1. Open the [[route:account|Account and security]] page.
2. Enter your **Current password**.
3. Enter the **New password** and **Repeat the password** values. The new password must be at least 10 characters long, and it must differ from the current one.
4. Click **Change password**.

:::

After the change you stay signed in on this device.

:::warning

Changing your password signs you out on every other device, and revokes all of your API tokens in every workspace. Scripts and integrations that use those tokens stop working until you create new tokens for them. Details: [[manual:workspace/api-tokens|API tokens]].

:::

## Sessions

The **Sessions** section lists the devices and browsers where you are signed in. For each one you see the browser and operating system, the IP address, and when it was last active. **This device** marks the browser you are using now.

If you see a device you do not recognize, or you signed in on a shared computer and forgot to sign out:

:::steps

1. Click **Sign out all other devices**.
2. Enter your password in the **Password** field.
3. Click **Sign out**.

:::

You stay signed in on this device, and you are signed out everywhere else. Your API tokens keep working; revoke them on the API tokens page if needed. If you think someone else knows your password, change your password instead: that signs out the other devices too.

## Confirming your password for sensitive actions

Before some sensitive actions, IntraWatch asks for your password again. Turning two-factor authentication on or off is one example. The **Confirm your password** window then appears. Enter your password in the **Password** field and click **Confirm**. The action then continues.

## Forgotten password

If you have forgotten your password, you can set a new one from the sign-in page:

:::steps

1. On the sign-in page, click the **Forgot your password?** link.
2. Enter the **Email** of your account, and click **Send reset link**.
3. Open the link you get by email.
4. Enter the **New password** and **Repeat the password** values, then click **Save password**.
5. Sign in with your new password.

:::

## Theme and language

In the account menu, use [[icon:mdi-weather-night]] **Switch to dark theme** or [[icon:mdi-weather-sunny]] **Switch to light theme** to switch between the dark and light look.

Under **Language** you can choose between **English** and **Magyar**. A [[icon:mdi-check]] check mark appears next to the active language. If you have not chosen a language yet, the interface follows your browser's language: it appears in Hungarian if your browser language is Hungarian, and in English otherwise.

:::note

The theme and language are stored in this browser, not in your account. In another browser or on another device, you need to set them again.

:::

Invitation emails are written in the language of the inviter's interface. Details: [[manual:workspace/workspace|Workspace and members]].

## Session and signing out

When you sign in, use the **Keep me signed in** option to have the browser remember your sign-in for longer.

If your session expires while you work, the **Your session has expired** window appears. Sign in again in that window. Your unsaved changes stay on the page, and the interface sends them after you sign in.

When you finish, use [[icon:mdi-logout]] **Sign out** in the account menu to sign out.
