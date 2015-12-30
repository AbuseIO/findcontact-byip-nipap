<?php

namespace AbuseIO\Findcontact;

use AbuseIO\Jobs\FindContact;
use Zend\XmlRpc\Client as RpcClient;
use AbuseIO\Models\Contact;

/**
 * Class Nipap
 * @package AbuseIO\Findcontact
 */
class Nipap
{

    /**
     * Does a query to the RPC host
     *
     * @param string $method
     * @param array $search
     * @return bool|array
     */
    public function doQuery($method, $search)
    {
        try {
            $username = 'admin';
            $password = 'admin';
            $RpcClient = new RpcClient('http://172.17.100.201:1337/XMLRPC');
            $httpClient = $RpcClient->getHttpClient();
            $httpClient->setAuth($username, $password);

            $return = $RpcClient->call($method, $search);
        } catch (\Exception $e) {
            $return = false;
        }

        return $return;
    }

    /**
     * NIPAP implementation for ByIP method
     *
     * @param string $ip
     * @return Contact|bool|object
     */
    public function getContactByIp($ip)
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            $netmask = 128;
        } elseif (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $netmask = 32;
        } else {
            return false;
        }

        // Start the query for the IP and gather results
        $result = $this->doQuery(
            'search_prefix',
            [
                [
                    'auth' => [
                        'authoritative_source' => 'nipap'
                    ],
                    'query' => [
                        'operator' => 'equals',
                        'val1' => 'prefix',
                        'val2' => "{$ip}/{$netmask}"
                    ],
                    'search_options' => [
                        'include_all_parents' => true,
                    ],
                ]
            ]
        );

        /*
         * Walk results in reverse to get the most specific match to use. (if host has no contact, then move up
         * every prefix until something is found. NIPAP does not inherit AVPS objects which we should ask them to do
         */

        if (is_array($result['result'])) {
            $resultRev = array_reverse($result['result']);
            $firstContact = false;

            foreach ($resultRev as $key => $resultRevSet) {
                if (!empty($resultRevSet['avps']['AbuseIO_Name']) &&
                    !empty($resultRevSet['avps']['AbuseIO_Contact']) &&
                    !empty($resultRevSet['avps']['AbuseIO_AutoNotify']) &&
                    !empty($resultRevSet['customer_id'])
                ) {
                    $contact = new Contact;

                    if (!empty($resultRevSet['avps']['AbuseIO_AccountId'])) {
                        $contact->account_id = $resultRevSet['avps']['AbuseIO_AccountId'];
                    } else {
                        $contact->account_id = 1;
                    }

                    $contact->reference     = $resultRevSet['customer_id'];
                    $contact->name          = $resultRevSet['avps']['AbuseIO_Name'];
                    $contact->enabled       = empty($resultRevSet['avps']['AbuseIO_Disabled']) ? true : false;
                    $contact->auto_notify   = $resultRevSet['avps']['AbuseIO_AutoNotify'];
                    $contact->email         = $resultRevSet['avps']['AbuseIO_Contact'];
                    $contact->api_host      = empty(
                    $resultRevSet['avps']['AbuseIO_RPCHost']) ? false : $resultRevSet['avps']['AbuseIO_RPCHost'];
                    $contact->api_key       = empty(
                    $resultRevSet['avps']['AbuseIO_RPCKey']) ? false : $resultRevSet['avps']['AbuseIO_RPCKey'];

                    return $contact;
                }

                /*
                 * Save the first found customer ID in case there is no AVPS found at all
                 */
                if (!empty($resultRevSet['customer_id']) &&
                    empty($firstContact)
                ) {
                    $firstContact = $resultRevSet['customer_id'];
                }

            }

            /*
             * At this point we never found the required AVPS objects, but we did find a customer ID (reference) so
             * we can fallback to a lookup onto the byID section of FindContact.
             */
            if (!empty($firstContact)) {
                $contact = FindContact::byId($firstContact);
                if ($contact->name !== 'UNDEF') {
                    return $contact;
                }
            }
        }

        return false;
    }
}
