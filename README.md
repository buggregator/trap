# Buggregator Trap: Revolutionize Your Debugging Experience with PHP

**Support us on Patreon**  
[![roxblnfk](https://img.shields.io/endpoint.svg?url=https%3A%2F%2Fshieldsio-patreon.vercel.app%2Fapi%3Fusername%3Droxblnfk%26type%3Dpatrons&style=flat)](https://patreon.com/roxblnfk)
[![butschster](https://img.shields.io/endpoint.svg?url=https%3A%2F%2Fshieldsio-patreon.vercel.app%2Fapi%3Fusername%3Dbutschster%26type%3Dpatrons&style=flat)](https://patreon.com/butschster)

**Follow us on Twitter**  
[![Twitter](https://img.shields.io/badge/twitter-Follow-blue)](https://twitter.com/buggregator)

- [Installation](#installation)
- [Usage](#usage)


Buggregator Trap, the streamlined Command Line Interface (CLI) version of Buggregator, marks a new era in debugging PHP applications. Boasting an array of powerful debugging "traps", including:

- Symfony var-dumper,
- Monolog,
- Sentry,
- SMTP,
- HTTP dumps,
- and Ray

This lightweight tool facilitates and streamlines the process of debugging for developers, regardless of their environment.

It enables you to easily install a client in your PHP application using a Composer package and run a local server specifically designed for debugging. This isn't just a debugging tool, it's a supercharged version of the `symfony/var-dumper` server, designed to offer more versatility and in-depth insights into your code.

Now you can effortlessly visualize and analyze console information about various elements of your codebase.

Here's a sneak peek into the console output you can expect with traps:

### symfony/var-dumper

```bash
Spatie\LaravelRay\Ray {#318
  +settings: Spatie\Ray\Settings\Settings^ {#39
    #settings: array:18 [
      "enable" => true
      "host" => "ray@127.0.0.1"
      "port" => "9912"
      "remote_path" => null
      "local_path" => null
      "always_send_raw_values" => false
      "send_cache_to_ray" => false
      "send_dumps_to_ray" => true
      "send_jobs_to_ray" => false
      "send_log_calls_to_ray" => true
      "send_queries_to_ray" => false
      "send_requests_to_ray" => false
      "send_http_client_requests_to_ray" => false
      "send_views_to_ray" => false
      "send_exceptions_to_ray" => true
      "send_duplicate_queries_to_ray" => false
      "send_slow_queries_to_ray" => false
      "send_deprecated_notices_to_ray" => false
    ]
    #loadedUsingSettingsFile: true
    #defaultSettings: array:6 [
      "enable" => true
      "host" => "localhost"
      "port" => 23517
      "remote_path" => null
      "local_path" => null
      "always_send_raw_values" => false
    ]
  }
  +limitOrigin: null
  +uuid: "df8f5bb2-db81-4d47-bf99-5797f2232ef6"
  +canSendPayload: true
}

POST http://127.0.0.1:8000/
---------------------------

 -------- -----------------------------------------------------------------
  date     Sun, 21 May 2023 20:58:10 +0000
  source   Common.php on line 46
  file     ~/app/Modules/VarDump/Common.php
 -------- -----------------------------------------------------------------
```

### monolog

```bash
+---------+----------------------------------+
| date    | 2023-05-21T20:59:33.153982+00:00 |
| channel | production                       |
+---------+----------------------------------+

 MONOLOG  WARNING

Hello warning

array:1 [
  "foo" => "bar"
]
```

### http-dump

```bash
 HTTP GET

+------+----------------------------+
| Time | 2023-05-21 21:00:34.988438 |
| URI  | /_debug?success=1          |
+------+----------------------------+

+- Query ... -+
| success | 1 |
+---------+---+

+-----------------+------------------------ Cookies ------------------------------------------+
| _ga             | GA1.1.734706.1673627                                                      |
| _lfa            | LF1.1.f98950321ce4fe9.3271587691                                          |
| theme           | dark                                                                      |
| _ga_111YG0MHMC  | GS1.1.1683808.27.1.1683828.0.0.0                                          |
| _ga_TD1X69YDT5  | GS1.1.4422450.42.0.1682450.0.0.0                                          |
+-----------------+---------------------------------------------------------------------------+

+---------------------------+-------------- Headers ------------------------------------------+
| Host                      | 127.0.0.1:9912                                                  |
| Connection                | keep-alive                                                      |
| Cache-Control             | max-age=0                                                       |
| sec-ch-ua                 | "Google Chrome";v="113", "Chromium";v="113", "Not-A.Brand";v="2 |
|                           | 4"                                                              |
| sec-ch-ua-mobile          | ?0                                                              |
| sec-ch-ua-platform        | "Windows"                                                       |
| Upgrade-Insecure-Requests | 1                                                               |
| User-Agent                | Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (K |
|                           | HTML, like Gecko) Chrome/113.0.0.0 Safari/537.36                |
| Accept                    | text/html,application/xhtml+xml,application/xml;q=0.9,image/avi |
|                           | f,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v |
|                           | =b3;q=0.7                                                       |
| Sec-Fetch-Site            | same-origin                                                     |
| Sec-Fetch-Mode            | navigate                                                        |
| Sec-Fetch-Dest            | document                                                        |
| Referer                   | http://127.0.0.1:9912/_debug                                    |
| Accept-Encoding           | gzip, deflate, br                                               |
| Accept-Language           | en-US,en;q=0.9,ru-RU;q=0.8,ru;q=0.7,ka;q=0.6                    |
+---------------------------+-----------------------------------------------------------------+
```

### smtp

```bash
 SMTP

+------+----------------------------+
| Time | 2023-05-21 21:01:51.621326 |
+------+----------------------------+

+------+------ Addresses -------------+
| from | Laravel [site@localhost.com] |
| to   | zechariah63@bashirian.com    |
+------+------------------------------+

Welcome Mail
---

Debug with Buggregator to fix problems faster

* Use in WordPress or any PHP project
* See models, mails, queries, … in Laravel
* Debug locally or via SSH
* Works with Javascript, Node.js and Ruby
* Measure performance & set breakpoints

Download: https://github.com/buggregator/app
```

In addition to the local debugging features, Buggregator Trap provides an innovative functionality as a proxy client. It can transmit data to a remote Buggregator server, thereby facilitating a centralized debugging process for your team or organization.


## Installation

To install Buggregator Trap in your PHP application, add the package as a dependency to your project using Composer:

```bash
composer require --dev buggregator/trap:*
```

## Usage

After successfully installing Buggregator Trap, you can initiate the debugging process by using the following command:

```bash
vendor/bin/trap
```

This command will activate the Trap server, setting it up to listen for all TCP requests. Upon receiving these requests, the server will search for a corresponding listener that can process the incoming data and display a dump accordingly.

Then just call the `trap()` function in your code:

```php
trap(); // dump current stack trace
trap($var); // dump variable
trap($var, foo: $far, bar: $bar); // dump variables sequence
```

The `trap()` function configures `$_SERVER['REMOTE_ADDR']` and `$_SERVER['REMOTE_PORT']` automatically, if they are not set. Also, it can dump google/protobuf messages.

### Default port

By default, the Trap server operates on port `9912`. However, if you wish to utilize a different port, you can easily make this adjustment using the `-p` option. 

For example, to switch to port 8000, you would use the following command:

```bash
vendor/bin/trap -p 8000
```

### Choosing Your Senders

Buggregator Trap provides a variety of "senders" that dictate where the dumps will be sent. Currently, the available sender options include:

- `console`: This option displays dumps directly in the console.
- `server`: With this choice, dumps are sent to a remote Buggregator server.
- `file`: This allows for dumps to be stored in a file for future reference.

By default, the Trap server is set to display dumps in the console. However, you can easily select your preferred senders using the `-s` option. 

For instance, to simultaneously use the console, file, and server senders, you would input:

```bash
vendor/bin/trap -s console -s file -s server
```

## Contributing

We enthusiastically invite you to contribute to Buggregator Trap! Whether you've uncovered a bug, have innovative feature suggestions, or wish to contribute in any other capacity, we warmly welcome your participation. Simply open an issue or submit a pull request on our GitHub repository to get started.

We use the [help wanted](https://github.com/buggregator/trap/issues?q=is%3Aopen+is%3Aissue+label%3A%22help+wanted%22) label to categorize all issues that would benefit from your help in the repository. 

### Why Should Developers Contribute to Open Source?

Open source contributions, such as to Buggregator Trap, present a unique and rewarding opportunity, especially for junior developers. 

Here are a few reasons why you should consider contributing:

1. **Experiment with New Technologies:** Open source projects can expose you to technologies and frameworks you might not encounter in your daily job. It's an excellent opportunity to learn and try out new things.
2. **Expand Your Network:** Collaborating on open source projects connects you with a global community of developers. You can learn from their experiences, and they can learn from yours.
3. **Improve Your Résumé:** Potential employers often value open source contributions. They demonstrate initiative, technical competency, and the ability to work collaboratively
4. **Learn Best Practices:** Code reviews and feedback in open source projects are valuable learning tools. They expose you to different perspectives and ways to improve your code.
5. **Create Impact:** Your contributions can help others and make a meaningful impact on the project. The feeling of seeing your code being used by others is immensely satisfying.

**Remember, every great developer was once a beginner. Contributing to open source projects is a step in your journey to becoming a better developer. So, don't hesitate to jump in and start contributing!**

## License

Buggregator Trap is open-sourced software licensed under the BSD-3 license.
