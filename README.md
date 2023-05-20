# Buggregator Trap

**Support us on Patreon**  
[![roxblnfk](https://img.shields.io/endpoint.svg?url=https%3A%2F%2Fshieldsio-patreon.vercel.app%2Fapi%3Fusername%3Droxblnfk%26type%3Dpatrons&style=flat)](https://patreon.com/roxblnfk)
[![butschster](https://img.shields.io/endpoint.svg?url=https%3A%2F%2Fshieldsio-patreon.vercel.app%2Fapi%3Fusername%3Dbutschster%26type%3Dpatrons&style=flat)](https://patreon.com/butschster)

**Follow us on Twitter**  
[![Twitter](https://img.shields.io/badge/twitter-Follow-blue)](https://twitter.com/buggregator)

Buggregator Trap is a lightweight CLI version of Buggregator, a powerful debugging tool for PHP applications.
It provides essential debugging features (traps) such as

- Symfony var-dumper,
- Monolog,
- Sentry,
- SMTP,
- HTTP dumps,
- Ray.

The CLI version allows you to install a client in your PHP application using a Composer package and run a local server
for debugging.

## Installation

To install Buggregator Trap in your PHP application, add the package as a dependency to your project using Composer:

```bash
composer require --dev buggregator/trap *
```

## Usage

Once the installation is complete, you can start the Buggregator Trap by running the following command:

```bash
vendor/bin/trap
```

This command will start the Trap server and make it listen to all TCP requests, attempting to find a listener that can
handle incoming requests and display a dump for them.

## Contributing

We welcome contributions to Buggregator Trap! If you find any bugs, have feature suggestions, or would like to
contribute in any other way, please open an issue or submit a pull request on the GitHub repository.

## License

Buggregator Trap is open-sourced software licensed under the BSD-4 license.
