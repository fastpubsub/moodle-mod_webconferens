# Todo

## Encrypt the display name

The room key covers video, audio, and chat. It does not cover the name.

The name is in the presence packet `public.participant.<id>.state`.
That packet also carries the role and the camera and microphone flags.
`start_room.js` hangs AES-256-GCM only on the `private.*` prefixes.
The overlay can read the public packet. The link to the overlay is still TLS.
The packet does not contain the Moodle user id.

Chat already sends the name inside `private.<role>.<id>.chat`, under the room key.

Put the presence packet under the same AES-256-GCM room key.
People in the room must still see who is there.

## Lesson mode

Students watch the teacher's material. One teacher, or several.
Students do not talk to each other on the air.
This mode is not ready. A teacher can already choose it for a course.
The code for this mode does not work.

## Conference mode

The speaker is on the air. The hall watches.
Viewers do not send their own camera.
This mode is not ready. A teacher can already choose it for a course.
The code for this mode does not work.

## Simple quizzes

A teacher asks one question and gives a list of answers.
Students pick an answer in the room.

How to store the questions and the answers on the Moodle server is still open.
The quiz must survive a reload and a later visit to the same activity.

## Slides and pictures

Show a PDF, a photo gallery, and other file formats in the room.
Send the file, not a video stream of the screen.

## Record and play back

Idea only. Not designed yet.

Record a meeting, a lesson, or a conference.
Play that recording in the browser later.
In a meeting, record everyone.
In a lesson, record the teacher. In a conference, record the speaker.

## Done in 0.1.0

- [x] Meeting. A teacher can add the activity and choose Meeting. Everyone in the room is a peer.
- [x] Each activity has its own AES-256 key, stored on the Moodle server under a site mask.
- [x] The live page encrypts media prefixes with AES-256-GCM. The browser receives only a short access token.
- [x] The overlay receives a room participant id, not the Moodle user id. The privacy API exports and deletes that map.
- [x] Participant ids older than one day are deleted about once an hour.
- [x] Video is H.264, with the hardware or software encoder that is available. Audio is Opus at 48 kHz, mono.
- [x] Chrome, Edge, Firefox, and Safari on a Mac can enter the room. Phones are not part of this release.
