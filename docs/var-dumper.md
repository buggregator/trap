# Symfony VarDumper

Trap receives dumps of the [Symfony VarDumper](https://symfony.com/doc/current/components/var_dumper.html) server
protocol, the one of `bin/console server:dump`. Run Trap instead of the dump server, and point `dump()` at it.

![var-dumper](https://github.com/buggregator/trap/assets/4152481/f4c855f5-87c4-4534-b72d-5b19d1aae0b0)

## Receive dumps

Trap accepts the dumps on any of its ports; the default one is `9912`, the default port of the VarDumper server.

`trap()` sends there with no setup: see [Functions](functions.md#where-dumps-go).

`dump()` sends there when the `VAR_DUMPER_FORMAT` server variable is `server`:

```bash
VAR_DUMPER_FORMAT=server
VAR_DUMPER_SERVER=127.0.0.1:9912
```

`VAR_DUMPER_SERVER` defaults to `127.0.0.1:9912`. `VAR_DUMPER_FORMAT` may also be a `tcp://host:port` address.
Set the variables in the environment of PHP or in a `.env` file your framework loads into `$_SERVER`.

In a Symfony application, the `dump_destination` option of the DebugBundle does the same:

```yaml
# config/packages/dev/debug.yaml
debug:
    dump_destination: "tcp://%env(VAR_DUMPER_SERVER)%"
```

## Formats of `VAR_DUMPER_FORMAT`

| Value             | Where dumps go                    |
|-------------------|-----------------------------------|
| `server`          | The server at `VAR_DUMPER_SERVER` |
| `tcp://host:port` | The server at this address        |
| `cli`             | Printed as text in place          |
| `html`            | Printed as HTML in place          |

## Protobuf messages

Trap registers VarDumper casters for `google/protobuf` messages, repeated fields, maps and enums. They work in any
`dump()` of a project with Trap installed, with or without the server.
See the comparison in [Functions](functions.md#protobuf-messages).
