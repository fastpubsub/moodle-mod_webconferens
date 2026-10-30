# webconferens (mod_webconferens)

## What this is

webconferens is a module for the [Moodle learning system](https://moodle.org/).
It adds an activity a teacher can choose for a course or a lesson.

It works like this. Moodle checks who the person is, stores the room,
and asks FastPubSub for a short access token. Video and audio do not
pass through Moodle. They go through the FastPubSub Realtime overlay,
a separate network. The site needs an overlay name (network name in
FastPubSub) and a main token (MT) before anyone can join.
The next sections say how to get them and where to paste them.

The product has three video conference modes: Meeting, Lesson, and Conference.

Meeting: Everyone sees everyone and may speak. A useful meeting is about
12 to 15 people. More voices at once turn into noise. That is the size of
a conversation.

Lesson: Students watch the teacher's material. One teacher, or several.
Students do not talk to each other on the air.

Conference: The speaker is on the air. The hall watches. Viewers do not send
their own camera.

This package is still in development.
The status section says which of these modes this release ships.

## FastPubSub is required

The conference does not start without two site settings:


| Setting         | What it is                                                           |
| --------------- | -------------------------------------------------------------------- |
| Overlay name    | The network name in FastPubSub. The default is `globaltest`.         |
| Main token (MT) | The secret for that overlay. Moodle uses it to create access tokens. |


Moodle asks the FastPubSub servers for a short token for each user.
The browser then connects to the overlay on its own.
FastPubSub carries the media streams.

The link to FastPubSub uses normal SSL.
The media inside that link is encrypted again, with a key for this room only.

One main token belongs to one overlay. Moodle does not create the overlay
or the main token. With an empty token the conference page cannot join.
The main token stays on the Moodle server.
The browser receives only the short token.

## How to get an overlay and a main token

Send an email to [info@fastpubsub.com](mailto:info@fastpubsub.com). Write:

1. Who you are. FastPubSub needs to know who will test the conference.
2. Where your future conference users are, by country or region.
  FastPubSub uses that to choose or adjust the overlay network.

The reply comes to the same email address. It contains the main token.
Paste that token into the webconferens site setting.
Leave the overlay name as `globaltest` unless the reply names another overlay.

Do not put the main token in git, or on a public page.
Plugin reviewers use this same email address. They can ask for a main token there.
The plugin submission also includes one working main token for the review.
Do not publish the token from the reply.

## Room formats

The product has three formats.
Each format is a different kind of meeting, with different roles.
Each activity is its own room, with its own tenant and its own media key.


| Format     | Roles                                                                                                                                        |
| ---------- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| Meeting    | Everyone is equal. Everyone sees everyone and may speak. Up to 12 or 15 people. This is not a technical limit. More people means more chaos. |
| Lesson     | Students watch the teacher's material. One teacher, or several. Students do not talk to each other on the air.                               |
| Conference | The speaker is on the air. The hall watches. Viewers do not send their own camera.                                                           |


## Status of this release

This package is still in development.
The sections above describe the product.
This release ships Meeting only.

| Format     | In this release                                  |
| ---------- | ------------------------------------------------ |
| Meeting    | Yes. The activity form offers Meeting.           |
| Lesson     | No. Not in the form. A posted value is rejected. |
| Conference | No. Not in the form. A posted value is rejected. |

See Changes for what shipped in 0.1.0.

## Install

This repository is the plugin. Moodle installs it at `{moodle}/mod/webconferens`.
Clone or copy it there. The folder name on disk must be `webconferens`.

A ZIP install needs that same folder name inside the archive:

```bash
git archive --prefix=webconferens/ -o webconferens.zip HEAD
```

The browser client is already built in `js/`. The site admin does not run npm.

1. Site administration, Plugins, Install plugins. Upload the ZIP.
  Or copy the `webconferens` folder into `{moodle}/mod/`.
2. Open Site administration, Notifications, and finish the upgrade.
3. Open Site administration, Plugins, Activity modules, Web Conference.
  That page is the webconferens site setting.
4. Set the overlay name and the main token. Save.

Moodle 4.1 or later. Checked on Moodle 4.5.4 (Build: 20250414).
Not tested on Moodle 5.1 or 5.2.

Add a webconferens activity to a course, choose Meeting, and open it.

Moodle cron must be running. About once an hour it deletes participant ids
that are more than one day old. The next visit gets a new id.
The room itself stays. The overlay sees that participant id,
not the Moodle user id.

## Course backup

A course backup stores the activity name, the description, the room format,
the tenant, and the media key. It does not store participant ids.

## Personal data

For each person in a room, Moodle stores the Moodle user id and a separate
participant id. That participant id stays until it is more than one day old.
FastPubSub receives the participant id and the display name, so other people
in the room can see who is there. The Moodle user id is not sent.

Video, audio, and chat are encrypted with a key known only to the people
in that room.