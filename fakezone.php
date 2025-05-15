#!/usr/bin/env php
<?php declare(strict_types=1);

namespace gbxyz\fakezone;

ini_set('memory_limit', -1);

require_once __DIR__.'/vendor/autoload.php';

if (realpath($argv[0]) == realpath(__FILE__)) {
    exit(generator::main());
}

/**
 * generate a realistic-looking XML Registry Data Escrow (RDE) deposit file
 * for a generic TLD
 * @see generator::main()
 * @see RFC8909
 * @see RFC9022
 */
final class generator {

    private static $nscounts = [
        1771,       // No. domains in .org with 1 nameserver, as of Feb 2025
        8077751,    // No. domains in .org with 2 nameservers, as of Feb 2025
        8935795,    // No. domains in .org with 3 nameservers, as of Feb 2025
        10688768,   // No. domains in .org with 4 nameservers, as of Feb 2025
        10881573,   // No. domains in .org with 5 nameservers, as of Feb 2025
        10924018,   // No. domains in .org with 6 nameservers, as of Feb 2025
        10949202,   // No. domains in .org with 7 nameservers, as of Feb 2025
        11087174,   // No. domains in .org with 8 nameservers, as of Feb 2025
        11087592,   // No. domains in .org with 9 nameservers, as of Feb 2025
        11088441,   // No. domains in .org with 10 nameservers, as of Feb 2025
        11088527,   // No. domains in .org with 11 nameservers, as of Feb 2025
        11089979,   // No. domains in .org with 12 nameservers, as of Feb 2025
        11090026,   // No. domains in .org with 13 nameservers, as of Feb 2025
    ];

    // number of .org domains with DNSSEC as of Feb 2025
    const DEFAULT_SECURE    = 0.02;

    const DEFAULT_COUNT     = 1000;
    const DEFAULT_TTL       = 3600;
    const DEFAULT_IDN       = 0.1;

    private static array $locales = [];

    /**
     * disallow instantiation
     */
    private function __construct() {
    }

    /**
     * entrypoint which is called when the script is invoked
     */
    public static function main(): int {
        $opt = getopt('', [
            'help',
            'origin:',
            'count:',
            'secure:',
            'seed:',
            'ttl:',
            'idn:',
            'idn-tables:',
            'nameservers:',
        ]);

        if (array_key_exists('help', $opt) || !array_key_exists('origin', $opt)) return self::help();

        self::$locales = array_values(array_map(
            fn ($f) => basename($f),
            array_filter(
                glob(__DIR__.'/vendor/fakerphp/faker/src/Faker/Provider/*'),
                fn ($f) => is_dir($f),
            ),
        ));

        $origin = strToLower(trim($opt['origin'], " \n\r\t\v\x00."));

        if (2 == strlen($origin)) {
            $locales = array_filter(self::$locales, fn($l) => str_ends_with(strtolower($l), '_'.$origin));

            if (!empty($locales)) self::$locales = array_values($locales);
        }

        return self::generate(
            origin:         $origin,
            count:          intval($opt['count'] ?? self::DEFAULT_COUNT),
            secure:         floatval($opt['secure'] ?? self::DEFAULT_SECURE),
            seed:           array_key_exists('seed', $opt) ? intval($opt['seed']) : random_int(0, pow(2, 32)),
            ttl:            intval($opt['ttl'] ?? self::DEFAULT_TTL),
            idn:            floatval($opt['idn'] ?? self::DEFAULT_IDN),
            idn_tables:     array_key_exists('idn-tables', $opt) ? explode(',', $opt['idn-tables']) : [],
            nameservers:    array_key_exists('nameservers', $opt) ? explode(',', $opt['nameservers']) : [],
        );
    }

    /**
     * display user help
     */
    private static function help(): int {
        global $argv;
        $fh = fopen('php://stderr', 'w');
        fprintf($fh, "Usage: php %s OPTIONS\n\nOptions:\n", $argv[0]);
        fwrite($fh, "  --help               show this help\n");
        fwrite($fh, "  --origin=ORIGIN      specify zone name, use '.' to generate\n");
        fwrite($fh, "                       a fake root zone\n");
        fwrite($fh, "  --count=COUNT        specify zone size (in delegations)\n");
        fwrite($fh, "  --secure=RATIO       what fraction of delegations are secure (default: 2%)\n");
        fwrite($fh, "  --ttl=TTL            TTL to use for records in the zone file (default: 3600)\n");
        fwrite($fh, "  --seed=SEED          random seed\n");
        fwrite($fh, "  --idn-tables=LIST    comma-separated list of IDN tables\n");
        fwrite($fh, "  --idn=RATIO          what fraction of delegations are IDNs (default: 10%)\n");
        fwrite($fh, "  --nameservers=LIST   comma-separated lists of nameservers for the zone (default: ns{2-6}.nic.zone)\n");
        fwrite($fh, "\n");
        fclose($fh);
        return 1;
    }

