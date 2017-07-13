<?php

// config
// @TODO move to env
$rootDomain = getenv("DOMAIN") !== false ? getenv("DOMAIN") : "docker.e-d-p.net";
$rancherHost = getenv("RANCHER_HOST") !== false ? getenv("RANCHER_HOST") : "10.0.2.200:8181";
$rancherKey = getenv("RANCHER_KEY") !== false ? getenv("RANCHER_KEY") : null;
$domainTtl = getenv("DOMAIN_TTL") !== false ? intval(getenv("DOMAIN_TTL")) : 60;
// end config

$rootDomain = trim($rootDomain, ".");
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

$rancherKeyPrefix = $rancherKey ? $rancherKey . '@' : '';

$containers = json_decode(file_get_contents("http://{$rancherKeyPrefix}{$rancherHost}/{$version}/containers?limit=1000", false, $context));
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
$nsIndex = 1;
foreach($containers->data as $container) {
    if (isset($container->data->fields->dockerHostIp)
        && isset($container->data->fields->labels->{"io.rancher.stack_service.name"})
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
    if (isset($container->data->fields->dockerHostIp)
        && isset($container->data->fields->environment->VIRTUAL_HOST)
        && substr($container->data->fields->environment->VIRTUAL_HOST, -strlen("." . $rootDomain)) === "." . $rootDomain) {
        $zoneContents .=
            str_pad($container->data->fields->environment->VIRTUAL_HOST . ".", 64, " ", STR_PAD_RIGHT)
            . " IN\tA\t"
            . $container->data->fields->dockerHostIp
            . "\n";
    }
}

// all containers by id
foreach($containers->data as $container) {
    if (isset($container->data->fields->dockerHostIp)) {
        $zoneContents .=
            str_pad($container->id . ".rancher-container." . $rootDomain . ".", 64, " ", STR_PAD_RIGHT)
            . " IN\tA\t"
            . $container->data->fields->dockerHostIp
            . "\n";
        $zoneContents .=
            str_pad($container->externalId . ".docker-container." . $rootDomain . ".", 64, " ", STR_PAD_RIGHT)
            . " IN\tA\t"
            . $container->data->fields->dockerHostIp
            . "\n";
        $zoneContents .=
            str_pad(substr($container->externalId, 0, 12) . ".docker-container." . $rootDomain . ".", 64, " ", STR_PAD_RIGHT)
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
