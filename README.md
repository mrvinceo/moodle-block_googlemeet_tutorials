# Google Meet tutorials (Moodle block)

**Plugin type:** block  
**Frankenstyle:** `block_googlemeet_tutorials`  
**Requires:** Moodle 4.5+  
**License:** GNU GPL v3 or later

Course block for booking capacity-limited Google Meet tutorial slots. Tutors create one-off or recurring slots; students register until capacity is reached; Meet links and Calendar attendees stay in sync via the tutor’s Google account.

## Features

- Tutor-managed tutorial slots with capacity limits
- Student registration / unregistration
- Optional recurrence (presets or custom RRULE-style options)
- Course-scoped or site-wide slots (where the host has permission)
- Google Calendar Meet creation and attendee updates on register/unregister
- Privacy API implementation

## Requirements

- Moodle 4.5 or later
- A Google Cloud project with the **Google Calendar API** enabled
- OAuth 2.0 credentials (Web application client)

This plugin does **not** depend on other Moodle plugins. A Google Workspace / Google account is required for hosts who create slots.

## Installation

1. Download the plugin ZIP (folder name must be `googlemeet_tutorials`).
2. Install via **Site administration → Plugins → Install plugins**, or unzip into `blocks/googlemeet_tutorials`.
3. Visit **Notifications** to complete the install / upgrade.
4. Configure Google OAuth under **Site administration → Plugins → Blocks → Google Meet tutorials**.

### Google Cloud setup

1. In [Google Cloud Console](https://console.cloud.google.com/), create or select a project.
2. Enable **Google Calendar API**.
3. Create **OAuth 2.0 Client ID** credentials (application type: Web application).
4. Add this authorised redirect URI (replace with your site URL):

   `https://YOUR.MOODLE.SITE/blocks/googlemeet_tutorials/auth.php`

5. Copy the Client ID, Client secret, and (optionally) API key into the plugin settings.

Hosts must use **Connect Google Calendar** before creating slots.

## Usage

1. Add the **Google Meet tutorials** block to a course.
2. As a host with `block/googlemeet_tutorials:manageslots`, open **Manage my tutorials**, connect Google, and add slots.
3. Students with `block/googlemeet_tutorials:register` open the schedule and register. Meet links are shown to registered students (and staff who share a group with the host, per plugin rules).

## Privacy

The plugin stores tutorial slots, series metadata, student registrations, and Google OAuth tokens for hosts. When students register, their email may be sent to Google Calendar as an event attendee. See the plugin’s Privacy API provider for details.

## Support

- Issue tracker: *(add your GitHub Issues URL after publishing the repository)*
- Documentation: this README

## Changelog

### 1.0.0

- Initial Marketplace release
- Standalone Google OAuth / Calendar client (no dependency on other Meet blocks)
