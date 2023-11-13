<div align="center">
    <img alt="logo" src="https://github.com/buggregator/trap/assets/4152481/c53e7107-e1c5-48b9-9789-4a6bce9b903b" style="width: 3in" />
    <div>Revolutionize Your Debugging Experience with PHP</div>
</div>
<h1 align="center">Buggregator Trap</h1>

<div align="center">

**Support us on Patreon**  
[![roxblnfk](https://img.shields.io/endpoint.svg?url=https%3A%2F%2Fshieldsio-patreon.vercel.app%2Fapi%3Fusername%3Droxblnfk%26type%3Dpatrons&style=flat-square)](https://patreon.com/roxblnfk)  [![butschster](https://img.shields.io/endpoint.svg?url=https%3A%2F%2Fshieldsio-patreon.vercel.app%2Fapi%3Fusername%3Dbutschster%26type%3Dpatrons&style=flat-square)](https://patreon.com/butschster)

**Community** <!-- and **Documentation** -->  
[![Twitter](https://img.shields.io/badge/twitter-Follow-blue?style=flat-square&logo=twitter)](https://twitter.com/buggregator)
[![Discord](https://img.shields.io/discord/1172942458598985738?style=flat-square&logo=discord&color=blue)](https://discord.gg/qF3HpXhMEP)

<!-- 
[![PHP](https://img.shields.io/packagist/php-v/buggregator/trap.svg?style=flat-square&logo=php)](https://packagist.org/packages/buggregator/trap)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/buggregator/trap.svg?style=flat-square&logo=packagist)](https://packagist.org/packages/buggregator/trap)
[![Total Downloads](https://img.shields.io/packagist/dt/buggregator/trap.svg?style=flat-square)](https://packagist.org/packages/buggregator/trap)
[![dependency status](https://php.package.health/packages/buggregator/trap/dev-master/status.svg)](https://php.package.health/packages/buggregator/trap/dev-master)
 [![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/buggregator/trap/run-tests.yml?label=tests&style=flat-square)](https://github.com/buggregator/trap/actions?query=workflow%3Arun-tests+branch%3Amain) -->

</div>

- [Intro](#intro)
- [Installation](#installation)
- [Usage](#usage)
- [Contributing](#contributing)
- [License](#license)


## Intro

Buggregator Trap, the streamlined Command Line Interface (CLI) version of Buggregator, marks a new era in debugging PHP
applications. Boasting an array of powerful debugging "traps", including:

- Symfony var-dumper,
- Monolog,
- Sentry,
- SMTP,
- HTTP dumps,
- Ray,
- And any raw data

This lightweight tool facilitates and streamlines the process of debugging for developers, regardless of their
environment.

It enables you to easily install a client in your PHP application using a Composer package and run a local server
specifically designed for debugging. This isn't just a debugging tool, it's a supercharged version of
the `symfony/var-dumper` server, designed to offer more versatility and in-depth insights into your code.

Now you can effortlessly visualize and analyze console information about various elements of your codebase.

Here's a sneak peek into the console output you can expect with traps:

| symfony/var-dumper (proto)                                                                             | Binary Data                                                                                             |
|--------------------------------------------------------------------------------------------------------|---------------------------------------------------------------------------------------------------------|
| ![var-dumper](https://github.com/buggregator/trap/assets/4152481/f4c855f5-87c4-4534-b72d-5b19d1aae0b0) | ![Binary Data](https://github.com/buggregator/trap/assets/4152481/cd8788ed-b10c-4b9a-b2e2-baa8912ea38d) |

| SMTP Mail Trap                                                                                   | HTTP Dump                                                                                         |
|--------------------------------------------------------------------------------------------------|---------------------------------------------------------------------------------------------------|
| ![smtp](https://github.com/buggregator/trap/assets/4152481/b11c4a7f-072a-4e66-b11d-9bbd3177bfe2) | ![http-dump](https://github.com/buggregator/trap/assets/4152481/48201ce6-7756-4402-8954-76a27489b632) |

In addition to the local debugging features, Buggregator Trap provides an innovative functionality as a proxy client. It
can transmit data to a remote Buggregator server, thereby facilitating a centralized debugging process for your team or
organization.

## Installation

To install Buggregator Trap in your PHP application, add the package as a dependency to your project using Composer:

```bash
composer require --dev buggregator/trap
```


## Usage

After successfully installing Buggregator Trap, you can initiate the debugging process by using the following command:

```bash
vendor/bin/trap
```

This command will activate the Trap server, setting it up to listen for all TCP requests. Upon receiving these requests,
the server will search for a corresponding listener that can process the incoming data and display a dump accordingly.

Then just call the `trap()` function in your code:

```php
trap(); // dump current stack trace
trap($var); // dump variable
trap($var, foo: $far, bar: $bar); // dump variables sequence
```

The `trap()` function configures `$_SERVER['REMOTE_ADDR']` and `$_SERVER['REMOTE_PORT']` automatically, if they are not
set. Also, it can dump google/protobuf messages.

### Default port

By default, the Trap server operates on port `9912`. However, if you wish to utilize a different port, you can easily
make this adjustment using the `-p` option.

For example, to switch to port 8000, you would use the following command:

```bash
vendor/bin/trap -p 8000
```

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

We enthusiastically invite you to contribute to Buggregator Trap! Whether you've uncovered a bug, have innovative
feature suggestions, or wish to contribute in any other capacity, we warmly welcome your participation. Simply open an
issue or submit a pull request on our GitHub repository to get started.

We use the [help wanted](https://github.com/buggregator/trap/issues?q=is%3Aopen+is%3Aissue+label%3A%22help+wanted%22)
label to categorize all issues that would benefit from your help in the repository.

### Why Should Developers Contribute to Open Source?

Open source contributions, such as to Buggregator Trap, present a unique and rewarding opportunity, especially for
junior developers.

Here are a few reasons why you should consider contributing:

1. **Experiment with New Technologies:** Open source projects can expose you to technologies and frameworks you might
   not encounter in your daily job. It's an excellent opportunity to learn and try out new things.
2. **Expand Your Network:** Collaborating on open source projects connects you with a global community of developers.
   You can learn from their experiences, and they can learn from yours.
3. **Improve Your Résumé:** Potential employers often value open source contributions. They demonstrate initiative,
   technical competency, and the ability to work collaboratively
4. **Learn Best Practices:** Code reviews and feedback in open source projects are valuable learning tools. They expose
   you to different perspectives and ways to improve your code.
5. **Create Impact:** Your contributions can help others and make a meaningful impact on the project. The feeling of
   seeing your code being used by others is immensely satisfying.

**Remember, every great developer was once a beginner. Contributing to open source projects is a step in your journey to
becoming a better developer. So, don't hesitate to jump in and start contributing!**


## License

Buggregator Trap is open-sourced software licensed under the BSD-3 license.
