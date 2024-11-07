# fakerde (& fakezone)

This repository contains two PHP scripts:

1. [fakerde.php](fakerde.php), which uses [PHP Faker](https://fakerphp.org) to generate fake registry data escrow files (see [RFC 8909](https://www.rfc-editor.org/info/rfc8909) and [RFC 9022](https://www.rfc-editor.org/info/rfc9022)).
2. [fakezone.php](fakezone.php), which uses PHP Faker to generate fake delegation-centric (ie TLD) zone files;

These may be used for various purposes.

## Usage

Run `composer install` to install the required dependencies. Then run `php fake{rde|zone}.php --help` to see usage instructions for each tool.

## Copyright & License

Copyright 2023-2024 Gavin Brown. See [LICENSE](LICENSE) for licensing information.