    /**
     * tell the user about something interesting
     */
    private static function info(string $error) {
        fprintf(STDERR, "INFO: %s\n", $error);
    }

    /**
     * tell the user about a fatal error
     */
    private static function die(string $error) {
        fprintf(STDERR, "ERROR: %s\n", $error);
        exit(1);
    }

    private static function generate(
        string  $origin,
        int     $count,
        float   $secure,
        int     $seed,
        int     $ttl,
        float   $idn,
        array   $idn_tables,
        array   $nameservers,
    ): int {
        mt_srand($seed);

        foreach ($idn_tables as $tag) {
            try {
                self::mapTableToLocale($tag);

            } catch (\Throwable $e) {
                self::die($e->getMessage());

            }
        }

        printf("; RANDOM SEED = %u\n", $seed);
        echo "\n";

        $status = self::generateApex($origin, $ttl, $nameservers);

        $status +=self::generateDelegations(
            origin:     $origin,
            count:      $count,
            secure:     $secure,
            ttl:        $ttl,
            idn:        $idn,
            idn_tables: $idn_tables,
        );

        return $status;
    }

    private static function generateApex(string $origin, int $ttl, array $nameservers): int {
        $serial = self::faker()->numberBetween(0, pow(2, 16));

        echo "; BEGIN ZONE APEX\n";
        echo "\n";

        if (empty($nameservers)) {
            for ($i = 1 ; $i <= self::faker()->numberBetween(2, 6) ; $i++) {
                $nameservers[] = sprintf('ns%u.%s', $i, (empty($origin) ? 'root-servers.net' : sprintf('nic.%s', $origin)));
            }
        }

        printf(
            "%s. %u IN SOA %s. contact.nic.%s. %u 1800 900 604800 86400\n",
            $origin,
            $ttl,
            $nameservers[0],
            $origin,
            $serial,
        );

        foreach ($nameservers as $ns) {
            printf(
                "%s. %u IN NS %s.\n",
                $origin,
                $ttl,
                $ns,
            );

            if (str_ends_with($ns, $origin)) {
                printf(
                    "%s. %u IN A %s\n",
                    $ns,
                    $ttl,
                    self::faker()->ipv4(),
                );

                printf(
                    "%s. %u IN AAAA %s\n",
                    $ns,
                    $ttl,
                    self::faker()->ipv6(),
                );
            }
        }

        echo "\n";
        echo "; END ZONE APEX\n";

        return 0;
    }

    private static function generateUniqueLabel(
        array   $idn_tables,
        float   $idn,
    ): string {
        static $seen = [];

        if (self::faker()->numberBetween(0, PHP_INT_MAX) / PHP_INT_MAX <= $idn && count($idn_tables) > 0) {
            $table = self::faker()->randomElement($idn_tables);
            $locale = self::mapTableToLocale($table);

            $label = str_replace(" ", "", mb_strtolower(self::faker($locale)->lastName()));

            while (isset($seen[$label])) {
                $label .= str_replace(" ", "", mb_strtolower(self::faker()->lastName()));
            }

            $label = idn_to_ascii($label);

        } else {
            $label = self::faker()->domainWord();

            while (isset($seen[$label])) {
                $label .= '-' . self::faker()->domainWord();
            }
        }

        if (strlen($label) > 63) {
            self::info("label length exceeded 63 characters, restarting");
            return self::generateUniqueLabel($idn_tables, $idn);

        } else {
            $seen[$label] = 1;
            return $label;

        }
    }

