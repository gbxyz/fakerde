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
        ]);

        if (isset($opt['help']) || 2 != count($opt)) return self::help();

        return self::generate(
            origin: strToLower(trim($opt['origin'], " \n\r\t\v\x00.")),
            count:  intval($opt['count']),
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

    private static function generate(string $origin, int $count): int {
        return self::generateApex($origin)
                + self::generateDelegations($origin, $count);
    }

    private static function generateApex(string $origin): int {
        printf(
            "%s 3600 IN SOA ns0.nic.%s contact.nic.%s %u 1800 900 604800 86400\n",
            (empty($origin) ? '.' : $origin),
            $origin,
            $origin,
            $origin,
            time(),            
        );

        for ($i = 1 ; $i <= self::faker()->numberBetween(3, 6) ; $i++) {
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

    private static function generateDelegations(string $origin, int $count): int {
        static $seen = [];

        for ($i = 0 ; $i < $count ; $i++) {
            $name = self::faker()->domainWord().'.'.$origin;

            if (isset($seen[$name])) {
                $i--;
                continue;
            }

            $seen[$name] = 1;
            for ($j = 1 ; $j <= self::faker()->numberBetween(2, 5) ; $j++) {
                $ns = sprintf('ns%u.%s.', $j, self::faker()->domainName());

                printf("%s 3600 IN NS %s\n", $name, $ns);

                if (str_ends_with($ns, $origin)) {
                    for ($k = 0 ; $k < self::faker()->numberBetween(1, 2) ; $k++) printf("%s 3600 IN A %s\n", $ns, self::faker()->ipv4());
                    for ($k = 0 ; $k < self::faker()->numberBetween(1, 2) ; $k++) printf("%s 3600 IN AAAA %s\n", $ns, self::faker()->ipv6());
                }
            }
        }

        return 0;
    }

    /**
     * access the cached faker object for the given locale
     */
    public static function faker(): \Faker\Generator {
        static $faker = null;
        static $fakers = [];

        if (is_null($faker)) $faker = \Faker\Factory::create();

        $locale = $faker->locale();

        if (!isset($fakers[$locale])) $fakers[$locale] = \Faker\Factory::create($locale);

        return $fakers[$locale];
    }
}
