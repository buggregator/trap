<p align="center">
    <img alt="logo"
         src="https://github.com/buggregator/trap/blob/master/resources/payloads/logo.png?raw=true"
         style="width: 3in; display: block"
    />
</p>
<p align="center">Revolutionize Your Debugging Experience with PHP</p>
<h1 align="center">Buggregator Trap</h1>

<div align="center">

[![Twitter](https://img.shields.io/badge/-Follow-black?style=flat-square&logo=X)](https://twitter.com/buggregator)
[![Discord](https://img.shields.io/static/v1?style=flat-square&label=Join&message=Discord&logo=Discord&color=%235865F2)](https://discord.gg/qF3HpXhMEP)
[![Support](https://img.shields.io/static/v1?style=flat-square&label=Support&message=%E2%9D%A4&logo=GitHub&color=%23fe0086)](https://patreon.com/roxblnfk)

</div>

<br />

**Trap** is a package designed to enhance the debugging experience in conjunction with the Buggregator Server.  
Trap includes:

- A set of functions for direct interaction with any Buggregator server.
- Extensions for Symfony VarDumper that become active immediately after installing Trap.
- A minimized version of the [Buggregator Server](https://github.com/buggregator/server) that does not require Docker
  and is intended solely for local use.

**Table of content:**

- [Installation](#installation)
- [Overview](#overview)
- [Usage](#usage)
- [Contributing](#contributing)
- [License](#license)

## Installation

To install Buggregator Trap in your PHP application, add the package as a dev dependency
to your project using Composer:

```bash
composer require --dev buggregator/trap -W
```

[![PHP](https://img.shields.io/packagist/php-v/buggregator/trap.svg?style=flat-square&logo=php)](https://packagist.org/packages/buggregator/trap)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/buggregator/trap.svg?style=flat-square&logo=packagist)](https://packagist.org/packages/buggregator/trap)
[![License](https://img.shields.io/packagist/l/buggregator/trap.svg?style=flat-square)](LICENSE.md)
[![Total Downloads](https://img.shields.io/packagist/dt/buggregator/trap.svg?style=flat-square)](https://packagist.org/packages/buggregator/trap)

And that's it. Trap is [ready to go](#usage).

### Phar

Sometimes your project may conflict with Trap's dependencies, or you might be interested in using only the local
server (e.g., for analyzing local profiler files).
In this case, consider installing Trap as a Phar (a self-contained PHP executable).
Using wget:

```bash
wget https://github.com/buggregator/trap/releases/latest/download/trap.phar
chmod +x trap.phar
./trap.phar --version
```

Using [Phive](https://phar.io/):

```bash
phive install buggregator/trap
```

## Overview

Buggregator Trap provides a toolkit for use in your code. Firstly, just having Buggregator Trap in your
package enhances the capabilities of Symfony Var-Dumper.

If you've already worked with `google/protobuf`, you probably know how unpleasant it can be.
Now let's compare the dumps of protobuf-message: on the left (with trap) and on the right (without trap).

![trap-proto-diff](https://github.com/buggregator/trap/assets/4152481/30662429-809e-422a-83c6-61d7d2788b18)

This simultaneously compact and informative output format of protobuf message will be just as compact
and informative in the Buggregator Server interface. Now, working with protobuf is enjoyable.

---

Buggreagtor Trap includes a console application - a mini-server.
The application is entirely written in PHP and does not require Docker to be installed in the system.
It may seem like it's just the same as the `symfony/var-dumper` server, but it's not.
Buggregator Trap boasts a much wider range of handlers ("traps") for debug messages:

- Symfony var-dumper,
- Monolog,
- Sentry,
- SMTP,
- HTTP dumps,
- Ray,
- Any raw data

You can effortlessly visualize and analyze console information about various elements of your codebase.

Here's a sneak peek into the console output you can expect with Trap:

| symfony/var-dumper (proto)                                                                             | Binary Data                                                                                             |
|--------------------------------------------------------------------------------------------------------|---------------------------------------------------------------------------------------------------------|
| ![var-dumper](https://github.com/buggregator/trap/assets/4152481/f4c855f5-87c4-4534-b72d-5b19d1aae0b0) | ![Binary Data](https://github.com/buggregator/trap/assets/4152481/cd8788ed-b10c-4b9a-b2e2-baa8912ea38d) |

| SMTP Mail Trap                                                                                   | HTTP Dump                                                                                         |
|--------------------------------------------------------------------------------------------------|---------------------------------------------------------------------------------------------------|
| ![smtp](https://github.com/buggregator/trap/assets/4152481/b11c4a7f-072a-4e66-b11d-9bbd3177bfe2) | ![http-dump](https://github.com/buggregator/trap/assets/4152481/48201ce6-7756-4402-8954-76a27489b632) |

---

Additionally, you can manually set traps in the code. Use the `trap()` function,
which works almost the same as Symfony's `dump()`, but configures the dumper to send dumps to the local server,
unless other user settings are provided.

Also, the `trap()` has a lot of useful options:

```php
// Limit the depth of the dumped structure
trap($veryDeepArray)->depth(3);

foreach ($veryLargeArray as $item) {
    // We don't need to dump more than 3 items
    trap($item)->times(3);
}

// Dump once if the condition is true
trap($animal)->once()->if($var instanceof Animal\Cat);
```

---

> [!TIP]
> Feature in development:
> add the flag `--ui` to rise the web interface of the Buggregator Server without docker.
![trap-ui](https://github.com/buggregator/trap/assets/4152481/1ccc2c85-2f81-4b62-8ae7-49ee76380674)
---

We care about the quality of our products' codebase and strive to provide the best user experience.
Buggregator Trap has passed the Proof of Concept stage and is now an important part of the Buggregator ecosystem.
We have big plans for the development of the entire ecosystem and we would be delighted if you join us on this journey.

## Usage

After successfully [installing](#installation) Buggregator Trap, you can initiate the debugging process by using the following command:

```bash
vendor/bin/trap
```

This command will start the Trap server, ready to receive any debug messages. Once a debug message is trapped, you will see a convenient report about it right here in the terminal.

Then just call the `trap()` function in your code:

```php
// dump the current stack trace
trap()->stackTrace();

// dump a variable with a depth limit
trap($var)->depth(4);

 // dump a named variables sequence
trap($var, foo: $far, bar: $bar);

// dump a variable and return it
$responder->respond(trap($response)->return()); 
```

> **Note**:
> The `trap()` function configures `$_SERVER['REMOTE_ADDR']` and `$_SERVER['REMOTE_PORT']` automatically,
> if they are not set.

Also, there are a couple of shortcuts here:

- `tr(...)` - equivalent to `trap(...)->return()`
- `td(...)` - equivalent to `trap(...); die;`

If called without arguments, they will display a short stack trace, used memory, and time between shortcut calls.

```php
function handle($input) {
    tr(); // Trace #0  -.---  3.42M

    $data = $this->prepareData($input);

    tr(); // Trace #1  0.015ms  6.58M

    $this->processor->process(tr(data: $data));

    td(); // exit with output: Trace #2  1.15ms  7.73M
}
```

### Default port

Trap automatically recognizes the type of traffic.
Therefore, there is no need to open separate ports for different protocols.
By default, it operates on the same ports as the Buggregator Server: `9912`, `9913`, `1025`, and `8000`.
However, if you wish to utilize a different port, you can easily make this adjustment using the `-p` option:

```bash
vendor/bin/trap -p9912 --ui=8000
```

Environment variables can also be used to set endpoints:

- `TRAP_TCP_PORTS` - for TCP traffic: `9912,9913,1025,8000`
- `TRAP_TCP_HOST` - for the TCP host (default: `127.0.0.1`)
- `TRAP_UI_PORT` - for the web interface: `8080`

### Choosing Your Senders

Buggregator Trap provides a variety of "senders" that dictate where the dumps will be sent. Currently, the available
sender options include:

- `console`: This option displays dumps directly in the console.
- `server`: With this choice, dumps are sent to a remote Buggregator server.
- `file`: This allows for dumps to be stored in a file for future reference.

By default, the Trap server is set to display dumps in the console. However, you can easily select your preferred
senders using the `-s` option.

For instance, to simultaneously use the console, file, and server senders, you would input:

```bash
vendor/bin/trap -s console -s file -s server
```

## Contributing

We believe in the power of community-driven development. Here's how you can contribute:

- **Report Bugs:** Encounter a glitch? Let us know on our [issue tracker](https://github.com/buggregator/trap/issues).
- **Feature Suggestions:** Have ideas to improve the Buggregator Trap? [Create a feature request](https://github.com/buggregator/trap/issues)!
- **Code Contributions:** Submit a pull request to help us improve the Buggregator Trap codebase. You can find a list of
  issues labeled "help wanted" [here](https://github.com/buggregator/trap/issues?q=is%3Aopen+is%3Aissue+label%3A%22help+wanted%22).
- **Documentation:** Help us improve our [guides and tutorials](https://github.com/buggregator/docs/tree/master/docs) for a smoother user experience.
- **Community Support:** Join our [Discord](https://discord.gg/qF3HpXhMEP) and help others get the most out of Buggregator.
- **Spread the Word:** Share your experience with Buggregator on social media and encourage others to contribute.
- **Donate:** Support our work by becoming a patron or making a one-time donation  
  [![roxblnfk](https://img.shields.io/endpoint.svg?url=https%3A%2F%2Fshieldsio-patreon.vercel.app%2Fapi%3Fusername%3Droxblnfk%26type%3Dpatrons&label=roxblnfk&style=flat-square)](https://patreon.com/roxblnfk)
  [![butschster](https://img.shields.io/endpoint.svg?url=https%3A%2F%2Fshieldsio-patreon.vercel.app%2Fapi%3Fusername%3Dbutschster%26type%3Dpatrons&label=butschster&style=flat-square)](https://patreon.com/butschster)

**Remember, every great developer was once a beginner. Contributing to open source projects is a step in your journey to
becoming a better developer. So, don't hesitate to jump in and start contributing!**

## License

Buggregator Trap is open-sourced software licensed under the BSD-3 license.

<!--

[![Contributors](https://contrib.rocks/image?repo=buggregator/trap)](https://github.com/buggregator/trap/graphs/contributors)

Quality badges:

[![Tests Status](https://img.shields.io/github/actions/workflow/status/buggregator/trap/testing.yml?label=tests&style=flat-square)](https://github.com/buggregator/trap/actions/workflows/testing.yml?query=workflow%3Atesting%3Amaster)
[![Dependency status](https://php.package.health/packages/buggregator/trap/dev-master/status.svg)](https://php.package.health/packages/buggregator/trap/dev-master)

# (tests coverage)
# (types coverage)
# (psalm level)
# (static analysis)
# (mutation)
# (scrutinizer score)
# (code style)
-->