    private static function mapTableToLocale(string $table): string {
        if (str_starts_with($table, 'und-')) {
            $script = substr(strtolower($table), -4);

            return match($script) {
                'arab'  => self::mapTableToLocale('ar'),
                'armn'  => self::mapTableToLocale('hy'),
//              'bali'  => self::mapTableToLocale('XX'),
//              'beng'  => self::mapTableToLocale('XX'),
                'cyrl'  => self::mapTableToLocale(self::faker()->randomElement(['ru', 'ua'])),
//              'deva'  => self::mapTableToLocale('XX'),
//              'ethi'  => self::mapTableToLocale('XX'),
                'geor'  => self::mapTableToLocale('ka'),
                'grek'  => self::mapTableToLocale('el'),
//              'gujr'  => self::mapTableToLocale('XX'),
//              'guru'  => self::mapTableToLocale('XX'),
                'hani'  => self::mapTableToLocale('zh'),
                'hebr'  => self::mapTableToLocale('he'),
                'jpan'  => self::mapTableToLocale('ja'),
//              'khmr'  => self::mapTableToLocale('ko'),
//              'knda'  => self::mapTableToLocale('XX'),
                'kore'  => self::mapTableToLocale('ko'),
                'laoo'  => self::mapTableToLocale('lo'),
                'latn'  => self::mapTableToLocale(self::faker()->randomElement(['fr', 'cs', 'sk', 'hu', 'pl', 'lt', 'lv', 'sl', 'tr'])),
//              'mlym'  => self::mapTableToLocale('XX'),
                'mymr'  => self::mapTableToLocale('ms'),
//              'orya'  => self::mapTableToLocale('XX'),
//              'sinh'  => self::mapTableToLocale('XX'),
//              'taml'  => self::mapTableToLocale('XX'),
//              'telu'  => self::mapTableToLocale('XX'),
//              'thaa'  => self::mapTableToLocale('XX'),
                'thai'  => self::mapTableToLocale('th'),
                default => throw new \Exception("Cannot map IDN table '{$table}' to a supported locale"),
            };

        } else {
            $lang = substr($table, 0, 2);

            $locales = array_filter(self::$locales, function($l) use ($lang): bool {
                return str_starts_with($l, $lang.'_');
            });

            if (count($locales) < 1) {
                throw new \Exception("Cannot map IDN table '{$table}' to a supported locale");
            }

            return $locales[self::faker()->randomKey($locales)];
        }
    }

    private static function generateDelegations(
        string  $origin,
        int     $count,
        float   $secure,
        int     $ttl,
        float   $idn,
        array   $idn_tables,
    ): int {
        static $glue = [];

        echo "\n";
        echo "; BEGIN DELEGATIONS\n";
        echo "\n";

        for ($i = 0 ; $i < $count ; $i++) {
            $name = self::generateUniqueLabel($idn_tables, $idn).(empty($origin) ? "" : ".".$origin);

            $hostDomain = self::faker()->domainName();

            $delegateHostDomain = false;
            if (str_ends_with($hostDomain, ".".$origin)) {
                if (!isset($seen[$hostDomain])) {
                    $delegateHostDomain = true;
                    $i++;
                    $seen[$hostDomain] = 1;
                }
            }

            $nscount = 13;

            $offset = self::faker()->numberBetween(0, max(self::$nscounts));
            for ($k = 0 ; $k < count(self::$nscounts) ; $k++) {
                if ($offset  < self::$nscounts[$k]) {
                    $nscount = $k+1;
                    break;
                }
            }

            for ($j = 1 ; $j <= $nscount ; $j++) {
                $ns = sprintf('ns%u.%s', $j, $hostDomain);

                if ($delegateHostDomain) {
                    printf("%s. %u IN NS %s.\n", $hostDomain, $ttl, $ns);
                }

                if (str_ends_with($ns, (empty($origin) ? "" : ".".$origin))) {
                    if (!isset($glue[$ns])) {

                        printf("%s. %u IN A %s\n", $ns, $ttl, self::faker()->ipv4());
                        if (self::faker()->numberBetween(0, 10) >= 4) printf("%s. %u IN AAAA %s\n", $ns, $ttl, self::faker()->ipv6());

                        $glue[$ns] = 1;
                    }
                }

                printf("%s. %u IN NS %s.\n", $name, $ttl, $ns);
            }

            if (self::faker()->numberBetween(0, PHP_INT_MAX) / PHP_INT_MAX <= $secure) {
                $ds = '';
                while (strlen($ds) < 16) $ds .= dechex(self::faker()->numberBetween(0, 16));

                printf(
                    "%s. %u IN DS 1234 8 2 %s\n",
                    $name,
                    $ttl,
                    hash('sha256', $ds)
                );
            }
        }

        echo "\n";
        echo "; END DELEGATIONS\n";

        return 0;
    }

    /**
     * access the cached faker object for the given locale
     */
    public static function faker(?string $locale=null): \Faker\Generator {
        static $fakers;

        $locale = $locale ?? self::randomLocale();

        if (!isset($fakers[$locale])) $fakers[$locale] = \Faker\Factory::create($locale);

        return $fakers[$locale];
    }

    /**
     * randomly select a locale
     */
    private static function randomLocale(): string {
        return self::$locales[mt_rand(0, count(self::$locales)-1)];
    }
}
