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
        1771,       // number of domains in .org with 1 nameserver, as of Feb 2025
        8077751,    // number of domains in .org with 2 nameservers, as of Feb 2025
        8935795,    // number of domains in .org with 3 nameservers, as of Feb 2025
        10688768,   // number of domains in .org with 4 nameservers, as of Feb 2025
        10881573,   // number of domains in .org with 5 nameservers, as of Feb 2025
        10924018,   // number of domains in .org with 6 nameservers, as of Feb 2025
        10949202,   // number of domains in .org with 7 nameservers, as of Feb 2025
        11087174,   // number of domains in .org with 8 nameservers, as of Feb 2025
        11087592,   // number of domains in .org with 9 nameservers, as of Feb 2025
        11088441,   // number of domains in .org with 10 nameservers, as of Feb 2025
        11088527,   // number of domains in .org with 11 nameservers, as of Feb 2025
        11089979,   // number of domains in .org with 12 nameservers, as of Feb 2025
        11090026,   // number of domains in .org with 13 nameservers, as of Feb 2025
    ];

    private static $locales = [
        "ar_EG",
        "ar_JO",
        "ar_SA",
        "at_AT",
        "bg_BG",
        "bn_BD",
        "cs_CZ",
        "da_DK",
        "de_AT",
        "de_CH",
        "de_DE",
        "el_CY",
        "el_GR",
        "en_AU",
        "en_CA",
        "en_GB",
        "en_HK",
        "en_IN",
        "en_NG",
        "en_NZ",
        "en_PH",
        "en_SG",
        "en_UG",
        "en_US",
        "en_ZA",
        "es_AR",
        "es_ES",
        "es_PE",
        "es_VE",
        "et_EE",
        "fa_IR",
        "fi_FI",
        "fr_BE",
        "fr_CA",
        "fr_CH",
        "fr_FR",
        "he_IL",
        "hr_HR",
        "hu_HU",
        "hy_AM",
        "id_ID",
        "is_IS",
        "it_CH",
        "it_IT",
        "ja_JP",
        "ka_GE",
        "kk_KZ",
        "ko_KR",
        "lt_LT",
        "lv_LV",
        "me_ME",
        "mn_MN",
        "ms_MY",
        "nb_NO",
        "ne_NP",
        "nl_BE",
        "nl_NL",
        "pl_PL",
        "pt_BR",
        "pt_PT",
        "ro_MD",
        "ro_RO",
        "ru_RU",
        "sk_SK",
        "sl_SI",
        "sr_Cyrl_RS",
        "sr_Latn_RS",
        "sr_RS",
        "sv_SE",
        "th_TH",
        "tr_TR",
        "uk_UA",
        "vi_VN",
        "zh_CN",
        "zh_TW",
    ];

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
        ]);

        if (array_key_exists('help', $opt) || !array_key_exists('origin', $opt) || !array_key_exists('count', $opt)) return self::help();

        return self::generate(
            origin: strToLower(trim($opt['origin'], " \n\r\t\v\x00.")),
            count:  intval($opt['count']),
            secure: floatval($opt['secure'] ?? 0.02), // number of .org domains with DNSSEC as of Feb 2025
            seed:   array_key_exists('seed', $opt) ? intval($opt['seed']) : random_int(0, pow(2, 32)),
        );
    }

    /**
     * display user help
     */
    private static function help(): int {
        global $argv;
        $fh = fopen('php://stderr', 'w');
        fprintf($fh, "Usage: php %s OPTIONS\n\nOptions:\n", $argv[0]);
        fwrite($fh, "  --help           show this help\n");
        fwrite($fh, "  --origin=ORIGIN  specify zone name, use '.' to generate\n");
        fwrite($fh, "                   a fake root zone\n");
        fwrite($fh, "  --count=COUNT    specify zone size (in delegations)\n");
        fwrite($fh, "  --secure=RATIO   what fraction of delegations is secure (default: 2%)\n");
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

    private static function generate(string $origin, int $count, float $secure, int $seed): int {
        mt_srand($seed);
        return self::generateApex($origin)
                + self::generateDelegations($origin, $count, $secure);
    }

    private static function generateApex(string $origin): int {
        $serial = self::faker()->numberBetween(0, pow(2, 16));

        printf(
            "%s 3600 IN SOA ns0.nic.%s contact.nic.%s %u 1800 900 604800 86400\n",
            (empty($origin) ? '.' : $origin."."),
            $origin,
            $origin,
            $serial,
        );

        for ($i = 1 ; $i <= self::faker()->numberBetween(2, 6) ; $i++) {
            printf(
                "%s 3600 IN NS ns%u.nic.%s\n",
                (empty($origin) ? '.' : $origin),
                $i,
                $origin,
            );

            printf(
                "ns%u.nic.%s 3600 IN A %s\n",
                $i,
                $origin,
                self::faker()->ipv4(),
            );

            printf(
                "ns%u.nic.%s 3600 IN AAAA %s\n",
                $i,
                $origin,
                self::faker()->ipv6(),
            );
        }

        return 0;
    }

    private static function generateUniqueLabel(): string {
        static $seen = [];

        $label = self::faker()->domainWord();

        while (isset($seen[$label])) {
            $label .= '-' . self::faker()->domainWord();
        }

        if (strlen($label) > 63) {
            self::info("label length exceeded 63 characters, restarting");
            return self::generateUniqueLabel();

        } else {
            $seen[$label] = 1;
            return $label;

        }
    }

    private static function generateDelegations(string $origin, int $count, float $secure): int {
        static $glue = [];

        for ($i = 0 ; $i < $count ; $i++) {
            $name = self::generateUniqueLabel().(empty($origin) ? "" : ".".$origin);

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

            $nscount = 1;

            for ($j = 1 ; $j <= $nscount ; $j++) {
                $ns = sprintf('ns%u.%s', $j, $hostDomain);

                if ($delegateHostDomain) {
                    printf("%s. 3600 IN NS %s.\n", $hostDomain, $ns);
                }

                if (str_ends_with($ns, (empty($origin) ? "" : ".".$origin))) {
                    if (!isset($glue[$ns])) {

                        printf("%s. 3600 IN A %s\n", $ns, self::faker()->ipv4());
                        if (self::faker()->numberBetween(0, 10) >= 4) printf("%s. 3600 IN AAAA %s\n", $ns, self::faker()->ipv6());

                        $glue[$ns] = 1;
                    }
                }

                printf("%s. 3600 IN NS %s.\n", $name, $ns);
            }

            if (self::faker()->numberBetween(0, PHP_INT_MAX) / PHP_INT_MAX <= $secure) {
                $ds = '';
                while (strlen($ds) < 16) $ds .= dechex(self::faker()->numberBetween(0, 16));

                printf(
                    "%s. 3600 IN DS 1234 8 2 %s\n",
                    $name,
                    hash('sha256', $ds)
                );
            }
        }

        return 0;
    }

    /**
     * access the cached faker object for the given locale
     */
    public static function faker(): \Faker\Generator {
        static $fakers;

        $locale = self::$locales[mt_rand(0, count(self::$locales)-1)];

        if (!isset($fakers[$locale])) $fakers[$locale] = \Faker\Factory::create($locale);

        return $fakers[$locale];
    }
}
