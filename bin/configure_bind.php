<?php

// config
// @TODO move to env
$rootDomain = trim("docker.e-d-p.net", ".");
$rancherHost = "10.0.2.200:8181";
$domainTtl = 60;
// end config

list($rootIP, $hostPort) = explode(":", $rancherHost);
$version = "v1";
$selfHost = "rancher-metadata";
$versionDate = "2015-07-25";

$opts = [
    "http" => [
        "method" => "GET",
        "header" => "Accept: application/json\r\n"
    ]
];

$context = stream_context_create($opts);

$containers = json_decode(file_get_contents("http://{$rancherHost}/{$version}/containers"));
$self = json_decode(file_get_contents("http://{$selfHost}/{$versionDate}/self/container", false, $context));

if ($containers == null) {
    echo "Failed to fetch containers metadata\n";
    exit(2);
}

if ($self == null) {
    echo "Failed to fetch self/container metadata\n";
    exit(2);
}

$serial = time() - 1483228800; // 2017-01-01

$refresh = $domainTtl;
$ttl = $domainTtl;
$expire = $domainTtl;

$retry = 15;

$zoneContents = <<<EOF
\$ttl {$ttl}
@	IN	SOA	{$rootDomain}. root.{$rootDomain}. (
					{$serial}
					{$refresh}
					{$retry}
					{$expire}
					{$ttl} )
@                          	IN	A	{$rootIP}

EOF;


// search for dns containers
foreach($containers->data as $container) {
    $nsIndex = 1;
    if (isset($container->data->fields->labels->{"io.rancher.stack_service.name"})
        && $self->labels->{"io.rancher.stack_service.name"} === $container->data->fields->labels->{"io.rancher.stack_service.name"}) {
        $zoneContents .=
            str_pad("", 64, " ", STR_PAD_RIGHT)
            . " IN\tNS\t"
            . "ns" . $nsIndex . "." . $rootDomain . "."
            . "\n";
        $zoneContents .=
            str_pad("ns" . $nsIndex, 64, " ", STR_PAD_RIGHT)
            . " IN\tA\t"
            . $container->data->fields->dockerHostIp
            . "\n";

        $nsIndex++;
    }
}

// search for application containers
foreach($containers->data as $container) {
    if (isset($container->data->fields->environment->VIRTUAL_HOST)) {
        $zoneContents .=
            str_pad($container->data->fields->environment->VIRTUAL_HOST . ".", 64, " ", STR_PAD_RIGHT)
            . " IN\tA\t"
            . $container->data->fields->dockerHostIp
            . "\n";
    }
}

$zoneFileName = "/etc/bind/zones/db." . $rootDomain;
$confFileName = "/etc/bind/named.conf.local";

$confContents = <<<EOF
zone "{$rootDomain}" {
    type master;
       file "/etc/bind/zones/db.{$rootDomain}";
};

EOF;

$oldZoneContents = @file_get_contents($zoneFileName);

// @TODO fix this comparsion: file are always different -> they contain date
if ($oldZoneContents != $zoneContents) {
    file_put_contents($zoneFileName, $zoneContents);
    file_put_contents($confFileName, $confContents);

    echo "New configuration generated\n";
    exit(0);
}

exit(1);
