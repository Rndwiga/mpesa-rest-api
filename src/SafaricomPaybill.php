<?php

/**
 * Description of SafaricomPaybill
 * 
 * @property SoapClient $soapclient Description
 *
 * @author Kim
 */
require_once 'config.php';
require_once '../Data.php';
require_once '../Crypt/RSA.php';

class SafaricomPaybill
{

    public static function c2b_registerurl()
    {
        $timestamp = date("YmdHis");
        $OCID = self::gen_conversation_id();

        $soap_xml = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:req="http://api-v1.gen.mm.vodafone.com/mminterface/request">
   <soapenv:Header>
      <tns:RequestSOAPHeader xmlns:tns="http://www.huawei.com/schema/osg/common/v2_1">
         <tns:spId>' . self::get_config("SP_ID") . '</tns:spId>
         <tns:spPassword>' . self::get_config("SP_PASSWORD") . '</tns:spPassword>
         <tns:timeStamp>' . $timestamp . '</tns:timeStamp>
         <tns:serviceId></tns:serviceId>
      </tns:RequestSOAPHeader>
   </soapenv:Header>
   <soapenv:Body>
      <req:RequestMsg><![CDATA[<?xml version="1.0" encoding="UTF-8"?>
<request xmlns="http://api-v1.gen.mm.vodafone.com/mminterface/request">
    <Transaction>
        <CommandID>RegisterURL</CommandID>
        <OriginatorConversationID>' . $OCID . '</OriginatorConversationID>
        <Parameters>
            <Parameter>
                <Key>ResponseType</Key>
                <Value>Cancel</Value>
            </Parameter>
        </Parameters>
        <ReferenceData>
           <ReferenceItem>
                <Key>ValidationURL</Key>
                <Value>' . self::get_config("VALIDATION_URL", "C2B") . '</Value>
            </ReferenceItem>
<ReferenceItem>
                <Key>ConfirmationURL</Key>
                <Value>' . self::get_config("CONFIRMATION_URL", "C2B") . '</Value>
            </ReferenceItem>
           
        </ReferenceData>
    </Transaction>
    <Identity>
        <Caller>
            <CallerType>0</CallerType>
            <ThirdPartyID/>
            <Password/>
            <CheckSum/>
            <ResultURL/>
        </Caller>
        <Initiator>
            <IdentifierType>1</IdentifierType>
            <Identifier/>
            <SecurityCredential/>
            <ShortCode/>
        </Initiator>
        <PrimaryParty>
            <IdentifierType>1</IdentifierType>
            <Identifier/>
            <ShortCode>' . self::get_config("KZO_PAYBILL") . '</ShortCode>
        </PrimaryParty>
    </Identity>
    <KeyOwner>1</KeyOwner>
</request>]]></req:RequestMsg>
   </soapenv:Body>
</soapenv:Envelope>
';

        $result = self::invokeBroker($soap_xml, self::get_config("REGISTERURL_ENDPOINT", "C2B"));

        if ($result)
        {
            $xml_cdata = self::get_string_between($result, "[CDATA[", "]]");
            $xml = simplexml_load_string($xml_cdata);
            // print_r($xml);
            $resp_data = (array) $xml;

            $feedback = ["status" => "", "message" => $resp_data["ResponseDesc"]];

            if ($resp_data["ResponseCode"] != 0)
                $feedback["status"] = "fail";
            else
                $feedback["status"] = "ok";

            echo json_encode($feedback);
        }
        else
        {
            echo json_encode(["status" => "fail", "message" => "could not connect to broker"]);
        }
    }

    public static function c2b_validatepayment($payment_soap)
    {
        $xml = simplexml_load_string($payment_soap, NULL, NULL, "http://schemas.xmlsoap.org/soap/envelope/");
        $xml->registerXPathNamespace('soapenv', 'http://schemas.xmlsoap.org/soap/envelope/');
        $xml->registerXPathNamespace('c2b', 'http://cps.huawei.com/cpsinterface/c2bpayment');

        $account_no = (string) $xml->xpath("//BillRefNumber")[0];
        $account_exists = (Data::msisdn_exists(Data::format_msisdn($account_no)) ||
                Data::email_exists($account_no));

        if ($account_exists)
        {
            $soap_resp = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:c2b="http://cps.huawei.com/cpsinterface/c2bpayment">
                            <soapenv:Header/>
                            <soapenv:Body>
                                <c2b:C2BPaymentValidationResult>
                                    <ResultCode>0</ResultCode>
                                    <ResultDesc>Service processing successful</ResultDesc>
                                    <ThirdPartyTransID></ThirdPartyTransID>
                                </c2b:C2BPaymentValidationResult>
                            </soapenv:Body>
                            </soapenv:Envelope>';
        } else
        {
            $soap_resp = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:c2b="http://cps.huawei.com/cpsinterface/c2bpayment">
                            <soapenv:Header/>
                            <soapenv:Body>
                                <c2b:C2BPaymentValidationResult>
                                    <ResultCode>C2B00012</ResultCode>
                                    <ResultDesc>Invalid Account Number</ResultDesc>
                                    <ThirdPartyTransID></ThirdPartyTransID>
                                </c2b:C2BPaymentValidationResult>
                            </soapenv:Body>
                            </soapenv:Envelope>';
        }

        self::invokeBroker($soap_resp, self::get_config("BROKER_URL", "C2B"));
    }

