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
     * temporary filehandle where objects are written
     */
    private static $fh;

    /**
     * map of short name => XML namespace
     */
    private const xmlns = [
        'rde'       => 'urn:ietf:params:xml:ns:rde-1.0',
        'header'    => 'urn:ietf:params:xml:ns:rdeHeader-1.0',
        'report'    => 'urn:ietf:params:xml:ns:rdeReport-1.0',
        'domain'    => 'urn:ietf:params:xml:ns:rdeDomain-1.0',
        'host'      => 'urn:ietf:params:xml:ns:rdeHost-1.0',
        'contact'   => 'urn:ietf:params:xml:ns:rdeContact-1.0',
        'registrar' => 'urn:ietf:params:xml:ns:rdeRegistrar-1.0',
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
            'input:',
            'registrant',
            'admin',
            'tech',
            'host-attributes',
        ]);

        if (isset($opt['help']) || !isset($opt['origin']) || !isset($opt['input'])) return self::help();

        return self::generate(
            origin:             strToLower(trim($opt['origin'], " \n\r\t\v\x00.")).".",
            input:              $opt['input'],
            registrant:         array_key_exists('registrant', $opt),
            admin:              array_key_exists('admin', $opt),
            tech:               array_key_exists('tech', $opt),
            host_attributes:    array_key_exists('host-attributes', $opt),
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
        fwrite($fh, "  --origin=ORIGIN      specify zone name\n");
        fwrite($fh, "  --input=FILE         specify zone file to parse\n");
        fwrite($fh, "  --registrant         add registrant to domains\n");
        fwrite($fh, "  --admin              add admin contact to domains\n");
        fwrite($fh, "  --tech               add tech contact to domains\n");
        fwrite($fh, "  --host-attributes    use host attributes instead of objects\n");
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

        if (false === $fh) self::die("Unable to open {$input}");

        while (true) {
            if (feof($fh)) {
                break;

            } else {
                $line = fgets($fh);

                if (false === $line) {
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
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:109.0) Gecko/20100101 Firefox/115.0');

            if (file_exists($local)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, ["If-Modified-Since: ".gmdate('D, d M Y H:i:s \G\M\T', filemtime($local))]);
            }

            $result = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if (304 == $status) {
                touch($local);
                return file_get_contents($local);

            } elseif (200 == $status) {
                file_put_contents($local, $result);
                return $result;

            } else {
                self::die("Got {$status} response when retrieving registrar information");

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
    private static function getRegistrarStats(): array {

        $date = (new DateTimeImmutable("4 months ago"))->format('Ym');

        $url = sprintf(
            'https://www.icann.org/sites/default/files/mrr/%s/%s-transactions-%s-en.csv',
            self::$tld, self::$tld, $date
        );

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, self::mirror($url));
        rewind($fh);

        // discard header
        fgetcsv($fh);

        $stats = [];

        while (true) {
            if (feof($fh)) {
                break;

            } else {
                $row = fgetcsv($fh);

                if (!is_array($row) || empty($row)) {
                    continue;

                } elseif (!empty($row[1])) {
                    $gurid = strval($row[1]);
                    if (isset(self::$registrars[$gurid]) && $row[2] > 0) $stats[$gurid] = intval($row[2]);


                }
            }
        }

        arsort($stats, SORT_NUMERIC);

        return $stats;
    }

    /**
     * randomly select a registrar, weighted by number of DUMs
     */
    private static function selectRegistrar(): ?int {
        static $max = 0;
        if ($max < 1) $max = array_sum(self::$stats);
        $v = rand(0, $max);

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
    private static function generateObjects(bool $registrant, bool $admin, bool $tech, bool $host_attributes): void {
        foreach (array_keys(self::$stats) as $gurid) {
            fwrite(self::$fh, self::generateRegistrarObject(self::$registrars[$gurid]));
        }

        self::info(sprintf('wrote %u registrars', count(self::$stats)));

        $delegations = [];
        foreach (self::getDelegations(self::$tld) as $name) {
            $delegations[$name] = self::selectRegistrar();
        }

        self::info(sprintf('%u domains will be written', count($delegations)));

        $hosts = [];
        $h = 0;
        if (!$host_attributes) {
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
                $contacts[$type] = substr(strToUpper(base_convert(sha1($name.$type), 16, 36)), 0, 16);

                fwrite(self::$fh, self::generateContactObject($contacts[$type], $gurid));

                if (0 == ++$c % 10000) self::info(sprintf('wrote %u of %u contacts', $c, 3*count($delegations)));
            }

            fwrite(self::$fh, self::generateDomainObject($name, $gurid, $contacts, $host_attributes));

            if (0 == ++$d % 10000) self::info(sprintf('wrote %u of %u domains', $d, count($delegations)));
        }

        self::info(sprintf('wrote %u contacts', $c));
        self::info(sprintf('wrote %u domains', $d));

        self::$counts = [
            'domain'    => $d,
            'host'      => $h,
            'contact'   => $c,
            'registrar' => count(self::$stats),
        ];
    }

    /**
     * wrap the object data in the deposit XML header/footer and write to the output file
     */
    private static function assembleDeposit(string $id, string $watermark, bool $contacts, bool $hosts): void {

        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->setIndent(true);

        $xml->startElementNS(name:'deposit', namespace:self::xmlns['rde'], prefix:null);

        $xml->writeAttribute('xmlns:domain', 'urn:ietf:params:xml:ns:domain-1.0');
        $xml->writeAttribute('xmlns:contact', 'urn:ietf:params:xml:ns:contact-1.0');

        $xml->writeAttribute('type', 'FULL');
        $xml->writeAttribute('id', $id);

        $xml->startElement('watermark');
        $xml->text($watermark);
        $xml->endElement();

        $xml->startElement('rdeMenu');

        $xml->startElement('version');
        $xml->text('1.0');
        $xml->endElement();

        $types = ['domain', 'registrar'];
        if ($contacts) $types[] = 'contact';
        if ($hosts) $types[] = 'host';

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

        $output = sprintf('%s_%s_full_S1_R0.xml', self::$tld, (new DateTimeImmutable())->format('Y-m-d'));

        $outfh = fopen($output, 'w');

        fwrite($outfh, $header);

        rewind(self::$fh);
        stream_copy_to_stream(self::$fh, $outfh);

        fwrite($outfh, $footer);

        fclose(self::$fh);

        fclose($outfh);

        self::info(sprintf('wrote %s', $output));
    }

    /**
     * write the report file
     */
    private static function writeReport(string $id, string $watermark): void {

        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);

        $xml->startElementNS(name:'report', namespace:self::xmlns['report'], prefix:null);

        $values = [
            'id'                => $id,
            'version'           => '1',
            'rydeSpecEscrow'    => 'RFC8909',
            'rydeSpecMapping'   => 'RFC9022',
            'resend'            => '0',
            'crDate'            => $watermark,
            'kind'              => 'FULL',
            'watermark'         => $watermark,
        ];

        foreach ($values as $name => $value) {
            $xml->startElement($name);
            $xml->text($value);
            $xml->endElement();
        }

        self::writeHeader($xml);

        $xml->endElement();

        $report = sprintf('%s_%s_full_R0.rep', self::$tld, (new DateTimeImmutable())->format('Y-m-d'));
        file_put_contents($report, $xml->flush());

        self::info(sprintf('wrote %s', $report));
    }

    private static function generate(string $origin, string $input, bool $registrant, bool $admin, bool $tech, bool $host_attributes): int {
        self::$tld = rtrim(strtolower($origin), ".");
        self::info(sprintf('running for .%s', self::$tld));

        self::$registrars   = self::getRegistrars();
        self::$stats        = self::getRegistrarStats(self::$tld);
        self::$rrs          = self::readZoneFile($input);

        $tmpfile = tempnam(sys_get_temp_dir(), __METHOD__);
        self::$fh = fopen($tmpfile, 'r+');

        self::generateObjects($registrant, $admin, $tech, $host_attributes);

        $id = strToUpper(base_convert((string)time(), 10, 36));
        $watermark = (new DateTimeImmutable)->format('c');

        self::assembleDeposit($id, $watermark, $registrant || $admin || $tech, !$host_attributes);

        self::writeReport($id, $watermark);

        unlink($tmpfile);

        return 0;
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

        if (3882 == $rar->gurid) {
            var_export($rar);
            echo $blob;
        }

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
        $xml->text('H'.strToUpper(base_convert(sha1($name), 16, 36)).'-'.strToUpper(self::$tld));
        $xml->endElement();

        $xml->startElement('status');
        $xml->writeAttribute('s', 'linked');
        $xml->endElement();

        foreach (['clientDeleteProhibited', 'clientUpdateProhibited'] as $s) {
            if (67 >= rand(0, 99)) {
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
     * return a list of locales supported by fakerphp
     */
    private static function locales(): array {
        static $locales = [];

        if (empty($locales)) {
            $locales = array_map(
                fn ($f) => basename($f),
                array_filter(
                    glob(__DIR__.'/vendor/fakerphp/faker/src/Faker/Provider/*'),
                    fn ($f) => is_dir($f),
                ),
            );

            sort($locales, SORT_STRING);
        }

        return $locales;
    }

    /**
     * randomly select a locale
     */
    private static function randomLocale(): string {
        $locales = self::locales();

        while (true) {
            $locale = $locales[rand(0, count($locales)-1)];
            $cc = substr($locale, -2);

            if (isset(self::dialCodes[$cc])) {
                return $locale;
            }
        }
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

        $crDate = new DateTimeImmutable(sprintf('%u seconds ago', rand(90 * 86400, 3650 * 86400)));
        $xml->startElement('crDate');
        $xml->text($crDate->format('c'));
        $xml->endElement();

        if ($includeExDate) {
            $exDate = new DateTimeImmutable(sprintf('@%u', time()+rand(86400, 365 * 86400)));
            $xml->startElement('exDate');
            $xml->text($exDate->format('c'));
            $xml->endElement();
        }

        $xml->startElement('upRr');
        $xml->text(self::$registrars[$sponsor]->id);
        $xml->endElement();

        $upDate = new DateTimeImmutable(sprintf('%u seconds ago', rand(86400, 90 * 86400)));
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
        $xml->text('C'.strToUpper(base_convert(sha1($id), 16, 36)).'-'.strToUpper(self::$tld));
        $xml->endElement();

        $xml->startElement('status');
        $xml->writeAttribute('s', 'linked');
        $xml->endElement();

        foreach (['clientDeleteProhibited', 'clientUpdateProhibited', 'clientTransferProhibited'] as $s) {
            if (67 >= rand(0, 99)) {
                $xml->startElement('status');
                $xml->writeAttribute('s', $s);
                $xml->endElement();
            }
        }

        $locale = self::randomLocale();

        $info = [
            'loc' => [
                'name'      => self::faker($locale)->name(),
                'org'       => (0 == rand(0, 3) ? null : self::faker($locale)->company()),
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

        if (0 == rand(0, 9)) {
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

        $xml->startElementNS(prefix:'rdeDomain', name:'domain', namespace:self::xmlns['domain']);

        $xml->startElement('name');
        $xml->text($name);
        $xml->endElement();

        $xml->startElement('roid');
        $xml->text('D'.strToUpper(base_convert(sha1($name), 16, 36)).'-'.strToUpper(self::$tld));
        $xml->endElement();

        foreach (['clientUpdateProhibited', 'clientDeleteProhibited', 'clientTransferProhibited'] as $s) {
            if (67 >= rand(0, 99)) {
                $xml->startElement('status');
                $xml->writeAttribute('s', $s);
                $xml->endElement();
            }
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

        $xml->endElement();
        return $xml->flush();
    }
}
