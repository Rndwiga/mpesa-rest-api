<?php

$saf_config = 
[
    "PAYBILL"=>"xxxx",
    "NAME" => "xxxx",
    "SP_ID"=>"xxxxx",
    "SP_PASSWORD"=>"xxxxx",
    "C2B"=>
    [
        "BROKER_URL"=>"",
        "REGISTERURL_ENDPOINT"=>"https://portal.safaricom.com/registerURL",
        "VALIDATION_URL"=>"",
        "CONFIRMATION_URL"=>""
    ],
    "B2C"=>
    [
        "BROKER_URL"=>"",
        "RESULT_URL"=>"xxxxx",
        "QUEUETIMEOUT_URL"=>"xxxxx",
        "PASSWORD"=>"xxxxx",
        "SERVICE_ID"=>"xxxx"
    ],
    "CURL"=>
    [
        "CONNECT_TIMEOUT"=>30,
        "READ_TIMEOUT"=>40
    ]
];