    public static function c2b_confirmpayment($payment_soap)
    {
        $xml = simplexml_load_string($payment_soap, NULL, NULL, "http://schemas.xmlsoap.org/soap/envelope/");
        $xml->registerXPathNamespace('soapenv', 'http://schemas.xmlsoap.org/soap/envelope/');
        $xml->registerXPathNamespace('c2b', 'http://cps.huawei.com/cpsinterface/c2bpayment');

        $account_no = (string) $xml->xpath("//BillRefNumber")[0];
        $amount = (double) $xml->xpath("//TransAmount")[0];
        $msisdn = (string) $xml->xpath("//MSISDN")[0];
        $transaction_id = (string) $xml->xpath("//TransID")[0];

        $acc = Data::get_accountid($account_no);
        Data::topup_account($acc, $amount, 1, $msisdn, $transaction_id);

        $confirm_ackn_soap = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:c2b="http://cps.huawei.com/cpsinterface/c2bpayment">
                                <soapenv:Header/>
                                <soapenv:Body>
                                    <c2b:C2BPaymentConfirmationResult>C2B Payment Transaction ' . $transaction_id . ' result received.</c2b:C2BPaymentConfirmationResult>
                                </soapenv:Body>
                              </soapenv:Envelope>
                            ';

        self::invokeBroker($confirm_ackn_soap, self::get_config("BROKER_URL", "C2B"));
    }

    public static function b2c_genericrequest($msisdn, $amount)
    {
        $timestamp = date("YmdHis");
        $b2c_initiator_credential = self::encrypt_b2cinit_password();
        $sp_password = base64_encode(hash('sha256', self::get_config('SP_ID') . "" . self::get_config('SP_PASSWORD') . "" . $timestamp));

        $soap_xml = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:req="http://api-v1.gen.mm.vodafone.com/mminterface/request">
   <soapenv:Header>
      <tns:RequestSOAPHeader xmlns:tns="http://www.huawei.com/schema/osg/common/v2_1">
         <tns:spId>' . self::get_config("SP_ID") . '</tns:spId>
         <tns:spPassword>' . $sp_password . '</tns:spPassword>
         <tns:timeStamp>' . $timestamp . '</tns:timeStamp>
         <tns:serviceId>' . self::get_config("SERVICE_ID", "B2C") . '</tns:serviceId>
      </tns:RequestSOAPHeader>
   </soapenv:Header>
   <soapenv:Body>
      <req:RequestMsg>
      <![CDATA[<?xml version="1.0" encoding="UTF-8"?>
      <request xmlns="http://api-v1.gen.mm.vodafone.com/mminterface/request">
<Transaction>
        <CommandID>SalaryPayment</CommandID>
        <LanguageCode>0</LanguageCode>
        <OriginatorConversationID>' . self::gen_conversation_id() . '</OriginatorConversationID>
        <ConversationID></ConversationID>
        <Remark>0</Remark>
<Parameters><Parameter>
        <Key>Amount</Key>
        <Value>' . $amount . '</Value>
</Parameter></Parameters>
<ReferenceData>
        <ReferenceItem>
                <Key>QueueTimeoutURL</Key>
                <Value>' . self::get_config("QUEUETIMEOUT_URL", "B2C") . '</Value>
</ReferenceItem></ReferenceData>
        <Timestamp>' . $timestamp . '</Timestamp>
</Transaction>
<Identity>
        <Caller>
                <CallerType>2</CallerType>
                <ThirdPartyID>' . self::get_config("KZO_NAME") . '</ThirdPartyID>
                <Password>' . self::get_config("PASSWORD", "B2C") . '</Password>
                <CheckSum>null</CheckSum>
                <ResultURL>' . self::get_config("RESULT_URL", "B2C") . '</ResultURL>
        </Caller>
        <Initiator>
               <IdentifierType>11</IdentifierType>
                   <Identifier>' . self::get_config("KZO_NAME") . '</Identifier>
                  <SecurityCredential>' . $b2c_initiator_credential . '</SecurityCredential>
                  <ShortCode>' . self::get_config("KZO_PAYBILL") . '</ShortCode>
        </Initiator>
                <PrimaryParty>
                        <IdentifierType>4</IdentifierType>
         <Identifier>' . self::get_config("KZO_PAYBILL") . '</Identifier>
                        <ShortCode>' . self::get_config("KZO_PAYBILL") . '</ShortCode>
                </PrimaryParty>
        <ReceiverParty>
                <IdentifierType>1</IdentifierType>
                <Identifier>' . $msisdn . '</Identifier>
                <ShortCode>' . self::get_config("KZO_PAYBILL") . '</ShortCode>
        </ReceiverParty>
        <AccessDevice>
                <IdentifierType>4</IdentifierType>
                <ShortCode>' . self::get_config("KZO_PAYBILL") . '</ShortCode>
         </AccessDevice>
 </Identity>
         <KeyOwner>1</KeyOwner>
        </request>]]></req:RequestMsg>
   </soapenv:Body>
</soapenv:Envelope>';


        $generic_response = self::invokeBroker($soap_xml, self::get_config("BROKER_URL", "B2C"));
        $xml_cdata = self::get_string_between($generic_response, "[CDATA[", "]]");
        $xml = simplexml_load_string($xml_cdata);
        $response = (array) $xml;

        if ($response["ResponseCode"] != 0)
        {
            echo json_encode(["status" => "fail", "message" => $response["ResponseDesc"]]);
        } else
        {
            echo json_encode(["status" => "ok", "message" => $response["ResponseDesc"]]);
        }
    }

