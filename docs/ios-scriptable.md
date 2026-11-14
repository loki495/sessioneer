# iOS client: Scriptable

`clients/ios/SessioneerReply.js` is the same flow as the Shortcut in [ios-shortcuts.md](ios-shortcuts.md),
as one script for the free [Scriptable](https://scriptable.app) app. It uses the same API and token.
Use it instead of building the Shortcut by hand: it handles single and multi-question prompts, typed
answers, multi-select, and messaging with recent context, and it keeps the token in the iOS Keychain.

## Setup

1. Configure the API as described under "Setup" in [ios-shortcuts.md](ios-shortcuts.md)
   (`SHORTCUTS_API_TOKEN`, `docker compose up -d`).
2. Install Scriptable. In it, tap **+**, paste the contents of `SessioneerReply.js`, and name the
   script `Sessioneer Reply`.
3. Optional: set `BASE_URL` at the top of the script to your address so it is never asked. Run it. It asks once for the address (unless set), the API token, and optionally a Cloudflare Access
   service token (client id and secret). Each is saved as you enter it, so an interrupted setup resumes and a finished one never asks again. They are stored in the Keychain, not in the script.

To launch it quickly, create a Shortcut with one **Run Script** action (Scriptable) set to
`Sessioneer Reply`, then use that Shortcut from Siri, the Action Button, Back Tap or a Lock Screen
control (see "Launching it fast" in ios-shortcuts.md).

## Use

- Pick a session. Each row shows the full title with the status and idle time on a second line.
- A blocked prompt shows its options. `Type my own answer` appears when the prompt accepts text.
  Several questions are asked one after another; multi-select questions use tick rows and **Done**.
- A session with no prompt shows its recent messages (in full, scrollable), a **Rename session** row and a **Write a message** row. Rename sets the same name the dashboard shows. Writing opens a multi-line box in a web sheet: type, tap **Close**, then choose **Send** or **Discard**. If the web sheet fails, it falls back to a one-line field.
- The result appears as a notification and is returned to a calling Shortcut.

Run with the parameter `reset` to forget the saved address and tokens. Run with a session id (the
Shortcut's **Run Script** input, or `scriptable:///run?scriptName=Sessioneer%20Reply&session=<id>`)
to skip the menu.

## Limits

- The script is tested offline against stand-ins for Scriptable's objects
  (`tests/test_scriptable_client.php`), which proves its flows and request bodies. How Scriptable
  draws the lists on a device is not covered; report layout problems.
- Scriptable is a third-party app. A push notification cannot launch it by itself (see the
  notification note in ios-shortcuts.md).
