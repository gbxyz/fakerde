# fakerde (& fakezone)

This repository contains two PHP scripts:

1. [fakerde.php](fakerde.php), which uses [PHP Faker](https://fakerphp.org) to
   generate fake registry data escrow files (see [RFC
   8909](https://www.rfc-editor.org/info/rfc8909) and [RFC
   9022](https://www.rfc-editor.org/info/rfc9022)).
2. [fakezone.php](fakezone.php), which uses PHP Faker to generate fake
   delegation-centric (ie TLD) zone files;

These may be used for various purposes.

## Usage

### fakezone.php

```
Usage: php fakezone.php OPTIONS

Options:
  --help           show this help
  --origin=ORIGIN  specify zone name, use '.' to generate
                   a fake root zone
  --count=COUNT    specify zone size (in delegations)
  --secure=RATIO   what fraction of delegations is secure (default: 2%)
```

### fakerde.php

```
Usage: php fakerde.php OPTIONS

Options:
  --help               show this help
  --origin=ORIGIN      specify zone name
  --resend=RESEND      specify resend (default 0)
  --input=FILE         specify zone file to parse
  --registrant         add registrant to domains
  --admin              add admin contact to domains
  --tech               add tech contact to domains
  --host-attributes    use host attributes instead of objects
  --encrypt=KEY        generate an encrypted .ryde file as well as the XML
  --sign=KEY           generate a .sig file as well as the encrypted .ryde file
  --xml                generate an XML deposit (the default)
  --csv                generate a CSV deposit (cannot be combined with --xml, not fully implemented)
  --no-report          do not generate a .rep file
```

## Limitations

PHP Faker uses lists of examples to generate things like domain names,
addresses, and so on. So both fakerde.php and fakezone.php will struggle to
generate files containing large numbers of domains.

## Copyright & License

Copyright 2023-2025 Gavin Brown. See [LICENSE](LICENSE) for licensing
information.
