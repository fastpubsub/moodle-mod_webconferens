# Changes

## 0.1.0 (2026-09-24)

### Meeting

- A teacher can add a Web Conference activity and choose Meeting.
- Everyone in that room is a peer. Everyone sees everyone and may speak.
- Lesson and Conference are not in this release.

### Media encryption

- Each activity gets its own AES-256 key.
- Moodle stores that key masked with a site mask. The database row is not the raw key.
- The live page puts an AES-256-GCM filter on the media prefixes
  `private.participant.`, `private.teacher.`, `private.student.`, and `private.speaker.`.
- The same key is known to the people in that room and is stored on the Moodle server. 
- The browser receives only a short access token.

### Participant ids

- The overlay does not receive the Moodle user id.
- The first visit to a room stores a separate participant id, eight hex characters, for that person in that room.
- A later visit to the same room reuses that id. Another activity gets another id.
- The privacy API lists this map, exports it, and deletes it with the user.
- The display name is sent so the room can show who is there. The Moodle user id is not.
- About once an hour, Moodle deletes participant ids that are more than one day old.

### Video and audio

- Camera, remote video, and the shared desk use H.264.
- The client encodes H.264 with the encoder that is available, hardware or software.
- Microphone audio is Opus at 48 kHz, mono.
- Large H.264 units are split. WebSocket frames stay at 16 KiB.
- Those media prefixes share one bandwidth ceiling, the same ceiling as the access token.

### Browsers

- Chrome and Edge take camera and microphone frames with a track processor, then encode H.264 and Opus.
- Firefox has no track processor on the page. Video frames come from the live preview. Microphone PCM comes from an audio worklet into the Opus encoder.
- Safari on a Mac uses the same preview for H.264. When the page has no Opus encoder, the microphone is encoded with the Opus implementation shipped in this plugin.
- A browser with no H.264 does not send a camera. The room asks the person to open Chrome.
- Phones are not part of this release.
