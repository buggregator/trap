# Binary data

Every TCP port of Trap takes any protocol. Traffic no protocol takes, binary or text, is binary data: a TLS client
on a plain port, a PostgreSQL client pointed at the wrong port, a PHP-FPM address that leads to Trap. Trap keeps the
bytes of the whole connection as one event.

A connection that sends no bytes makes no event in the web UI and the MCP tools: port checks such as `nc -z` and
TCP health checks of Docker or Kubernetes connect and close, and would push the other events out. The console still
prints it, with the size `0.00 B`.

```bash
printf '\x00\x01binary' | nc 127.0.0.1 9912
```

## Console

The console prints the size, the guessed format and a hexdump of the first 512 bytes.

## Web UI

The [web UI](web-ui.md) has no event type for raw bytes: it shows binary data as an [HTML page](html.md), an
`html` event titled `Binary data`, with the guessed format after a colon, as `Binary data: TLS ClientHello for
api.example.com`.

- The feed card is a hexdump of the first 256 bytes, with the size and the guessed format.
- The page of the event carries the first 1 MiB of the bytes and a viewer of them:
  - **Hex**: pages of 4 KiB with the offset, the hex and the ASCII; a jump to an offset (`0x1f0`, `1f0h` or `496`);
    a search for text or bytes (`16 03 01`); a click on a byte shows the integers and floats that start at it,
    little- and big-endian, and its UTF-8 character.
  - **Text**: the bytes decoded as UTF-8 or windows-1252, control characters as visible symbols; the first 256 KiB.
  - **Strings**: runs of printable ASCII, as the `strings` command finds them; a click shows one in Hex.
  - **Statistics**: the entropy, the share of NUL, control, printable and high bytes, the most frequent bytes, a
    histogram of the byte values and the entropy along the data.
  - **Download all bytes**: the whole connection, from `GET /api/binary/{uuid}` of the UI port.

The page sends no request of its own: its frame has no origin, and Trap refuses such a page the bytes as it refuses
it the UI API, see [Web UI](web-ui.md). The download is a link, which opens in a new tab.

The feed list `/api/events/preview` and the live feed carry only the card, so a large connection keeps the feed
light; `/api/event/{uuid}`, which the page of the event loads, and `/api/events` carry the viewer with the bytes.
The UI search finds the event by the text of its card: the title, the hex and the ASCII of the first 256 bytes.

## Guessed formats

Trap guesses the format from the first 4 KiB, by signature:

| Kind                   | Formats                                                                                                              |
| ---------------------- | -------------------------------------------------------------------------------------------------------------------- |
| A client of a protocol | TLS ClientHello with its server name, HTTP/2 preface, PROXY protocol, PostgreSQL, FastCGI, Redis, SSH, AMQP, MongoDB |
| Compressed or a file   | gzip, Zstandard, xz, bzip2, ZIP, zlib, PNG, JPEG, GIF, WebP, PDF                                                     |
| Serialized data        | PHP `serialize()`, igbinary, Java serialization, MessagePack, Protocol Buffers                                       |
| Text                   | JSON, ASCII or UTF-8 text                                                                                            |

A name that ends with `probably` is a weaker guess: the bytes read as the format, and nothing else told so.
The page and the MCP text add a hint, such as turning TLS on for the port for a TLS client.

## MCP

The [MCP tools](mcp.md#binary-data) list binary data as `html` events, told from pages by the `kind` field:
`get_event` gives the guessed format, the entropy and a hexdump of the first 1024 bytes, not the markup of the viewer.

## Memory

An event keeps the bytes of its connection: the first 64 KiB in memory, the rest in a temporary file, which goes
when the event does. The events limit of the UI pushes old ones out, see [Web UI](web-ui.md). A connection has no
size limit, so a large `maxEvents` with many large connections takes as much disk and keeps one open file each.

`vendor/bin/trap test` sends a PNG image as binary data.