    public static function b2c_processresult($soap)
    {
        $cdata = get_string_between($soap, "[CDATA[", "]]");
        $xml = simplexml_load_string($cdata);
        $xml->registerXPathNamespace('soapenv', 'http://schemas.xmlsoap.org/soap/envelope/');
        $xml->registerXPathNamespace('req', 'http://cps.huawei.com/cpsinterface/result');

        $recep = (array) $xml->xpath("//ResultParameter[Key='ReceiverPartyPublicName']");
        $msisdn = explode("-", ((string) $recep[0]->Value))[0];
        $cash = (array) $xml->xpath("//ResultParameter[Key='TransactionAmount']");
        $amount = $cash[0]->Value;
        $transaction_id = (string) $xml->xpath("//TransactionId")[0];

        $acc_id = Data::get_accountid($msisdn);

        Data::record_withdrawal($acc_id, $amount, 1, $transaction_id);

        $response_soap = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:req="http://api-v1.gen.mm.vodafone.com/mminterface/request">
   <soapenv:Header/>
   <soapenv:Body>
      <req:ResponseMsg><![CDATA[<?xml version="1.0" encoding="UTF-8"?>
<response xmlns="http://api-v1.gen.mm.vodafone.com/mminterface/response">
    <ResponseCode>00000000</ResponseCode>
    <ResponseDesc>success</ResponseDesc>
</response>]]></req:ResponseMsg>
   </soapenv:Body>
</soapenv:Envelope>
';

        self::invokeBroker($response_soap, self::get_config("BROKER_URL", "B2C"));
    }

    public static function b2c_resendtimeout($soap)
    {
        $xml = simplexml_load_string($soap, NULL, NULL, "http://schemas.xmlsoap.org/soap/envelope/");
        $xml->registerXPathNamespace('soapenv', 'http://schemas.xmlsoap.org/soap/envelope/');
        $xml->registerXPathNamespace('loc', 'http://www.csapi.org/schema/timeoutnotification/data/v1_0/local');
        $xml->registerXPathNamespace('res', "http://api-v1.gen.mm.vodafone.com/mminterface/result");

        $originalRq = base64_decode((string) $xml->xpath("//loc:originRequest")[0]);
        
        self::invokeBroker($originalRq, self::get_config("BROKER_URL", "B2C"));
    }

    private static function encrypt_b2cinit_password()
    {
        $password = self::get_config("PASSWORD", "B2C");
        $pub_key = openssl_pkey_get_public(file_get_contents('Certs/ApiCryptPublicOnly.cer'));

        $pubKeyData = openssl_pkey_get_details($pub_key);

        $rsa = new Crypt_RSA();
        $rsa->loadKey($pubKeyData['key']); // public key


        $rsa->setEncryptionMode(CRYPT_RSA_ENCRYPTION_PKCS1);

        $ciphertext = $rsa->encrypt($password);

        $securityCredential = base64_encode($ciphertext);

        return $securityCredential;
    }

    private static function gen_conversation_id()
    {
        $timestamp = date("YmdHis");
        return self::get_config("KZO_PAYBILL") . "_" . self::get_config("KZO_NAME") . "_" . $timestamp . Data::generateRandomString();
    }

    private static function get_config($attr, $namespace = null)
    {
        global $saf_config;

        if (!$namespace)
            return $saf_config[$attr];

        return $saf_config[$namespace][$attr];
    }

    private static function invokeBroker($soap_request, $endPoint)
    {
        $headers = array(
            "Content-type: text/xml;charset=\"utf-8\"",
            "Accept: text/xml",
            "Cache-Control: no-cache",
            "Pragma: no-cache",
        );

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $endPoint);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::get_config("CONNECT_TIMEOUT", "CURL"));
        curl_setopt($ch, CURLOPT_TIMEOUT, self::get_config("READ_TIMEOUT", "CURL"));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $soap_request);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);

        /* SSL options */
        //curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        //curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);

        $result = curl_exec($ch);
        curl_close($ch);

        return $result;
    }

    private static function get_string_between($string, $start, $end)
    {
        $string = ' ' . $string;
        $ini = strpos($string, $start);
        if ($ini == 0)
        {
            return '';
        }
        $ini += strlen($start);
        $len = strpos($string, $end, $ini) - $ini;
        return substr($string, $ini, $len);
    }

}
