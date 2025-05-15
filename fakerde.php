#!/usr/bin/env php
<?php declare(strict_types=1);

namespace gbxyz\fakerde;

use DateTimeImmutable;
use Net_DNS2_RR;
use Transliterator;
use XMLWriter;

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
     * multi-dimensional array of resource records parsed from the zone file, indexed by owner name and type
     */
    private static array $rrs;

    /**
     * list of ICANN-accredited registrars indexed by Globally Unique Registrar ID (GURID)
     */
    private static array $registrars;

    /**
     * map of GURID => DUMs
     */
    private static array $stats;

    /**
     * count of objects of each type
     */
    private static array $counts;

    /**
     * the TLD being faked
     */
    private static string $tld;

    /**
     * the repository ID
     */
    private static string $repositoryID;

    /**
     * temporary filehandle where objects are written
     */
    private static $fh;

    const TYPE_DOMAIN   = 1;
    const TYPE_HOST     = 2;
    const TYPE_CONTACT  = 3;

    private static function generateROID(string $seed): string {
        return substr(sprintf(
            '%s-%s',
            strToUpper(base_convert(hash("sha512", $seed), 16, 36)),
            self::$repositoryID
        ), -89);
    }

    /**
     * map of short name => XML namespace
     */
    private const xmlns = [
        'rde'           => 'urn:ietf:params:xml:ns:rde-1.0',
        'header'        => 'urn:ietf:params:xml:ns:rdeHeader-1.0',
        'report'        => 'urn:ietf:params:xml:ns:rdeReport-1.0',
        'domain'        => 'urn:ietf:params:xml:ns:rdeDomain-1.0',
        'host'          => 'urn:ietf:params:xml:ns:rdeHost-1.0',
        'contact'       => 'urn:ietf:params:xml:ns:rdeContact-1.0',
        'registrar'     => 'urn:ietf:params:xml:ns:rdeRegistrar-1.0',
        'eppParams'     => 'urn:ietf:params:xml:ns:rdeEppParams-1.0',
        'policy'        => 'urn:ietf:params:xml:ns:rdePolicy-1.0',
        'idn'           => 'urn:ietf:params:xml:ns:rdeIDN-1.0',
        'nndn'          => 'urn:ietf:params:xml:ns:rdeNNDN-1.0',
    ];

    /**
     * list of EPP extension URIs to include in the EPP Parameters object.
     */
    private const extURI = [
        'urn:ietf:params:xml:ns:rgp-1.0',
        'urn:ietf:params:xml:ns:secDNS-1.1',
        'urn:ietf:params:xml:ns:launch-1.0',
    ];

    /**
     * country dialling codes so we can construct compliant e164 phone numbers
     */
    private const dialCodes = [
        'AD' => '376',
        'AE' => '971',
        'AF' => '93',
        'AG' => '1',
        'AI' => '1',
        'AL' => '355',
        'AM' => '374',
        'AO' => '244',
        'AQ' => '672',
        'AR' => '54',
        'AS' => '1',
        'AT' => '43',
        'AU' => '61',
        'AW' => '297',
        'AX' => '358',
        'AZ' => '994',
        'BA' => '387',
        'BB' => '1',
        'BD' => '880',
        'BE' => '32',
        'BF' => '226',
        'BG' => '359',
        'BH' => '973',
        'BI' => '257',
        'BJ' => '229',
        'BL' => '590',
        'BM' => '1',
        'BN' => '673',
        'BO' => '591',
        'BQ' => '599',
        'BR' => '55',
        'BS' => '1',
        'BT' => '975',
        'BV' => '47',
        'BW' => '267',
        'BY' => '375',
        'BZ' => '501',
        'CA' => '1',
        'CC' => '61',
        'CD' => '243',
        'CF' => '236',
        'CG' => '242',
        'CH' => '41',
        'CI' => '225',
        'CK' => '682',
        'CL' => '56',
        'CM' => '237',
        'CN' => '86',
        'CO' => '57',
        'CR' => '506',
        'CU' => '53',
        'CV' => '238',
        'CW' => '599',
        'CX' => '61',
        'CY' => '357',
        'CZ' => '420',
        'DE' => '49',
        'DJ' => '253',
        'DK' => '45',
        'DM' => '1',
        'DO' => '1',
        'DZ' => '213',
        'EC' => '593',
        'EE' => '372',
        'EG' => '20',
        'EH' => '212',
        'ER' => '291',
        'ES' => '34',
        'ET' => '251',
        'FI' => '358',
        'FJ' => '679',
        'FK' => '500',
        'FM' => '691',
        'FO' => '298',
        'FR' => '33',
        'GA' => '241',
        'GB' => '44',
        'GD' => '1',
        'GE' => '995',
        'GF' => '594',
        'GG' => '44',
        'GH' => '233',
        'GI' => '350',
        'GL' => '299',
        'GM' => '220',
        'GN' => '224',
        'GP' => '590',
        'GQ' => '240',
        'GR' => '30',
        'GS' => '500',
        'GT' => '502',
        'GU' => '1',
        'GW' => '245',
        'GY' => '592',
        'HK' => '852',
        'HM' => '672',
        'HN' => '504',
        'HR' => '385',
        'HT' => '509',
        'HU' => '36',
        'ID' => '62',
        'IE' => '353',
        'IL' => '972',
        'IM' => '44',
        'IN' => '91',
        'IO' => '246',
        'IQ' => '964',
        'IR' => '98',
        'IS' => '354',
        'IT' => '39',
        'JE' => '44',
        'JM' => '1',
        'JO' => '962',
        'JP' => '81',
        'KE' => '254',
        'KG' => '996',
        'KH' => '855',
        'KI' => '686',
        'KM' => '269',
        'KN' => '1',
        'KP' => '850',
        'KR' => '82',
        'KW' => '965',
        'KY' => '1',
        'KZ' => '7',
        'LA' => '856',
        'LB' => '961',
        'LC' => '1',
        'LI' => '423',
        'LK' => '94',
        'LR' => '231',
        'LS' => '266',
        'LT' => '370',
        'LU' => '352',
        'LV' => '371',
        'LY' => '218',
        'MA' => '212',
        'MC' => '377',
        'MD' => '373',
        'ME' => '382',
        'MF' => '590',
        'MG' => '261',
        'MH' => '692',
        'MK' => '389',
        'ML' => '223',
        'MM' => '95',
        'MN' => '976',
        'MO' => '853',
        'MP' => '1',
        'MQ' => '596',
        'MR' => '222',
        'MS' => '1',
        'MT' => '356',
        'MU' => '230',
        'MV' => '960',
        'MW' => '265',
        'MX' => '52',
        'MY' => '60',
        'MZ' => '258',
        'NA' => '264',
        'NC' => '687',
        'NE' => '227',
        'NF' => '672',
        'NG' => '234',
        'NI' => '505',
        'NL' => '31',
        'NO' => '47',
        'NP' => '977',
        'NR' => '674',
        'NU' => '683',
        'NZ' => '64',
        'OM' => '968',
        'PA' => '507',
        'PE' => '51',
        'PF' => '689',
        'PG' => '675',
        'PH' => '63',
        'PK' => '92',
        'PL' => '48',
        'PM' => '508',
        'PN' => '870',
        'PR' => '1',
        'PS' => '970',
        'PT' => '351',
        'PW' => '680',
        'PY' => '595',
        'QA' => '974',
        'RE' => '262',
        'RO' => '40',
        'RS' => '381',
        'RU' => '7',
        'RW' => '250',
        'SA' => '966',
        'SB' => '677',
        'SC' => '248',
        'SD' => '249',
        'SE' => '46',
        'SG' => '65',
        'SH' => '290',
        'SI' => '386',
        'SJ' => '47',
        'SK' => '421',
        'SL' => '232',
        'SM' => '378',
        'SN' => '221',
        'SO' => '252',
        'SR' => '597',
        'SS' => '211',
        'ST' => '239',
        'SV' => '503',
        'SX' => '1',
        'SY' => '963',
        'SZ' => '268',
        'TC' => '1',
        'TD' => '235',
        'TF' => '262',
        'TG' => '228',
        'TH' => '66',
        'TJ' => '992',
        'TK' => '690',
        'TL' => '670',
        'TM' => '993',
        'TN' => '216',
        'TO' => '676',
        'TR' => '90',
        'TT' => '1',
        'TV' => '688',
        'TW' => '886',
        'TZ' => '255',
        'UA' => '380',
        'UG' => '256',
        'US' => '1',
        'UY' => '598',
        'UZ' => '998',
        'VA' => '39',
        'VC' => '1',
        'VE' => '58',
        'VG' => '1',
        'VI' => '1',
        'VN' => '84',
        'VU' => '678',
        'WF' => '681',
        'WS' => '685',
        'YE' => '967',
        'YT' => '262',
        'ZA' => '27',
        'ZM' => '260',
        'ZW' => '263',
    ];

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
            'repository-id:',
            'input:',
            'registrant',
            'admin',
            'tech',
            'host-attributes',
            'encrypt:',
            'sign:',
            'resend:',
            'no-report',
            'idn-tables:',
            'nndn:',
        ]);

        if (isset($opt['help']) || !isset($opt['origin']) || !isset($opt['input'])) return self::help("missing argument(s)");

        $origin = strToLower(trim($opt['origin'], " \n\r\t\v\x00."));

        $id = $opt['repository-id'] ?? $origin;
        if (strlen($id) > 8) return self::help("repository ID cannot be more than 8 octets");

        self::$locales = array_values(array_filter(
            array_map(
                fn ($f) => basename($f),
                array_filter(
                    glob(__DIR__.'/vendor/fakerphp/faker/src/Faker/Provider/*'),
                    fn ($f) => is_dir($f),
                ),
            ),
            fn($l) => isset(self::dialCodes[substr($l, -2)])
        ));

        if (2 == strlen($origin)) {
            $locales = array_filter(self::$locales, fn($l) => str_ends_with(strtolower($l), $origin));
            if (!empty($locales)) self::$locales = array_values($locales);
        }

        self::generate(
            origin:             $origin.".",
            repositoryID:       $id,
            input:              $opt['input'],
            registrant:         array_key_exists('registrant', $opt),
            admin:              array_key_exists('admin', $opt),
            tech:               array_key_exists('tech', $opt),
            host_attributes:    array_key_exists('host-attributes', $opt),
            encryption_key:     $opt['encrypt'] ?? null,
            signing_key:        $opt['sign'] ?? null,
            resend:             array_key_exists('resend', $opt) ? (int)$opt['resend'] : 0,
            no_report:          array_key_exists('no-report', $opt),
            idn_tables:         array_key_exists('idn-tables', $opt) ? explode(',', $opt['idn-tables']) : [],
            nndn:               intval($opt['nndn'] ?? 0),
        );

        return 0;
    }

    /**
     * display user help
     */
    private static function help(?string $message): int {
        global $argv;
        $fh = fopen('php://stderr', 'w');
        if (!is_null($message)) fwrite($fh, sprintf("Error: %s\n", $message));
        fprintf($fh, "Usage: php %s OPTIONS\n\nOptions:\n", $argv[0]);
        fwrite($fh, "  --help               show this help\n");
        fwrite($fh, "  --origin=ORIGIN      specify zone name\n");
        fwrite($fh, "  --resend=RESEND      specify resend (default 0)\n");
        fwrite($fh, "  --input=FILE         specify zone file to parse\n");
        fwrite($fh, "  --registrant         add registrant to domains\n");
        fwrite($fh, "  --admin              add admin contact to domains\n");
        fwrite($fh, "  --tech               add tech contact to domains\n");
        fwrite($fh, "  --host-attributes    use host attributes instead of objects\n");
        fwrite($fh, "  --encrypt=KEY        generate an encrypted .ryde file as well as the XML\n");
        fwrite($fh, "  --sign=KEY           generate a .sig file as well as the encrypted .ryde file\n");
        fwrite($fh, "  --no-report          do not generate a .rep file\n");
        fwrite($fh, "  --idn-tables=LIST    comma-separated list of IDN languate tags\n");
        fwrite($fh, "  --nndn=COUNT         add COUNT 'NNDN' objects\n");
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
        throw new \Exception($error);
    }

    /**
     * read a zone file and convert into Net_DNS2_RR objects. This assumes
     * that the file has already been normalized using ldns-readzone or named-compilezone
     */
    private static function readZoneFile(string $zone): array {
        $fh = fopen($zone, 'r');

        $rrs = [];

        if (false === $fh) self::die("Unable to open {$zone}");

        while (true) {
            if (feof($fh)) {
                break;

            } else {
                $line = fgets($fh);

                if (false === $line || strlen($line) <= 1 || str_starts_with($line, ";")) {
                    continue;

                } else {
                    $rr = Net_DNS2_RR::fromString($line);
                    $name = strToLower($rr->name);
                    $type = strToUpper($rr->type);

                    if (!isset($rrs[$name])) $rrs[$name] = [];
                    if (!isset($rrs[$name][$type])) $rrs[$name][$type] = [];
                    $rrs[$name][$type][] = $rr;
                }
            }
        }

        return $rrs;
    }

    /**
     * parse the RDAP search results to get registrar info
     */
    private static function parseRegistrarData(string $data): array {
        $json = json_decode($data);
        foreach ($json->entitySearchResults as $e) {
            $gurid = $e->publicIds[0]->identifier;

            $rar = [
                'id'    => sprintf('%u-IANA', $gurid),
                'gurid' => (string)$gurid,
            ];

            $i = [];
            foreach ($e->vcardArray[1] ?? [] as $node) {
                list($type, $opt, $fmt, $value) = $node;
                $i[$type] = $value;
            }

            $rar['org']     = $i['org'] ?? $i['fn'];
            $rar['voice']   = $i['tel'] ?? null;
            $rar['email']   = $i['email'] ?? null;

            foreach ($e->links ?? [] as $link) {
                if ('related' == $link->rel && "Registrar's Website" == $link->title) {
                    $rar['url'] = $link->href;
                    break;
                }
            }

            $rars[strval($gurid)] = (object)$rar;
        }

        return $rars;
    }

    /**
     * mirror a URL
     */
    private static function mirror(string $url): string {
        $local = sys_get_temp_dir().'/'.__METHOD__.'-'.sha1($url);

        if (file_exists($local) && time()-filemtime($local) < 86400) {
            return file_get_contents($local);

        } else {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:109.0) Gecko/20100101 Firefox/115.0');

            $headers = [];
            if (file_exists($local)) {
                $headers[] = "If-Modified-Since: ".gmdate('D, d M Y H:i:s \G\M\T', filemtime($local));
            }

            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            $result = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if (304 == $status) {
                touch($local);
                return file_get_contents($local);

            } elseif (200 == $status) {
                file_put_contents($local, $result);
                return $result;

            } else {
                self::die("Got {$status} response when retrieving '{$url}'");

            }
        }
    }

    /**
     * get registrar data from registrars.rdap.org and return as useful objects
     */
    private static function getRegistrars(): array {
        return self::parseRegistrarData(self::mirror('https://registrars.rdap.org/entities'));
    }

    /**
     * get the most recent public transactions report for this TLD from ICANN
     */
    private static function getRegistrarStats(?string $tld=null): array {

        $tld ??= self::$tld;

        $date = (new DateTimeImmutable("6 months ago"))->format('Ym');

        $url = sprintf(
            'https://www.icann.org/sites/default/files/mrr/%s/%s-transactions-%s-en.csv',
            $tld, $tld, $date
        );

        $stats = [];

        try {
            $fh = fopen('php://temp', 'r+');
            fwrite($fh, self::mirror($url));
            rewind($fh);

            // discard header
            fgetcsv(
                stream: $fh,
                length: null,
                separator: ",",
                enclosure: '"',
                escape: "",
            );

            while (true) {
                if (feof($fh)) {
                    break;

                } else {
                    $row = fgetcsv(
                        stream: $fh,
                        length: null,
                        separator: ",",
                        enclosure: '"',
                        escape: "",
                    );

                    if (!is_array($row) || empty($row)) {
                        continue;

                    } elseif (!empty($row[1])) {
                        $gurid = strval($row[1]);
                        if (isset(self::$registrars[$gurid]) && $row[2] > 0) $stats[$gurid] = intval($row[2]);

                    }
                }
            }

            arsort($stats, SORT_NUMERIC);

        } catch (\Exception $e) {
            self::info($e->getMessage());
            if ("org" !== $tld) {
                return self::getRegistrarStats("org");

            } else {
                exit(1);

            }

        }

        return $stats;
    }

    /**
     * randomly select a registrar, weighted by number of DUMs
     */
    private static function selectRegistrar(): ?int {
        static $max = 0;
        if ($max < 1) $max = array_sum(self::$stats);
        $v = mt_rand(0, $max);

        $total = 0;
        foreach (self::$stats as $gurid => $dums) {
            $total += $dums;
            if ($total >= $v) return $gurid;
        }

        return null;
    }

    /**
     * extract all the second-level delegations from the zone
     */
    private static function getDelegations(): array {
        $pattern = sprintf("/^[a-z0-9\-]+\.%s$/i", preg_quote(self::$tld));

        return array_filter(
            array_keys(self::$rrs),
            fn($n) => 1 === preg_match($pattern, $n)
        );
    }

    /**
     * write the <rdeHeader> element
     */
    private static function writeHeader(XMLWriter $xml): void {
        $xml->startElementNS(name:'header', namespace:self::xmlns['header'], prefix:null);

        $xml->startElement('tld');
        $xml->text(self::$tld);
        $xml->endElement();

        foreach (self::$counts as $type => $count) {
            if ($count > 0) {
                $xml->startElement('count');
                $xml->writeAttribute('uri', self::xmlns[$type]);
                $xml->text((string)$count);
                $xml->endElement();
            }
        }

        $xml->endElement(); // </header>
    }

    /**
     * write all the objects and update the counts
     */
    private static function generateObjects(
        bool    $registrant,
        bool    $admin,
        bool    $tech,
        bool    $host_attributes,
        array   $idn_tables,
        int     $nndn,
    ): void {

        $delegations = [];
        foreach (self::getDelegations(self::$tld) as $name) {
            $delegations[$name] = self::selectRegistrar();
        }

        $rars = array_unique(array_values($delegations));
        foreach ($rars as $gurid) {
            fwrite(self::$fh, self::generateRegistrarObject(self::$registrars[$gurid]));
        }

        self::info(sprintf('wrote %u registrars', count($rars)));

        self::info(sprintf('%u domains will be written', count($delegations)));

        $h = 0;
        if (!$host_attributes) {
            $hosts = [];

            foreach ($delegations as $name => $gurid) {
                foreach (self::$rrs[$name]['NS'] ?? [] as $ns) {
                    if (!isset($hosts[$ns->nsdname])) {
                        fwrite(self::$fh, self::generateHostObject($ns->nsdname, intval($gurid)));
                        $hosts[$ns->nsdname] = 1;

                        if (0 == ++$h % 10000) self::info(sprintf('wrote %u hosts', $h));
                    }
                }
            }
        }

        self::info(sprintf('wrote %u hosts', $h));

        $types = [];
        if ($registrant) $types[] = 'registrant';
        if ($admin) $types[] = 'admin';
        if ($tech) $types[] = 'tech';

        $d = $c = 0;
        foreach ($delegations as $name => $gurid) {
            $contacts = [];
            foreach ($types as $type) {
                $contacts[$type] = substr(strToUpper(base_convert(sha1($name.chr(0).$type), 16, 36)), 0, 16);

                fwrite(self::$fh, self::generateContactObject($contacts[$type], $gurid));

                if (0 == ++$c % 10000) self::info(sprintf('wrote %u of %u contacts', $c, 3*count($delegations)));
            }

            fwrite(self::$fh, self::generateDomainObject($name, $gurid, $contacts, $host_attributes));

            if (0 == ++$d % 10000) self::info(sprintf('wrote %u of %u domains', $d, count($delegations)));
        }

        self::info(sprintf('wrote %u contacts', $c));
        self::info(sprintf('wrote %u domains', $d));

        for ($i = 0 ; $i < $nndn ; $i++) {
            fwrite(self::$fh, self::generateNNDNObject());
        }

        self::info(sprintf('wrote %u NNDN objects', $nndn));

        fwrite(self::$fh, self::generateEPPParamsObject(
            $registrant || $admin || $tech,
            !$host_attributes
        ));
        self::info('wrote EPP Parameters object');

        self::$counts = [
            'domain'    => $d,
            'host'      => $h,
            'contact'   => $c,
            'registrar' => count($rars),
            'eppParams' => 1,
            'policy'    => 0,
            'idn'       => count($idn_tables),
            'nndn'      => $nndn,
        ];

        fwrite(self::$fh, self::generatePolicyObject(
            $registrant,
            $admin,
            $tech,
        ));

        fwrite(self::$fh, self::generateIDNObjects($idn_tables));

        self::info(sprintf('wrote %u policy objects', self::$counts['policy']));
    }

    private static function generateNNDNObject(): string {
        static $seen = array_map(fn($n) => basename($n, '.'.self::$tld), self::getDelegations());

        $name = strtolower(self::faker(self::randomLocale())->domainWord());

        while (in_array($name, $seen)) {
            $name .= '-' . self::faker(self::randomLocale())->domainWord();
        }

        $seen[] = $name;

        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);

        $nndn = [
            'aName'     => $name.'.'.self::$tld,
            'nameState' => 'blocked',
        ];

        $xml->startElementNS(name:'NNDN', namespace:self::xmlns['nndn'], prefix:null);

        foreach ($nndn as $name => $value) {
            $xml->startElement($name);
            $xml->text($value);
            $xml->endElement();
        }

        $xml->endElement();

        return $xml->flush();
    }

    private static function generateIDNObjects(array $idn_tables): string {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);

        foreach ($idn_tables as $tag) {
            $xml->startElementNS(name:'idnTableRef', namespace:self::xmlns['idn'], prefix:null);
            $xml->writeAttribute('id', $tag);

            $xml->startElement('url');
            $xml->text(sprintf('https://www.nic.%s/idn/%s/table.html', self::$tld, $tag));
            $xml->endElement();

            $xml->startElement('urlPolicy');
            $xml->text(sprintf('https://www.nic.%s/idn/%s/policy.html', self::$tld, $tag));
            $xml->endElement();

            $xml->endElement();
        }

        return $xml->flush();
    }

    private static function generateEPPParamsObject(bool $contacts, bool $hosts): string {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);

        $xml->startElementNS(name:'eppParams', namespace:self::xmlns['eppParams'], prefix:null);

        $xml->startElement('version');
        $xml->text('1.0');
        $xml->endElement();

        $xml->startElement('lang');
        $xml->text('en');
        $xml->endElement();

        $objURIs = ['urn:ietf:params:xml:ns:domain-1.0'];
        if ($contacts) $objURIs[] = 'urn:ietf:params:xml:ns:contact-1.0';
        if ($hosts) $objURIs[] = 'urn:ietf:params:xml:ns:host-1.0';

        foreach ($objURIs as $uri) {
            $xml->startElement('objURI');
            $xml->text($uri);
            $xml->endElement();
        }

        $xml->startElement('svcExtension');

        foreach (self::extURI as $uri) {
            $xml->startElementNS(prefix:'epp', name:'extURI', namespace:null);
            $xml->text($uri);
            $xml->endElement();
        }

        $xml->endElement(); // svcExtension

        $xml->startElement('dcp');
        $xml->writeRaw("<epp:access><epp:all/></epp:access><epp:statement><epp:purpose><epp:admin/><epp:prov/></epp:purpose><epp:recipient><epp:ours/><epp:public/></epp:recipient><epp:retention><epp:stated/></epp:retention></epp:statement>");
        $xml->endElement();

        $xml->endElement();
        return $xml->flush();
    }

    private static function generatePolicyObject(bool $registrant, bool $admin, bool $tech): string {

        $elements = [];
        if ($registrant) $elements[] = 'rdeDomain:registrant';
        if ($admin) $elements[] = "rdeDomain:contact[@type='admin']";
        if ($tech) $elements[] = "rdeDomain:contact[@type='tech']";

        self::$counts['policy'] = count($elements);

        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);

        foreach ($elements as $element) {
            $xml->startElementNS(name:'policy', namespace:self::xmlns['policy'], prefix:null);
            $xml->writeAttribute('scope', '//rde:deposit/rde:contents/rdeDomain:domain');
            $xml->writeAttribute('element', $element);

            $xml->endElement();
        }

        return $xml->flush();
    }

    /**
     * wrap the object data in the deposit XML header/footer and write to the output file
     */
    private static function assembleDeposit(
        string  $id,
        string  $watermark,
        int     $resend,
        bool    $contacts,
        bool    $hosts,
        bool    $idn_tables,
        bool    $nndn,
    ): string {

        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->setIndent(true);

        $xml->startElementNS(name:'deposit', namespace:self::xmlns['rde'], prefix:null);

        $xml->writeAttribute('xmlns:epp', 'urn:ietf:params:xml:ns:epp-1.0');
        $xml->writeAttribute('xmlns:domain', 'urn:ietf:params:xml:ns:domain-1.0');
        $xml->writeAttribute('xmlns:secDNS', 'urn:ietf:params:xml:ns:secDNS-1.1');

        if ($contacts)      $xml->writeAttribute('xmlns:contact',   'urn:ietf:params:xml:ns:contact-1.0');
        if ($hosts)         $xml->writeAttribute('xmlns:host',      'urn:ietf:params:xml:ns:host-1.0');
        if ($idn_tables)    $xml->writeAttribute('xmlns:rdeIDN',    self::xmlns['idn']);
        if ($nndn)          $xml->writeAttribute('xmlns:rdeNNDN',   self::xmlns['nndn']);

        $xml->writeAttribute('type', 'FULL');
        $xml->writeAttribute('id', $id);

        $xml->startElement('watermark');
        $xml->text($watermark);
        $xml->endElement();

        $xml->startElement('rdeMenu');

        $xml->startElement('version');
        $xml->text('1.0');
        $xml->endElement();

        $types = ['domain', 'registrar','eppParams'];
        if ($contacts) {
            $types[] = 'contact';
            $types[] = 'policy';
        }
        if ($hosts)         $types[] = 'host';
        if ($idn_tables)    $types[] = 'idn';
        if ($nndn)          $types[] = 'nndn';

        foreach ($types as $type) {
            $xml->startElement('objURI');
            $xml->text(self::xmlns[$type]);
            $xml->endElement();
        }

        $xml->endElement(); // </rdeMenu>

        $xml->startElement('contents');

        self::writeHeader($xml);

        $marker = sprintf('<!-- MARKER %s -->', uniqid());
        $xml->writeRaw($marker);

        $xml->endElement(); // </contents>
        $xml->endElement(); // </deposit>

        list($header, $footer) = explode($marker, $xml->flush());

        $output = sprintf('%s_%s_full_S1_R%u.xml', self::$tld, (new DateTimeImmutable())->format('Y-m-d'), $resend);

        $outfh = fopen($output, 'w');

        fwrite($outfh, $header);

        rewind(self::$fh);
        stream_copy_to_stream(self::$fh, $outfh);

        fwrite($outfh, $footer);

        fclose(self::$fh);

        fclose($outfh);

        self::info(sprintf('wrote %s', $output));

        return $output;
    }

    /**
     * write the report file
     */
    private static function writeReport(string $id, string $watermark, int $resend=0): void {

        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);

        $xml->startElementNS(name:'report', namespace:self::xmlns['report'], prefix:null);

        $values = [
            'id'                => $id,
            'version'           => 1,
            'rydeSpecEscrow'    => 'RFC8909',
            'rydeSpecMapping'   => 'RFC9022',
            'resend'            => $resend,
            'crDate'            => $watermark,
            'kind'              => 'FULL',
            'watermark'         => $watermark,
        ];

        foreach ($values as $name => $value) {
            $xml->startElement($name);
            $xml->text(strval($value));
            $xml->endElement();
        }

        self::writeHeader($xml);

        $xml->endElement();

        $report = sprintf('%s_%s_full_R%u.rep', self::$tld, (new DateTimeImmutable())->format('Y-m-d'), $resend);
        file_put_contents($report, $xml->flush());

        self::info(sprintf('wrote %s', $report));
    }

    private static function generateArchive(string $file): string {
        $new = basename($file, '.xml').'.tar';

        $proc = proc_open(
            [
                'tar',
                '--create',
                '--file',
                $new,
                $file,
            ],
            [],
            $pipes,
        );

        if (proc_close($proc) > 0) self::die("archiving failed");

        self::info(sprintf('wrote %s', $new));

        return $new;
    }

    private static function encryptDeposit(string $file, string $encryption_key): string {
        $new = basename($file, '.tar').'.ryde';

        $env = [];

        $GNUPGHOME = getenv('GNUPGHOME');
        if (is_string($GNUPGHOME) && strlen($GNUPGHOME) > 0) {
            $env['GNUPGHOME'] = $GNUPGHOME;
        }

        $proc = proc_open(
            [
                'gpg',
                '--yes',
                '--encrypt',
                '--trust-model', 'always',
                '--recipient', $encryption_key,
                '--output', $new,
                $file
            ],
            [],
            $pipes,
            null,
            $env,
        );

        if (proc_close($proc) > 0) self::die("encryption failed");

        self::info(sprintf('wrote %s', $new));

        return $new;
    }

    private static function signDeposit(string $file, string $signing_key): string {
        $sig = basename($file, '.ryde').'.sig';

        $env = [];

        $GNUPGHOME = getenv('GNUPGHOME');
        if (is_string($GNUPGHOME) && strlen($GNUPGHOME) > 0) {
            $env['GNUPGHOME'] = $GNUPGHOME;
        }

        $proc = proc_open(
            [
                'gpg',
                '--yes',
                '--detach-sig',
                '--local-user', $signing_key,
                '--output', $sig,
                $file
            ],
            [],
            $pipes,
            null,
            $env,
        );

        if (proc_close($proc) > 0) self::die("signing failed");

        self::info(sprintf('wrote %s', $sig));

        return $sig;
    }

    private static function generate(
        string  $origin,
        string  $repositoryID,
        string  $input,
        bool    $registrant,
        bool    $admin,
        bool    $tech,
        bool    $host_attributes,
        ?string $encryption_key=null,
        ?string $signing_key=null,
        int     $resend=0,
        bool    $no_report=false,
        array   $idn_tables=[],
        int     $nndn=0,
    ): void {
        self::info(sprintf('running for .%s, resend %u', $origin, $resend));

        self::$tld          = rtrim(strtolower($origin), ".");
        self::$repositoryID = strToUpper($repositoryID);
        self::$registrars   = self::getRegistrars();
        self::$stats        = self::getRegistrarStats(self::$tld);
        self::$rrs          = self::readZoneFile($input);

        $tmpfile = tempnam(sys_get_temp_dir(), __METHOD__);
        self::$fh = fopen($tmpfile, 'r+');

        self::generateObjects(
            registrant:         $registrant,
            admin:              $admin,
            tech:               $tech,
            host_attributes:    $host_attributes,
            idn_tables:         $idn_tables,
            nndn:               $nndn,
        );

        $id = strToUpper(base_convert((string)time(), 10, 36));
        $watermark = (new DateTimeImmutable)->format('c');

        $file = self::assembleDeposit(
            id:         $id,
            watermark:  $watermark,
            resend:     $resend,
            contacts:   $registrant || $admin || $tech,
            hosts:      !$host_attributes,
            idn_tables: count($idn_tables) > 0,
            nndn:       $nndn > 0,
        );

        if (false === $no_report) {
            self::writeReport($id, $watermark, $resend);
        }

        if (!is_null($encryption_key)) {
            $file = self::generateArchive($file);
            $file = self::encryptDeposit($file, $encryption_key);
            if (!is_null($signing_key)) self::signDeposit($file, $signing_key);
        }

        unlink($tmpfile);
    }

    /**
     * generate a registrar
     */
    private static function generateRegistrarObject(object $rar): string {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);

        $xml->startElementNS(name:'registrar', namespace:self::xmlns['registrar'], prefix:null);

        $xml->startElement('id');
        $xml->text($rar->id);
        $xml->endElement();

        $xml->startElement('name');
        $xml->text($rar->org ?? sprintf('Unknown Registrar #%u', $rar->gurid));
        $xml->endElement();

        $xml->startElement('gurid');
        $xml->text($rar->gurid);
        $xml->endElement();

        $xml->startElement('status');
        $xml->text('ok');
        $xml->endElement();

        $xml->endElement();

        $blob = $xml->flush();

        return $blob;
    }

    /**
     * generate a host object
     */
    private static function generateHostObject(string $name, int $sponsor): string {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);

        $xml->startElementNS(name:'host', namespace:self::xmlns['host'], prefix:null);

        $xml->startElement('name');
        $xml->text($name);
        $xml->endElement();

        $xml->startElement('roid');
        $xml->text(self::generateROID($name));
        $xml->endElement();

        $xml->startElement('status');
        $xml->writeAttribute('s', 'linked');
        $xml->endElement();

        foreach (['clientDeleteProhibited', 'clientUpdateProhibited'] as $s) {
            if (67 >= mt_rand(0, 99)) {
                $xml->startElement('status');
                $xml->writeAttribute('s', $s);
                $xml->endElement();
            }
        }

        if (str_ends_with($name, '.'.self::$tld)) {
            foreach (['A', 'AAAA'] as $type) {
                foreach (self::$rrs[$name][$type] ?? [] as $rr) {
                    $xml->startElement('addr');
                    $xml->writeAttribute('ip', 4 == strlen(inet_pton($rr->address)) ? 'v4' : 'v6');
                    $xml->text($rr->address);
                    $xml->endElement();
                }
            }
        }

        self::writeStandardMetadata($xml, $sponsor);

        $xml->endElement();
        return $xml->flush();
    }

    /**
     * randomly select a locale
     */
    private static function randomLocale(): string {
        return self::$locales[mt_rand(0, count(self::$locales)-1)];
    }

    /**
     * get the ISO-3166 country code from a locale
     */
    private static function ccFromLocale(string $locale): string {
        return strToUpper(substr($locale, -2));
    }

    /**
     * access the cached faker object for the given locale
     */
    public static function faker(string $locale): \Faker\Generator {
        static $fakers = [];

        if (!isset($fakers[$locale])) $fakers[$locale] = \Faker\Factory::create($locale);

        return $fakers[$locale];
    }

    /**
     * generate a valid number in e164 format
     */
    public static function fakePhone(string $locale): string {
        $phone = self::faker($locale)->e164PhoneNumber();

        $cc = self::ccFromLocale($locale);
        return substr($phone, 0, 1+strlen(self::dialCodes[$cc])).'.'.substr($phone, 1+strlen(self::dialCodes[$cc]));
    }

    /**
     * there isn't a standard name, different locales use different terms, so this attempts to find the right one.
     */
    private static function fakeSP(string $locale): ?string {
        static $methods = ['state', 'province', 'departmentName', 'county', 'cantonName', 'region', 'prefecture', 'district', 'governorate', 'borough', 'area', 'kommune', 'locality'];

        $class = sprintf('Faker\\Provider\\%s\\Address', $locale);

        foreach ($methods as $method) {
            if (method_exists($class, $method)) {
                return self::faker($locale)->$method();
            }
        }

        return null;
    }

    /**
     * write the standard metadata that's common to all objects
     */
    private static function writeStandardMetadata(XMLWriter $xml, int $sponsor, bool $includeExDate=false): void {
        $xml->startElement('clID');
        $xml->text(self::$registrars[$sponsor]->id);
        $xml->endElement();

        $xml->startElement('crRr');
        $xml->text(self::$registrars[$sponsor]->id);
        $xml->endElement();

        $crDate = new DateTimeImmutable(sprintf('%u seconds ago', mt_rand(90 * 86400, 3650 * 86400)));
        $xml->startElement('crDate');
        $xml->text($crDate->format('c'));
        $xml->endElement();

        if ($includeExDate) {
            $exDate = new DateTimeImmutable(sprintf('@%u', time()+mt_rand(86400, 365 * 86400)));
            $xml->startElement('exDate');
            $xml->text($exDate->format('c'));
            $xml->endElement();
        }

        $xml->startElement('upRr');
        $xml->text(self::$registrars[$sponsor]->id);
        $xml->endElement();

        $upDate = new DateTimeImmutable(sprintf('%u seconds ago', mt_rand(86400, 90 * 86400)));
        $xml->startElement('upDate');
        $xml->text($upDate->format('c'));
        $xml->endElement();
    }

    /**
     * generate a contact object
     */
    private static function generateContactObject(string $id, int $sponsor): string {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);

        $xml->startElementNS(name:'contact', namespace:self::xmlns['contact'], prefix:null);

        $xml->startElement('id');
        $xml->text($id);
        $xml->endElement();

        $xml->startElement('roid');
        $xml->text(self::generateROID($id));
        $xml->endElement();

        $xml->startElement('status');
        $xml->writeAttribute('s', 'linked');
        $xml->endElement();

        foreach (['clientDeleteProhibited', 'clientUpdateProhibited', 'clientTransferProhibited'] as $s) {
            if (67 >= mt_rand(0, 99)) {
                $xml->startElement('status');
                $xml->writeAttribute('s', $s);
                $xml->endElement();
            }
        }

        $locale = self::randomLocale();

        $info = [
            'loc' => [
                'name'      => self::faker($locale)->name(),
                'org'       => (0 == mt_rand(0, 3) ? null : self::faker($locale)->company()),
                'street'    => self::faker($locale)->streetAddress(),
                'city'      => self::faker($locale)->city(),
                'sp'        => self::fakeSP($locale),
            ],
            'int' => [
            ],
        ];

        $pc = self::faker($locale)->postcode();
        $cc = self::ccFromLocale($locale);

        static $t = null;
        if (is_null($t)) $t = Transliterator::create('Any-Latin; Latin-ASCII');

        foreach ($info['loc'] as $n => $v) {
            $info['int'][$n] = !is_null($v) ? $t->transliterate($v) : null;
        }

        if (empty(array_diff_assoc($info['loc'], $info['int']))) unset($info['loc']);

        foreach (array_keys($info) as $type) {
            $xml->startElement('postalInfo');
            $xml->writeAttribute('type', $type);

            $xml->startElement('contact:name');
            $xml->text($info[$type]['name']);
            $xml->endElement();

            if (!is_null($info[$type]['org'])) {
                $xml->startElement('contact:org');
                $xml->text($info[$type]['org']);
                $xml->endElement();
            }

            $xml->startElement('contact:addr');

            $street = preg_split("/\n/", $info[$type]['street'], -1, PREG_SPLIT_NO_EMPTY);
            foreach ($street as $line) {
                $xml->startElement('contact:street');
                $xml->text($line);
                $xml->endElement();
            }

            $xml->startElement('contact:city');
            $xml->text($info[$type]['city']);
            $xml->endElement();

            if (!is_null($info[$type]['sp'])) {
                $xml->startElement('contact:sp');
                $xml->text($info[$type]['sp']);
                $xml->endElement();
            }

            $xml->startElement('contact:pc');
            $xml->text($pc);
            $xml->endElement();

            $xml->startElement('contact:cc');
            $xml->text($cc);
            $xml->endElement();

            $xml->endElement(); // </addr>
            $xml->endElement(); // </postalInfo>
        }

        $xml->startElement('voice');
        $xml->text(self::fakePhone($locale));
        $xml->endElement();

        if (0 == mt_rand(0, 9)) {
            $xml->startElement('fax');
            $xml->text(self::fakePhone($locale));
            $xml->endElement();
        }

        $xml->startElement('email');
        $xml->text(self::faker($locale)->email());
        $xml->endElement();

        self::writeStandardMetadata($xml, $sponsor);

        $xml->endElement();
        return $xml->flush();
    }

    /**
     * generate a domain object
     */
    private static function generateDomainObject(string $name, int $sponsor, array $contacts, $host_attributes): string {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);

        $xml->startElementNS(name:'domain', namespace:self::xmlns['domain'], prefix:null);

        $xml->startElement('name');
        $xml->text($name);
        $xml->endElement();

        $xml->startElement('roid');
        $xml->text(self::generateROID($name));
        $xml->endElement();

        $status = array_filter(
            ['clientUpdateProhibited', 'clientDeleteProhibited', 'clientTransferProhibited'],
            fn() => 67 >= mt_rand(0, 99)
        );

        if (1 == mt_rand(0, 100)) {
            $status[] = 'pendingTransfer';

        } elseif (1 == mt_rand(0, 100)) {
            $status[] = 'pendingDelete';

        }

        if (count($status) < 1) $status[] = 'ok';

        foreach ($status as $s) {
            $xml->startElement('status');
            $xml->writeAttribute('s', $s);
            $xml->endElement();
        }

        if (in_array('pendingDelete', $status)) {
            $xml->startElement('rgpStatus');
            $xml->writeAttribute('s', mt_rand(0, 86400 * 35) < 86400 * 30 ? 'redemptionPeriod' : 'pendingDelete');
            $xml->endElement();
        }

        if (array_key_exists('registrant', $contacts)) {
            $xml->startElement('registrant');
            $xml->text($contacts['registrant']);
            $xml->endElement();

            unset($contacts['registrant']);
        }

        foreach (array_keys($contacts) as $type) {
            $xml->startElement('contact');
            $xml->writeAttribute('type', $type);
            $xml->text($contacts[$type]);
            $xml->endElement();
        }

        if (isset(self::$rrs[$name]['NS'])) {
            $xml->startElement('ns');

            foreach (self::$rrs[$name]['NS'] as $rr) {
                if ($host_attributes) {
                    $xml->startElement('domain:hostAttr');

                    $xml->startElement('domain:hostName');
                    $xml->text($rr->nsdname);
                    $xml->endElement();

                    if (str_ends_with($rr->nsdname, '.'.self::$tld)) {
                        foreach (['A', 'AAAA'] as $type) {
                            foreach (self::$rrs[$rr->nsdname][$type] ?? [] as $addr) {
                                $xml->startElement('hostAddr');
                                $xml->writeAttribute('ip', 4 == strlen(inet_pton($addr->address)) ? 'v4' : 'v6');
                                $xml->text($addr->address);
                                $xml->endElement();
                            }
                        }
                    }

                    $xml->endElement();

                } else {
                    $xml->startElement('domain:hostObj');
                    $xml->text($rr->nsdname);
                    $xml->endElement();
                }
            }
            $xml->endElement();
        }

        self::writeStandardMetadata($xml, $sponsor, true);

        if (isset(self::$rrs[$name]['DS'])) {
            $xml->startElement('secDNS');
            foreach (self::$rrs[$name]['DS'] as $ds) {
                $xml->startElement("secDNS:dsData");

                $xml->writeElement("secDNS:keyTag",     $ds->keytag);
                $xml->writeElement("secDNS:alg",        $ds->algorithm);
                $xml->writeElement("secDNS:digestType", $ds->digesttype);
                $xml->writeElement("secDNS:digest",     $ds->digest);

                $xml->endElement();
            }
            $xml->endElement();
        }

        $xml->endElement();
        return $xml->flush();
    }
}
